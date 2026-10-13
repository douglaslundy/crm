<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarMotivoDePerda;
use App\Modules\Crm\Application\Actions\CriarMotivoDePerda;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Http\Requests\MotivoDePerdaRequest;
use App\Modules\Crm\Http\Resources\MotivoDePerdaResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MotivosDePerdaController
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

        return MotivoDePerdaResource::collection(MotivoDePerda::query()->orderBy('nome')->get());
    }

    public function store(MotivoDePerdaRequest $request, CriarMotivoDePerda $criar): JsonResponse
    {
        $motivo = $criar->executar($this->autor($request), $request->string('nome')->toString());

        return (new MotivoDePerdaResource($motivo))->response()->setStatusCode(201);
    }

    public function update(MotivoDePerdaRequest $request, string $id, AtualizarMotivoDePerda $atualizar): MotivoDePerdaResource
    {
        $motivo = MotivoDePerda::query()->findOrFail($id);

        return new MotivoDePerdaResource($atualizar->executar($this->autor($request), $motivo, $request->validated()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
