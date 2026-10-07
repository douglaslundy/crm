<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\DadosDaAssinatura;
use App\Modules\Billing\Domain\Gateway\DadosDoBoleto;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use App\Modules\Billing\Infrastructure\MercadoPagoGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Só prova o formato do pedido e a leitura da resposta: o adaptador nunca falou com o Mercado Pago de verdade. */
class MercadoPagoGatewayTest extends TestCase
{
    private function gateway(?string $token = 'TEST-token'): MercadoPagoGateway
    {
        return new MercadoPagoGateway($token, 'https://mp.test', 'https://app.test/api/webhooks/mercadopago', 'https://app.test/assinatura');
    }

    private function boleto(string $documento = '11444777000161'): DadosDoBoleto
    {
        return new DadosDoBoleto('cob-1', 10050, 'Assinatura Plano (mensal)', 'dono@empresa.test', 'Empresa Teste', $documento, CarbonImmutable::parse('2026-10-10', 'America/Sao_Paulo'), 'idem-1');
    }

    public function test_criar_boleto_envia_o_pedido_esperado_e_le_a_resposta(): void
    {
        Http::fake(['mp.test/v1/payments' => Http::response([
            'id' => 123456, 'status' => 'pending', 'external_reference' => 'cob-1', 'transaction_amount' => 100.5,
            'transaction_details' => ['external_resource_url' => 'https://mp.test/boleto/1', 'digitable_line' => '1234.5678'],
        ], 201)]);

        $r = $this->gateway()->criarBoleto($this->boleto());

        Http::assertSent(function (Request $req): bool {
            $corpo = $req->data();

            return $req->url() === 'https://mp.test/v1/payments'
                && $req->method() === 'POST'
                && $req->hasHeader('Authorization', 'Bearer TEST-token')
                && $req->hasHeader('X-Idempotency-Key', 'idem-1')
                && $corpo['transaction_amount'] === 100.5
                && $corpo['payment_method_id'] === 'bolbradesco'
                && $corpo['external_reference'] === 'cob-1'
                && $corpo['notification_url'] === 'https://app.test/api/webhooks/mercadopago'
                && str_starts_with($corpo['date_of_expiration'], '2026-10-10T23:59:59')
                && $corpo['payer']['email'] === 'dono@empresa.test'
                && $corpo['payer']['first_name'] === 'Empresa Teste'
                && $corpo['payer']['identification'] === ['type' => 'CNPJ', 'number' => '11444777000161'];
        });
        $this->assertSame('123456', $r->id);
        $this->assertSame(StatusDoPagamento::Pendente, $r->status);
        $this->assertSame('cob-1', $r->referenciaExterna);
        $this->assertSame(10050, $r->valorCentavos);
        $this->assertSame('https://mp.test/boleto/1', $r->boletoUrl);
        $this->assertSame('1234.5678', $r->linhaDigitavel);
    }

    public function test_documento_de_11_digitos_vai_como_cpf(): void
    {
        Http::fake(['mp.test/*' => Http::response(['id' => 1, 'status' => 'pending', 'transaction_amount' => 100.5], 201)]);

        $this->gateway()->criarBoleto($this->boleto('52998224725'));

        Http::assertSent(fn (Request $req): bool => $req->data()['payer']['identification']['type'] === 'CPF');
    }

    /** @return array<string, array{string, StatusDoPagamento}> */
    public static function statusDoMp(): array
    {
        return [
            'approved' => ['approved', StatusDoPagamento::Aprovado],
            'pending' => ['pending', StatusDoPagamento::Pendente],
            'in_process' => ['in_process', StatusDoPagamento::Pendente],
            'authorized' => ['authorized', StatusDoPagamento::Pendente],
            'rejected' => ['rejected', StatusDoPagamento::Recusado],
            'cancelled' => ['cancelled', StatusDoPagamento::Cancelado],
            'refunded' => ['refunded', StatusDoPagamento::Cancelado],
            'charged_back' => ['charged_back', StatusDoPagamento::Cancelado],
            'expired' => ['expired', StatusDoPagamento::Vencido],
        ];
    }

    #[DataProvider('statusDoMp')]
    public function test_buscar_pagamento_mapeia_o_status(string $mp, StatusDoPagamento $esperado): void
    {
        Http::fake(['mp.test/v1/payments/99' => Http::response(['id' => 99, 'status' => $mp, 'external_reference' => 'cob-1', 'transaction_amount' => 100.1, 'metadata' => ['preapproval_id' => 'pre-7']], 200)]);

        $r = $this->gateway()->buscarPagamento('99');

        $this->assertSame($esperado, $r->status);
        $this->assertSame(10010, $r->valorCentavos, 'arredonda, nunca trunca (100.1 * 100 = 10009.999...)');
        $this->assertSame('pre-7', $r->assinaturaGatewayId);
    }

    public function test_status_desconhecido_vira_pendente_e_nunca_aprovado(): void
    {
        Http::fake(['mp.test/*' => Http::response(['id' => 1, 'status' => 'algo_novo', 'transaction_amount' => 1], 200)]);

        $this->assertSame(StatusDoPagamento::Pendente, $this->gateway()->buscarPagamento('1')->status);
    }

    public function test_criar_assinatura_de_cartao(): void
    {
        Http::fake(['mp.test/preapproval' => Http::response(['id' => 'pre-1', 'status' => 'authorized'], 201)]);

        $r = $this->gateway()->criarAssinaturaDeCartao(new DadosDaAssinatura('cob-1', 100000, 'Assinatura Plano (anual)', 12, 'dono@empresa.test', 'tok_1', 'idem-2'));

        Http::assertSent(function (Request $req): bool {
            $c = $req->data();

            return $req->url() === 'https://mp.test/preapproval' && $req->method() === 'POST'
                && $req->hasHeader('X-Idempotency-Key', 'idem-2')
                && $c['reason'] === 'Assinatura Plano (anual)' && $c['external_reference'] === 'cob-1'
                && $c['payer_email'] === 'dono@empresa.test' && $c['card_token_id'] === 'tok_1'
                && $c['auto_recurring'] === ['frequency' => 12, 'frequency_type' => 'months', 'transaction_amount' => 1000.0, 'currency_id' => 'BRL']
                && $c['back_url'] === 'https://app.test/assinatura' && $c['status'] === 'authorized';
        });
        $this->assertSame('pre-1', $r->id);
        $this->assertTrue($r->autorizada);
    }

    public function test_assinatura_nao_autorizada(): void
    {
        Http::fake(['mp.test/preapproval' => Http::response(['id' => 'pre-1', 'status' => 'pending'], 201)]);

        $this->assertFalse($this->gateway()->criarAssinaturaDeCartao(new DadosDaAssinatura('cob-1', 10000, 'x', 1, 'a@b.test', 'tok', 'i'))->autorizada);
    }

    public function test_cancelar_assinatura(): void
    {
        Http::fake(['mp.test/preapproval/pre-1' => Http::response(['id' => 'pre-1', 'status' => 'cancelled'], 200)]);

        $this->gateway()->cancelarAssinatura('pre-1');

        Http::assertSent(fn (Request $req): bool => $req->method() === 'PUT' && $req->url() === 'https://mp.test/preapproval/pre-1' && $req->data() === ['status' => 'cancelled']);
    }

    public function test_resposta_de_erro_vira_billing_exception_sem_vazar_o_corpo(): void
    {
        Http::fake(['mp.test/*' => Http::response(['message' => 'segredo interno do MP', 'cause' => [['description' => 'detalhe']]], 500)]);

        try {
            $this->gateway()->criarBoleto($this->boleto());
            $this->fail('deveria lançar');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_GATEWAY_FALHOU', $e->codigo());
            $this->assertSame(502, $e->status());
            $this->assertStringNotContainsString('segredo interno', $e->getMessage());
        }
    }

    public function test_falha_de_conexao_vira_billing_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(BillingException::class);

        $this->gateway()->buscarPagamento('1');
    }

    public function test_sem_token_configurado_lanca_antes_de_chamar(): void
    {
        Http::fake();

        try {
            $this->gateway(null)->buscarPagamento('1');
            $this->fail('deveria lançar');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_GATEWAY_NAO_CONFIGURADO', $e->codigo());
        }
        Http::assertNothingSent();
    }
}
