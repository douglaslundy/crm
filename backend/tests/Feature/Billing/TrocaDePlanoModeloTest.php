<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\Actions\ConciliarPagamento;
use App\Modules\Billing\Application\Actions\IniciarAssinatura;
use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Billing\Http\Resources\AssinaturaDeCobrancaResource;
use App\Modules\Billing\Http\Resources\CobrancaResource;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class TrocaDePlanoModeloTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCobranca();
    }

    private function upgrade(Plano $destino, string $assinaturaId): Cobranca
    {
        $c = new Cobranca;
        $c->forceFill([
            'assinatura_id' => $assinaturaId, 'tipo' => TipoDeCobranca::Upgrade, 'valor_centavos' => 1500, 'vence_em' => '2026-11-10',
            'status' => StatusDaCobranca::Pendente, 'metodo' => null, 'plano_destino_id' => $destino->id,
        ])->save();

        return $c->refresh();
    }

    public function test_upgrade_nasce_com_plano_destino_e_nao_estende_periodo(): void
    {
        $destino = Plano::factory()->create();
        $a = $this->excedentePendente()->assinatura;

        $c = $this->upgrade($destino, $a->id);

        $this->assertSame($destino->id, $c->plano_destino_id);
        $this->assertFalse(TipoDeCobranca::Upgrade->estendePeriodo());
    }

    public function test_o_banco_aceita_so_um_upgrade_pendente_por_assinatura(): void
    {
        $destino = Plano::factory()->create();
        $a = $this->excedentePendente()->assinatura;
        $primeiro = $this->upgrade($destino, $a->id);

        try {
            $this->upgrade($destino, $a->id);
            $this->fail('deveria recusar o segundo upgrade pendente');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $primeiro->forceFill(['status' => StatusDaCobranca::Cancelada])->save();
        $this->assertNotNull($this->upgrade($destino, $a->id)->id);
    }

    public function test_resources_trazem_os_campos_novos(): void
    {
        $destino = Plano::factory()->create();
        $a = $this->excedentePendente()->assinatura;
        $a->forceFill(['plano_proximo_id' => $destino->id, 'plano_proximo_em' => '2026-12-01'])->save();
        $c = $this->upgrade($destino, $a->id);

        $dadosA = (new AssinaturaDeCobrancaResource($a->refresh()))->resolve(request());
        $dadosC = (new CobrancaResource($c))->resolve(request());

        $this->assertSame($destino->id, $dadosA['plano_proximo_id']);
        $this->assertSame('2026-12-01', $dadosA['plano_proximo_em']);
        $this->assertSame($destino->id, $dadosC['plano_destino_id']);
    }

    public function test_valor_do_ciclo_usa_o_gravado_ou_o_preco_do_plano(): void
    {
        $a = $this->excedentePendente()->assinatura;
        $this->assertNull($a->valor_ciclo_centavos);
        $this->assertSame(10000, $a->valorDoCiclo());

        $a->forceFill(['valor_ciclo_centavos' => 8000])->save();
        $this->assertSame(8000, $a->refresh()->valorDoCiclo());
    }

    public function test_checkout_grava_o_valor_do_ciclo(): void
    {
        foreach ([[CicloDeCobranca::Mensal, MetodoDePagamento::Boleto, null, 10000], [CicloDeCobranca::Anual, MetodoDePagamento::Cartao, 'tok_1', 100000]] as [$ciclo, $metodo, $token, $esperado]) {
            $r = app(IniciarAssinatura::class)->executar($this->admin, $this->plano->id, $ciclo, $metodo, $token);
            $this->assertSame($esperado, $r->assinatura->valor_ciclo_centavos);
            $r->assinatura->forceFill(['situacao' => StatusDaAssinatura::Cancelada])->save();
        }
    }

    public function test_renovacao_do_cartao_nao_usa_o_valor_de_excedente_ja_pago(): void
    {
        $mensalidade = $this->cobrancaPendente();
        $mensalidade->forceFill(['status' => StatusDaCobranca::Paga, 'pago_em' => now()->subDays(30), 'gateway_pagamento_id' => 'pg-0'])->save();
        $mensalidade->assinatura->forceFill(['situacao' => StatusDaAssinatura::Ativa, 'paga_ate' => today()->toDateString()])->save();
        $excedente = $this->excedentePendente([], $mensalidade->assinatura);
        $excedente->forceFill(['status' => StatusDaCobranca::Paga, 'pago_em' => now(), 'gateway_pagamento_id' => 'pg-e'])->save();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-2', StatusDoPagamento::Aprovado, null, 10000, assinaturaGatewayId: 'pre-1'));

        app(ConciliarPagamento::class)->executar('pg-2');

        $this->assertSame(2, Cobranca::query()->where('tipo', TipoDeCobranca::Assinatura->value)->where('status', StatusDaCobranca::Paga->value)->count());
    }

    public function test_renovacao_usa_o_valor_do_ciclo_gravado_quando_existe(): void
    {
        $mensalidade = $this->cobrancaPendente();
        $mensalidade->forceFill(['status' => StatusDaCobranca::Paga, 'pago_em' => now()->subDays(30), 'gateway_pagamento_id' => 'pg-0'])->save();
        $mensalidade->assinatura->forceFill(['situacao' => StatusDaAssinatura::Ativa, 'paga_ate' => today()->toDateString(), 'valor_ciclo_centavos' => 12345])->save();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-2', StatusDoPagamento::Aprovado, null, 12345, assinaturaGatewayId: 'pre-1'));

        app(ConciliarPagamento::class)->executar('pg-2');

        $this->assertSame(1, Cobranca::query()->where('valor_centavos', 12345)->where('status', StatusDaCobranca::Paga->value)->count());
    }

    public function test_regularizacao_por_boleto_nao_usa_o_valor_de_excedente_pago(): void
    {
        $mensalidade = $this->cobrancaPendente();
        $mensalidade->forceFill(['status' => StatusDaCobranca::Paga, 'pago_em' => now()->subDays(40), 'gateway_pagamento_id' => 'pg-0'])->save();
        $mensalidade->assinatura->forceFill(['situacao' => StatusDaAssinatura::Ativa, 'paga_ate' => today()->subDays(5)->toDateString()])->save();
        $excedente = $this->excedentePendente([], $mensalidade->assinatura);
        $excedente->forceFill(['status' => StatusDaCobranca::Paga, 'pago_em' => now(), 'gateway_pagamento_id' => 'pg-e'])->save();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Inadimplente])->save();
        $this->admin->unsetRelation('tenant');

        $r = app(IniciarAssinatura::class)->executar($this->admin, $this->plano->id, CicloDeCobranca::Mensal, MetodoDePagamento::Boleto, null);

        $this->assertSame(10000, $r->cobranca->valor_centavos);
    }
}
