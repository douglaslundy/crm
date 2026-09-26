<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Domain\Exceptions\TenantNaoDefinidoException;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\RegistroDeTeste;
use Tests\TestCase;

class IsolamentoTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('registros_de_teste', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('descricao');
            $table->timestamps();
        });
        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
    }

    private function contexto(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function test_cria_registro_no_tenant_do_contexto(): void
    {
        $this->contexto()->set($this->tenantA->id);

        $registro = RegistroDeTeste::create(['descricao' => 'a']);

        $this->assertSame($this->tenantA->id, $registro->tenant_id);
    }

    public function test_consulta_so_enxerga_o_tenant_do_contexto(): void
    {
        $this->contexto()->set($this->tenantA->id);
        RegistroDeTeste::create(['descricao' => 'de A']);
        $this->contexto()->set($this->tenantB->id);
        RegistroDeTeste::create(['descricao' => 'de B']);

        $this->contexto()->set($this->tenantA->id);

        $this->assertSame(['de A'], RegistroDeTeste::pluck('descricao')->all());
    }

    public function test_sem_contexto_consulta_nao_devolve_nada(): void
    {
        $this->contexto()->set($this->tenantA->id);
        RegistroDeTeste::create(['descricao' => 'de A']);

        $this->contexto()->clear();

        $this->assertSame(0, RegistroDeTeste::count());
    }

    public function test_sem_contexto_criar_lanca_excecao(): void
    {
        $this->expectException(TenantNaoDefinidoException::class);

        RegistroDeTeste::create(['descricao' => 'órfão']);
    }

    public function test_criar_com_tenant_diferente_do_contexto_lanca_excecao(): void
    {
        $this->contexto()->set($this->tenantA->id);

        $this->expectException(TenantNaoDefinidoException::class);

        RegistroDeTeste::create(['descricao' => 'intruso', 'tenant_id' => $this->tenantB->id]);
    }

    public function test_sem_scope_explicito_admin_enxerga_todos(): void
    {
        $this->contexto()->set($this->tenantA->id);
        RegistroDeTeste::create(['descricao' => 'de A']);
        $this->contexto()->set($this->tenantB->id);
        RegistroDeTeste::create(['descricao' => 'de B']);
        $this->contexto()->clear();

        $this->assertSame(2, RegistroDeTeste::withoutTenantScope()->count());
    }
}
