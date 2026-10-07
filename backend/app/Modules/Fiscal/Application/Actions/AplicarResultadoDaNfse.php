<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use Illuminate\Support\Facades\DB;

/** Única porta de saída de uma emissão de NFS-e: toda mudança de status vinda da ADN passa por aqui, com lock. */
final class AplicarResultadoDaNfse
{
    public function executar(string $notaId, ResultadoDeEmissaoNfse $r): Nota
    {
        return DB::transaction(function () use ($notaId, $r): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Processando) {
                return $nota;
            }
            $anterior = $nota->status;
            $campos = ['status' => $r->status, 'cstat' => $r->cstat, 'motivo' => $r->motivo];
            if ($r->status === StatusNota::Autorizada) {
                $campos += ['chave' => $r->chave, 'numero_nfse' => $r->numeroNfse, 'xml_autorizado' => $r->xmlAutorizado, 'emitido_em' => now(), 'mensagem_erro' => null];
            }
            if ($r->status === StatusNota::Erro) {
                $campos['mensagem_erro'] = $r->mensagemErro;
            }
            $nota->forceFill($campos)->save();
            NotaEvento::registrar($nota, $anterior, $r->status, $r->mensagemErro ?? ($r->cstat !== null ? "cStat {$r->cstat}: {$r->motivo}" : null));

            return $nota;
        });
    }

    /** Reconciliação e retry: a ADN já tem a nota (autorizada ou cancelada), mesmo que a resposta do nosso envio tenha se perdido. */
    public function aplicarConsulta(string $notaId, ResultadoDeConsultaNfse $r): Nota
    {
        return DB::transaction(function () use ($notaId, $r): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if (! in_array($nota->status, [StatusNota::Processando, StatusNota::Erro], true)) {
                return $nota;
            }
            $anterior = $nota->status;

            if ($r->situacao === SituacaoNaSefaz::Autorizada) {
                $nota->forceFill([
                    'status' => StatusNota::Autorizada, 'chave' => $r->chave, 'numero_nfse' => $r->numeroNfse, 'cstat' => $r->cstat,
                    'motivo' => 'NFS-e gerada', 'xml_autorizado' => $r->xmlAutorizado, 'emitido_em' => $nota->emitido_em ?? now(), 'mensagem_erro' => null,
                ])->save();
                NotaEvento::registrar($nota, $anterior, StatusNota::Autorizada, 'Conciliada com a ADN');
            } elseif ($r->situacao === SituacaoNaSefaz::Cancelada) {
                $nota->forceFill(['status' => StatusNota::Cancelada, 'chave' => $r->chave ?? $nota->chave, 'cancelado_em' => now()])->save();
                NotaEvento::registrar($nota, $anterior, StatusNota::Cancelada, 'Cancelamento encontrado na ADN');
            }

            return $nota;
        });
    }
}
