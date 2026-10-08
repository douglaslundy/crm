<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\V1;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Http\Resources\ClienteResource;
use App\Modules\Shared\Http\PaginadorPorCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Só a listagem difere do app (cursor em vez de lista inteira); show, store e update são os do app. */
final class ClientesController
{
    public function index(Request $request, PaginadorPorCursor $paginador): JsonResponse
    {
        return response()->json($paginador->paginar(
            Cliente::query(),
            $request,
            fn (Cliente $cliente): array => (new ClienteResource($cliente))->resolve($request),
            ['nome', 'cpf_cnpj', 'email'],
        ));
    }
}
