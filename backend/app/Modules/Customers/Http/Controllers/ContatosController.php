<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Application\Actions\AtualizarContato;
use App\Modules\Customers\Application\Actions\CriarContato;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Domain\Models\Contato;
use App\Modules\Customers\Http\Requests\AtualizarContatoRequest;
use App\Modules\Customers\Http\Requests\CriarContatoRequest;
use App\Modules\Customers\Http\Resources\ContatoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContatosController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function store(CriarContatoRequest $request, string $clienteId, CriarContato $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $cliente = Cliente::query()->findOrFail($clienteId);

        return (new ContatoResource($criar->executar($cliente, $request->dados())))->response()->setStatusCode(201);
    }

    public function update(AtualizarContatoRequest $request, string $clienteId, string $contatoId, AtualizarContato $atualizar): ContatoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $contato = Contato::query()->where('cliente_id', $clienteId)->findOrFail($contatoId);

        return new ContatoResource($atualizar->executar($contato, $request->dados()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
