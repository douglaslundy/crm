<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\CriarChaveDeApi;
use App\Modules\Identity\Application\Actions\ListarChavesDeApi;
use App\Modules\Identity\Application\Actions\RevogarChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Requests\CriarChaveDeApiRequest;
use App\Modules\Identity\Http\Resources\ChaveDeApiResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ChavesDeApiController
{
    public function index(Request $request, ListarChavesDeApi $listar): AnonymousResourceCollection
    {
        return ChaveDeApiResource::collection($listar->executar($this->autor($request)));
    }

    public function store(CriarChaveDeApiRequest $request, CriarChaveDeApi $criar): JsonResponse
    {
        $resultado = $criar->executar($this->autor($request), $request->nome(), $request->ambiente());
        $resultado->modelo->load('usuario');

        return (new ChaveDeApiResource($resultado->modelo))->comChave($resultado->texto)->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $id, RevogarChaveDeApi $revogar): ChaveDeApiResource
    {
        $chave = $revogar->executar($this->autor($request), $id);

        return new ChaveDeApiResource($chave->load('usuario'));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
