<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class NotasApiTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    /**
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    private function corpo(array $sobrescrever = []): array
    {
        return array_replace([
            'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0, 'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $sobrescrever);
    }

    private function criar(): string
    {
        return $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo())->assertCreated()->json('data.id');
    }

    public function test_cria_lista_e_mostra_rascunho(): void
    {
        $id = $this->criar();

        $this->spaComo($this->admin)->getJson('/api/app/notas')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'RASCUNHO');
        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}")->assertOk()
            ->assertJsonPath('data.total_centavos', 1000)->assertJsonPath('data.cliente.nome', 'Maria Teste')
            ->assertJsonCount(1, 'data.itens')->assertJsonCount(1, 'data.pagamentos');
    }

    public function test_filtra_por_status(): void
    {
        $id = $this->criar();
        Nota::query()->findOrFail($id)->forceFill(['status' => StatusNota::Autorizada])->save();
        $this->criar();

        $this->spaComo($this->admin)->getJson('/api/app/notas?status=AUTORIZADA')->assertOk()->assertJsonCount(1, 'data');
        $this->spaComo($this->admin)->getJson('/api/app/notas?status=INVALIDO')->assertStatus(422);
    }

    public function test_validacao_do_corpo(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['itens' => []]))->assertStatus(422)->assertJsonValidationErrors('itens');
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['pagamentos' => [['tpag' => '77', 'valor_centavos' => 1000]]]))->assertStatus(422);
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1,5']]]))->assertStatus(422);
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['cliente_id' => '00000000-0000-0000-0000-000000000000']))->assertStatus(422);
    }

    public function test_emitir_responde_202_e_autoriza_com_o_fake(): void
    {
        $id = $this->criar();

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202)->assertJsonStructure(['data' => ['id', 'status']]);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}/status")->assertOk()
            ->assertJsonPath('data.status', 'AUTORIZADA')->assertJsonPath('data.numero', 1);
    }

    public function test_bloqueio_fiscal_vira_422_com_codigo(): void
    {
        $this->produto->update(['ncm' => null]);
        $id = $this->criar();

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(422)
            ->assertJsonPath('codigo', 'FISCAL_PRODUTO_INCOMPLETO');
    }

    public function test_rejeicao_da_sefaz_fica_no_recurso(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('232', 'IE do destinatário não informada'));
        $id = $this->criar();
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}")->assertJsonPath('data.status', 'REJEITADA')
            ->assertJsonPath('data.cstat', '232')->assertJsonPath('data.motivo', 'IE do destinatário não informada');
    }

    public function test_rejeitada_pode_ser_editada_e_reemitida(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('232', 'x'));
        $id = $this->criar();
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir");

        $this->spaComo($this->admin)->putJson("/api/app/notas/{$id}", $this->corpo(['desconto_centavos' => 0]))->assertOk();
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);
        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}/status")->assertJsonPath('data.status', 'AUTORIZADA')->assertJsonPath('data.numero', 1);
    }

    public function test_autorizada_nao_e_editavel_nem_excluivel(): void
    {
        $id = $this->criar();
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir");

        $this->spaComo($this->admin)->putJson("/api/app/notas/{$id}", $this->corpo())->assertStatus(409)->assertJsonPath('codigo', 'NOTA_NAO_EDITAVEL');
        $this->spaComo($this->admin)->deleteJson("/api/app/notas/{$id}")->assertStatus(409);
    }

    public function test_exclui_rascunho(): void
    {
        $id = $this->criar();
        $this->spaComo($this->admin)->deleteJson("/api/app/notas/{$id}")->assertNoContent();
        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}")->assertNotFound();
    }

    public function test_baixa_o_xml_so_de_nota_autorizada_e_nunca_o_expoe_no_json(): void
    {
        $id = $this->criar();
        $this->spaComo($this->admin)->get("/api/app/notas/{$id}/xml", ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('codigo', 'NOTA_SEM_XML');

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir");
        $resp = $this->spaComo($this->admin)->get("/api/app/notas/{$id}/xml")->assertOk();
        $this->assertSame('<nfeProc>fake</nfeProc>', $resp->getContent());
        $this->assertStringContainsString('NFe-1.xml', (string) $resp->headers->get('Content-Disposition'));

        $json = $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}")->getContent();
        $this->assertStringNotContainsString('nfeProc', (string) $json);
    }

    public function test_papeis(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        $fiscal = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Fiscal]);
        $id = $this->criar();

        $this->spaComo($vendedor)->postJson('/api/app/notas', $this->corpo())->assertCreated();
        $this->spaComo($vendedor)->postJson("/api/app/notas/{$id}/emitir")->assertForbidden();
        $this->spaComo($leitura)->getJson('/api/app/notas')->assertOk();
        $this->spaComo($leitura)->postJson('/api/app/notas', $this->corpo())->assertForbidden();
        $this->spaComo($leitura)->putJson("/api/app/notas/{$id}", $this->corpo())->assertForbidden();
        $this->spaComo($leitura)->deleteJson("/api/app/notas/{$id}")->assertForbidden();
        $this->spaComo($fiscal)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);
    }

    public function test_isolamento_entre_tenants(): void
    {
        $id = $this->criar();
        $outro = Tenant::factory()->for(Plano::factory())->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spaComo($intruso)->getJson('/api/app/notas')->assertOk()->assertJsonCount(0, 'data');
        $this->spaComo($intruso)->getJson("/api/app/notas/{$id}")->assertNotFound();
        $this->spaComo($intruso)->getJson("/api/app/notas/{$id}/status")->assertNotFound();
        $this->spaComo($intruso)->putJson("/api/app/notas/{$id}", $this->corpo())->assertNotFound();
        $this->spaComo($intruso)->deleteJson("/api/app/notas/{$id}")->assertNotFound();
        $this->spaComo($intruso)->postJson("/api/app/notas/{$id}/emitir")->assertNotFound();
        $this->spaComo($intruso)->get("/api/app/notas/{$id}/xml", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_cliente_e_produto_de_outro_tenant_sao_rejeitados(): void
    {
        $outro = Tenant::factory()->for(Plano::factory())->create();
        app(TenantContext::class)->set($outro->id);
        $clienteAlheio = Cliente::factory()->create();
        $produtoAlheio = Produto::factory()->completo()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['cliente_id' => $clienteAlheio->id]))->assertStatus(422);
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['itens' => [['produto_id' => $produtoAlheio->id, 'quantidade' => '1']]]))->assertStatus(422);
    }

    public function test_sem_autenticacao_401(): void
    {
        $this->spa()->getJson('/api/app/notas')->assertUnauthorized();
    }
}
