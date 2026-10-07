<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\AtualizarVendaMista;
use App\Modules\Fiscal\Application\Actions\CriarVendaMista;
use App\Modules\Fiscal\Application\Actions\EmitirVendaMista;
use App\Modules\Fiscal\Application\Actions\ExcluirVendaMista;
use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Fiscal\Http\Requests\SalvarVendaRequest;
use App\Modules\Fiscal\Http\Resources\VendaResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class VendaController
{
    public function store(SalvarVendaRequest $request, CriarVendaMista $acao): JsonResponse
    {
        $venda = $acao->executar($request->dados(), $this->autor($request));

        return (new VendaResource($venda->load(['cliente', 'notas'])))->response()->setStatusCode(201);
    }

    public function show(Venda $venda): VendaResource
    {
        return new VendaResource($venda->load(['cliente', 'notas']));
    }

    public function update(SalvarVendaRequest $request, Venda $venda, AtualizarVendaMista $acao): VendaResource
    {
        $venda = $acao->executar($venda, $request->dados(), $this->autor($request));

        return new VendaResource($venda->load(['cliente', 'notas']));
    }

    public function destroy(Request $request, Venda $venda, ExcluirVendaMista $acao): Response
    {
        $acao->executar($venda, $this->autor($request));

        return response()->noContent();
    }

    public function emitir(Request $request, Venda $venda, EmitirVendaMista $acao): JsonResponse
    {
        $dados = $request->validate(['emitir_apenas_validas' => ['nullable', 'boolean']]);
        $resultado = $acao->executar($venda, $this->autor($request), (bool) ($dados['emitir_apenas_validas'] ?? false));

        return response()->json(['data' => ['id' => $venda->id, 'notas' => $resultado->notas()]], 202);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
