<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmModelosTest extends TestCase
{
    use RefreshDatabase;

    public function test_funil_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Funil::factory()->create());
    }

    public function test_etapa_e_isolada_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Etapa::factory()->create());
    }

    public function test_motivo_de_perda_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => MotivoDePerda::factory()->create());
    }

    public function test_negocio_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Negocio::factory()->create());
    }

    /** @param Closure(): Model $criar */
    private function verificaIsolamento(Closure $criar): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($a->id);
        $doA = $criar();
        $contexto->set($b->id);
        $criar();

        $this->assertNull($doA::query()->find($doA->getKey()));
        $contexto->set($a->id);
        $this->assertNotNull($doA::query()->find($doA->getKey()));
        $this->assertSame(1, $doA::query()->count());
    }
}
