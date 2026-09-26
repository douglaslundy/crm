<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Publico;

use App\Modules\Platform\Infrastructure\ConsultaCnpj;
use App\Modules\Shared\Domain\Cnpj;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class ConsultarCnpjController
{
    public function __invoke(string $cnpj, ConsultaCnpj $consulta): JsonResponse
    {
        $valido = Cnpj::tentar($cnpj) ?? throw ValidationException::withMessages(['cnpj' => 'Informe um CNPJ válido.']);

        $dados = $consulta->buscar($valido);
        if ($dados === null) {
            return response()->json(['message' => 'CNPJ não encontrado. Preencha os dados manualmente.'], 404);
        }

        return response()->json(['data' => $dados]);
    }
}
