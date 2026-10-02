<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use Illuminate\Support\Facades\DB;

/** Única porta de saída de um cancelamento: toda mudança de status vinda da SEFAZ passa por aqui, com lock. */
final class AplicarResultadoDoCancelamento
{
    public function executar(string $notaId, ResultadoDeCancelamento $r): Nota
    {
        return DB::transaction(function () use ($notaId, $r): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Cancelando) {
                return $nota;
            }

            if ($r->aceito) {
                $nota->forceFill([
                    'status' => StatusNota::Cancelada, 'cancelado_em' => now(), 'cancelamento_protocolo' => $r->protocolo,
                    'cancelamento_cstat' => $r->cstat, 'cancelamento_motivo' => $r->motivo, 'xml_cancelamento' => $r->xmlCancelamento,
                ])->save();
                NotaEvento::registrar($nota, StatusNota::Cancelando, StatusNota::Cancelada, "cStat {$r->cstat}: {$r->motivo}");

                return $nota;
            }

            $nota->forceFill([
                'status' => StatusNota::Autorizada, 'cancelamento_cstat' => $r->cstat, 'cancelamento_motivo' => $r->motivo,
            ])->save();
            NotaEvento::registrar($nota, StatusNota::Cancelando, StatusNota::Autorizada, "Cancelamento recusado (cStat {$r->cstat}): {$r->motivo}");

            return $nota;
        });
    }

    /** Falha de comunicação: a nota continua CANCELANDO e a reconciliação decide pelo estado real na SEFAZ. */
    public function registrarFalhaTecnica(string $notaId, string $mensagem): void
    {
        DB::transaction(function () use ($notaId, $mensagem): void {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->first();
            if ($nota === null || $nota->status !== StatusNota::Cancelando) {
                return;
            }
            NotaEvento::registrar($nota, StatusNota::Cancelando, StatusNota::Cancelando, 'Falha técnica ao cancelar: '.mb_substr($mensagem, 0, 300));
        });
    }
}
