<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\DadosDaAssinatura;
use App\Modules\Billing\Domain\Gateway\DadosDoBoleto;
use App\Modules\Billing\Domain\Gateway\DadosDoPagamentoDeCartao;
use App\Modules\Billing\Domain\Gateway\DadosDoPix;
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

    private function cartao(): DadosDoPagamentoDeCartao
    {
        return new DadosDoPagamentoDeCartao('cob-9', 5000, 'Documentos excedentes', 'dono@empresa.test', 'Empresa Teste', '11444777000161', 'tok-1', 'idem-9');
    }

    private function pix(): DadosDoPix
    {
        return new DadosDoPix('cob-9', 5000, 'Documentos excedentes', 'dono@empresa.test', 'Empresa Teste', '52998224725', CarbonImmutable::parse('2026-10-10 12:00:00', 'America/Sao_Paulo'), 'idem-9');
    }

    public function test_cartao_avulso_envia_o_pedido_esperado_e_le_o_status(): void
    {
        Http::fake(['mp.test/v1/payments' => Http::response(['id' => 77, 'status' => 'approved', 'external_reference' => 'cob-9', 'transaction_amount' => 50.0], 201)]);

        $r = $this->gateway()->criarPagamentoDeCartao($this->cartao());

        Http::assertSent(function (Request $req): bool {
            $c = $req->data();

            return $req->url() === 'https://mp.test/v1/payments'
                && $req->hasHeader('X-Idempotency-Key', 'idem-9')
                && $c['transaction_amount'] === 50.0
                && $c['token'] === 'tok-1'
                && $c['installments'] === 1
                && $c['description'] === 'Documentos excedentes'
                && $c['external_reference'] === 'cob-9'
                && $c['notification_url'] === 'https://app.test/api/webhooks/mercadopago'
                && $c['payer']['email'] === 'dono@empresa.test'
                && $c['payer']['identification'] === ['type' => 'CNPJ', 'number' => '11444777000161']
                && ! isset($c['payment_method_id']);
        });
        $this->assertSame('77', $r->id);
        $this->assertSame(StatusDoPagamento::Aprovado, $r->status);
        $this->assertSame(5000, $r->valorCentavos);
    }

    public function test_cartao_avulso_recusado_vira_recusado(): void
    {
        Http::fake(['mp.test/*' => Http::response(['id' => 78, 'status' => 'rejected', 'transaction_amount' => 50.0], 201)]);

        $this->assertSame(StatusDoPagamento::Recusado, $this->gateway()->criarPagamentoDeCartao($this->cartao())->status);
    }

    public function test_pix_envia_o_pedido_esperado_e_le_o_qr_code(): void
    {
        Http::fake(['mp.test/v1/payments' => Http::response([
            'id' => 88, 'status' => 'pending', 'external_reference' => 'cob-9', 'transaction_amount' => 50.0,
            'date_of_expiration' => '2026-10-10T12:05:00.000-03:00',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => '000201PIX', 'qr_code_base64' => 'QVJDRA==']],
        ], 201)]);

        $r = $this->gateway()->criarPix($this->pix());

        Http::assertSent(function (Request $req): bool {
            $c = $req->data();

            return $req->hasHeader('X-Idempotency-Key', 'idem-9')
                && $c['payment_method_id'] === 'pix'
                && $c['transaction_amount'] === 50.0
                && $c['external_reference'] === 'cob-9'
                && str_starts_with($c['date_of_expiration'], '2026-10-10T12:00:00')
                && $c['payer']['identification'] === ['type' => 'CPF', 'number' => '52998224725']
                && ! isset($c['token']);
        });
        $this->assertSame(StatusDoPagamento::Pendente, $r->status);
        $this->assertSame('000201PIX', $r->pixCopiaECola);
        $this->assertSame('QVJDRA==', $r->pixQrCodeBase64);
        $this->assertSame('2026-10-10 12:05:00', $r->pixExpiraEm?->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
    }

    public function test_pix_sem_expiracao_na_resposta_usa_a_do_pedido(): void
    {
        Http::fake(['mp.test/*' => Http::response(['id' => 89, 'status' => 'pending', 'transaction_amount' => 50.0], 201)]);

        $r = $this->gateway()->criarPix($this->pix());

        $this->assertSame('2026-10-10 12:00:00', $r->pixExpiraEm?->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
        $this->assertNull($r->pixCopiaECola);
    }

    public function test_pagamento_avulso_com_erro_ou_sem_token_lanca_billing_exception(): void
    {
        Http::fake(['mp.test/*' => Http::response(['message' => 'segredo'], 500)]);
        foreach ([fn () => $this->gateway()->criarPix($this->pix()), fn () => $this->gateway()->criarPagamentoDeCartao($this->cartao())] as $chamada) {
            try {
                $chamada();
                $this->fail('deveria falhar');
            } catch (BillingException $e) {
                $this->assertSame('BILLING_GATEWAY_FALHOU', $e->codigo());
            }
        }

        try {
            $this->gateway(null)->criarPix($this->pix());
            $this->fail('deveria falhar');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_GATEWAY_NAO_CONFIGURADO', $e->codigo());
        }
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

    public function test_atualizar_assinatura_envia_o_novo_valor_da_recorrencia(): void
    {
        Http::fake(['mp.test/preapproval/pre-7' => Http::response(['id' => 'pre-7', 'status' => 'authorized'], 200)]);

        $this->gateway()->atualizarAssinatura('pre-7', 15050);

        Http::assertSent(function (Request $req): bool {
            return $req->url() === 'https://mp.test/preapproval/pre-7'
                && $req->method() === 'PUT'
                && $req->hasHeader('Authorization', 'Bearer TEST-token')
                && $req->data()['auto_recurring'] === ['transaction_amount' => 150.5, 'currency_id' => 'BRL'];
        });
    }

    public function test_atualizar_assinatura_com_erro_ou_sem_token_lanca_billing_exception(): void
    {
        Http::fake(['mp.test/*' => Http::response(['message' => 'segredo'], 500)]);
        try {
            $this->gateway()->atualizarAssinatura('pre-7', 100);
            $this->fail('deveria falhar');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_GATEWAY_FALHOU', $e->codigo());
        }

        try {
            $this->gateway(null)->atualizarAssinatura('pre-7', 100);
            $this->fail('deveria falhar');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_GATEWAY_NAO_CONFIGURADO', $e->codigo());
        }
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
