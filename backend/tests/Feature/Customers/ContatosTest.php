<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Domain\Models\Contato;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContatosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);
        $this->cliente = Cliente::factory()->for($this->tenant)->create(['tipo' => 'PJ']);
    }

    public function test_cria_contato_do_cliente(): void
    {
        $this->spa()->actingAs($this->admin)->postJson("/api/app/clientes/{$this->cliente->id}/contatos", [
            'nome' => 'Ana', 'cargo' => 'Compras',
        ])->assertCreated()->assertJsonPath('data.nome', 'Ana');
    }

    public function test_cliente_show_traz_os_contatos(): void
    {
        Contato::factory()->for($this->cliente)->create(['nome' => 'Ana']);

        $this->spa()->actingAs($this->admin)->getJson("/api/app/clientes/{$this->cliente->id}")
            ->assertOk()->assertJsonPath('data.contatos.0.nome', 'Ana');
    }

    public function test_contato_de_outro_cliente_responde_404(): void
    {
        $outroCliente = Cliente::factory()->for($this->tenant)->create();
        $contato = Contato::factory()->for($outroCliente)->create();

        $this->spa()->actingAs($this->admin)
            ->putJson("/api/app/clientes/{$this->cliente->id}/contatos/{$contato->id}", ['nome' => 'X'])
            ->assertNotFound();
    }

    public function test_leitura_nao_cria_contato(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson("/api/app/clientes/{$this->cliente->id}/contatos", [
            'nome' => 'Ana',
        ])->assertForbidden();
    }
}
