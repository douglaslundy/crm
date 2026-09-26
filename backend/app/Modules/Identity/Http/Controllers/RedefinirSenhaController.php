<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Requests\RedefinirSenhaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RedefinirSenhaController
{
    public function __invoke(RedefinirSenhaRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Usuario $usuario, string $senha): void {
                $usuario->forceFill(['password' => $senha, 'remember_token' => Str::random(60)])->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Link inválido ou expirado.']);
        }

        return response()->json(['message' => 'Senha redefinida. Faça login com a nova senha.']);
    }
}
