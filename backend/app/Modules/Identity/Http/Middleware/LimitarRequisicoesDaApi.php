<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\Exceptions\ApiNaoContratadaException;
use App\Modules\Identity\Domain\Exceptions\LimiteDaApiExcedidoException;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Limite do plano por chave, em janela de 1 minuto. Roda depois de AutenticarChaveDeApi. */
final class LimitarRequisicoesDaApi
{
    private const JANELA_EM_SEGUNDOS = 60;

    public function __construct(private readonly RateLimiter $limiter, private readonly EntitlementService $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var ChaveDeApi $chave */
        $chave = $request->attributes->get('chave_de_api');
        $limite = $this->entitlement->limite(Tenant::query()->findOrFail($chave->tenant_id), Recurso::ApiRequisicoesMin);

        // Sem linha de limite, o plano não oferece a API (fail-closed).
        if ($limite === 0) {
            throw new ApiNaoContratadaException;
        }
        if ($limite < 0) {
            return $next($request);
        }

        $id = 'api-v1:'.$chave->id;
        if ($this->limiter->tooManyAttempts($id, $limite)) {
            $segundos = max(1, $this->limiter->availableIn($id));
            throw new LimiteDaApiExcedidoException($limite, $segundos, now()->getTimestamp() + $segundos);
        }

        // hit() incrementa de forma atômica: requisições paralelas não passam juntas do limite.
        if ($this->limiter->hit($id, self::JANELA_EM_SEGUNDOS) > $limite) {
            $segundos = max(1, $this->limiter->availableIn($id));
            throw new LimiteDaApiExcedidoException($limite, $segundos, now()->getTimestamp() + $segundos);
        }

        $response = $next($request);
        $response->headers->add([
            'X-RateLimit-Limit' => (string) $limite,
            'X-RateLimit-Remaining' => (string) max(0, $limite - $this->limiter->attempts($id)),
            'X-RateLimit-Reset' => (string) (now()->getTimestamp() + max(1, $this->limiter->availableIn($id))),
        ]);

        return $response;
    }
}
