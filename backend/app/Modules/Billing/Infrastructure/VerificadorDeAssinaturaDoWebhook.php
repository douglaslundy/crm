<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure;

use Carbon\CarbonImmutable;

/**
 * Confere o `x-signature` do webhook do Mercado Pago (`ts=<tempo>,v1=<hmac>`). O HMAC-SHA256 cobre o manifesto
 * `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`. Falha fechada: qualquer parte ausente, segredo vazio ou
 * `ts` fora da tolerância recusa.
 */
final class VerificadorDeAssinaturaDoWebhook
{
    public function valido(?string $xSignature, ?string $xRequestId, ?string $dataId, ?string $segredo, CarbonImmutable $agora): bool
    {
        if ($segredo === null || $segredo === '' || $xSignature === null || $xRequestId === null || $xRequestId === '' || $dataId === null || $dataId === '') {
            return false;
        }

        $partes = [];
        foreach (explode(',', $xSignature) as $trecho) {
            [$chave, $valor] = array_pad(explode('=', trim($trecho), 2), 2, '');
            $partes[$chave] = $valor;
        }
        $ts = $partes['ts'] ?? '';
        $v1 = $partes['v1'] ?? '';
        if (preg_match('/^\d{10}(\d{3})?$/', $ts) !== 1 || $v1 === '') {
            return false;
        }

        $segundos = strlen($ts) === 13 ? intdiv((int) $ts, 1000) : (int) $ts;
        if (abs($agora->timestamp - $segundos) > (int) config('billing.tolerancia_webhook_segundos')) {
            return false;
        }

        $manifesto = 'id:'.strtolower($dataId).';request-id:'.$xRequestId.';ts:'.$ts.';';

        return hash_equals(hash_hmac('sha256', $manifesto, $segredo), $v1);
    }
}
