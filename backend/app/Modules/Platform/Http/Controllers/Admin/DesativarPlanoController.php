<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\DesativarPlano;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Http\Resources\PlanoResource;
use Illuminate\Http\Request;

final class DesativarPlanoController
{
    public function __invoke(Request $request, Plano $plano, DesativarPlano $desativar): PlanoResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return new PlanoResource($desativar->executar($plano, $autor));
    }
}
