<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Support\Facades\DB;

/**
 * O próximo número nunca fica no número de uma nota que já o recebeu (mesma série e mesmo ambiente): voltar o contador
 * repete número na SEFAZ (539) e queima a sequência. Série nova ou outro ambiente podem recomeçar do 1.
 */
final class AtualizarSerie
{
    public function executar(Emitente $emitente, ModeloDocumento $modelo, string $serie, int $proximoNumero): EmitenteSerie
    {
        return DB::transaction(function () use ($emitente, $modelo, $serie, $proximoNumero): EmitenteSerie {
            // Mesma ordem de lock de `IniciarEmissao`: a linha da série primeiro.
            EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', $modelo)->lockForUpdate()->first();

            $ultimo = Nota::query()
                ->where('modelo', $modelo)->where('serie', $serie)->where('ambiente', $emitente->ambiente_fiscal->value)
                ->whereNotNull('numero')->max('numero');
            if ($ultimo !== null && $proximoNumero <= (int) $ultimo) {
                throw new EmissaoBloqueadaException(
                    'FISCAL_NUMERO_JA_EMITIDO',
                    "A série {$serie} já usou o número {$ultimo} neste ambiente. O próximo número deve ser ".((int) $ultimo + 1).' ou maior; para recomeçar do 1, use outra série.',
                );
            }

            return EmitenteSerie::query()->updateOrCreate(
                ['emitente_id' => $emitente->id, 'modelo' => $modelo],
                ['serie' => $serie, EmitenteSerie::colunaDoNumero($emitente->ambiente_fiscal) => $proximoNumero],
            );
        });
    }
}
