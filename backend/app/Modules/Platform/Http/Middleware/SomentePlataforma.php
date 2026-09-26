<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** SUPERADMIN faz tudo; SUPORTE só consulta. */
final class SomentePlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof Usuario || ! $usuario->papel->ehDaPlataforma()) {
            throw new AcessoNegadoException('Esta área é exclusiva da administração da plataforma.');
        }

        if ($usuario->papel === Papel::Suporte && ! $request->isMethodSafe()) {
            throw new AcessoNegadoException('O perfil de suporte só pode consultar.');
        }

        return $next($request);
    }
}
