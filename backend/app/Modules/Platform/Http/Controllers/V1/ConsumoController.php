<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\V1;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConsumoController
{
    public function __invoke(Request $request, EntitlementService $entitlement): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return response()->json(['data' => $entitlement->consumo($autor->tenant)]);
    }
}
