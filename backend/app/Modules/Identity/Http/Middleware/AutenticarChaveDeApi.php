<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Exceptions\ApiNaoContratadaException;
use App\Modules\Identity\Domain\Exceptions\ChaveDeApiInvalidaException;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Exceptions\AssinaturaCanceladaException;
use App\Modules\Platform\Domain\Exceptions\AssinaturaPendenteException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica o /api/v1 pela chave (Authorization: Bearer). A chave age como o usuário que a criou:
 * ele precisa seguir ativo e gerenciando a empresa, senão a chave "morre" com ele.
 */
final class AutenticarChaveDeApi
{
    private const TAMANHO_DA_CHAVE = 45;

    public function __construct(
        private readonly GeradorDeChaveDeApi $gerador,
        private readonly TenantContext $contexto,
        private readonly EntitlementService $entitlement,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $texto = $request->bearerToken();
        if ($texto === null || $texto === '') {
            throw ChaveDeApiInvalidaException::ausente();
        }

        $chave = $this->localizar($texto);
        $autor = $chave === null ? null : Usuario::query()->find($chave->usuario_id);
        if ($chave === null || $autor === null || ! $this->autorPodeUsar($autor, $chave)) {
            throw ChaveDeApiInvalidaException::invalida();
        }

        Auth::setUser($autor);
        $this->contexto->set($chave->tenant_id);
        $request->attributes->set('chave_de_api', $chave);
        $request->attributes->set('ambiente_da_chave', $chave->ambiente);

        $tenant = $autor->tenant;
        if ($tenant === null || ! $this->entitlement->temModulo($tenant, Modulo::Api)) {
            throw new ApiNaoContratadaException;
        }
        if ($tenant->situacao === SituacaoAssinatura::Pendente) {
            throw new AssinaturaPendenteException;
        }
        if ($tenant->situacao === SituacaoAssinatura::Cancelada) {
            throw new AssinaturaCanceladaException;
        }

        $this->registrarUso($chave);

        return $next($request);
    }

    private function localizar(string $texto): ?ChaveDeApi
    {
        $formatoValido = strlen($texto) === self::TAMANHO_DA_CHAVE
            && (str_starts_with($texto, AmbienteDaChave::Live->prefixoDaChave()) || str_starts_with($texto, AmbienteDaChave::Test->prefixoDaChave()));
        if (! $formatoValido) {
            return null;
        }

        $chave = ChaveDeApi::withoutTenantScope()->where('hash', $this->gerador->hashDe($texto))->first();

        return $chave === null || $chave->estaRevogada() ? null : $chave;
    }

    private function autorPodeUsar(Usuario $autor, ChaveDeApi $chave): bool
    {
        return $autor->ativo
            && $autor->tenant_id === $chave->tenant_id
            && in_array($autor->papel, [Papel::Proprietario, Papel::Admin], true);
    }

    /** No máximo uma escrita por minuto por chave, numa única query (sem corrida entre requisições). */
    private function registrarUso(ChaveDeApi $chave): void
    {
        ChaveDeApi::query()->whereKey($chave->id)
            ->where(fn ($q) => $q->whereNull('ultimo_uso_em')->orWhere('ultimo_uso_em', '<', now()->subMinute()))
            ->update(['ultimo_uso_em' => now()]);
    }
}
