<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\Actions\AplicarDowngrade;
use App\Modules\Billing\Application\Actions\CancelarAssinatura;
use App\Modules\Billing\Application\Actions\CancelarDowngrade;
use App\Modules\Billing\Application\Actions\ConciliarPagamento;
use App\Modules\Billing\Application\Actions\TrocarDePlano;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

/** Achados da revisão final da F5b2: pagamento obsoleto, boleto já emitido, renovação do cartão e downgrade em conta atrasada. */
class TrocaDePlanoRevisaoTest extends TestCase
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

    private function plano(int $mensal): Plano
    {
        $limites = [];
        foreach (Recurso::cases() as $r) {
            $limites[$r->value] = Plano::ILIMITADO;
        }

        return Plano::factory()->comLimites($limites)->create(['preco_mensal_centavos' => $mensal, 'preco_anual_centavos' => $mensal * 10]);
    }

    private function upgrade(string $status = 'PENDENTE', ?string $planoOrigem = null, string $pagaAteOrigem = '2026-10-30'): Cobranca
    {
        $c = new Cobranca;
        $c->forceFill([
            'assinatura_id' => $this->assinatura->id, 'tipo' => TipoDeCobranca::Upgrade, 'valor_centavos' => 3000, 'vence_em' => '2026-10-16',
            'status' => StatusDaCobranca::from($status), 'metodo' => null, 'plano_destino_id' => $this->caro->id,
            'plano_origem_id' => $planoOrigem ?? $this->plano->id, 'paga_ate_origem' => $pagaAteOrigem,
        ])->save();

        return $c->refresh();
    }

    private function pagar(Cobranca $c): void
    {
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-1', StatusDoPagamento::Aprovado, $c->id, 3000));
        app(ConciliarPagamento::class)->executar('pg-1');
    }

    private function codigoDoErro(callable $acao): string
    {
        try {
            $acao();
        } catch (BillingException $e) {
            return $e->codigo();
        }
        $this->fail('deveria lançar BillingException');
    }

    public function test_upgrade_cancelado_que_acaba_pago_quita_sem_trocar_o_plano_e_avisa(): void
    {
        $c = $this->upgrade('CANCELADA');
        Log::spy();

        $this->pagar($c);

        $this->assertSame(StatusDaCobranca::Paga, $c->refresh()->status);
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id);
        $this->assertSame([], array_values(array_filter($this->gateway->chamadas, fn (array $x): bool => $x[0] === 'atualizarAssinatura')));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_upgrade_pago_depois_de_cancelada_a_assinatura_nao_troca_o_plano(): void
    {
        $c = $this->upgrade();
        $this->assinatura->forceFill(['situacao' => StatusDaAssinatura::Cancelada])->save();

        $this->pagar($c);

        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id);
    }

    public function test_upgrade_obsoleto_por_outra_troca_ou_renovacao_nao_troca_o_plano(): void
    {
        $outro = $this->plano(8000);
        $c = $this->upgrade('PENDENTE', $outro->id);
        Log::spy();
        $this->pagar($c);
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id, 'o plano de origem mudou depois do pedido');

        $this->assinatura->forceFill(['plano_id' => $outro->id])->save();
        $d = $this->upgrade('PENDENTE', $outro->id, '2026-09-30');
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-2', StatusDoPagamento::Aprovado, $d->id, 3000));
        app(ConciliarPagamento::class)->executar('pg-2');
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id, 'a assinatura foi renovada depois do pedido');
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_assinatura_de_boleto_com_boleto_do_proximo_ciclo_emitido_nao_troca_nem_cancela(): void
    {
        $this->assinatura->forceFill(['metodo' => MetodoDePagamento::Boleto, 'plano_proximo_id' => $this->barato->id, 'plano_proximo_em' => '2026-10-30'])->save();
        $boleto = new Cobranca;
        $boleto->forceFill([
            'assinatura_id' => $this->assinatura->id, 'tipo' => TipoDeCobranca::Assinatura, 'valor_centavos' => 5000, 'vence_em' => '2026-10-28',
            'status' => StatusDaCobranca::Pendente, 'metodo' => MetodoDePagamento::Boleto, 'gateway_pagamento_id' => 'bol-1',
        ])->save();

        $this->assertSame('BILLING_TROCA_BOLETO_EMITIDO', $this->codigoDoErro(fn () => app(TrocarDePlano::class)->executar($this->admin, $this->caro->id)));
        $this->assertSame('BILLING_TROCA_BOLETO_EMITIDO', $this->codigoDoErro(fn () => app(CancelarDowngrade::class)->executar($this->admin)));
        $this->assertSame($this->barato->id, $this->assinatura->refresh()->plano_proximo_id);
    }

    public function test_downgrade_e_aplicado_mesmo_com_a_conta_inadimplente(): void
    {
        $this->assinatura->forceFill(['plano_proximo_id' => $this->barato->id, 'plano_proximo_em' => '2026-10-15'])->save();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Inadimplente])->save();

        $this->assertTrue(app(AplicarDowngrade::class)->executar($this->assinatura->id));

        $this->assertSame($this->barato->id, $this->tenant->refresh()->plano_id);
        $this->assertSame(5000, $this->assinatura->refresh()->valor_ciclo_centavos);
    }

    public function test_renovacao_do_cartao_aceita_o_valor_anterior_quando_o_gateway_ficou_para_tras(): void
    {
        $this->assinatura->forceFill(['valor_ciclo_centavos' => 16000, 'paga_ate' => '2026-10-15'])->save();
        $paga = new Cobranca;
        $paga->forceFill([
            'assinatura_id' => $this->assinatura->id, 'tipo' => TipoDeCobranca::Assinatura, 'valor_centavos' => 10000, 'vence_em' => '2026-09-15',
            'status' => StatusDaCobranca::Paga, 'metodo' => MetodoDePagamento::Cartao, 'gateway_pagamento_id' => 'pg-0', 'pago_em' => now()->subDays(30),
        ])->save();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-2', StatusDoPagamento::Aprovado, null, 10000, assinaturaGatewayId: 'pre-1'));

        app(ConciliarPagamento::class)->executar('pg-2');

        $this->assertSame(1, Cobranca::query()->where('gateway_pagamento_id', 'pg-2')->where('valor_centavos', 10000)->where('status', StatusDaCobranca::Paga->value)->count());
    }

    public function test_renovacao_com_valor_desconhecido_continua_divergente(): void
    {
        $this->assinatura->forceFill(['valor_ciclo_centavos' => 16000, 'paga_ate' => '2026-10-15'])->save();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-3', StatusDoPagamento::Aprovado, null, 7777, assinaturaGatewayId: 'pre-1'));

        try {
            app(ConciliarPagamento::class)->executar('pg-3');
            $this->fail('deveria divergir');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_VALOR_DIVERGENTE', $e->codigo());
        }
    }

    public function test_cancelar_a_assinatura_limpa_o_downgrade_agendado(): void
    {
        $this->assinatura->forceFill(['plano_proximo_id' => $this->barato->id, 'plano_proximo_em' => '2026-10-30'])->save();

        app(CancelarAssinatura::class)->executar($this->admin);

        $a = $this->assinatura->refresh();
        $this->assertSame([null, null], [$a->plano_proximo_id, $a->plano_proximo_em]);
    }

    public function test_conta_atrasada_consegue_cancelar_o_downgrade_pela_api(): void
    {
        $this->assinatura->forceFill(['plano_proximo_id' => $this->barato->id, 'plano_proximo_em' => '2026-10-30'])->save();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Inadimplente])->save();

        $this->spaComo($this->admin)->deleteJson('/api/app/assinatura/trocar-plano')->assertOk()->assertJsonPath('data.plano_proximo_id', null);
    }
}
