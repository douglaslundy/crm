<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\Emissao\PreparadorDaNota;
use App\Modules\Fiscal\Application\Emissao\PreparadorDaNotaDeServico;
use App\Modules\Fiscal\Application\Emissao\VerificadorDoEmitente;
use App\Modules\Fiscal\Application\Jobs\EmitirNfseJob;
use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
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
        private readonly PreparadorDaNotaDeServico $preparadorDeServico,
    ) {}

    /** Os bloqueios de assinatura, plano e emitente que valem antes de preparar a nota. */
    private function garantirPodeEmitirNota(Nota $nota, Usuario $autor): Emitente
    {
        $tenant = $autor->tenant;
        if (! $tenant->situacao->permiteEscrita()) {
            throw AssinaturaSemEscritaException::para($tenant->situacao);
        }
        $nfce = $nota->modelo === ModeloDocumento::Nfce;
        $servico = $nota->modelo === ModeloDocumento::Dps;
        $this->planos->garantirModulo($tenant, match (true) {
            $servico => Modulo::FiscalNfse,
            $nfce => Modulo::FiscalNfce,
            default => Modulo::FiscalNfe,
        });

        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        if ($servico) {
            $this->verificador->garantirAptoParaNfse($emitente, $tenant);
        } else {
            $this->verificador->garantirApto($emitente, $tenant, $nota->modelo);
        }
        if ($nfce) {
            $this->verificador->garantirCsc($emitente, $emitente->ambiente_fiscal->value);
        }
        if ($emitente->ambiente_fiscal === AmbienteFiscal::Producao) {
            $this->planos->garantirCapacidade($tenant, Recurso::DocumentosMes);
        }

        return $emitente;
    }

    /** Diz se a nota está pronta para emitir, sem emitir: não muda a nota, os itens, a série nem o status. */
    public function verificar(Nota $nota, Usuario $autor): void
    {
        $this->politica->garantirPodeEmitir($autor);
        $nota = Nota::query()->whereKey($nota->id)->firstOrFail();
        if (! $nota->status->editavel()) {
            throw new NotaNaoEditavelException('Esta nota não pode ser emitida.');
        }
        $emitente = $this->garantirPodeEmitirNota($nota, $autor);

        // `preparar` valida e congela o snapshot; aqui só a validação interessa, então tudo é desfeito.
        DB::beginTransaction();
        try {
            if ($nota->modelo === ModeloDocumento::Dps) {
                $this->preparadorDeServico->preparar($nota, $emitente);
            } else {
                $this->preparador->preparar($nota, $emitente);
            }
        } finally {
            DB::rollBack();
        }
    }

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

            $emitente = $this->garantirPodeEmitirNota($nota, $autor);
            $servico = $nota->modelo === ModeloDocumento::Dps;

            if ($servico) {
                $this->preparadorDeServico->preparar($nota, $emitente);
            } else {
                $this->preparador->preparar($nota, $emitente);
            }

            $ambiente = $emitente->ambiente_fiscal->value;
            // Uso denegado consome o número: reaproveitá-lo seria rejeitado de novo.
            $denegada = in_array($nota->cstat, ResultadoDeConsulta::DENEGADAS, true);
            $numeroNovo = $nota->numero === null || $nota->ambiente !== $ambiente || $denegada;
            if ($numeroNovo) {
                $serie = EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', $nota->modelo)->lockForUpdate()->firstOrFail();
                $nota->numero = $serie->proximo_numero;
                $nota->serie = $serie->serie;
                $serie->update(['proximo_numero' => $serie->proximo_numero + 1]);
            }

            $anterior = $nota->status;
            // ERRO = incerteza: a nota pode ter chegado à SEFAZ. Com o mesmo número, a chave fica para o job
            // consultar antes de reenviar. REJEITADA é decisão da SEFAZ, então a chave antiga não vale mais.
            $manterChave = $anterior === StatusNota::Erro && ! $numeroNovo;
            $nota->forceFill([
                'status' => StatusNota::Processando, 'ambiente' => $ambiente, 'iniciada_em' => now(),
                'chave' => $manterChave ? $nota->chave : null, 'protocolo' => null,
                'xml_enviado' => $manterChave ? $nota->xml_enviado : null, 'xml_autorizado' => null,
                'cstat' => null, 'motivo' => null, 'mensagem_erro' => null,
                'tentativas_numero' => $numeroNovo ? 0 : $nota->tentativas_numero,
            ])->save();
            NotaEvento::registrar($nota, $anterior, StatusNota::Processando, "Emissão iniciada (nº {$nota->numero}, série {$nota->serie}, {$ambiente})", $autor->id);

            return [$nota, true];
        });

        [$nota, $despachar] = $resultado;
        if ($despachar) {
            if ($nota->modelo === ModeloDocumento::Dps) {
                EmitirNfseJob::dispatch($nota->id, $nota->tenant_id);
            } else {
                EmitirNotaJob::dispatch($nota->id, $nota->tenant_id);
            }
        }

        return $nota->refresh();
    }
}
