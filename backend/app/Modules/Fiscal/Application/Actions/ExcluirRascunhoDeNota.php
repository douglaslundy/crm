<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Support\Facades\DB;

final class ExcluirRascunhoDeNota
{
    public function executar(Nota $nota): void
    {
        DB::transaction(function () use ($nota): void {
            $nota = Nota::query()->whereKey($nota->id)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Rascunho) {
                throw new NotaNaoEditavelException('Só é possível excluir uma nota em rascunho.');
            }
            $nota->delete();
        });
    }
}
