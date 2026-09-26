<?php

use App\Modules\Identity\Http\Middleware\GarantirUsuarioAtivo;
use App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Route model binding de Model de tenant precisa do contexto já definido;
        // sem isso o TenantScope (fail-closed) responde 404 para o próprio registro.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: DefinirTenantDoUsuario::class,
        );

        // Usuário desativado precisa perder o acesso antes de qualquer resolução de
        // tenant/binding; sem isso o SortedMiddleware do Laravel roda a checagem depois.
        $middleware->prependToPriorityList(
            before: DefinirTenantDoUsuario::class,
            prepend: GarantirUsuarioAtivo::class,
        );

        // Atrás de balanceador/CDN, o IP real do cliente vem no X-Forwarded-For.
        // Sem isso, todos compartilham o IP do proxy nos limites de tentativa.
        // TRUSTED_PROXIES: lista separada por vírgula (IPs/CIDRs) ou "*".
        $proxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A mensagem de 429 do framework é fixa em inglês e não passa pela tradução.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(
                    ['message' => 'Muitas tentativas. Aguarde um pouco e tente novamente.'],
                    429,
                    $e->getHeaders(),
                );
            }

            return null;
        });
    })->create();
