<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarConfiguracao;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Http\Requests\ConfiguracaoRequest;
use App\Modules\Crm\Http\Resources\ConfiguracaoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfiguracaoController
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $this->politica->garantirPodeLer($autor);

        return response()->json(['data' => ['vendedor_ve_so_os_seus' => ConfiguracaoDoCrm::vendedorVeSoOsSeus()]]);
    }

    public function update(ConfiguracaoRequest $request, AtualizarConfiguracao $atualizar): ConfiguracaoResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return new ConfiguracaoResource($atualizar->executar($autor, $request->boolean('vendedor_ve_so_os_seus')));
    }
}
