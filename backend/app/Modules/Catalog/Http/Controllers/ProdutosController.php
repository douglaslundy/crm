<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Actions\AtualizarProduto;
use App\Modules\Catalog\Application\Actions\CriarProduto;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Http\Requests\AtualizarProdutoRequest;
use App\Modules\Catalog\Http\Requests\CriarProdutoRequest;
use App\Modules\Catalog\Http\Resources\ProdutoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProdutosController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): AnonymousResourceCollection
    {
        return ProdutoResource::collection(Produto::query()->orderBy('nome')->get());
    }

    public function show(string $id): ProdutoResource
    {
        return new ProdutoResource(Produto::query()->findOrFail($id));
    }

    public function store(CriarProdutoRequest $request, CriarProduto $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return (new ProdutoResource($criar->executar($this->autor($request), $request->dados())))
            ->response()->setStatusCode(201);
    }

    public function update(AtualizarProdutoRequest $request, string $id, AtualizarProduto $atualizar): ProdutoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $produto = Produto::query()->findOrFail($id);

        return new ProdutoResource($atualizar->executar($produto, $request->dados()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
