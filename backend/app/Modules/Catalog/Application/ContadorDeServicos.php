<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class ContadorDeServicos implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::Servicos;
    }

    public function contar(Tenant $tenant): int
    {
        return Servico::withoutTenantScope()->where('tenant_id', $tenant->id)->count();
    }
}
