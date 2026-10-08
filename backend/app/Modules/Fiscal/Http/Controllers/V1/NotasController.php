<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers\V1;

use App\Modules\Fiscal\Application\Actions\EmitirNotaPelaApi;
use App\Modules\Fiscal\Http\Requests\EmitirNotaApiRequest;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;

final class NotasController
{
    public function store(EmitirNotaApiRequest $request, EmitirNotaPelaApi $emitir): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        /** @var AmbienteDaChave $ambiente */
        $ambiente = $request->attributes->get('ambiente_da_chave');

        $nota = $emitir->executar($autor, $ambiente, $request->dados());
        $request->attributes->set('idempotencia_nota_id', $nota->id);

        return response()->json(['data' => ['id' => $nota->id, 'modelo' => $nota->modelo->value, 'status' => $nota->status->value]], 202);
    }
}
