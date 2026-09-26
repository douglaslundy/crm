<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\AssinaturaPendenteException;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Regras de acesso por situação da assinatura (spec F1 §4). */
final class VerificarSituacaoDoTenant
{
    /** Rotas que uma conta PENDENTE pode ler. O /me fica fora deste grupo. */
    public const LIBERADAS_EM_PENDENTE = ['app.assinatura'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $situacao = $usuario->tenant->situacao ?? SituacaoAssinatura::Pendente;

        if (! $request->isMethodSafe() && ! $situacao->permiteEscrita()) {
            throw AssinaturaSemEscritaException::para($situacao);
        }

        if ($situacao === SituacaoAssinatura::Pendente && ! $request->routeIs(...self::LIBERADAS_EM_PENDENTE)) {
            throw new AssinaturaPendenteException;
        }

        return $next($request);
    }
}
