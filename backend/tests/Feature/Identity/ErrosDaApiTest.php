<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class ErrosDaApiTest extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi();
        config(['api.erros_base_url' => 'https://docs.exemplo.test/erros']);
    }

    private function assertProblema(TestResponse $resposta, int $status, string $codigo): void
    {
        $resposta->assertStatus($status);
        $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
        $resposta->assertJsonPath('status', $status)->assertJsonPath('codigo', $codigo)
            ->assertJsonPath('type', 'https://docs.exemplo.test/erros/'.strtolower($codigo));
        $this->assertIsString($resposta->json('title'));
        $this->assertIsString($resposta->json('detail'));
    }

    public function test_validacao_devolve_422_com_lista_de_campos(): void
    {
        $resposta = $this->comoApi()->postJson('/api/v1/clientes', []);

        $this->assertProblema($resposta, 422, 'VALIDACAO');
        $erros = $resposta->json('errors');
        $this->assertNotEmpty($erros);
        $this->assertArrayHasKey('campo', $erros[0]);
        $this->assertArrayHasKey('mensagem', $erros[0]);
        $this->assertContains('tipo', array_column($erros, 'campo'));
    }

    public function test_registro_inexistente_devolve_404(): void
    {
        $this->assertProblema($this->comoApi()->getJson('/api/v1/clientes/'.fake()->uuid()), 404, 'RECURSO_NAO_ENCONTRADO');
    }

    public function test_url_desconhecida_devolve_404(): void
    {
        $this->assertProblema($this->comoApi()->getJson('/api/v1/nada'), 404, 'RECURSO_NAO_ENCONTRADO');
    }

    public function test_metodo_nao_permitido_devolve_405(): void
    {
        $resposta = $this->comoApi()->deleteJson('/api/v1/clientes');

        $this->assertProblema($resposta, 405, 'METODO_NAO_PERMITIDO');
        $this->assertNotNull($resposta->headers->get('Allow'));
    }

    public function test_sem_chave_devolve_401_problem_json(): void
    {
        $resposta = $this->getJson('/api/v1/clientes');

        $this->assertProblema($resposta, 401, 'API_CHAVE_AUSENTE');
        $resposta->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_erro_de_negocio_leva_codigo_status_e_extras(): void
    {
        Route::middleware(['api', 'api-v1'])->get('api/v1/_negocio', fn () => throw LimiteDoPlanoAtingidoException::para(Recurso::Produtos, 5));

        $resposta = $this->comoApi()->getJson('/api/v1/_negocio');

        $this->assertProblema($resposta, 422, 'LIMITE_DO_PLANO');
        $resposta->assertJsonPath('recurso', 'PRODUTOS')->assertJsonPath('limite', 5);
        $this->assertStringContainsString('5', (string) $resposta->json('detail'));
    }

    public function test_erro_interno_nao_vaza_a_mensagem_da_excecao(): void
    {
        Route::middleware(['api', 'api-v1'])->get('api/v1/_quebra', fn () => throw new RuntimeException('segredo'));

        $resposta = $this->comoApi()->getJson('/api/v1/_quebra');

        $this->assertProblema($resposta, 500, 'ERRO_INTERNO');
        $this->assertStringNotContainsString('segredo', $resposta->getContent() ?: '');
    }

    public function test_o_app_continua_com_message_e_codigo(): void
    {
        $resposta = $this->spaComo($this->admin)->postJson('/api/app/clientes', []);

        $resposta->assertUnprocessable()->assertJsonStructure(['message', 'errors']);
        $this->assertStringNotContainsString('problem+json', (string) $resposta->headers->get('Content-Type'));

        $this->spaComo($this->admin)->getJson('/api/app/chaves-api/'.fake()->uuid())->assertStatus(405);
        $this->spaComo($this->admin)->deleteJson('/api/app/chaves-api/'.fake()->uuid())->assertNotFound()->assertJsonMissingPath('type');
    }
}
