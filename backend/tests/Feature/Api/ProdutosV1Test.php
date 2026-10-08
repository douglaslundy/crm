<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Catalog\Domain\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class ProdutosV1Test extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi();
    }

    /** @return array<string, mixed> */
    private function corpo(array $extra = []): array
    {
        return $extra + ['sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500];
    }

    public function test_lista_mostra_cria_e_atualiza(): void
    {
        $id = (string) $this->comoApi()->postJson('/api/v1/produtos', $this->corpo())->assertCreated()->json('data.id');

        $this->comoApi()->getJson('/api/v1/produtos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'SKU-1');
        $this->comoApi()->getJson("/api/v1/produtos/{$id}")->assertOk()->assertJsonPath('data.nome', 'Parafuso');
        $this->comoApi()->putJson("/api/v1/produtos/{$id}", $this->corpo(['nome' => 'Parafuso M8', 'ncm' => '73181500']))
            ->assertOk()->assertJsonPath('data.nome', 'Parafuso M8')->assertJsonPath('data.ncm', '73181500');
    }

    public function test_dado_fiscal_ausente_fica_pendente_e_nunca_ganha_padrao(): void
    {
        $resposta = $this->comoApi()->postJson('/api/v1/produtos', $this->corpo())->assertCreated();

        $resposta->assertJsonPath('data.origem', null)->assertJsonPath('data.ncm', null)->assertJsonPath('data.pendente_fiscal', true);
        $this->assertDatabaseHas('produtos', ['sku' => 'SKU-1', 'origem' => null, 'ncm' => null]);
    }

    public function test_origem_zero_e_aceita_e_gravada_como_zero(): void
    {
        $resposta = $this->comoApi()->postJson('/api/v1/produtos', $this->corpo(['origem' => 0]))->assertCreated();

        $this->assertSame(0, $resposta->json('data.origem'));
        $this->assertDatabaseHas('produtos', ['sku' => 'SKU-1', 'origem' => 0]);
    }

    public function test_sku_duplicado_e_recusado_no_formato_da_api(): void
    {
        $this->comoApi()->postJson('/api/v1/produtos', $this->corpo())->assertCreated();

        $this->comoApi()->postJson('/api/v1/produtos', $this->corpo())->assertUnprocessable()->assertJsonPath('codigo', 'VALIDACAO');
    }

    public function test_isolamento_produto_de_outro_tenant_nao_aparece_nem_e_acessivel(): void
    {
        $alheio = $this->emOutroTenant(fn ($outro) => Produto::factory()->for($outro)->create(['nome' => 'Alheio']));
        Produto::factory()->for($this->tenant)->create(['nome' => 'Meu']);

        $lista = $this->comoApi()->getJson('/api/v1/produtos')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('Alheio', $lista->getContent() ?: '');
        $this->assertProblema404($this->comoApi()->getJson("/api/v1/produtos/{$alheio->id}"));
        $this->assertProblema404($this->comoApi()->putJson("/api/v1/produtos/{$alheio->id}", $this->corpo(['sku' => $alheio->sku, 'nome' => 'Invadido'])));
        $this->assertSame('Alheio', Produto::withoutTenantScope()->find($alheio->id)?->nome);
    }

    private function assertProblema404(TestResponse $resposta): void
    {
        $resposta->assertNotFound()->assertJsonPath('codigo', 'RECURSO_NAO_ENCONTRADO');
        $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
    }
}
