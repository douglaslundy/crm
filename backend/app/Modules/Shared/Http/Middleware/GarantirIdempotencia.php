<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Domain\Exceptions\ErroDeIdempotenciaException;
use App\Modules\Shared\Domain\Models\Idempotencia;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency-Key nas rotas POST do /api/v1. Reserva a chave com um insert único antes de executar, grava a
 * resposta 2xx/4xx e a devolve a quem repetir a chamada; 5xx libera a chave para nova tentativa.
 * Uso: `idempotencia:obrigatoria` ou `idempotencia` (opcional). Roda fora de qualquer transação do handler.
 */
final class GarantirIdempotencia
{
    private const VALIDADE_EM_HORAS = 24;

    /** Reserva sem resposta há mais que isto é de um processo que morreu: pode ser descartada. */
    private const RESERVA_ORFA_EM_SEGUNDOS = 120;

    public function __construct(private readonly TenantContext $contexto) {}

    public function handle(Request $request, Closure $next, string $modo = 'opcional'): Response
    {
        $chave = $request->header('Idempotency-Key');
        if ($chave === null) {
            if ($modo === 'obrigatoria') {
                throw ErroDeIdempotenciaException::ausente();
            }

            return $next($request);
        }
        if (preg_match('/^[\x21-\x7E]{1,255}$/', $chave) !== 1) {
            throw ErroDeIdempotenciaException::invalida();
        }

        $rota = $request->method().' '.($request->route()?->uri() ?? $request->path());
        $hash = $this->hashDoCorpo($request);

        $reserva = $this->reservar($chave, $rota, $hash);
        if ($reserva instanceof Response) {
            return $reserva;
        }

        try {
            $resposta = $next($request);
        } catch (Throwable $e) {
            $this->liberar($reserva);
            throw $e;
        }

        if ($resposta->getStatusCode() >= 500) {
            $this->liberar($reserva);

            return $resposta;
        }

        Idempotencia::query()->whereKey($reserva)->update([
            'status_http' => $resposta->getStatusCode(),
            'corpo' => (string) $resposta->getContent(),
            'tipo_do_conteudo' => $resposta->headers->get('Content-Type'),
            'nota_id' => $request->attributes->get('idempotencia_nota_id'),
            'updated_at' => now(),
        ]);

        return $resposta;
    }

    /** Devolve o id da reserva feita, ou a resposta a enviar (replay) ou lança o erro de conflito. */
    private function reservar(string $chave, string $rota, string $hash): string|Response
    {
        $tenantId = $this->contexto->require();

        for ($tentativa = 0; $tentativa < 3; $tentativa++) {
            $id = (string) Str::uuid7();
            $criou = DB::table('idempotencias')->insertOrIgnore([
                'id' => $id, 'tenant_id' => $tenantId, 'chave' => $chave, 'rota' => $rota, 'hash_do_corpo' => $hash,
                'expira_em' => now()->addHours(self::VALIDADE_EM_HORAS), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($criou === 1) {
                return $id;
            }

            $existente = Idempotencia::query()->where('rota', $rota)->where('chave', $chave)->first();
            if ($existente === null) {
                continue;
            }
            if ($this->descartavel($existente)) {
                Idempotencia::query()->whereKey($existente->id)->delete();

                continue;
            }
            if ($existente->hash_do_corpo !== $hash) {
                throw ErroDeIdempotenciaException::corpoDiferente();
            }
            if ($existente->status_http === null) {
                throw ErroDeIdempotenciaException::emAndamento();
            }

            return response((string) $existente->corpo, $existente->status_http, [
                'Content-Type' => $existente->tipo_do_conteudo ?? 'application/json',
                'Idempotent-Replayed' => 'true',
            ]);
        }

        throw ErroDeIdempotenciaException::emAndamento();
    }

    private function descartavel(Idempotencia $registro): bool
    {
        return $registro->expira_em->isPast()
            || ($registro->status_http === null && $registro->created_at !== null && $registro->created_at->lt(now()->subSeconds(self::RESERVA_ORFA_EM_SEGUNDOS)));
    }

    private function liberar(string $id): void
    {
        Idempotencia::query()->whereKey($id)->delete();
    }

    /** SHA-256 do caminho concreto e do corpo com as chaves ordenadas (a ordem dos campos não importa). */
    private function hashDoCorpo(Request $request): string
    {
        $corpo = $request->isJson() ? $request->json()->all() : $request->post();

        return hash('sha256', $request->path().'|'.json_encode($this->ordenar($corpo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function ordenar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        $ordenado = array_map(fn (mixed $item): mixed => $this->ordenar($item), $valor);
        if (! array_is_list($ordenado)) {
            ksort($ordenado);
        }

        return $ordenado;
    }
}
