<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConverterEmClienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_converte_lead_sem_nenhum_dado_fiscal(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($tenant->id);
        $lead = Cliente::factory()->for($tenant)->create(['estagio' => 'LEAD', 'cpf_cnpj' => null]);

        $this->spa()->actingAs($admin)->postJson("/api/app/clientes/{$lead->id}/converter-em-cliente")
            ->assertOk()->assertJsonPath('data.estagio', 'CLIENTE');
    }

    public function test_leitura_nao_converte(): void
    {
        $tenant = Tenant::factory()->create();
        $leitura = Usuario::factory()->for($tenant)->create(['papel' => Papel::Leitura]);
        app(TenantContext::class)->set($tenant->id);
        $lead = Cliente::factory()->for($tenant)->create(['estagio' => 'LEAD']);

        $this->spa()->actingAs($leitura)->postJson("/api/app/clientes/{$lead->id}/converter-em-cliente")
            ->assertForbidden();
    }

    public function test_cliente_de_outro_tenant_responde_404(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheio = Cliente::factory()->for($outro)->create(['estagio' => 'LEAD']);
        app(TenantContext::class)->set($tenant->id);

        $this->spa()->actingAs($admin)->postJson("/api/app/clientes/{$alheio->id}/converter-em-cliente")
            ->assertNotFound();
    }
}
