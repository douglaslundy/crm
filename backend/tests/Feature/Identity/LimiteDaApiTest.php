<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class LimiteDaApiTest extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    public function test_tres_chamadas_passam_com_remaining_decrescente_e_a_quarta_e_429(): void
    {
        $this->montarApi([Recurso::ApiRequisicoesMin->value => 3]);
        $this->travelTo(now()->startOfSecond());

        foreach ([2, 1, 0] as $restante) {
            $this->comoApi()->getJson('/api/v1/clientes')->assertOk()
                ->assertHeader('X-RateLimit-Limit', '3')->assertHeader('X-RateLimit-Remaining', (string) $restante)
                ->assertHeader('X-RateLimit-Reset', (string) (now()->getTimestamp() + 60));
        }

        $bloqueada = $this->comoApi()->getJson('/api/v1/clientes')
            ->assertStatus(429)->assertJsonPath('codigo', 'API_LIMITE_EXCEDIDO')
            ->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertGreaterThan(0, (int) $bloqueada->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $bloqueada->headers->get('Retry-After'));
    }

    public function test_depois_da_janela_volta_a_passar(): void
    {
        $this->montarApi([Recurso::ApiRequisicoesMin->value => 1]);
        $this->comoApi()->getJson('/api/v1/clientes')->assertOk();
        $this->comoApi()->getJson('/api/v1/clientes')->assertStatus(429);

        $this->travel(61)->seconds();

        $this->comoApi()->getJson('/api/v1/clientes')->assertOk();
    }

    public function test_duas_chaves_do_mesmo_tenant_nao_dividem_o_contador(): void
    {
        $this->montarApi([Recurso::ApiRequisicoesMin->value => 1]);
        [, $outra] = $this->novaChave($this->admin);

        $this->comoApi()->getJson('/api/v1/clientes')->assertOk();
        $this->comoApi()->getJson('/api/v1/clientes')->assertStatus(429);
        $this->comoApi($outra)->getJson('/api/v1/clientes')->assertOk();
    }

    public function test_chave_de_outro_tenant_nao_e_afetada(): void
    {
        $this->montarApi([Recurso::ApiRequisicoesMin->value => 1]);
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Api)->comLimites([Recurso::ApiRequisicoesMin->value => 1]))->create();
        $admin = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);
        [, $textoDoOutro] = $this->novaChave($admin);

        $this->comoApi()->getJson('/api/v1/clientes')->assertOk();
        $this->comoApi()->getJson('/api/v1/clientes')->assertStatus(429);
        $this->comoApi($textoDoOutro)->getJson('/api/v1/clientes')->assertOk();
    }

    public function test_ilimitado_nao_manda_headers_e_nao_bloqueia(): void
    {
        $this->montarApi();

        for ($i = 0; $i < 50; $i++) {
            $resposta = $this->comoApi()->getJson('/api/v1/clientes')->assertOk();
        }
        $this->assertNull($resposta->headers->get('X-RateLimit-Limit'));
        $this->assertNull($resposta->headers->get('X-RateLimit-Remaining'));
        $this->assertNull($resposta->headers->get('X-RateLimit-Reset'));
    }

    public function test_limite_zero_fecha_a_api_sem_dividir_por_zero(): void
    {
        $this->montarApi([Recurso::ApiRequisicoesMin->value => 0]);

        $this->comoApi()->getJson('/api/v1/clientes')->assertForbidden()->assertJsonPath('codigo', 'API_NAO_CONTRATADA');
    }

    public function test_plano_sem_linha_de_limite_tambem_fecha_a_api(): void
    {
        $this->montarApi();
        $this->tenant->forceFill(['plano_id' => Plano::factory()->comModulos(Modulo::Api)->create()->id])->save();

        $this->comoApi()->getJson('/api/v1/clientes')->assertForbidden()->assertJsonPath('codigo', 'API_NAO_CONTRATADA');
    }

    public function test_o_app_nao_e_contado(): void
    {
        $this->montarApi([Recurso::ApiRequisicoesMin->value => 1]);

        for ($i = 0; $i < 5; $i++) {
            $this->spaComo($this->admin)->getJson('/api/app/clientes')->assertOk();
        }
        $this->comoApi()->getJson('/api/v1/clientes')->assertOk();
    }

    public function test_resposta_de_erro_da_api_tambem_leva_os_headers(): void
    {
        $this->montarApi([Recurso::ApiRequisicoesMin->value => 5]);

        $this->comoApi()->getJson('/api/v1/clientes/'.fake()->uuid())->assertNotFound()->assertHeader('X-RateLimit-Limit', '5');
    }
}
