<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

/**
 * Assinatura do corpo enviado: `t=<unix>,v1=<hmac-sha256 hex de "<t>.<corpo>">`. O integrador recusa um `t` antigo
 * (replay) e compara em tempo constante; `verificar` é o mesmo algoritmo, usado nos testes e no guia.
 */
final class AssinadorDeWebhook
{
    public function cabecalho(string $segredo, int $t, string $corpo): string
    {
        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$corpo}", $segredo);
    }

    public function verificar(string $segredo, string $cabecalho, string $corpo, int $toleranciaSegundos = 300): bool
    {
        if (preg_match('/^t=(\d{1,12}),v1=([0-9a-f]{64})$/', $cabecalho, $partes) !== 1) {
            return false;
        }
        $t = (int) $partes[1];
        if (abs(time() - $t) > $toleranciaSegundos) {
            return false;
        }

        return hash_equals($this->cabecalho($segredo, $t, $corpo), $cabecalho);
    }
}
