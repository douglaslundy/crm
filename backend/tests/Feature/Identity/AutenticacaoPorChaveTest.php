<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class AutenticacaoPorChaveTest extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi();
    }

    private function listar(?string $texto = null): TestResponse
    {
        return $this->comoApi($texto)->getJson('/api/v1/clientes');
    }

    public function test_sem_header_responde_401_chave_ausente(): void
    {
        $this->getJson('/api/v1/clientes')
            ->assertUnauthorized()->assertJsonPath('codigo', 'API_CHAVE_AUSENTE')->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_header_sem_bearer_ou_chave_sem_prefixo_responde_401_invalida(): void
    {
        $this->withHeaders(['Authorization' => 'Basic abc'])->getJson('/api/v1/clientes')
            ->assertUnauthorized()->assertJsonPath('codigo', 'API_CHAVE_AUSENTE');
        $this->comoApi('qualquercoisa')->getJson('/api/v1/clientes')
            ->assertUnauthorized()->assertJsonPath('codigo', 'API_CHAVE_INVALIDA')->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_chave_inexistente_revogada_e_autor_sem_acesso_dao_a_mesma_resposta(): void
    {
        $inexistente = $this->listar('live_'.str_repeat('a', 40));
        $inexistente->assertUnauthorized()->assertJsonPath('codigo', 'API_CHAVE_INVALIDA');
        $mensagem = $inexistente->json('message');

        $this->chave->forceFill(['revogada_em' => now()])->save();
        $this->listar()->assertUnauthorized()->assertJsonPath('codigo', 'API_CHAVE_INVALIDA')->assertJsonPath('message', $mensagem);
        $this->chave->forceFill(['revogada_em' => null])->save();

        $this->admin->forceFill(['ativo' => false])->save();
        $this->listar()->assertUnauthorized()->assertJsonPath('codigo', 'API_CHAVE_INVALIDA')->assertJsonPath('message', $mensagem);
        $this->admin->forceFill(['ativo' => true, 'papel' => Papel::Vendedor])->save();
        $this->listar()->assertUnauthorized()->assertJsonPath('codigo', 'API_CHAVE_INVALIDA')->assertJsonPath('message', $mensagem);
    }

    public function test_chaves_live_e_test_validas_passam_e_o_ambiente_fica_no_request(): void
    {
        Route::middleware(['api', 'api-v1'])->get('api/v1/_ambiente', fn (Request $r) => [
            'ambiente' => $r->attributes->get('ambiente_da_chave')->value,
            'usuario' => $r->user()?->id,
            'tenant' => app(TenantContext::class)->id(),
        ]);
        [, $textoTest] = $this->novaChave($this->admin, AmbienteDaChave::Test);

        $this->comoApi()->getJson('/api/v1/_ambiente')->assertOk()
            ->assertExactJson(['ambiente' => 'LIVE', 'usuario' => $this->admin->id, 'tenant' => $this->tenant->id]);
        $this->comoApi($textoTest)->getJson('/api/v1/_ambiente')->assertOk()->assertJsonPath('ambiente', 'TEST');
    }

    public function test_ultimo_uso_e_atualizado_no_maximo_uma_vez_por_minuto(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->assertNull($this->chave->fresh()?->ultimo_uso_em);

        $this->listar()->assertOk();
        $primeiro = $this->chave->fresh()?->ultimo_uso_em;
        $this->assertNotNull($primeiro);

        $this->travel(30)->seconds();
        $this->listar()->assertOk();
        $this->assertEquals($primeiro, $this->chave->fresh()?->ultimo_uso_em);

        $this->travel(40)->seconds();
        $this->listar()->assertOk();
        $this->assertTrue($this->chave->fresh()?->ultimo_uso_em?->gt($primeiro));
    }

    public function test_plano_sem_o_modulo_api_responde_403(): void
    {
        $this->tenant->forceFill(['plano_id' => Plano::factory()->create()->id])->save();

        $this->listar()->assertForbidden()->assertJsonPath('codigo', 'API_NAO_CONTRATADA');
    }

    public function test_conta_pendente_e_cancelada_recusam_ate_a_leitura(): void
    {
        foreach ([SituacaoAssinatura::Pendente, SituacaoAssinatura::Cancelada] as $situacao) {
            $this->tenant->forceFill(['situacao' => $situacao])->save();
            $this->listar()->assertForbidden();
        }
    }

    public function test_inadimplente_e_suspensa_leem_mas_nao_escrevem(): void
    {
        foreach ([SituacaoAssinatura::Inadimplente, SituacaoAssinatura::Suspensa] as $situacao) {
            $this->tenant->forceFill(['situacao' => $situacao])->save();
            $this->listar()->assertOk();
        }

        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Ana'])
            ->assertForbidden()->assertJsonPath('codigo', 'ASSINATURA_SEM_ESCRITA');
    }

    public function test_sessao_do_app_nao_vale_no_v1_e_chave_nao_vale_no_app(): void
    {
        $this->spaComo($this->admin)->getJson('/api/v1/clientes')->assertUnauthorized();

        $this->comoApi()->getJson('/api/app/clientes')->assertUnauthorized();
        $this->comoApi()->getJson('/api/app/chaves-api')->assertUnauthorized();
    }

    public function test_o_tenant_do_contexto_e_o_da_chave(): void
    {
        $outro = Tenant::factory()->for(Plano::factory())->create();
        app(TenantContext::class)->set($outro->id);
        Cliente::factory()->for($outro)->create(['nome' => 'Alheio']);
        app(TenantContext::class)->set($this->tenant->id);
        Cliente::factory()->for($this->tenant)->create(['nome' => 'Meu']);

        $resposta = $this->listar()->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('Alheio', $resposta->getContent() ?: '');
    }

    public function test_proprietario_tambem_pode_ser_autor_da_chave(): void
    {

        $dono = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Proprietario]);
        [, $texto] = $this->novaChave($dono);

        $this->listar($texto)->assertOk();
    }
}
