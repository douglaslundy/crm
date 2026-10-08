<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeChavesDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;

final class ListarChavesDeApi
{
    public function __construct(private readonly PoliticaDeChavesDeApi $politica) {}

    /** @return Collection<int, ChaveDeApi> */
    public function executar(Usuario $autor): Collection
    {
        $this->politica->garantirPodeGerenciar($autor);

        return ChaveDeApi::query()->with('usuario')->orderByDesc('created_at')->orderByDesc('id')->get();
    }
}
