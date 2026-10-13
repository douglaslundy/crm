<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;

trait ComCrm
{
    private Tenant $tenant;

    private Usuario $admin;

    protected function prepararCrm(Papel $papel = Papel::Admin): void
    {
        $plano = Plano::factory()->comModulos(Modulo::Crm)->comLimites([Recurso::Clientes->value => Plano::ILIMITADO]);
        $this->tenant = Tenant::factory()->for($plano)->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    protected function usuarioDoPapel(Papel $papel, ?Tenant $tenant = null): Usuario
    {
        return Usuario::factory()->for($tenant ?? $this->tenant)->create(['papel' => $papel]);
    }
}
