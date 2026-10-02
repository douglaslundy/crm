<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class AtualizarRascunhoDeNota
{
    public function __construct(private readonly PoliticaDeNotas $politica, private readonly CriarRascunhoDeNota $criar) {}

    /** @param array<string, mixed> $dados */
    public function executar(Nota $nota, array $dados, Usuario $autor): Nota
    {
        $this->politica->garantirPodeRascunhar($autor);

        return DB::transaction(function () use ($nota, $dados): Nota {
            $nota = Nota::query()->whereKey($nota->id)->lockForUpdate()->firstOrFail();
            if (! $nota->status->editavel()) {
                throw new NotaNaoEditavelException;
            }
            $this->criar->preencher($nota, $dados);

            return $nota->refresh();
        });
    }
}
