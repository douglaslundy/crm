<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarEtapa;
use App\Modules\Crm\Application\Actions\CriarEtapa;
use App\Modules\Crm\Application\Actions\ReordenarEtapas;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Http\Requests\EtapaRequest;
use App\Modules\Crm\Http\Requests\OrdemDasEtapasRequest;
use App\Modules\Crm\Http\Resources\EtapaResource;
use App\Modules\Crm\Http\Resources\FunilResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EtapasController
{
    public function store(EtapaRequest $request, string $funilId, CriarEtapa $criar): JsonResponse
    {
        $funil = Funil::query()->findOrFail($funilId);
        $etapa = $criar->executar(
            $this->autor($request),
            $funil,
            $request->string('nome')->toString(),
            $request->integer('probabilidade'),
        );

        return (new EtapaResource($etapa))->response()->setStatusCode(201);
    }

    public function update(EtapaRequest $request, string $id, AtualizarEtapa $atualizar): EtapaResource
    {
        $etapa = Etapa::query()->findOrFail($id);

        return new EtapaResource($atualizar->executar($this->autor($request), $etapa, $request->validated()));
    }

    public function ordenar(OrdemDasEtapasRequest $request, string $funilId, ReordenarEtapas $reordenar): FunilResource
    {
        $funil = Funil::query()->findOrFail($funilId);
        /** @var list<string> $ids */
        $ids = $request->validated('etapas');
        $reordenar->executar($this->autor($request), $funil, $ids);

        return new FunilResource($funil->load('etapas'));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
