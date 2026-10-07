<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class RascunhoDeNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    public function test_cria_rascunho_de_nfse_com_snapshot_do_servico_e_sem_pagamentos(): void
    {
        $this->montarCenarioNfse();

        $r = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse())->assertCreated();

        $r->assertJsonPath('data.modelo', 'DPS')
            ->assertJsonPath('data.status', 'RASCUNHO')
            ->assertJsonPath('data.total_centavos', 15000)
            ->assertJsonPath('data.itens.0.produto_id', null)
            ->assertJsonPath('data.itens.0.servico_id', $this->servico->id)
            ->assertJsonPath('data.itens.0.c_trib_nac', '140101')
            ->assertJsonPath('data.itens.0.aliquota_iss', '5.00');
        $this->assertSame([], $r->json('data.pagamentos'));
    }

    public function test_varios_itens_somam_o_subtotal_e_o_desconto_entra_no_total(): void
    {
        $this->montarCenarioNfse();
        $outro = Servico::factory()->completo()->create(['nome' => 'Alinhamento', 'preco_centavos' => 8000]);

        $r = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse([
            'desconto_centavos' => 1000,
            'itens' => [
                ['servico_id' => $this->servico->id, 'quantidade' => '2', 'valor_unitario_centavos' => null],
                ['servico_id' => $outro->id, 'quantidade' => '1', 'valor_unitario_centavos' => 9000],
            ],
        ]))->assertCreated();

        $r->assertJsonPath('data.subtotal_centavos', 39000)->assertJsonPath('data.total_centavos', 38000);
    }

    public function test_tenant_sem_modulo_nfse_nao_cria_rascunho(): void
    {
        $this->montarCenarioNfse();
        $this->removerModuloNfse();

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse())
            ->assertStatus(403)->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_nota_de_servico_recusa_produto_pagamento_e_servico_de_outro_tenant(): void
    {
        $this->montarCenarioNfse();
        $produto = Produto::factory()->completo()->create();
        $servicoDeOutro = $this->servicoDeOutroTenant();

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse(['itens' => [['produto_id' => $produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['itens.0.produto_id', 'itens.0.servico_id']);
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse(['pagamentos' => [['tpag' => '01', 'valor_centavos' => 15000]]]))
            ->assertStatus(422)->assertJsonValidationErrors('pagamentos');
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse(['itens' => [['servico_id' => $servicoDeOutro, 'quantidade' => '1', 'valor_unitario_centavos' => null]]]))
            ->assertStatus(422)->assertJsonValidationErrors('itens.0.servico_id');
    }

    public function test_nfe_recusa_item_de_servico(): void
    {
        $this->montarCenarioNfse(Modulo::FiscalNfe);

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse(['modelo' => 'NFE', 'pagamentos' => [['tpag' => '01', 'valor_centavos' => 15000]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['itens.0.produto_id', 'itens.0.servico_id']);
    }

    public function test_edita_o_rascunho_de_nfse_e_recusa_trocar_o_modelo(): void
    {
        $this->montarCenarioNfse(Modulo::FiscalNfe);
        $nota = $this->rascunhoDeNfse();

        $this->spaComo($this->admin)->putJson("/api/app/notas/{$nota->id}", $this->corpoNfse(['desconto_centavos' => 500]))
            ->assertOk()->assertJsonPath('data.total_centavos', 14500);

        $this->spaComo($this->admin)->putJson("/api/app/notas/{$nota->id}", $this->corpoNfse(['modelo' => 'NFE', 'pagamentos' => [['tpag' => '01', 'valor_centavos' => 15000]], 'itens' => [['produto_id' => Produto::factory()->completo()->create()->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]]]))
            ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_MODELO_IMUTAVEL');
        $this->assertSame(ModeloDocumento::Dps, $nota->refresh()->modelo);
    }

    public function test_lista_filtra_por_modelo_dps(): void
    {
        $this->montarCenarioNfse();
        $this->rascunhoDeNfse();

        $this->spaComo($this->admin)->getJson('/api/app/notas?modelo=DPS')->assertOk()->assertJsonCount(1, 'data');
        $this->spaComo($this->admin)->getJson('/api/app/notas?modelo=NFE')->assertOk()->assertJsonCount(0, 'data');
    }

    private function servicoDeOutroTenant(): string
    {
        $anterior = $this->tenant->id;
        $outro = Tenant::factory()->for(Plano::factory())->create();
        app(TenantContext::class)->set($outro->id);
        $id = Servico::factory()->completo()->create()->id;
        app(TenantContext::class)->set($anterior);

        return $id;
    }
}
