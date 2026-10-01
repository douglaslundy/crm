<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class ContadorDeProdutos implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::Produtos;
    }

    public function contar(Tenant $tenant): int
    {
        return Produto::withoutTenantScope()->where('tenant_id', $tenant->id)->count();
    }
}
