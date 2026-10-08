<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\Models\Idempotencia;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class IdempotenciaTest extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    private int $execucoes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi();
        $this->execucoes = 0;

        Route::middleware(['api', 'api-v1', 'idempotencia:obrigatoria'])->post('api/v1/_idem', function (Request $r) {
            $this->execucoes++;

            return response()->json(['n' => $this->execucoes], 201);
        });
        Route::middleware(['api', 'api-v1', 'idempotencia'])->post('api/v1/_idem_opc', function () {
            $this->execucoes++;

            return response()->json(['n' => $this->execucoes], 201);
        });
        Route::middleware(['api', 'api-v1', 'idempotencia:obrigatoria'])->post('api/v1/_idem_422', function () {
            $this->execucoes++;

            throw LimiteDoPlanoAtingidoException::para(Recurso::Produtos, 1);
        });
        Route::middleware(['api', 'api-v1', 'idempotencia:obrigatoria'])->post('api/v1/_idem_500', function () {
            $this->execucoes++;

            throw new RuntimeException('falha');
        });
        Route::middleware(['api', 'api-v1', 'idempotencia:obrigatoria'])->post('api/v1/_idem/{id}', function (string $id) {
            $this->execucoes++;

            return response()->json(['id' => $id], 201);
        });
    }

    private function postar(string $url, array $corpo, ?string $chave = 'k-1', ?string $texto = null): TestResponse
    {
        $api = $this->comoApi($texto);

        return $chave === null ? $api->postJson($url, $corpo) : $api->withHeaders(['Idempotency-Key' => $chave])->postJson($url, $corpo);
    }

    public function test_chave_ausente_na_rota_obrigatoria_e_400(): void
    {
        $this->postar('/api/v1/_idem', ['a' => 1], null)->assertStatus(400)->assertJsonPath('codigo', 'IDEMPOTENCY_KEY_AUSENTE');
        $this->assertSame(0, $this->execucoes);
    }

    public function test_chave_ausente_na_rota_opcional_executa_sempre(): void
    {
        $this->postar('/api/v1/_idem_opc', ['a' => 1], null)->assertCreated();
        $this->postar('/api/v1/_idem_opc', ['a' => 1], null)->assertCreated();
        $this->assertSame(2, $this->execucoes);
    }

    public function test_chave_invalida_e_400(): void
    {
        foreach (['', 'com espaco', str_repeat('a', 256)] as $chave) {
            $this->postar('/api/v1/_idem', ['a' => 1], $chave)->assertStatus(400)->assertJsonPath('codigo', 'IDEMPOTENCY_KEY_INVALIDA');
        }
        $this->assertSame(0, $this->execucoes);
    }

    public function test_replay_devolve_a_resposta_original_sem_executar_de_novo(): void
    {
        $primeira = $this->postar('/api/v1/_idem', ['a' => 1, 'b' => ['x' => 1, 'y' => 2]])->assertCreated();
        $this->assertNull($primeira->headers->get('Idempotent-Replayed'));

        $segunda = $this->postar('/api/v1/_idem', ['b' => ['y' => 2, 'x' => 1], 'a' => 1])->assertCreated();

        $this->assertSame('true', $segunda->headers->get('Idempotent-Replayed'));
        $this->assertSame($primeira->json(), $segunda->json());
        $this->assertSame(1, $this->execucoes);
    }

    public function test_corpo_diferente_com_a_mesma_chave_e_409(): void
    {
        $this->postar('/api/v1/_idem', ['a' => 1])->assertCreated();

        $this->postar('/api/v1/_idem', ['a' => 2])->assertStatus(409)->assertJsonPath('codigo', 'IDEMPOTENCIA_CORPO_DIFERENTE');
        $this->assertSame(1, $this->execucoes);
    }

    public function test_o_caminho_faz_parte_do_hash(): void
    {
        $this->postar('/api/v1/_idem/'.fake()->uuid(), ['a' => 1])->assertCreated();

        $this->postar('/api/v1/_idem/'.fake()->uuid(), ['a' => 1])->assertStatus(409)->assertJsonPath('codigo', 'IDEMPOTENCIA_CORPO_DIFERENTE');
    }

    public function test_erro_de_negocio_422_fica_gravado_e_e_repetido(): void
    {
        $primeira = $this->postar('/api/v1/_idem_422', ['a' => 1])->assertStatus(422);
        $segunda = $this->postar('/api/v1/_idem_422', ['a' => 1])->assertStatus(422);

        $this->assertSame('true', $segunda->headers->get('Idempotent-Replayed'));
        $this->assertSame($primeira->json('codigo'), $segunda->json('codigo'));
        $this->assertStringStartsWith('application/problem+json', (string) $segunda->headers->get('Content-Type'));
        $this->assertSame(1, $this->execucoes);
    }

    public function test_erro_500_libera_a_chave_para_nova_tentativa(): void
    {
        $this->postar('/api/v1/_idem_500', ['a' => 1])->assertStatus(500);
        $this->postar('/api/v1/_idem_500', ['a' => 1])->assertStatus(500);

        $this->assertSame(2, $this->execucoes);
        $this->assertSame(0, Idempotencia::query()->count());
    }

    public function test_reserva_em_andamento_devolve_409_com_retry_after(): void
    {
        $this->reservar('k-1', '/api/v1/_idem', ['a' => 1]);

        $this->postar('/api/v1/_idem', ['a' => 1])->assertStatus(409)
            ->assertJsonPath('codigo', 'IDEMPOTENCIA_EM_ANDAMENTO')->assertHeader('Retry-After', '1');
        $this->assertSame(0, $this->execucoes);
    }

    public function test_reserva_orfa_e_descartada(): void
    {
        $registro = $this->reservar('k-1', '/api/v1/_idem', ['a' => 1]);
        $registro->forceFill(['created_at' => now()->subSeconds(121)])->save();

        $this->postar('/api/v1/_idem', ['a' => 1])->assertCreated();
        $this->assertSame(1, $this->execucoes);
    }

    public function test_registro_expirado_e_descartado(): void
    {
        $this->postar('/api/v1/_idem', ['a' => 1])->assertCreated();
        Idempotencia::query()->update(['expira_em' => now()->subMinute()]);

        $this->postar('/api/v1/_idem', ['a' => 2])->assertCreated();
        $this->assertSame(2, $this->execucoes);
    }

    public function test_a_mesma_chave_em_duas_empresas_nao_colide(): void
    {
        $this->postar('/api/v1/_idem', ['a' => 1])->assertCreated();

        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Api)->comLimites([Recurso::ApiRequisicoesMin->value => -1]))->create();
        app(TenantContext::class)->set($outro->id);
        $adminDoOutro = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);
        [, $textoDoOutro] = $this->novaChave($adminDoOutro);

        $this->postar('/api/v1/_idem', ['a' => 99], 'k-1', $textoDoOutro)->assertCreated();
        $this->assertSame(2, $this->execucoes);
    }

    public function test_comando_apaga_so_os_expirados(): void
    {
        $this->postar('/api/v1/_idem', ['a' => 1], 'k-vivo')->assertCreated();
        $this->postar('/api/v1/_idem', ['a' => 2], 'k-velho')->assertCreated();
        Idempotencia::query()->where('chave', 'k-velho')->update(['expira_em' => now()->subMinute()]);

        Artisan::call('api:limpar-idempotencias');

        $this->assertSame(['k-vivo'], Idempotencia::query()->pluck('chave')->all());
    }

    /** @param array<string, mixed> $corpo */
    private function reservar(string $chave, string $url, array $corpo): Idempotencia
    {
        $registro = new Idempotencia;
        $registro->forceFill([
            'chave' => $chave, 'rota' => 'POST '.ltrim($url, '/'),
            'hash_do_corpo' => hash('sha256', ltrim($url, '/').'|'.json_encode($corpo).'|[]'),
            'expira_em' => now()->addDay(),
        ])->save();

        return $registro;
    }
}
