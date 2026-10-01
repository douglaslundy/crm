<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Domain\Models\Contato;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomersIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Cliente::factory()->create());
    }

    public function test_contato_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Contato::factory()->for(Cliente::factory())->create());
    }

    public function test_dois_tenants_podem_ter_o_mesmo_cpf(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        Cliente::factory()->comCpfValido()->create(['cpf_cnpj' => '11144477735']);

        $contexto->set($tenantB->id);
        $clienteB = Cliente::factory()->comCpfValido()->create(['cpf_cnpj' => '11144477735']);

        $this->assertSame('11144477735', $clienteB->cpf_cnpj);
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
