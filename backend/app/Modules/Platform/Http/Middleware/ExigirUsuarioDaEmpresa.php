<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExigirUsuarioDaEmpresa
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->tenant_id === null) {
            throw new AcessoNegadoException('Esta área é exclusiva das empresas.');
        }

        return $next($request);
    }
}
