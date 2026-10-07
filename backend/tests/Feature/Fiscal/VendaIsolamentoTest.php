<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class VendaIsolamentoTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    public function test_venda_e_isolada_por_tenant(): void
    {
        $this->montarCenarioNfse();
        $venda = Venda::factory()->create(['cliente_id' => $this->cliente->id]);
        $this->assertSame(1, Venda::query()->count());

        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);

        $this->assertSame(0, Venda::query()->count());
        $this->assertNull(Venda::query()->find($venda->id));
    }
}
