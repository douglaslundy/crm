<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Actions\AtualizarServico;
use App\Modules\Catalog\Application\Actions\CriarServico;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Catalog\Http\Requests\AtualizarServicoRequest;
use App\Modules\Catalog\Http\Requests\CriarServicoRequest;
use App\Modules\Catalog\Http\Resources\ServicoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ServicosController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): AnonymousResourceCollection
    {
        return ServicoResource::collection(Servico::query()->orderBy('nome')->get());
    }

    public function show(string $id): ServicoResource
    {
        return new ServicoResource(Servico::query()->findOrFail($id));
    }

    public function store(CriarServicoRequest $request, CriarServico $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return (new ServicoResource($criar->executar($this->autor($request), $request->dados())))
            ->response()->setStatusCode(201);
    }

    public function update(AtualizarServicoRequest $request, string $id, AtualizarServico $atualizar): ServicoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $servico = Servico::query()->findOrFail($id);

        return new ServicoResource($atualizar->executar($servico, $request->dados()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
