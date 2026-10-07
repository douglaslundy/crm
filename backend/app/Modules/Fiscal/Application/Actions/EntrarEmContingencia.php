<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * NFC-e que perdeu a comunicação com a SEFAZ: emite offline (tpEmis 9) e guarda o XML assinado para retransmitir.
 * Só é chamada para falha REAL de comunicação; antes de entrar, consulta a chave original para nunca duplicar a nota.
 */
final class EntrarEmContingencia
{
    public const JUSTIFICATIVA = 'Falha de comunicação com a SEFAZ na emissão; emitida em contingência offline.';

    public function __construct(
        private readonly EmissorDeNfe $emissor,
        private readonly AplicarResultadoDaEmissao $aplicar,
        private readonly ConsultarChaveOriginal $consultarChaveOriginal,
    ) {}

    public function executar(string $notaId): Nota
    {
        $nota = Nota::query()->findOrFail($notaId);
        if ($nota->status !== StatusNota::Processando || $nota->modelo !== ModeloDocumento::Nfce) {
            return $nota;
        }
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);

        $consulta = $this->consultarChaveOriginal->executar($emitente, $nota);
        if ($consulta !== null) {
            return $this->aplicar->aplicarConsulta($nota->id, $consulta);
        }

        $agora = CarbonImmutable::now();
        $dto = NotaParaEmissao::deNota($nota, $emitente, Tenant::query()->findOrFail($nota->tenant_id));
        $xml = $this->emissor->prepararContingencia($emitente, $dto, self::JUSTIFICATIVA, $agora);

        return DB::transaction(function () use ($notaId, $xml, $agora): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Processando) {
                return $nota;
            }
            $chaveOriginal = $nota->chave;
            $nota->forceFill([
                'status' => StatusNota::Contingencia, 'chave' => $xml->chave, 'xml_enviado' => $xml->xml,
                'cstat' => null, 'motivo' => null, 'mensagem_erro' => null,
                'contingencia_desde' => $agora, 'contingencia_limite_em' => $agora->addHours(TipoDeContingencia::OfflineNfce->prazoEmHoras()),
                'contingencia_motivo' => self::JUSTIFICATIVA, 'contingencia_tipo' => TipoDeContingencia::OfflineNfce,
            ])->save();
            NotaEvento::registrar($nota, StatusNota::Processando, StatusNota::Contingencia, 'Entrou em contingência offline'.($chaveOriginal === null ? '' : " (chave original {$chaveOriginal})"));

            return $nota;
        });
    }
}
