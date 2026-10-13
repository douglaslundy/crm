<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Events;

final class NegocioCriado
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $negocioId,
        public readonly string $clienteId,
        public readonly ?string $autorId,
    ) {}
}
