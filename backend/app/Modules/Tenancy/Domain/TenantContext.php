<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

use App\Modules\Tenancy\Domain\Exceptions\TenantNaoDefinidoException;

/** Tenant da operação atual. Registrado como scoped: zera a cada request/job. */
final class TenantContext
{
    private ?string $tenantId = null;

    public function set(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function require(): string
    {
        return $this->tenantId ?? throw TenantNaoDefinidoException::semContexto();
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }
}
