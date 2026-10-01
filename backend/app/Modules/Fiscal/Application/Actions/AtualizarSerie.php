<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;

/** Editável livremente nesta fase: ainda não existe nota emitida (trava fica para a F3). */
final class AtualizarSerie
{
    public function executar(Emitente $emitente, ModeloDocumento $modelo, string $serie, int $proximoNumero): EmitenteSerie
    {
        return EmitenteSerie::query()->updateOrCreate(
            ['emitente_id' => $emitente->id, 'modelo' => $modelo],
            ['serie' => $serie, 'proximo_numero' => $proximoNumero],
        );
    }
}
