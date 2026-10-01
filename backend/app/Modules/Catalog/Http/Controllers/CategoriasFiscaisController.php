<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Actions\AplicarCategoriaFiscal;
use App\Modules\Catalog\Application\Actions\AtualizarCategoriaFiscal;
use App\Modules\Catalog\Application\Actions\CriarCategoriaFiscal;
use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Http\Requests\AtualizarCategoriaFiscalRequest;
use App\Modules\Catalog\Http\Requests\CriarCategoriaFiscalRequest;
use App\Modules\Catalog\Http\Resources\CategoriaFiscalPadraoResource;
use App\Modules\Catalog\Http\Resources\ProdutoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CategoriasFiscaisController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): AnonymousResourceCollection
    {
        return CategoriaFiscalPadraoResource::collection(CategoriaFiscalPadrao::query()->orderBy('categoria')->get());
    }

    public function store(CriarCategoriaFiscalRequest $request, CriarCategoriaFiscal $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return (new CategoriaFiscalPadraoResource($criar->executar($request->dados())))->response()->setStatusCode(201);
    }

    public function update(AtualizarCategoriaFiscalRequest $request, string $id, AtualizarCategoriaFiscal $atualizar): CategoriaFiscalPadraoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $categoria = CategoriaFiscalPadrao::query()->findOrFail($id);

        return new CategoriaFiscalPadraoResource($atualizar->executar($categoria, $request->dados()));
    }

    public function aplicar(Request $request, string $produtoId, string $categoriaId, AplicarCategoriaFiscal $aplicar): ProdutoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $produto = Produto::query()->findOrFail($produtoId);
        $categoria = CategoriaFiscalPadrao::query()->findOrFail($categoriaId);

        return new ProdutoResource($aplicar->executar($produto, $categoria));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
