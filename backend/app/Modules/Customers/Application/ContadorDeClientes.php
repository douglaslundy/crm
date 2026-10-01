<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** Conta leads e clientes: um registro é um registro, independente do estágio. */
final class ContadorDeClientes implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::Clientes;
    }

    public function contar(Tenant $tenant): int
    {
        return Cliente::withoutTenantScope()->where('tenant_id', $tenant->id)->count();
    }
}
