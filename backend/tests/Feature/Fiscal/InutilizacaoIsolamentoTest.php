<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InutilizacaoIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_inutilizacao_e_isolada_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        $registro = Inutilizacao::factory()->create();
        $this->assertSame($tenantA->id, $registro->tenant_id);

        $contexto->set($tenantB->id);
        $this->assertSame(0, Inutilizacao::query()->count());
    }

    public function test_xml_da_resposta_nao_vai_para_o_json(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);
        $registro = Inutilizacao::factory()->create(['xml_resposta' => '<retInutNFe/>']);

        $this->assertArrayNotHasKey('xml_resposta', $registro->toArray());
    }
}
