<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure;

use App\Modules\Shared\Domain\Exceptions\ConsultaCepIndisponivelException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Conveniência de preenchimento, nunca validação obrigatória (mesmo padrão de `ConsultaCnpj`). */
final class ConsultaCep
{
    private const TIMEOUT_SEGUNDOS = 5;

    private const CACHE_SEGUNDOS = 86400;

    /** @return array{logradouro: string, bairro: string, cidade: string, uf: string, codigo_ibge: string}|null */
    public function buscar(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep) ?? '';
        $chave = 'viacep:'.$cep;
        /** @var array{logradouro: string, bairro: string, cidade: string, uf: string, codigo_ibge: string}|null $emCache */
        $emCache = Cache::get($chave);
        if ($emCache !== null) {
            return $emCache;
        }

        try {
            $resposta = Http::baseUrl((string) config('services.viacep.url'))
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->acceptJson()
                ->get("/ws/{$cep}/json/");
        } catch (ConnectionException) {
            throw new ConsultaCepIndisponivelException;
        }

        if (! $resposta->successful()) {
            throw new ConsultaCepIndisponivelException;
        }

        if ($resposta->json('erro') === true) {
            return null;
        }

        $dados = [
            'logradouro' => trim((string) $resposta->json('logradouro')),
            'bairro' => trim((string) $resposta->json('bairro')),
            'cidade' => trim((string) $resposta->json('localidade')),
            'uf' => trim((string) $resposta->json('uf')),
            'codigo_ibge' => trim((string) $resposta->json('ibge')),
        ];
        Cache::put($chave, $dados, self::CACHE_SEGUNDOS);

        return $dados;
    }
}
