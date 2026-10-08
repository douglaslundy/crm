<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

final class ResolvedorDeDnsDoSistema implements ResolvedorDeDns
{
    public function resolver(string $host): array
    {
        $ips = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $tipo => $campo) {
            $registros = @dns_get_record($host, $tipo);
            foreach (is_array($registros) ? $registros : [] as $registro) {
                if (isset($registro[$campo]) && is_string($registro[$campo])) {
                    $ips[] = $registro[$campo];
                }
            }
        }
        if ($ips === []) {
            $ipv4 = @gethostbynamel($host);
            $ips = is_array($ipv4) ? $ipv4 : [];
        }

        return array_values(array_unique($ips));
    }
}
