<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Application\Actions\AtualizarCliente;
use App\Modules\Customers\Application\Actions\ConverterEmCliente;
use App\Modules\Customers\Application\Actions\CriarCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Http\Requests\AtualizarClienteRequest;
use App\Modules\Customers\Http\Requests\CriarClienteRequest;
use App\Modules\Customers\Http\Resources\ClienteResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ClientesController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): AnonymousResourceCollection
    {
        return ClienteResource::collection(Cliente::query()->orderBy('nome')->get());
    }

    public function show(string $id): ClienteResource
    {
        return new ClienteResource(Cliente::query()->with('contatos')->findOrFail($id));
    }

    public function store(CriarClienteRequest $request, CriarCliente $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return (new ClienteResource($criar->executar($this->autor($request), $request->dados())))
            ->response()->setStatusCode(201);
    }

    public function update(AtualizarClienteRequest $request, string $id, AtualizarCliente $atualizar): ClienteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $cliente = Cliente::query()->findOrFail($id);

        return new ClienteResource($atualizar->executar($cliente, $request->dados()));
    }

    public function converterEmCliente(Request $request, string $id, ConverterEmCliente $converter): ClienteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $cliente = Cliente::query()->findOrFail($id);

        return new ClienteResource($converter->executar($cliente));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
