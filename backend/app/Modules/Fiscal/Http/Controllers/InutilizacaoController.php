<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\InutilizarNumeracao;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Fiscal\Http\Requests\InutilizarNumeracaoRequest;
use App\Modules\Fiscal\Http\Resources\InutilizacaoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class InutilizacaoController
{
    public function index(): AnonymousResourceCollection
    {
        return InutilizacaoResource::collection(Inutilizacao::query()->latest('created_at')->paginate(25));
    }

    public function store(InutilizarNumeracaoRequest $request, InutilizarNumeracao $acao): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return (new InutilizacaoResource($acao->executar($request->dados(), $autor)))->response()->setStatusCode(201);
    }
}
