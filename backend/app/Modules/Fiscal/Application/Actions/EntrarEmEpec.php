<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Domain\Exceptions\FalhaDeComunicacaoException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * NF-e que perdeu a comunicação com a SEFAZ da UF: remonta com tpEmis 4, registra o EPEC no Ambiente Nacional e guarda
 * o XML assinado para retransmitir em até 7 dias. Só é chamada para falha REAL de comunicação; antes de entrar,
 * consulta a chave original para nunca duplicar a nota.
 */
final class EntrarEmEpec
{
    public const JUSTIFICATIVA = 'Falha de comunicação com a SEFAZ na emissão; emitida em contingência EPEC.';

    public function __construct(
        private readonly EmissorDeNfe $emissor,
        private readonly AplicarResultadoDaEmissao $aplicar,
        private readonly ConsultarChaveOriginal $consultarChaveOriginal,
    ) {}

    public function executar(string $notaId): Nota
    {
        $nota = Nota::query()->findOrFail($notaId);
        if ($nota->status !== StatusNota::Processando || $nota->modelo !== ModeloDocumento::Nfe) {
            return $nota;
        }
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);

        $consulta = $this->consultarChaveOriginal->executar($emitente, $nota);
        if ($consulta !== null) {
            return $this->aplicar->aplicarConsulta($nota->id, $consulta);
        }

        $agora = CarbonImmutable::now();
        $dto = NotaParaEmissao::deNota($nota, $emitente, Tenant::query()->findOrFail($nota->tenant_id));
        $xml = $this->emissor->prepararEpec($emitente, $dto, self::JUSTIFICATIVA, $agora);

        // O envio fica fora da transação: não segura lock de linha durante a rede.
        try {
            $registro = $this->emissor->registrarEpec($emitente, $dto, $xml);
        } catch (FalhaDeComunicacaoException) {
            return $this->aplicar->executar($nota->id, ResultadoDeEmissao::erro('SEFAZ e Ambiente Nacional indisponíveis ao registrar o EPEC. Tente novamente: o número foi preservado.'));
        }
        if (! $registro->registrado) {
            return $this->aplicar->executar($nota->id, ResultadoDeEmissao::erro("EPEC não registrado (cStat {$registro->cstat}): {$registro->motivo}"));
        }

        return DB::transaction(function () use ($notaId, $xml, $registro, $agora): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Processando) {
                return $nota;
            }
            $chaveOriginal = $nota->chave;
            $nota->forceFill([
                'status' => StatusNota::Contingencia, 'chave' => $xml->chave, 'xml_enviado' => $xml->xml,
                'cstat' => null, 'motivo' => null, 'mensagem_erro' => null,
                'contingencia_tipo' => TipoDeContingencia::Epec, 'contingencia_desde' => $agora,
                'contingencia_limite_em' => $agora->addHours(TipoDeContingencia::Epec->prazoEmHoras()),
                'contingencia_motivo' => self::JUSTIFICATIVA,
                'epec_protocolo' => $registro->protocolo, 'epec_registrado_em' => $agora, 'xml_epec' => $registro->xmlEvento,
            ])->save();
            NotaEvento::registrar($nota, StatusNota::Processando, StatusNota::Contingencia, 'Entrou em EPEC (protocolo '.$registro->protocolo.')'.($chaveOriginal === null ? '' : " (chave original {$chaveOriginal})"));

            return $nota;
        });
    }
}
