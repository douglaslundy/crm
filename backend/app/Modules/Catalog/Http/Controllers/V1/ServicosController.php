<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\V1;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Catalog\Http\Resources\ServicoResource;
use App\Modules\Shared\Http\PaginadorPorCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Só a listagem difere do app (cursor em vez de lista inteira); show, store e update são os do app. */
final class ServicosController
{
    public function index(Request $request, PaginadorPorCursor $paginador): JsonResponse
    {
        return response()->json($paginador->paginar(
            Servico::query(),
            $request,
            fn (Servico $servico): array => (new ServicoResource($servico))->resolve($request),
            ['nome', 'codigo_lc116'],
        ));
    }
}
