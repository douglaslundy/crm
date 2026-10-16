<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Middleware\AutenticarChaveDeApi;
use App\Modules\Identity\Http\Middleware\GarantirUsuarioAtivo;
use App\Modules\Identity\Http\Middleware\LimitarRequisicoesDaApi;
use App\Modules\Platform\Http\Middleware\ExigirUsuarioDaEmpresa;
use App\Modules\Platform\Http\Middleware\SomentePlataforma;
use App\Modules\Platform\Http\Middleware\VerificarSituacaoDoTenant;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use App\Modules\Shared\Http\Middleware\GarantirIdempotencia;
use App\Modules\Shared\Http\TratadorDeErrosDaApi;
use App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        $middleware->group('empresa', [
            'auth:sanctum',
            GarantirUsuarioAtivo::class,
            DefinirTenantDoUsuario::class,
            ExigirUsuarioDaEmpresa::class,
            VerificarSituacaoDoTenant::class,
        ]);
        // /api/v1: a chave de API substitui a sessão. Autentica e define o tenant antes da resolução de bindings.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: AutenticarChaveDeApi::class,
        );
        $middleware->group('api-v1', [
            AutenticarChaveDeApi::class,
            LimitarRequisicoesDaApi::class,
            VerificarSituacaoDoTenant::class,
        ]);
        $middleware->alias(['idempotencia' => GarantirIdempotencia::class]);
        $middleware->group('plataforma', [
            'auth:sanctum',
            GarantirUsuarioAtivo::class,
            DefinirTenantDoUsuario::class,
            SomentePlataforma::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ErroDeNegocio::class);

        // O /api/v1 fala RFC 9457 (problem+json); registrado primeiro para valer antes dos formatos do app.
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/v1/*') ? app(TratadorDeErrosDaApi::class)->tratar($e, $request) : null);

        $exceptions->render(fn (ErroDeNegocio $e) => response()->json(
            ['message' => $e->getMessage(), 'codigo' => $e->codigo()] + $e->extras(),
            $e->status(),
            $e->headers(),
        ));

        // O framework converte firstOrFail() em 404 com texto em inglês ("No query results for model...").
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'Registro não encontrado.'], 404);
            }

            return null;
        });

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
