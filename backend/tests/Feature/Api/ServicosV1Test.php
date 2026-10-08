<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class ServicosV1Test extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi([Recurso::Servicos->value => 100]);
    }

    public function test_lista_mostra_cria_e_atualiza(): void
    {
        $id = (string) $this->comoApi()->postJson('/api/v1/servicos', ['nome' => 'Consultoria', 'preco_centavos' => 10000])->assertCreated()->json('data.id');

        $this->comoApi()->getJson('/api/v1/servicos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Consultoria');
        $this->comoApi()->getJson("/api/v1/servicos/{$id}")->assertOk()->assertJsonPath('data.preco_centavos', 10000);
        $this->comoApi()->putJson("/api/v1/servicos/{$id}", ['nome' => 'Consultoria Sênior', 'preco_centavos' => 20000])
            ->assertOk()->assertJsonPath('data.nome', 'Consultoria Sênior');
    }

    public function test_codigo_lc116_malformado_e_recusado_como_no_app(): void
    {
        $this->comoApi()->postJson('/api/v1/servicos', ['nome' => 'Consultoria', 'preco_centavos' => 10000, 'codigo_lc116' => '1401'])
            ->assertUnprocessable()->assertJsonPath('codigo', 'VALIDACAO');
    }

    public function test_isolamento_servico_de_outro_tenant_nao_aparece_nem_e_acessivel(): void
    {
        $alheio = $this->emOutroTenant(fn ($outro) => Servico::factory()->for($outro)->create(['nome' => 'Alheio']));
        Servico::factory()->for($this->tenant)->create(['nome' => 'Meu']);

        $lista = $this->comoApi()->getJson('/api/v1/servicos')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('Alheio', $lista->getContent() ?: '');
        $this->assertProblema404($this->comoApi()->getJson("/api/v1/servicos/{$alheio->id}"));
        $this->assertProblema404($this->comoApi()->putJson("/api/v1/servicos/{$alheio->id}", ['nome' => 'Invadido', 'preco_centavos' => 1]));
        $this->assertSame('Alheio', Servico::withoutTenantScope()->find($alheio->id)?->nome);
    }

    private function assertProblema404(TestResponse $resposta): void
    {
        $resposta->assertNotFound()->assertJsonPath('codigo', 'RECURSO_NAO_ENCONTRADO');
        $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
    }
}
