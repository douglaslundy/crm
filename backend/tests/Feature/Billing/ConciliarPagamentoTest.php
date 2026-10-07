<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\Actions\ConciliarPagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class ConciliarPagamentoTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCobranca();
    }

    private function aprovado(Cobranca $cobranca, string $id = 'pg-1', int $valor = 10000): void
    {
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento($id, StatusDoPagamento::Aprovado, $cobranca->id, $valor));
    }

    private function hoje(): CarbonImmutable
    {
        return CarbonImmutable::today((string) config('app.fuso_negocio'));
    }

    public function test_pagamento_aprovado_marca_paga_estende_o_periodo_e_ativa_o_tenant(): void
    {
        $c = $this->cobrancaPendente();
        $this->aprovado($c);

        app(ConciliarPagamento::class)->executar('pg-1');

        $c->refresh();
        $this->assertSame(StatusDaCobranca::Paga, $c->status);
        $this->assertSame('pg-1', $c->gateway_pagamento_id);
        $this->assertSame($this->hoje()->addMonthNoOverflow()->toDateString(), $c->assinatura->paga_ate?->toDateString());
        $this->assertSame(StatusDaAssinatura::Ativa, $c->assinatura->situacao);
        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
        $this->assertSame($c->assinatura->plano_id, $this->tenant->plano_id);
    }

    public function test_conciliar_duas_vezes_nao_estende_o_periodo_de_novo(): void
    {
        $c = $this->cobrancaPendente();
        $this->aprovado($c);
        $this->aprovado($c);
        app(ConciliarPagamento::class)->executar('pg-1');
        $primeira = $c->assinatura->refresh()->paga_ate?->toDateString();

        app(ConciliarPagamento::class)->executar('pg-1');

        $this->assertSame($primeira, $c->assinatura->refresh()->paga_ate?->toDateString());
    }

    public function test_pagamento_pendente_ou_recusado_nao_ativa(): void
    {
        $c = $this->cobrancaPendente();
        foreach ([StatusDoPagamento::Pendente, StatusDoPagamento::Recusado] as $status) {
            $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-1', $status, $c->id, 10000));
            app(ConciliarPagamento::class)->executar('pg-1');
        }

        $this->assertSame(StatusDaCobranca::Pendente, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao);
    }

    public function test_valor_divergente_nao_concilia(): void
    {
        $c = $this->cobrancaPendente();
        $this->aprovado($c, 'pg-1', 9999);

        try {
            app(ConciliarPagamento::class)->executar('pg-1');
            $this->fail('deveria recusar');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_VALOR_DIVERGENTE', $e->codigo());
        }
        $this->assertSame(StatusDaCobranca::Pendente, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao);
    }

    public function test_pagamento_tardio_de_cobranca_vencida_reativa_tenant_suspenso(): void
    {
        $c = $this->cobrancaPendente(['status' => StatusDaCobranca::Vencida, 'vence_em' => $this->hoje()->subDays(20)->toDateString()]);
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        $this->aprovado($c);

        app(ConciliarPagamento::class)->executar('pg-1');

        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
        $this->assertSame(StatusDaCobranca::Paga, $c->refresh()->status);
    }

    public function test_inadimplente_volta_a_ativa(): void
    {
        $c = $this->cobrancaPendente();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Inadimplente])->save();
        $this->aprovado($c);

        app(ConciliarPagamento::class)->executar('pg-1');

        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    }

    public function test_acha_a_cobranca_pelo_assinatura_gateway_id_quando_nao_ha_referencia(): void
    {
        $c = $this->cobrancaPendente();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-9', StatusDoPagamento::Aprovado, null, 10000, assinaturaGatewayId: 'pre-1'));

        app(ConciliarPagamento::class)->executar('pg-9');

        $this->assertSame(StatusDaCobranca::Paga, $c->refresh()->status);
    }

    public function test_ciclo_seguinte_do_cartao_cria_nova_cobranca_paga(): void
    {
        $c = $this->cobrancaPendente();
        $this->aprovado($c);
        app(ConciliarPagamento::class)->executar('pg-1');
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-2', StatusDoPagamento::Aprovado, null, 10000, assinaturaGatewayId: 'pre-1'));

        app(ConciliarPagamento::class)->executar('pg-2');

        $this->assertSame(2, Cobranca::query()->where('assinatura_id', $c->assinatura_id)->where('status', StatusDaCobranca::Paga)->count());
        $this->assertSame($this->hoje()->addMonthsNoOverflow(2)->toDateString(), $c->assinatura->refresh()->paga_ate?->toDateString());
    }

    public function test_renovacao_que_repete_a_referencia_da_primeira_cobranca_ainda_estende_o_periodo(): void
    {
        $c = $this->cobrancaPendente();
        $this->aprovado($c);
        app(ConciliarPagamento::class)->executar('pg-1');
        // o Mercado Pago pode copiar o external_reference do preapproval para cada pagamento recorrente
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-2', StatusDoPagamento::Aprovado, $c->id, 10000, assinaturaGatewayId: 'pre-1'));

        app(ConciliarPagamento::class)->executar('pg-2');

        $this->assertSame(2, Cobranca::query()->where('assinatura_id', $c->assinatura_id)->where('status', StatusDaCobranca::Paga)->count());
        $this->assertSame($this->hoje()->addMonthsNoOverflow(2)->toDateString(), $c->assinatura->refresh()->paga_ate?->toDateString());
    }

    public function test_renovacao_usa_o_valor_da_cobranca_anterior_e_nao_o_preco_atual_do_plano(): void
    {
        $c = $this->cobrancaPendente();
        $this->aprovado($c);
        app(ConciliarPagamento::class)->executar('pg-1');
        $this->plano->forceFill(['preco_mensal_centavos' => 12000])->save();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-2', StatusDoPagamento::Aprovado, null, 10000, assinaturaGatewayId: 'pre-1'));

        app(ConciliarPagamento::class)->executar('pg-2');

        $this->assertSame(2, Cobranca::query()->where('status', StatusDaCobranca::Paga)->count());
    }

    public function test_pagar_o_boleto_de_regularizacao_baixa_as_cobrancas_vencidas_e_o_tenant_nao_e_suspenso_de_novo(): void
    {
        $hoje = $this->hoje();
        $vencida = $this->cobrancaPendente(['status' => StatusDaCobranca::Vencida, 'vence_em' => $hoje->subDays(12)->toDateString()]);
        $vencida->assinatura->forceFill(['situacao' => StatusDaAssinatura::Ativa, 'paga_ate' => $hoje->subDays(12)->toDateString()])->save();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        $regularizacao = Cobranca::factory()->for($vencida->assinatura)->create();
        $regularizacao->forceFill(['vence_em' => $hoje->addDays(3)->toDateString()])->save();
        $this->aprovado($regularizacao);

        app(ConciliarPagamento::class)->executar('pg-1');
        $this->artisan('billing:aplicar-inadimplencia')->assertSuccessful();

        $this->assertSame(StatusDaCobranca::Cancelada, $vencida->refresh()->status);
        $this->assertSame(StatusDaCobranca::Paga, $regularizacao->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    }

    public function test_pagamento_de_assinatura_cancelada_nao_ativa_o_tenant(): void
    {
        $c = $this->cobrancaPendente();
        $c->assinatura->forceFill(['situacao' => StatusDaAssinatura::Cancelada])->save();
        $this->aprovado($c);

        app(ConciliarPagamento::class)->executar('pg-1');

        $this->assertSame(StatusDaCobranca::Paga, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao);
        $this->assertNull($c->assinatura->refresh()->paga_ate);
    }

    public function test_pagamento_sem_cobranca_nossa_e_ignorado(): void
    {
        $this->cobrancaPendente();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-x', StatusDoPagamento::Aprovado, 'desconhecida', 10000, assinaturaGatewayId: 'pre-desconhecida'));

        $this->assertNull(app(ConciliarPagamento::class)->executar('pg-x'));
        $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao);
    }
}
