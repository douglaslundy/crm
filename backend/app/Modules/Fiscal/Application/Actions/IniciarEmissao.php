<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\Emissao\PreparadorDaNota;
use App\Modules\Fiscal\Application\Emissao\VerificadorDoEmitente;
use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use Illuminate\Support\Facades\DB;

final class IniciarEmissao
{
    public function __construct(
        private readonly PoliticaDeNotas $politica,
        private readonly EntitlementService $planos,
        private readonly VerificadorDoEmitente $verificador,
        private readonly PreparadorDaNota $preparador,
    ) {}

    public function executar(string $notaId, Usuario $autor): Nota
    {
        $this->politica->garantirPodeEmitir($autor);

        $resultado = DB::transaction(function () use ($notaId, $autor): array {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();

            if (in_array($nota->status, [StatusNota::Autorizada, StatusNota::Processando], true)) {
                return [$nota, false];
            }
            if (! $nota->status->editavel()) {
                throw new NotaNaoEditavelException('Esta nota não pode ser emitida.');
            }

            $tenant = $autor->tenant;
            if (! $tenant->situacao->permiteEscrita()) {
                throw AssinaturaSemEscritaException::para($tenant->situacao);
            }
            $this->planos->garantirModulo($tenant, Modulo::FiscalNfe);

            $emitente = Emitente::query()->findOrFail($nota->emitente_id);
            $this->verificador->garantirApto($emitente, $tenant);
            if ($emitente->ambiente_fiscal === AmbienteFiscal::Producao) {
                $this->planos->garantirCapacidade($tenant, Recurso::DocumentosMes);
            }

            $this->preparador->preparar($nota, $emitente);

            $ambiente = $emitente->ambiente_fiscal->value;
            $numeroNovo = $nota->numero === null || $nota->ambiente !== $ambiente;
            if ($numeroNovo) {
                $serie = EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', ModeloDocumento::Nfe)->lockForUpdate()->firstOrFail();
                $nota->numero = $serie->proximo_numero;
                $nota->serie = $serie->serie;
                $serie->update(['proximo_numero' => $serie->proximo_numero + 1]);
            }

            $anterior = $nota->status;
            $nota->forceFill([
                'status' => StatusNota::Processando, 'ambiente' => $ambiente, 'iniciada_em' => now(),
                'chave' => null, 'protocolo' => null, 'xml_enviado' => null, 'xml_autorizado' => null,
                'cstat' => null, 'motivo' => null, 'mensagem_erro' => null,
                'tentativas_numero' => $numeroNovo ? 0 : $nota->tentativas_numero,
            ])->save();
            NotaEvento::registrar($nota, $anterior, StatusNota::Processando, "Emissão iniciada (nº {$nota->numero}, série {$nota->serie}, {$ambiente})", $autor->id);

            return [$nota, true];
        });

        [$nota, $despachar] = $resultado;
        if ($despachar) {
            EmitirNotaJob::dispatch($nota->id, $nota->tenant_id);
        }

        return $nota->refresh();
    }
}
