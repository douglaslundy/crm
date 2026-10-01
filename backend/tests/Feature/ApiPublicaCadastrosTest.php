<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiPublicaCadastrosTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_produto_pela_v1(): void
    {
        $plano = Plano::factory()->comLimites([Recurso::Produtos->value => 10])->create();
        $tenant = Tenant::factory()->for($plano)->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->postJson('/api/v1/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertCreated();
    }

    public function test_produto_de_outro_tenant_responde_404_na_v1(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheio = Produto::factory()->for($outro)->create();
        app(TenantContext::class)->set($tenant->id);

        $this->spa()->actingAs($admin)->getJson("/api/v1/produtos/{$alheio->id}")->assertNotFound();
    }

    public function test_lista_clientes_pela_v1(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($tenant->id);
        Cliente::factory()->for($tenant)->create();

        $this->spa()->actingAs($admin)->getJson('/api/v1/clientes')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_leitura_nao_cria_pela_v1(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $leitura = Usuario::factory()->for($tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Ana'])
            ->assertForbidden();
    }
}
