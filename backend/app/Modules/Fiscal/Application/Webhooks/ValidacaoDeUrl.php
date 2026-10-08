<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

/** URL já validada: o envio se conecta a um dos `ips` (nunca resolve o nome de novo). */
final readonly class ValidacaoDeUrl
{
    /** @param list<string> $ips */
    public function __construct(
        public string $url,
        public string $host,
        public int $porta,
        public array $ips,
    ) {}
}
