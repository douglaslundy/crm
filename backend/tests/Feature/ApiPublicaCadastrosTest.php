<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class ApiPublicaCadastrosTest extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi();
    }

    public function test_cria_produto_pela_v1(): void
    {
        $this->comoApi()->postJson('/api/v1/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertCreated();
    }

    public function test_produto_de_outro_tenant_responde_404_na_v1(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheio = Produto::factory()->for($outro)->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->comoApi()->getJson("/api/v1/produtos/{$alheio->id}")->assertNotFound();
    }

    public function test_lista_clientes_pela_v1(): void
    {
        Cliente::factory()->for($this->tenant)->create();

        $this->comoApi()->getJson('/api/v1/clientes')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_autor_sem_papel_de_gestao_nao_cria_pela_v1(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        [, $texto] = $this->novaChave($leitura);

        $this->comoApi($texto)->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Ana'])
            ->assertUnauthorized()->assertJsonPath('codigo', 'API_CHAVE_INVALIDA');
    }

    public function test_plano_sem_o_modulo_api_nao_usa_a_v1(): void
    {
        $this->tenant->forceFill(['plano_id' => Plano::factory()->create()->id])->save();

        $this->comoApi()->getJson('/api/v1/clientes')->assertForbidden()->assertJsonPath('codigo', 'API_NAO_CONTRATADA');
    }
}
