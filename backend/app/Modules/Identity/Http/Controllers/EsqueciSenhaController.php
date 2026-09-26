<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\EsqueciSenhaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

final class EsqueciSenhaController
{
    public function __invoke(EsqueciSenhaRequest $request): JsonResponse
    {
        // Resultado ignorado e envio depois da resposta, de propósito: nem a mensagem
        // nem o tempo de resposta podem revelar se o e-mail está cadastrado.
        $email = $request->string('email')->toString();
        dispatch(function () use ($email): void {
            Password::broker()->sendResetLink(['email' => $email]);
        })->afterResponse();

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.',
        ]);
    }
}
