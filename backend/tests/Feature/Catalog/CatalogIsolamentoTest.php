<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_produto_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Produto::factory()->create());
    }

    public function test_servico_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Servico::factory()->create());
    }

    public function test_categoria_fiscal_padrao_e_isolada_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => CategoriaFiscalPadrao::factory()->create());
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
