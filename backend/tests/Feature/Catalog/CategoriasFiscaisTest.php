<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriasFiscaisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    public function test_cria_categoria_fiscal_padrao(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/categorias-fiscais-padrao', [
            'categoria' => 'Ferragens', 'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
        ])->assertCreated()->assertJsonPath('data.categoria', 'Ferragens');
    }

    public function test_aplicar_categoria_preenche_so_os_campos_vazios(): void
    {
        $categoria = CategoriaFiscalPadrao::factory()->for($this->tenant)->create([
            'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
        ]);
        $produto = Produto::factory()->for($this->tenant)->create([
            'ncm' => '12345678', 'origem' => null, 'tributacao_icms' => null, 'fiscal_fonte' => FonteFiscal::Manual,
        ]);

        $resposta = $this->spa()->actingAs($this->admin)
            ->postJson("/api/app/produtos/{$produto->id}/aplicar-categoria/{$categoria->id}")
            ->assertOk();

        // O NCM já preenchido não é sobrescrito.
        $this->assertSame('12345678', $resposta->json('data.ncm'));
        $this->assertSame(0, $resposta->json('data.origem'));
        $this->assertSame('NORMAL', $resposta->json('data.tributacao_icms'));
        $this->assertSame('PADRAO', $resposta->json('data.fiscal_fonte'));
        $this->assertNull($resposta->json('data.fiscal_revisado_em'));
    }

    public function test_aplicar_categoria_em_produto_ja_completo_nao_muda_a_fonte(): void
    {
        $categoria = CategoriaFiscalPadrao::factory()->for($this->tenant)->create();
        $produto = Produto::factory()->completo()->for($this->tenant)->create([
            'fiscal_fonte' => FonteFiscal::Manual, 'fiscal_revisado_em' => now(),
        ]);

        $resposta = $this->spa()->actingAs($this->admin)
            ->postJson("/api/app/produtos/{$produto->id}/aplicar-categoria/{$categoria->id}")
            ->assertOk();

        $this->assertSame('MANUAL', $resposta->json('data.fiscal_fonte'));
        $this->assertNotNull($resposta->json('data.fiscal_revisado_em'));
    }

    public function test_leitura_nao_cria_categoria(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson('/api/app/categorias-fiscais-padrao', [
            'categoria' => 'Ferragens',
        ])->assertForbidden();
    }
}
