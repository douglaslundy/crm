<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProdutosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Produtos->value => 2]))->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        // BelongsToTenant exige o contexto já setado para criar por factory fora de uma chamada HTTP.
        app(TenantContext::class)->set($this->tenant->id);
    }

    /** Cria um Produto de outro tenant, trocando o contexto só durante a criação. */
    private function produtoDeOutroTenant(array $atributos = []): Produto
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $produto = Produto::factory()->for($outro)->create($atributos);
        app(TenantContext::class)->set($this->tenant->id);

        return $produto;
    }

    public function test_cria_produto(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'SKU-1')
            ->assertJsonPath('data.fiscal_fonte', 'MANUAL');
    }

    public function test_origem_zero_e_gravada_como_zero_nunca_como_null(): void
    {
        $resposta = $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500, 'origem' => 0,
        ])->assertCreated();

        $this->assertSame(0, $resposta->json('data.origem'));
        $this->assertDatabaseHas('produtos', ['sku' => 'SKU-1', 'origem' => 0]);
    }

    public function test_ncm_malformado_vira_null_nunca_o_valor_cru(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500, 'ncm' => '123',
        ])->assertCreated()->assertJsonPath('data.ncm', null);
    }

    public function test_sku_duplicado_no_mesmo_tenant_e_recusado(): void
    {
        Produto::factory()->for($this->tenant)->create(['sku' => 'SKU-1']);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Outro', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    public function test_sku_igual_em_outro_tenant_e_permitido(): void
    {
        $this->produtoDeOutroTenant(['sku' => 'SKU-1']);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertCreated();
    }

    public function test_respeita_o_limite_do_plano(): void
    {
        Produto::factory()->for($this->tenant)->count(2)->create();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-3', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_DO_PLANO');
    }

    public function test_atualizar_marca_fonte_manual_e_revisado_agora(): void
    {
        $produto = Produto::factory()->for($this->tenant)->create(['fiscal_fonte' => 'PADRAO', 'ncm' => '12345678']);

        $this->spa()->actingAs($this->admin)->putJson("/api/app/produtos/{$produto->id}", [
            'sku' => $produto->sku, 'nome' => 'Novo nome', 'unidade' => 'UN', 'preco_centavos' => 999, 'ncm' => '87654321',
        ])->assertOk()
            ->assertJsonPath('data.nome', 'Novo nome')
            ->assertJsonPath('data.fiscal_fonte', 'MANUAL');

        $this->assertNotNull($produto->refresh()->fiscal_revisado_em);
    }

    public function test_vendedor_cria_produto_mas_leitura_nao(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($vendedor)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->spa()->actingAs($leitura)->postJson('/api/app/produtos', [
            'sku' => 'SKU-2', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertForbidden()->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_produto_de_outro_tenant_responde_404(): void
    {
        $alheio = $this->produtoDeOutroTenant();

        $this->spa()->actingAs($this->admin)->getJson("/api/app/produtos/{$alheio->id}")->assertNotFound();
    }
}
