<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarFunil;
use App\Modules\Crm\Application\Actions\CriarFunil;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Http\Requests\FunilRequest;
use App\Modules\Crm\Http\Resources\FunilResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FunisController
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly PreparadorDoCrm $preparador,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);
        $this->preparador->garantir($autor);

        return FunilResource::collection(Funil::query()->with('etapas')->orderBy('posicao')->get());
    }

    public function store(FunilRequest $request, CriarFunil $criar): JsonResponse
    {
        $funil = $criar->executar($this->autor($request), $request->string('nome')->toString());

        return (new FunilResource($funil->load('etapas')))->response()->setStatusCode(201);
    }

    public function update(FunilRequest $request, string $id, AtualizarFunil $atualizar): FunilResource
    {
        $funil = Funil::query()->findOrFail($id);

        return new FunilResource($atualizar->executar($this->autor($request), $funil, $request->validated())->load('etapas'));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
