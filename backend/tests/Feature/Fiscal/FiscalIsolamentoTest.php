<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_emitente_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Emitente::factory()->create());
    }

    public function test_serie_e_isolada_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => EmitenteSerie::factory()->for(Emitente::factory())->create());
    }

    private function verificaIsolamento(Closure $criar): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        /** @var Model $registro */
        $registro = $criar();
        $this->assertSame($tenantA->id, $registro->getAttribute('tenant_id'));

        $contexto->set($tenantB->id);
        $this->assertSame(0, $registro::query()->count());
    }
}
