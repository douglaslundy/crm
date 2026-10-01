<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendenciasFiscaisTest extends TestCase
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

    public function test_lista_so_produtos_e_servicos_pendentes(): void
    {
        Produto::factory()->completo()->for($this->tenant)->create();
        $pendente = Produto::factory()->for($this->tenant)->create(['ncm' => null]);
        Servico::factory()->completo()->for($this->tenant)->create();
        $servicoPendente = Servico::factory()->for($this->tenant)->create(['codigo_lc116' => null]);

        $resposta = $this->spa()->actingAs($this->admin)->getJson('/api/app/produtos/pendencias-fiscais')->assertOk();

        $resposta->assertJsonCount(1, 'data.produtos')->assertJsonCount(1, 'data.servicos');
        $this->assertSame($pendente->id, $resposta->json('data.produtos.0.id'));
        $this->assertSame($servicoPendente->id, $resposta->json('data.servicos.0.id'));
    }

    public function test_produto_com_origem_zero_nao_aparece_como_pendente_por_causa_da_origem(): void
    {
        Produto::factory()->for($this->tenant)->create(['ncm' => '12345678', 'origem' => 0, 'tributacao_icms' => 'NORMAL']);

        $this->spa()->actingAs($this->admin)->getJson('/api/app/produtos/pendencias-fiscais')
            ->assertOk()->assertJsonCount(0, 'data.produtos');
    }

    public function test_produto_fonte_padrao_nao_revisado_aparece_como_pendente(): void
    {
        Produto::factory()->completo()->for($this->tenant)->create(['fiscal_fonte' => FonteFiscal::Padrao, 'fiscal_revisado_em' => null]);

        $this->spa()->actingAs($this->admin)->getJson('/api/app/produtos/pendencias-fiscais')
            ->assertOk()->assertJsonCount(1, 'data.produtos');
    }

    public function test_marcar_revisado_tira_da_lista(): void
    {
        $produto = Produto::factory()->completo()->for($this->tenant)->create(['fiscal_fonte' => FonteFiscal::Padrao, 'fiscal_revisado_em' => null]);

        $this->spa()->actingAs($this->admin)->postJson("/api/app/produtos/{$produto->id}/marcar-revisado")
            ->assertOk()->assertJsonPath('data.pendente_fiscal', false);

        $this->spa()->actingAs($this->admin)->getJson('/api/app/produtos/pendencias-fiscais')
            ->assertOk()->assertJsonCount(0, 'data.produtos');
    }
}
