<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class ClientesV1Test extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi();
    }

    public function test_lista_mostra_cria_e_atualiza(): void
    {
        $criado = $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.444.777-35'])
            ->assertCreated()->assertJsonPath('data.cpf_cnpj', '11144477735');
        $id = (string) $criado->json('data.id');

        $this->comoApi()->getJson('/api/v1/clientes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->comoApi()->getJson("/api/v1/clientes/{$id}")->assertOk()->assertJsonPath('data.nome', 'Maria');
        $this->comoApi()->putJson("/api/v1/clientes/{$id}", ['tipo' => 'PF', 'nome' => 'Maria Souza', 'cpf_cnpj' => '11144477735'])
            ->assertOk()->assertJsonPath('data.nome', 'Maria Souza');
    }

    public function test_as_regras_do_app_valem_igual(): void
    {
        $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Ana', 'cpf_cnpj' => '11144477735'])->assertCreated();

        $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Outra', 'cpf_cnpj' => '11144477735'])
            ->assertStatus(422)->assertJsonPath('type', config('api.erros_base_url').'/validacao');
        $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Inválida', 'cpf_cnpj' => '12345678900'])->assertUnprocessable();
    }

    public function test_nao_ha_exclusao_pela_api(): void
    {
        $cliente = Cliente::factory()->for($this->tenant)->create();

        $this->comoApi()->deleteJson("/api/v1/clientes/{$cliente->id}")->assertStatus(405)->assertJsonPath('codigo', 'METODO_NAO_PERMITIDO');
    }

    public function test_isolamento_cliente_de_outro_tenant_nao_aparece_nem_e_acessivel(): void
    {
        $alheio = $this->emOutroTenant(fn ($outro) => Cliente::factory()->for($outro)->create(['nome' => 'Alheio']));
        Cliente::factory()->for($this->tenant)->create(['nome' => 'Meu']);

        $lista = $this->comoApi()->getJson('/api/v1/clientes')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('Alheio', $lista->getContent() ?: '');
        $this->assertProblema404($this->comoApi()->getJson("/api/v1/clientes/{$alheio->id}"));
        $this->assertProblema404($this->comoApi()->putJson("/api/v1/clientes/{$alheio->id}", ['tipo' => 'PF', 'nome' => 'Invadido']));
        $this->assertSame('Alheio', Cliente::withoutTenantScope()->find($alheio->id)?->nome);
    }

    private function assertProblema404(TestResponse $resposta): void
    {
        $resposta->assertNotFound()->assertJsonPath('codigo', 'RECURSO_NAO_ENCONTRADO');
        $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
    }
}
