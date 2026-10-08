<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers\V1;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Http\Resources\EmpresaV1Resource;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmpresaController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        /** @var AmbienteDaChave $ambiente */
        $ambiente = $request->attributes->get('ambiente_da_chave');

        return response()->json(['data' => (new EmpresaV1Resource($autor->tenant, Emitente::query()->first(), $ambiente))->toArray()]);
    }
}
