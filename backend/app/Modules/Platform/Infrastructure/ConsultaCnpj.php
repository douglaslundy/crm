<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Platform\Domain\Exceptions\ConsultaCnpjIndisponivelException;
use App\Modules\Shared\Domain\Cnpj;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Conveniência de preenchimento, nunca validação obrigatória: a BrasilAPI é
 * comunitária e sem SLA. Só respostas encontradas vão para o cache.
 */
final class ConsultaCnpj
{
    private const TIMEOUT_SEGUNDOS = 5;

    private const CACHE_SEGUNDOS = 86400;

    /** @return array{razao_social: string, nome_fantasia: ?string}|null */
    public function buscar(Cnpj $cnpj): ?array
    {
        $chave = 'brasilapi:cnpj:'.$cnpj->valor;
        /** @var array{razao_social: string, nome_fantasia: ?string}|null $emCache */
        $emCache = Cache::get($chave);
        if ($emCache !== null) {
            return $emCache;
        }

        try {
            $resposta = Http::baseUrl((string) config('services.brasilapi.url'))
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->acceptJson()
                ->get('/api/cnpj/v1/'.$cnpj->valor);
        } catch (ConnectionException) {
            throw new ConsultaCnpjIndisponivelException;
        }

        if ($resposta->status() === 404) {
            return null;
        }
        if (! $resposta->successful()) {
            throw new ConsultaCnpjIndisponivelException;
        }

        $fantasia = trim((string) $resposta->json('nome_fantasia'));
        $dados = [
            'razao_social' => trim((string) $resposta->json('razao_social')),
            'nome_fantasia' => $fantasia === '' ? null : $fantasia,
        ];
        Cache::put($chave, $dados, self::CACHE_SEGUNDOS);

        return $dados;
    }
}
