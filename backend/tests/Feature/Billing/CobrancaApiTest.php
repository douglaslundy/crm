<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class CobrancaApiTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCobranca();
    }

    /** @param  array<string, mixed>  $sobrescrever */
    private function corpo(array $sobrescrever = []): array
    {
        return array_replace(['plano_id' => $this->plano->id, 'ciclo' => 'MENSAL', 'metodo' => 'BOLETO'], $sobrescrever);
    }

    public function test_checkout_de_boleto_devolve_201_com_o_boleto(): void
    {
        $r = $this->spaComo($this->admin)->postJson('/api/app/assinatura/checkout', $this->corpo())->assertCreated();

        $r->assertJsonPath('data.assinatura.situacao', 'PENDENTE')
            ->assertJsonPath('data.assinatura.ciclo', 'MENSAL')
            ->assertJsonPath('data.cobranca.valor_centavos', 10000)
            ->assertJsonPath('data.cobranca.status', 'PENDENTE');
        $this->assertNotNull($r->json('data.cobranca.boleto_url'));
        $this->assertNotNull($r->json('data.cobranca.boleto_linha_digitavel'));
    }

    public function test_checkout_de_cartao_com_token(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/assinatura/checkout', $this->corpo(['metodo' => 'CARTAO', 'ciclo' => 'ANUAL', 'token_do_cartao' => 'tok_1']))
            ->assertCreated()->assertJsonPath('data.cobranca.valor_centavos', 100000);
    }

    public function test_cartao_sem_token_devolve_422_com_codigo(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/assinatura/checkout', $this->corpo(['metodo' => 'CARTAO']))
            ->assertStatus(422)->assertJsonPath('codigo', 'BILLING_CARTAO_SEM_TOKEN');
        $this->assertSame(0, Assinatura::query()->count());
    }

    public function test_validacao_do_corpo(): void
    {
        $api = $this->spaComo($this->admin);

        $api->postJson('/api/app/assinatura/checkout', $this->corpo(['plano_id' => 'x']))->assertStatus(422)->assertJsonValidationErrors('plano_id');
        $api->postJson('/api/app/assinatura/checkout', $this->corpo(['ciclo' => 'SEMANAL']))->assertStatus(422)->assertJsonValidationErrors('ciclo');
        $api->postJson('/api/app/assinatura/checkout', $this->corpo(['metodo' => 'PIX']))->assertStatus(422)->assertJsonValidationErrors('metodo');
    }

    public function test_plano_sem_preco_do_ciclo_devolve_422(): void
    {
        $this->plano->forceFill(['preco_anual_centavos' => null])->save();

        $this->spaComo($this->admin)->postJson('/api/app/assinatura/checkout', $this->corpo(['ciclo' => 'ANUAL']))
            ->assertStatus(422)->assertJsonPath('codigo', 'BILLING_CICLO_INDISPONIVEL');
    }

    public function test_so_proprietario_e_admin_usam_a_cobranca(): void
    {
        $this->cobrancaPendente();
        foreach ([Papel::Fiscal, Papel::Vendedor, Papel::Leitura] as $papel) {
            $usuario = Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);
            $api = $this->spaComo($usuario);

            $api->postJson('/api/app/assinatura/checkout', $this->corpo())->assertForbidden()->assertJsonPath('codigo', 'BILLING_SEM_PERMISSAO');
            $api->getJson('/api/app/assinatura/cobrancas')->assertForbidden();
            $api->postJson('/api/app/assinatura/cancelar')->assertForbidden();
        }
    }

    public function test_lista_as_faturas_do_tenant_mais_recente_primeiro(): void
    {
        $antiga = $this->cobrancaPendente(['vence_em' => '2026-01-10']);
        $nova = Cobranca::factory()->for($antiga->assinatura)->create();
        $nova->forceFill(['vence_em' => '2026-02-10', 'boleto_url' => 'https://mp.test/b'])->save();

        $r = $this->spaComo($this->admin)->getJson('/api/app/assinatura/cobrancas')->assertOk();

        $this->assertSame([$nova->id, $antiga->id], array_column($r->json('data'), 'id'));
        $r->assertJsonPath('data.0.boleto_url', 'https://mp.test/b')->assertJsonPath('data.0.vence_em', '2026-02-10');
    }

    public function test_cancelar_cancela_a_assinatura(): void
    {
        $c = $this->cobrancaPendente();

        $this->spaComo($this->admin)->postJson('/api/app/assinatura/cancelar')->assertOk()->assertJsonPath('data.situacao', 'CANCELADA');

        $this->assertSame(StatusDaAssinatura::Cancelada, $c->assinatura->refresh()->situacao);
    }

    public function test_outro_tenant_nao_ve_faturas_nem_cancela_assinatura_alheia(): void
    {
        $this->cobrancaPendente();
        $outro = Tenant::factory()->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($outro->id);
        $api = $this->spaComo($intruso);

        $api->getJson('/api/app/assinatura/cobrancas')->assertOk()->assertJsonCount(0, 'data');
        $api->postJson('/api/app/assinatura/cancelar')->assertNotFound()->assertJsonPath('codigo', 'BILLING_SEM_ASSINATURA');
        $this->assertSame(StatusDaAssinatura::Pendente, Assinatura::withoutTenantScope()->firstOrFail()->situacao);
    }

    /** @return array<string, array{SituacaoAssinatura}> */
    public static function situacoesQuePrecisamPoderPagar(): array
    {
        return ['pendente' => [SituacaoAssinatura::Pendente], 'inadimplente' => [SituacaoAssinatura::Inadimplente], 'suspensa' => [SituacaoAssinatura::Suspensa]];
    }

    #[DataProvider('situacoesQuePrecisamPoderPagar')]
    public function test_quem_esta_pendente_inadimplente_ou_suspenso_consegue_assinar_e_ver_faturas(SituacaoAssinatura $situacao): void
    {
        $this->tenant->forceFill(['situacao' => $situacao])->save();
        $this->admin->unsetRelation('tenant');
        $api = $this->spaComo($this->admin);

        $api->postJson('/api/app/assinatura/checkout', $this->corpo())->assertCreated();
        $api->getJson('/api/app/assinatura/cobrancas')->assertOk()->assertJsonCount(1, 'data');
        $api->postJson('/api/app/assinatura/cancelar')->assertOk();
    }

    public function test_empresa_cancelada_nao_assina(): void
    {
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Cancelada])->save();
        $this->admin->unsetRelation('tenant');

        $this->spaComo($this->admin)->postJson('/api/app/assinatura/checkout', $this->corpo())->assertStatus(403)->assertJsonPath('codigo', 'BILLING_EMPRESA_CANCELADA');
        $this->assertSame(0, Assinatura::query()->count());
    }

    public function test_assinatura_mostra_a_assinatura_de_cobranca_quando_existe(): void
    {
        $api = $this->spaComo($this->admin);
        $api->getJson('/api/app/assinatura')->assertOk()->assertJsonPath('data.assinatura', null);

        $this->cobrancaPendente();

        $api->getJson('/api/app/assinatura')->assertOk()
            ->assertJsonPath('data.assinatura.situacao', 'PENDENTE')->assertJsonPath('data.assinatura.metodo', 'BOLETO');
    }
}
