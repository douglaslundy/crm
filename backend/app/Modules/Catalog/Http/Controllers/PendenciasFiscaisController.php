<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Catalog\Http\Resources\ProdutoResource;
use App\Modules\Catalog\Http\Resources\ServicoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PendenciasFiscaisController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): JsonResponse
    {
        $produtos = Produto::query()->get()->filter(fn (Produto $p): bool => $p->pendenteDeRevisaoFiscal())->values();
        $servicos = Servico::query()->get()->filter(fn (Servico $s): bool => $s->pendenteDeRevisaoFiscal())->values();

        return response()->json([
            'data' => [
                'produtos' => ProdutoResource::collection($produtos),
                'servicos' => ServicoResource::collection($servicos),
            ],
        ]);
    }

    public function marcarRevisado(Request $request, string $id): ProdutoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $produto = Produto::query()->findOrFail($id);
        $produto->update(['fiscal_revisado_em' => now()]);

        return new ProdutoResource($produto);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
