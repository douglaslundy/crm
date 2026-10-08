<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class AplicarInadimplenciaTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'America/Sao_Paulo'));
        $this->montarCenarioCobranca();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();
    }

    /** Cobrança pendente que venceu há $dias dias (0 = vence hoje), numa assinatura ativa. */
    private function vencida(int $dias): Cobranca
    {
        $c = $this->cobrancaPendente(['vence_em' => Carbon::parse('2026-10-07')->subDays($dias)->toDateString()]);
        $c->assinatura->forceFill(['situacao' => StatusDaAssinatura::Ativa])->save();

        return $c;
    }

    private function assinaturaAtiva(string $pagaAte): Assinatura
    {
        $a = Assinatura::factory()->create(['plano_id' => $this->plano->id]);
        $a->forceFill(['situacao' => StatusDaAssinatura::Ativa, 'metodo' => MetodoDePagamento::Cartao, 'gateway_assinatura_id' => 'pre-1', 'paga_ate' => $pagaAte])->save();

        return $a;
    }

    private function rodar(): void
    {
        $this->artisan('billing:aplicar-inadimplencia')->assertSuccessful();
    }

    public function test_cobranca_pendente_vencida_ontem_vira_vencida_e_tenant_fica_inadimplente(): void
    {
        $c = $this->vencida(1);

        $this->rodar();

        $this->assertSame(StatusDaCobranca::Vencida, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Inadimplente, $this->tenant->refresh()->situacao);
    }

    public function test_vence_hoje_ainda_nao_e_vencida(): void
    {
        $c = $this->vencida(0);

        $this->rodar();

        $this->assertSame(StatusDaCobranca::Pendente, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    }

    public function test_limite_da_suspensao_exatamente_no_decimo_dia(): void
    {
        $this->vencida(9);
        $this->rodar();
        $this->assertSame(SituacaoAssinatura::Inadimplente, $this->tenant->refresh()->situacao);

        Cobranca::query()->update(['vence_em' => '2026-09-27']);   // 10 dias
        $this->rodar();

        $this->assertSame(SituacaoAssinatura::Suspensa, $this->tenant->refresh()->situacao);
    }

    public function test_tenant_em_teste_nao_e_alterado_por_cobranca_vencida(): void
    {
        $this->vencida(20);
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Teste])->save();

        $this->rodar();

        $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao);
    }

    public function test_assinatura_cancelada_e_ignorada(): void
    {
        $c = $this->vencida(20);
        $c->assinatura->forceFill(['situacao' => StatusDaAssinatura::Cancelada])->save();

        $this->rodar();

        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    }

    public function test_usa_a_cobranca_vencida_mais_antiga_do_tenant(): void
    {
        $recente = $this->vencida(2);
        $antiga = Cobranca::factory()->for($recente->assinatura)->create();
        $antiga->forceFill(['vence_em' => '2026-09-25'])->save();   // 12 dias

        $this->rodar();

        $this->assertSame(SituacaoAssinatura::Suspensa, $this->tenant->refresh()->situacao);
    }

    public function test_rodar_de_novo_e_idempotente(): void
    {
        $this->vencida(1);
        $this->rodar();
        $this->rodar();
        $this->assertSame(SituacaoAssinatura::Inadimplente, $this->tenant->refresh()->situacao);

        Cobranca::query()->update(['vence_em' => '2026-09-20']);
        $this->rodar();
        $this->rodar();

        $this->assertSame(SituacaoAssinatura::Suspensa, $this->tenant->refresh()->situacao);
    }

    public function test_usa_as_carencias_da_config(): void
    {
        config(['billing.carencia_inadimplente_dias' => 3]);
        $this->vencida(2);

        $this->rodar();

        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    }

    public function test_renovacao_do_cartao_que_nao_veio_leva_a_inadimplencia_e_a_suspensao_pelo_paga_ate(): void
    {
        $a = $this->assinaturaAtiva('2026-10-06');   // venceu ontem, sem nenhuma cobrança nossa vencida

        $this->rodar();
        $this->assertSame(SituacaoAssinatura::Inadimplente, $this->tenant->refresh()->situacao);

        $a->forceFill(['paga_ate' => '2026-09-27'])->save();   // 10 dias
        $this->rodar();
        $this->assertSame(SituacaoAssinatura::Suspensa, $this->tenant->refresh()->situacao);
    }

    public function test_paga_ate_de_hoje_ou_futuro_nao_e_atraso(): void
    {
        $this->assinaturaAtiva('2026-10-07');

        $this->rodar();

        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    }

    public function test_cobranca_paga_nao_conta(): void
    {
        $c = $this->vencida(30);
        $c->forceFill(['status' => StatusDaCobranca::Paga])->save();

        $this->rodar();

        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    }

    public function test_excedente_vencido_leva_a_inadimplente_e_a_suspensa_nos_limites(): void
    {
        $assinatura = $this->assinaturaAtiva('2026-11-30');
        $c = $this->excedentePendente(['vence_em' => '2026-09-28'], $assinatura);   // venceu há 9 dias

        $this->rodar();
        $this->assertSame(StatusDaCobranca::Vencida, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Inadimplente, $this->tenant->refresh()->situacao);

        $c->forceFill(['vence_em' => '2026-09-27'])->save();                          // venceu há 10 dias
        $this->rodar();
        $this->assertSame(SituacaoAssinatura::Suspensa, $this->tenant->refresh()->situacao);
    }
}
