<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

/** Resolve um nome de host em endereços IP. Existe como contrato para o teste trocar o DNS. */
interface ResolvedorDeDns
{
    /** @return list<string> os IPs (v4 e v6) do host; vazio quando não resolve */
    public function resolver(string $host): array;
}
