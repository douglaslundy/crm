<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\CriarVendaMista;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Fixtures\CenarioDeVenda;
use Tests\TestCase;

class VendasApiTest extends TestCase
{
    use CenarioDeVenda;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioVenda();
        Bus::fake();
    }

    private function venda(): Venda
    {
        return app(CriarVendaMista::class)->executar($this->corpoVenda(), $this->admin);
    }

    public function test_cria_a_venda_pela_api(): void
    {
        $r = $this->spaComo($this->admin)->postJson('/api/app/vendas', $this->corpoVenda([
            'desconto_centavos' => 1600, 'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 900]],
        ]))->assertCreated();

        $r->assertJsonPath('data.total_centavos', 14400)->assertJsonPath('data.desconto_centavos', 1600)
            ->assertJsonPath('data.cliente.id', $this->cliente->id)->assertJsonCount(2, 'data.notas');
        $this->assertSame(['DPS', 'NFE'], collect($r->json('data.notas'))->pluck('modelo')->sort()->values()->all());
    }

    public function test_validacao_da_venda(): void
    {
        $api = $this->spaComo($this->admin);

        $api->postJson('/api/app/vendas', $this->corpoVenda(['itens_servicos' => []]))->assertStatus(422)->assertJsonValidationErrors('itens_servicos');
        $api->postJson('/api/app/vendas', $this->corpoVenda(['pagamentos' => []]))->assertStatus(422)->assertJsonValidationErrors('pagamentos');
        $api->postJson('/api/app/vendas', $this->corpoVenda(['cliente_id' => null]))->assertStatus(422)->assertJsonValidationErrors('cliente_id');
        $api->postJson('/api/app/vendas', $this->corpoVenda(['desconto_centavos' => -1]))->assertStatus(422)->assertJsonValidationErrors('desconto_centavos');
        $api->postJson('/api/app/vendas', $this->corpoVenda(['desconto_centavos' => 16000]))->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_DESCONTO_INVALIDO');
        $this->assertSame(0, Venda::query()->count());
    }

    public function test_mostra_atualiza_e_exclui(): void
    {
        $venda = $this->venda();
        $api = $this->spaComo($this->admin);

        $api->getJson("/api/app/vendas/{$venda->id}")->assertOk()->assertJsonCount(2, 'data.notas');
        $api->putJson("/api/app/vendas/{$venda->id}", $this->corpoVenda([
            'desconto_centavos' => 1600, 'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 900]],
        ]))->assertOk()->assertJsonPath('data.total_centavos', 14400);
        $api->deleteJson("/api/app/vendas/{$venda->id}")->assertNoContent();

        $this->assertSame(0, Venda::query()->count());
        $this->assertSame(0, Nota::query()->count());
    }

    public function test_emitir_devolve_202_com_o_status_de_cada_nota(): void
    {
        $venda = $this->venda();

        $r = $this->spaComo($this->admin)->postJson("/api/app/vendas/{$venda->id}/emitir")->assertStatus(202);

        $this->assertSame(['PROCESSANDO', 'PROCESSANDO'], array_column($r->json('data.notas'), 'status'));
    }

    public function test_emitir_com_bloqueio_devolve_409_e_com_confirmacao_202(): void
    {
        $venda = $this->venda();
        $this->produto->forceFill(['ncm' => null])->save();
        $api = $this->spaComo($this->admin);

        $api->postJson("/api/app/vendas/{$venda->id}/emitir")->assertStatus(409)
            ->assertJsonPath('codigo', 'FISCAL_VENDA_NOTA_BLOQUEADA')->assertJsonCount(2, 'notas');
        $r = $api->postJson("/api/app/vendas/{$venda->id}/emitir", ['emitir_apenas_validas' => true])->assertStatus(202);

        $this->assertSame(['PROCESSANDO', 'RASCUNHO'], array_column(collect($r->json('data.notas'))->sortBy('modelo')->values()->all(), 'status'));
    }

    public function test_nota_da_venda_nao_se_edita_nem_se_exclui_pelas_rotas_de_nota(): void
    {
        $nota = $this->venda()->notas()->firstOrFail();
        $api = $this->spaComo($this->admin);

        $api->deleteJson("/api/app/notas/{$nota->id}")->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_NOTA_DA_VENDA');
        $api->getJson("/api/app/notas/{$nota->id}")->assertOk()->assertJsonPath('data.venda_id', $nota->venda_id);
    }

    public function test_leitura_nao_cria_nem_emite(): void
    {
        $venda = $this->venda();
        $leitor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        $api = $this->spaComo($leitor);

        $api->postJson('/api/app/vendas', $this->corpoVenda())->assertForbidden();
        $api->postJson("/api/app/vendas/{$venda->id}/emitir")->assertForbidden();
    }

    public function test_venda_de_outro_tenant_da_404_em_todos_os_verbos(): void
    {
        $venda = $this->venda();
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::FiscalNfe, Modulo::FiscalNfse))->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($outro->id);
        $api = $this->spaComo($intruso);

        $api->getJson("/api/app/vendas/{$venda->id}")->assertNotFound();
        $api->putJson("/api/app/vendas/{$venda->id}", $this->corpoVenda())->assertNotFound();
        $api->deleteJson("/api/app/vendas/{$venda->id}")->assertNotFound();
        $api->postJson("/api/app/vendas/{$venda->id}/emitir")->assertNotFound();
        $this->assertSame(1, Venda::withoutTenantScope()->count());
    }
}
