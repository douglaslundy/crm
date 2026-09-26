<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\SalvarPlano;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Http\Requests\SalvarPlanoRequest;
use App\Modules\Platform\Http\Resources\PlanoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlanosController
{
    public function index(): AnonymousResourceCollection
    {
        return PlanoResource::collection(
            Plano::query()->with(['modulos', 'limites'])->withCount('tenants')->orderBy('ordem')->orderBy('nome')->get(),
        );
    }

    public function show(Plano $plano): PlanoResource
    {
        return new PlanoResource($plano->load(['modulos', 'limites'])->loadCount('tenants'));
    }

    public function store(SalvarPlanoRequest $request, SalvarPlano $salvar): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return (new PlanoResource($salvar->executar(null, $request->dados(), $autor)))->response()->setStatusCode(201);
    }

    public function update(SalvarPlanoRequest $request, Plano $plano, SalvarPlano $salvar): PlanoResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return new PlanoResource($salvar->executar($plano, $request->dados(), $autor));
    }
}
