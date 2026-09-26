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
        // Resultado ignorado de propósito: a resposta nunca revela se o e-mail existe.
        Password::broker()->sendResetLink(['email' => $request->string('email')->toString()]);

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.',
        ]);
    }
}
