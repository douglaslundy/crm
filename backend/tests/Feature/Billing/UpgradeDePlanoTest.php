<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\Actions\ConciliarPagamento;
use App\Modules\Billing\Application\Actions\PagarCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Gateway\DadosDoPix;
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
use RuntimeException;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class UpgradeDePlanoTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    private Assinatura $assinatura;

    private Plano $caro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'America/Sao_Paulo'));
        $this->montarCenarioCobranca();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();
        $limites = [];
        foreach (Recurso::cases() as $r) {
            $limites[$r->value] = Plano::ILIMITADO;
        }
        $this->caro = Plano::factory()->comLimites($limites)->create(['preco_mensal_centavos' => 16000, 'preco_anual_centavos' => 160000]);
        $this->assinatura = Assinatura::factory()->create(['plano_id' => $this->plano->id]);
        $this->assinatura->forceFill([
            'situacao' => StatusDaAssinatura::Ativa, 'metodo' => MetodoDePagamento::Cartao, 'gateway_assinatura_id' => 'pre-1',
            'paga_ate' => '2026-10-30', 'valor_ciclo_centavos' => 10000,
        ])->save();
    }

    private function upgradePendente(?Plano $destino = null, string $vence = '2026-10-16'): Cobranca
    {
        $c = new Cobranca;
        $c->forceFill([
            'assinatura_id' => $this->assinatura->id, 'tipo' => TipoDeCobranca::Upgrade, 'valor_centavos' => 3000, 'vence_em' => $vence,
            'status' => StatusDaCobranca::Pendente, 'metodo' => null, 'plano_destino_id' => ($destino ?? $this->caro)->id, 'plano_origem_id' => $this->plano->id, 'paga_ate_origem' => '2026-10-30',
        ])->save();

        return $c->refresh();
    }

    private function aprovar(Cobranca $c, string $id = 'pg-1'): void
    {
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento($id, StatusDoPagamento::Aprovado, $c->id, 3000));
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    private function chamadas(string $metodo): array
    {
        return array_values(array_filter($this->gateway->chamadas, fn (array $c): bool => $c[0] === $metodo));
    }

    public function test_pagar_o_upgrade_por_pix_pede_a_diferenca_e_nomeia_o_plano(): void
    {
        $c = $this->upgradePendente();

        $r = app(PagarCobranca::class)->executar($this->admin, $c->id, MetodoDePagamento::Pix, null);

        $this->assertSame(MetodoDePagamento::Pix, $r->metodo);
        $pedido = $this->chamadas('criarPix')[0][1][0];
        $this->assertInstanceOf(DadosDoPix::class, $pedido);
        $this->assertSame(3000, $pedido->valorCentavos);
        $this->assertStringContainsString($this->caro->nome, $pedido->descricao);
    }

    public function test_pagar_o_upgrade_por_cartao_aprovado_troca_o_plano_na_hora(): void
    {
        $c = $this->upgradePendente();
        $this->gateway->enfileirarCartao(new ResultadoDePagamento('car-1', StatusDoPagamento::Aprovado, $c->id, 3000));
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('car-1', StatusDoPagamento::Aprovado, $c->id, 3000));

        $r = app(PagarCobranca::class)->executar($this->admin, $c->id, MetodoDePagamento::Cartao, 'tok-1');

        $this->assertSame(StatusDaCobranca::Paga, $r->status);
        $this->assertSame($this->caro->id, $this->tenant->refresh()->plano_id);
    }

    public function test_upgrade_pago_troca_o_plano_sem_mexer_no_periodo(): void
    {
        $c = $this->upgradePendente();
        $this->assinatura->forceFill(['plano_proximo_id' => Plano::factory()->create()->id, 'plano_proximo_em' => '2026-10-30'])->save();
        $this->aprovar($c);

        app(ConciliarPagamento::class)->executar('pg-1');

        $c->refresh();
        $a = $this->assinatura->refresh();
        $this->assertSame(StatusDaCobranca::Paga, $c->status);
        $this->assertNull($c->periodo_inicio);
        $this->assertSame($this->caro->id, $this->tenant->refresh()->plano_id);
        $this->assertSame([$this->caro->id, 16000, '2026-10-30', null, null], [$a->plano_id, $a->valor_ciclo_centavos, $a->paga_ate?->toDateString(), $a->plano_proximo_id, $a->plano_proximo_em]);
        $this->assertSame([['atualizarAssinatura', ['pre-1', 16000]]], $this->chamadas('atualizarAssinatura'));
    }

    public function test_conciliar_duas_vezes_troca_e_atualiza_o_gateway_uma_vez(): void
    {
        $c = $this->upgradePendente();
        $this->aprovar($c);
        $this->aprovar($c);

        app(ConciliarPagamento::class)->executar('pg-1');
        app(ConciliarPagamento::class)->executar('pg-1');

        $this->assertCount(1, $this->chamadas('atualizarAssinatura'));
    }

    public function test_boleto_nao_chama_o_gateway_ao_trocar(): void
    {
        $this->assinatura->forceFill(['metodo' => MetodoDePagamento::Boleto])->save();
        $c = $this->upgradePendente();
        $this->aprovar($c);

        app(ConciliarPagamento::class)->executar('pg-1');

        $this->assertSame($this->caro->id, $this->tenant->refresh()->plano_id);
        $this->assertSame([], $this->chamadas('atualizarAssinatura'));
    }

    public function test_falha_do_gateway_ao_atualizar_o_valor_nao_desfaz_o_pagamento(): void
    {
        $c = $this->upgradePendente();
        $this->aprovar($c);
        $this->gateway->falharEm('atualizarAssinatura', new RuntimeException('caiu'));

        app(ConciliarPagamento::class)->executar('pg-1');

        $this->assertSame(StatusDaCobranca::Paga, $c->refresh()->status);
        $this->assertSame($this->caro->id, $this->tenant->refresh()->plano_id);
    }

    public function test_plano_destino_inativado_antes_do_pagamento_quita_sem_trocar_e_avisa(): void
    {
        $c = $this->upgradePendente();
        $this->caro->forceFill(['ativo' => false])->save();
        $this->aprovar($c);
        Log::spy();

        app(ConciliarPagamento::class)->executar('pg-1');

        $this->assertSame(StatusDaCobranca::Paga, $c->refresh()->status);
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_upgrade_nao_baixa_excedente_vencido_nem_reativa_conta_suspensa_com_outra_divida(): void
    {
        $excedente = $this->excedentePendente(['status' => StatusDaCobranca::Vencida], $this->assinatura);
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        $c = $this->upgradePendente();
        $this->aprovar($c);

        app(ConciliarPagamento::class)->executar('pg-1');

        $this->assertSame(StatusDaCobranca::Vencida, $excedente->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Suspensa, $this->tenant->refresh()->situacao);
    }

    public function test_upgrade_vencido_e_cancelado_sem_virar_inadimplencia(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'America/Sao_Paulo'));
        $upgrade = $this->upgradePendente(null, '2026-10-16');
        $mensalidade = Cobranca::factory()->for($this->assinatura)->create();
        $mensalidade->forceFill(['vence_em' => '2026-10-16'])->save();

        $this->artisan('billing:aplicar-inadimplencia')->assertSuccessful();

        $this->assertSame(StatusDaCobranca::Cancelada, $upgrade->refresh()->status);
        $this->assertSame(StatusDaCobranca::Vencida, $mensalidade->refresh()->status);
    }

    public function test_so_o_upgrade_vencido_nao_leva_a_inadimplente(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'America/Sao_Paulo'));
        $this->upgradePendente(null, '2026-10-16');

        $this->artisan('billing:aplicar-inadimplencia')->assertSuccessful();

        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    }
}
