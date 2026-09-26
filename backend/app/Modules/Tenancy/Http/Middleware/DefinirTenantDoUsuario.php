<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DefinirTenantDoUsuario
{
    public function __construct(private readonly TenantContext $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->contexto->set($request->user()?->tenant_id);

        return $next($request);
    }
}
