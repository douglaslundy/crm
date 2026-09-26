<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Publico;

use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Http\Resources\PlanoResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlanosPublicosController
{
    public function __invoke(): AnonymousResourceCollection
    {
        return PlanoResource::collection(
            Plano::query()->with(['modulos', 'limites'])
                ->where('ativo', true)->where('visivel', true)
                ->orderBy('ordem')->orderBy('nome')
                ->get(),
        );
    }
}
