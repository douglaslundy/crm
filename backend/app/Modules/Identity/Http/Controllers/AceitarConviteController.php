<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Requests\RedefinirSenhaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Mesmas regras de senha da redefinição, com o broker de convites (72 h). */
final class AceitarConviteController
{
    public function __invoke(RedefinirSenhaRequest $request): JsonResponse
    {
        $status = Password::broker('convites')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Usuario $usuario, string $senha): void {
                $usuario->forceFill(['password' => $senha, 'remember_token' => Str::random(60)])->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Convite inválido ou expirado. Peça um novo convite ao administrador.',
            ]);
        }

        return response()->json(['message' => 'Senha definida. Faça login para entrar.']);
    }
}
