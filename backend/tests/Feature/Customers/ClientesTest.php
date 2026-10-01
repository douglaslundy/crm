<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Clientes->value => 2]))->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    public function test_cria_lead_sem_nenhum_dado_fiscal(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria',
        ])->assertCreated()
            ->assertJsonPath('data.nome', 'Maria')
            ->assertJsonPath('data.estagio', 'LEAD')
            ->assertJsonPath('data.cpf_cnpj', null);
    }

    public function test_cria_cliente_pf_com_cpf_valido(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.444.777-35',
        ])->assertCreated()->assertJsonPath('data.cpf_cnpj', '11144477735');
    }

    public function test_cria_cliente_pj_com_cnpj_valido(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PJ', 'nome' => 'Padaria', 'cpf_cnpj' => '11.222.333/0001-81',
        ])->assertCreated()->assertJsonPath('data.cpf_cnpj', '11222333000181');
    }

    public function test_cpf_invalido_e_recusado(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.111.111-11',
        ])->assertStatus(422)->assertJsonValidationErrors('cpf_cnpj');
    }

    public function test_cpf_duplicado_no_mesmo_tenant_e_recusado(): void
    {
        Cliente::factory()->comCpfValido()->for($this->tenant)->create(['cpf_cnpj' => '11144477735']);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Outra Maria', 'cpf_cnpj' => '111.444.777-35',
        ])->assertStatus(422)->assertJsonValidationErrors('cpf_cnpj');
    }

    public function test_cpf_igual_em_outro_tenant_e_permitido(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        Cliente::factory()->comCpfValido()->for($outro)->create(['cpf_cnpj' => '11144477735']);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.444.777-35',
        ])->assertCreated();
    }

    public function test_respeita_o_limite_do_plano(): void
    {
        Cliente::factory()->for($this->tenant)->count(2)->create();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria',
        ])->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_DO_PLANO');
    }

    public function test_leitura_nao_cria_cliente(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria',
        ])->assertForbidden()->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_cliente_de_outro_tenant_responde_404(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheio = Cliente::factory()->for($outro)->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spa()->actingAs($this->admin)->getJson("/api/app/clientes/{$alheio->id}")->assertNotFound();
    }
}
