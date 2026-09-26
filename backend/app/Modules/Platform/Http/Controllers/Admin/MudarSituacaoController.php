<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\MudarSituacaoDaEmpresa;
use App\Modules\Platform\Http\Requests\MudarSituacaoRequest;
use App\Modules\Platform\Http\Resources\EmpresaResource;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class MudarSituacaoController
{
    public function __invoke(MudarSituacaoRequest $request, Tenant $tenant, MudarSituacaoDaEmpresa $mudar): EmpresaResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $mudar->executar($tenant, $request->situacao(), $request->string('motivo')->toString(), $autor);

        return new EmpresaResource($tenant->load('plano'));
    }
}
