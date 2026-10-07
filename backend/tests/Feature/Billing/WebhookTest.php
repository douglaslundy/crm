<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use App\Modules\Billing\Domain\Models\WebhookRecebido;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\Fixtures\GatewayDeCobrancaFake;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    private const SEGREDO = 's3cr3t';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCobranca();
        config(['billing.mercadopago.webhook_secret' => self::SEGREDO]);
    }

    /** @return array<string, string> */
    private function assinado(string $dataId, string $requestId = 'req-1', string $segredo = self::SEGREDO): array
    {
        $ts = time();
        $manifesto = 'id:'.strtolower($dataId).';request-id:'.$requestId.';ts:'.$ts.';';

        return ['x-signature' => 'ts='.$ts.',v1='.hash_hmac('sha256', $manifesto, $segredo), 'x-request-id' => $requestId];
    }

    /** @param  array<string, mixed>  $corpo */
    private function enviar(string $dataId, array $corpo, ?array $headers = null): TestResponse
    {
        return $this->postJson('/api/webhooks/mercadopago?data.id='.$dataId, $corpo, $headers ?? $this->assinado($dataId));
    }

    /** @return array<string, mixed> */
    private function evento(string $dataId, int $id = 777, string $type = 'payment'): array
    {
        return ['type' => $type, 'id' => $id, 'action' => 'payment.updated', 'data' => ['id' => $dataId]];
    }

    public function test_assinatura_invalida_da_401_e_nao_processa_nada(): void
    {
        $r = $this->enviar('pg-1', $this->evento('pg-1'), ['x-signature' => 'ts=1,v1=errado', 'x-request-id' => 'req-1']);

        $r->assertStatus(401);
        $this->assertSame(0, WebhookRecebido::query()->count());
        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_sem_headers_da_401(): void
    {
        $this->postJson('/api/webhooks/mercadopago?data.id=pg-1', $this->evento('pg-1'))->assertStatus(401);
        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_sem_segredo_configurado_recusa_tudo(): void
    {
        config(['billing.mercadopago.webhook_secret' => null]);

        $this->enviar('pg-1', $this->evento('pg-1'), $this->assinado('pg-1', 'req-1', ''))->assertStatus(401);
        $this->enviar('pg-1', $this->evento('pg-1'), $this->assinado('pg-1'))->assertStatus(401);
        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_pagamento_aprovado_pelo_gateway_ativa_o_tenant(): void
    {
        $c = $this->cobrancaPendente();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-1', StatusDoPagamento::Aprovado, $c->id, 10000));

        $this->enviar('pg-1', $this->evento('pg-1'))->assertOk();

        $this->assertSame(StatusDaCobranca::Paga, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
        $this->assertNotNull(WebhookRecebido::query()->firstOrFail()->processado_em);
    }

    public function test_corpo_dizendo_aprovado_com_gateway_pendente_nao_ativa(): void
    {
        $c = $this->cobrancaPendente();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-1', StatusDoPagamento::Pendente, $c->id, 10000));
        $corpo = $this->evento('pg-1') + ['status' => 'approved'];
        $corpo['data']['status'] = 'approved';

        $this->enviar('pg-1', $corpo)->assertOk();

        $this->assertSame(StatusDaCobranca::Pendente, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao);
    }

    public function test_evento_repetido_responde_200_e_nao_refaz(): void
    {
        $c = $this->cobrancaPendente();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-1', StatusDoPagamento::Aprovado, $c->id, 10000));

        $this->enviar('pg-1', $this->evento('pg-1', 900))->assertOk();
        $this->enviar('pg-1', $this->evento('pg-1', 900))->assertOk();

        $this->assertCount(1, array_filter($this->gateway->chamadas, fn (array $c): bool => $c[0] === 'buscarPagamento'));
        $this->assertSame(1, WebhookRecebido::query()->count());
    }

    public function test_tipo_que_nao_e_pagamento_e_ignorado_com_200(): void
    {
        $this->enviar('x-1', $this->evento('x-1', 5, 'plan'))->assertOk();

        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_falha_transitoria_do_gateway_da_503_e_permite_reenvio(): void
    {
        $c = $this->cobrancaPendente();
        $this->gateway->falharEm('buscarPagamento', new RuntimeException('mp fora'));

        $this->enviar('pg-1', $this->evento('pg-1', 321))->assertStatus(503);
        $this->assertNull(WebhookRecebido::query()->firstOrFail()->processado_em);

        $novo = new GatewayDeCobrancaFake;
        $novo->enfileirarPagamento(new ResultadoDePagamento('pg-1', StatusDoPagamento::Aprovado, $c->id, 10000));
        app()->instance(GatewayDeCobranca::class, $novo);

        $this->enviar('pg-1', $this->evento('pg-1', 321))->assertOk();
        $this->assertNotNull(WebhookRecebido::query()->firstOrFail()->processado_em);
        $this->assertSame(StatusDaCobranca::Paga, $c->refresh()->status);
    }

    public function test_valor_divergente_responde_200_e_nao_ativa(): void
    {
        $c = $this->cobrancaPendente();
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-1', StatusDoPagamento::Aprovado, $c->id, 9999));

        $this->enviar('pg-1', $this->evento('pg-1', 654))->assertOk();

        $this->assertSame(StatusDaCobranca::Pendente, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao);
    }
}
