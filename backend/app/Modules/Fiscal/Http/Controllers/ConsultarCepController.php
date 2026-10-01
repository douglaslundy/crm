<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Shared\Infrastructure\ConsultaCep;
use Illuminate\Http\JsonResponse;

final class ConsultarCepController
{
    public function __invoke(string $cep, ConsultaCep $consulta): JsonResponse
    {
        $dados = $consulta->buscar($cep);
        if ($dados === null) {
            return response()->json(['message' => 'CEP não encontrado. Preencha o endereço manualmente.'], 404);
        }

        return response()->json(['data' => $dados]);
    }
}
