<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Usuário desativado com sessão aberta perde o acesso na próxima requisição. */
final class GarantirUsuarioAtivo
{
    public const MENSAGEM = 'Sua conta foi desativada. Fale com o administrador da empresa.';

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario instanceof Usuario && ! $usuario->ativo) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => self::MENSAGEM], 401);
        }

        return $next($request);
    }
}
