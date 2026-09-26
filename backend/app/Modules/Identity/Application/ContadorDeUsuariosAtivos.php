<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class ContadorDeUsuariosAtivos implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::Usuarios;
    }

    public function contar(Tenant $tenant): int
    {
        return Usuario::query()->where('tenant_id', $tenant->id)->where('ativo', true)->count();
    }
}
