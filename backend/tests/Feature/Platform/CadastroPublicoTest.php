<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\CadastrarEmpresa;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CadastroPublicoTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function dados(Plano $plano, array $extra = []): array
    {
        return array_merge([
            'cnpj' => '11.222.333/0001-81',
            'razao_social' => 'Padaria Pão Bom Ltda',
            'nome_fantasia' => 'Pão Bom',
            'plano_id' => $plano->id,
            'responsavel_nome' => 'Ana Souza',
            'email' => 'Ana@PaoBom.com',
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
            'aceite_termos' => true,
        ], $extra);
    }

    public function test_planos_publicos_so_ativos_e_visiveis_em_ordem(): void
    {
        Plano::factory()->create(['nome' => 'B', 'ordem' => 2]);
        Plano::factory()->create(['nome' => 'A', 'ordem' => 1]);
        Plano::factory()->create(['nome' => 'Oculto', 'visivel' => false]);
        Plano::factory()->create(['nome' => 'Inativo', 'ativo' => false]);

        $this->getJson('/api/publico/planos')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nome', 'A')
            ->assertJsonPath('data.1.nome', 'B');
    }

    public function test_consulta_cnpj_encontrado_com_cache(): void
    {
        Http::fake(['*/api/cnpj/v1/11222333000181' => Http::response(['razao_social' => 'PADARIA PAO BOM LTDA', 'nome_fantasia' => ''])]);

        $this->getJson('/api/publico/cnpj/11222333000181')
            ->assertOk()
            ->assertExactJson(['data' => ['razao_social' => 'PADARIA PAO BOM LTDA', 'nome_fantasia' => null]]);
        $this->getJson('/api/publico/cnpj/11222333000181')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_consulta_cnpj_nao_encontrado(): void
    {
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

        $this->getJson('/api/publico/cnpj/11222333000181')->assertNotFound();
    }

    public function test_consulta_cnpj_com_brasilapi_fora_responde_503(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->getJson('/api/publico/cnpj/11222333000181')
            ->assertStatus(503)
            ->assertJsonPath('codigo', 'CONSULTA_INDISPONIVEL');
    }

    public function test_consulta_cnpj_invalido_nem_chama_a_brasilapi(): void
    {
        Http::fake();

        $this->getJson('/api/publico/cnpj/11222333000182')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_cadastro_com_teste_nasce_em_teste_com_a_data_certa(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $plano = Plano::factory()->create(['dias_teste' => 14]);

        $resposta = $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano))
            ->assertCreated()
            ->assertJsonPath('data.email', 'ana@paobom.com')
            ->assertJsonPath('data.papel', 'PROPRIETARIO')
            ->assertJsonPath('data.tenant.situacao', 'TESTE')
            ->assertJsonPath('data.tenant.teste_termina_em', '2026-10-15')
            ->assertJsonPath('data.tenant.cnpj', '11222333000181');

        $usuario = Usuario::query()->findOrFail($resposta->json('data.id'));
        $this->assertSame(Papel::Proprietario, $usuario->papel);
        $this->assertAuthenticatedAs($usuario, 'web');
    }

    public function test_cadastro_sem_teste_nasce_pendente(): void
    {
        $plano = Plano::factory()->create(['dias_teste' => 0]);

        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano))
            ->assertCreated()
            ->assertJsonPath('data.tenant.situacao', 'PENDENTE')
            ->assertJsonPath('data.tenant.teste_termina_em', null);
    }

    public function test_cnpj_alfanumerico_e_normalizado_e_duplicado_detectado_com_outra_mascara(): void
    {
        $plano = Plano::factory()->create();

        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['cnpj' => '12.abc.345/01de-35']))
            ->assertCreated()
            ->assertJsonPath('data.tenant.cnpj', '12ABC34501DE35');

        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['cnpj' => '12ABC34501DE35', 'email' => 'outro@x.com']))
            ->assertStatus(422)
            ->assertJsonPath('errors.cnpj.0', CadastrarEmpresa::CNPJ_DUPLICADO);
    }

    public function test_validacoes_do_cadastro(): void
    {
        $plano = Plano::factory()->create();
        $oculto = Plano::factory()->create(['visivel' => false]);
        Usuario::factory()->for(Tenant::factory())->create(['email' => 'ja@existe.com']);

        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['email' => 'JA@existe.com']))
            ->assertJsonPath('errors.email.0', CadastrarEmpresa::EMAIL_DUPLICADO);
        $this->spa()->postJson('/api/publico/cadastro', $this->dados($oculto))
            ->assertJsonPath('errors.plano_id.0', 'Este plano não está disponível.');
        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['aceite_termos' => false]))
            ->assertJsonValidationErrors('aceite_termos');
        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['cnpj' => '11222333000182']))
            ->assertJsonPath('errors.cnpj.0', 'Informe um CNPJ válido.');

        // 1 = só o tenant criado no setup (dono do e-mail já existente); nenhuma tentativa acima deve criar outro.
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_violacao_de_unicidade_vira_422(): void
    {
        // Simula a corrida: a validação passou, mas outro cadastro gravou o mesmo CNPJ antes.
        $plano = Plano::factory()->create();
        Tenant::factory()->create(['cnpj' => '11222333000181']);

        try {
            app(CadastrarEmpresa::class)->executar([
                'cnpj' => '11222333000181', 'razao_social' => 'X', 'nome_fantasia' => null, 'plano_id' => $plano->id,
                'responsavel_nome' => 'Ana', 'email' => 'ana@x.com', 'password' => 'Senha123',
            ]);
            $this->fail('Deveria lançar.');
        } catch (ValidationException $e) {
            $this->assertSame([CadastrarEmpresa::CNPJ_DUPLICADO], $e->errors()['cnpj']);
        }

        $this->assertSame(0, Usuario::query()->count());
        $this->assertSame(SituacaoAssinatura::Ativa, Tenant::query()->firstOrFail()->situacao);
    }
}
