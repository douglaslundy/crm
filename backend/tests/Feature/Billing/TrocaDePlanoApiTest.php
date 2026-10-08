<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class TrocaDePlanoApiTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    private Assinatura $assinatura;

    private Plano $caro;

    private Plano $barato;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'America/Sao_Paulo'));
        $this->montarCenarioCobranca();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();
        $this->caro = $this->plano(16000);
        $this->barato = $this->plano(5000);
        $this->assinatura = Assinatura::factory()->create(['plano_id' => $this->plano->id]);
        $this->assinatura->forceFill([
            'situacao' => StatusDaAssinatura::Ativa, 'metodo' => MetodoDePagamento::Cartao, 'gateway_assinatura_id' => 'pre-1',
            'paga_ate' => '2026-10-30', 'valor_ciclo_centavos' => 10000,
        ])->save();
    }

    private function plano(int $mensal, int $usuarios = Plano::ILIMITADO): Plano
    {
        $limites = [];
        foreach (Recurso::cases() as $r) {
            $limites[$r->value] = Plano::ILIMITADO;
        }
        $limites[Recurso::Usuarios->value] = $usuarios;

        return Plano::factory()->comLimites($limites)->create(['preco_mensal_centavos' => $mensal, 'preco_anual_centavos' => $mensal * 10]);
    }

    public function test_upgrade_devolve_a_cobranca_a_pagar(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $this->caro->id])
            ->assertOk()
            ->assertJsonPath('data.tipo', 'UPGRADE')
            ->assertJsonPath('data.cobranca.tipo', 'UPGRADE')
            ->assertJsonPath('data.cobranca.valor_centavos', 3000)
            ->assertJsonPath('data.cobranca.plano_destino_id', $this->caro->id)
            ->assertJsonPath('data.cobranca.metodo', null);
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id);
    }

    public function test_downgrade_devolve_o_agendamento(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $this->barato->id])
            ->assertOk()
            ->assertJsonPath('data.tipo', 'DOWNGRADE')
            ->assertJsonPath('data.cobranca', null)
            ->assertJsonPath('data.assinatura.plano_proximo_id', $this->barato->id)
            ->assertJsonPath('data.assinatura.plano_proximo_em', '2026-10-30');
    }

    public function test_downgrade_bloqueado_por_excesso_devolve_422_com_a_lista(): void
    {
        $apertado = $this->plano(5000, 0);

        $this->spaComo($this->admin)->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $apertado->id])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'PLANO_EXCEDIDO')
            ->assertJsonPath('excessos.0.recurso', 'USUARIOS');
    }

    public function test_plano_id_invalido_e_erro_de_validacao(): void
    {
        $api = $this->spaComo($this->admin);

        $api->postJson('/api/app/assinatura/trocar-plano', [])->assertStatus(422)->assertJsonValidationErrors('plano_id');
        $api->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => 'x'])->assertStatus(422)->assertJsonValidationErrors('plano_id');
    }

    public function test_so_proprietario_e_admin_trocam(): void
    {
        foreach ([Papel::Fiscal, Papel::Vendedor, Papel::Leitura] as $papel) {
            $usuario = Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);

            $this->spaComo($usuario)->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $this->caro->id])
                ->assertForbidden()->assertJsonPath('codigo', 'BILLING_SEM_PERMISSAO');
            $this->spaComo($usuario)->deleteJson('/api/app/assinatura/trocar-plano')->assertForbidden();
        }
    }

    public function test_conta_inadimplente_ou_suspensa_nao_troca(): void
    {
        foreach ([SituacaoAssinatura::Inadimplente, SituacaoAssinatura::Suspensa] as $situacao) {
            $this->tenant->forceFill(['situacao' => $situacao])->save();

            $this->spaComo($this->admin)->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $this->caro->id])->assertStatus(422)->assertJsonPath('codigo', 'BILLING_TROCA_INDISPONIVEL');
        }
        $this->assertSame(0, Cobranca::query()->count());
    }

    public function test_cancelar_o_downgrade(): void
    {
        $api = $this->spaComo($this->admin);
        $api->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $this->barato->id])->assertOk();

        $api->deleteJson('/api/app/assinatura/trocar-plano')->assertOk()->assertJsonPath('data.plano_proximo_id', null);
        $api->deleteJson('/api/app/assinatura/trocar-plano')->assertNotFound()->assertJsonPath('codigo', 'BILLING_SEM_DOWNGRADE');
    }

    public function test_outro_tenant_nao_troca_nem_cancela_nem_paga_o_que_e_alheio(): void
    {
        $api = $this->spaComo($this->admin);
        $upgrade = $api->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $this->caro->id])->assertOk()->json('data.cobranca.id');

        $outro = Tenant::factory()->for($this->plano)->situacao(SituacaoAssinatura::Ativa)->create(['cnpj' => '52998224000172']);
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($outro->id);
        $apiAlheia = $this->spaComo($intruso);

        $apiAlheia->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $this->caro->id])->assertStatus(422)->assertJsonPath('codigo', 'BILLING_TROCA_INDISPONIVEL');
        $apiAlheia->deleteJson('/api/app/assinatura/trocar-plano')->assertNotFound();
        $apiAlheia->postJson("/api/app/assinatura/cobrancas/{$upgrade}/pagar", ['metodo' => 'PIX'])->assertNotFound();
        $apiAlheia->getJson('/api/app/assinatura/cobrancas')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNull(Cobranca::withoutTenantScope()->findOrFail($upgrade)->gateway_pagamento_id);
    }

    public function test_o_upgrade_aparece_nas_faturas_e_a_assinatura_traz_o_agendamento(): void
    {
        $api = $this->spaComo($this->admin);
        $api->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $this->caro->id])->assertOk();

        $api->getJson('/api/app/assinatura/cobrancas')->assertOk()->assertJsonPath('data.0.plano_destino_id', $this->caro->id);

        $api->postJson('/api/app/assinatura/trocar-plano', ['plano_id' => $this->barato->id])->assertOk();
        $api->getJson('/api/app/assinatura')->assertOk()->assertJsonPath('data.assinatura.plano_proximo_id', $this->barato->id);
    }
}
