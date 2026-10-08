<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\ContratoOpenApi;
use Tests\TestCase;

/** As respostas reais da v1 precisam caber nos schemas de `docs/api/openapi.yaml` (que proíbem chaves a mais). */
class ContratoV1Test extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi([Recurso::Servicos->value => 100]);
    }

    private function assertContrato(string $schema, mixed $dados): void
    {
        $this->assertSame([], ContratoOpenApi::validar($schema, $dados), "Resposta fora do contrato {$schema}");
    }

    public function test_cliente_respeita_o_contrato(): void
    {
        $criado = $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.444.777-35'])->assertCreated();
        $this->assertContrato('RespostaDeCliente', $criado->json());
        $id = (string) $criado->json('data.id');

        $this->assertContrato('RespostaDeCliente', $this->comoApi()->getJson("/api/v1/clientes/{$id}")->assertOk()->json());
        $this->assertContrato('ListaDeClientes', $this->comoApi()->getJson('/api/v1/clientes')->assertOk()->json());
    }

    public function test_produto_respeita_o_contrato(): void
    {
        $corpo = ['sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500];
        $criado = $this->comoApi()->postJson('/api/v1/produtos', $corpo)->assertCreated();
        $this->assertContrato('RespostaDeProduto', $criado->json());

        $completo = $this->comoApi()->postJson('/api/v1/produtos', array_merge($corpo, ['sku' => 'SKU-2', 'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL']))->assertCreated();
        $this->assertContrato('RespostaDeProduto', $completo->json());
        $this->assertContrato('ListaDeProdutos', $this->comoApi()->getJson('/api/v1/produtos')->assertOk()->json());
    }

    public function test_servico_respeita_o_contrato(): void
    {
        $criado = $this->comoApi()->postJson('/api/v1/servicos', ['nome' => 'Consultoria', 'preco_centavos' => 10000])->assertCreated();
        $this->assertContrato('RespostaDeServico', $criado->json());

        $completo = $this->comoApi()->postJson('/api/v1/servicos', [
            'nome' => 'Manutenção', 'preco_centavos' => 5000, 'codigo_lc116' => '14.01', 'c_trib_nac' => '140101', 'codigo_municipal' => '001', 'aliquota_iss' => 2.5, 'nbs' => '1.0101',
        ])->assertCreated();
        $this->assertContrato('RespostaDeServico', $completo->json());
        $this->assertContrato('ListaDeServicos', $this->comoApi()->getJson('/api/v1/servicos')->assertOk()->json());
    }

    public function test_empresa_e_consumo_respeitam_o_contrato(): void
    {
        Emitente::factory()->for($this->tenant)->create(['uf' => 'SP', 'regime_tributario' => 'SIMPLES', 'inscricao_estadual' => '111222333444', 'cnae' => '4751201']);

        $this->assertContrato('RespostaDeEmpresa', $this->comoApi()->getJson('/api/v1/empresa')->assertOk()->json());
        $this->assertContrato('RespostaDeConsumo', $this->comoApi()->getJson('/api/v1/consumo')->assertOk()->json());
    }

    public function test_erros_respeitam_o_contrato(): void
    {
        $invalido = $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Inválida', 'cpf_cnpj' => '12345678900'])->assertUnprocessable();
        $this->assertContrato('Problem', $invalido->json());
        $this->assertNotEmpty($invalido->json('errors'));

        $this->assertContrato('Problem', $this->comoApi()->getJson('/api/v1/clientes/00000000-0000-4000-8000-000000000000')->assertNotFound()->json());
        $this->assertContrato('Problem', $this->comoApi('test_invalida')->getJson('/api/v1/clientes')->assertUnauthorized()->json());
    }

    public function test_limite_do_plano_e_422_e_esta_documentado_assim(): void
    {
        $this->tenant->plano?->limites()->where('recurso', Recurso::Clientes->value)->update(['limite' => 1]);
        $this->tenant->plano?->unsetRelation('limites');
        $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Primeiro', 'cpf_cnpj' => '529.982.247-25'])->assertCreated();

        $recusado = $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Segundo', 'cpf_cnpj' => '111.444.777-35'])->assertStatus(422);
        $this->assertSame('LIMITE_DO_PLANO', $recusado->json('codigo'));
        $this->assertContrato('Problem', $recusado->json());

        $doc = ContratoOpenApi::carregar()['components']['responses'];
        $this->assertStringContainsString('LIMITE_DO_PLANO', $doc['Validacao']['description']);
        $this->assertStringNotContainsString('LIMITE_DO_PLANO', $doc['RegraDeNegocio']['description']);
    }
}
