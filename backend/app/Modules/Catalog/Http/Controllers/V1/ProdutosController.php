<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\V1;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Http\Resources\ProdutoResource;
use App\Modules\Shared\Http\PaginadorPorCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Só a listagem difere do app (cursor em vez de lista inteira); show, store e update são os do app. */
final class ProdutosController
{
    public function index(Request $request, PaginadorPorCursor $paginador): JsonResponse
    {
        return response()->json($paginador->paginar(
            Produto::query(),
            $request,
            fn (Produto $produto): array => (new ProdutoResource($produto))->resolve($request),
            ['nome', 'sku', 'gtin'],
        ));
    }
}
