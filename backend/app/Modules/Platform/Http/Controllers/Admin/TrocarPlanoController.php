<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\TrocarPlanoDaEmpresa;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Http\Requests\TrocarPlanoRequest;
use App\Modules\Platform\Http\Resources\EmpresaResource;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class TrocarPlanoController
{
    public function __invoke(TrocarPlanoRequest $request, Tenant $tenant, TrocarPlanoDaEmpresa $trocar): EmpresaResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $novo = Plano::query()->with('limites')->findOrFail($request->string('plano_id')->toString());

        return new EmpresaResource($trocar->executar($tenant, $novo, $autor));
    }
}
