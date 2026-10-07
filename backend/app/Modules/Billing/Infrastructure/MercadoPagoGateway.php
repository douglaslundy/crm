<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\DadosDaAssinatura;
use App\Modules\Billing\Domain\Gateway\DadosDoBoleto;
use App\Modules\Billing\Domain\Gateway\ResultadoDeAssinatura;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adaptador do Mercado Pago. NUNCA foi exercitado contra o Mercado Pago de verdade: o formato dos pedidos e das
 * respostas vem da documentação e está coberto só por testes com `Http::fake()`. A conferir no sandbox (roteiro em
 * `docs/cobranca.md`): `transaction_details.digitable_line` e `external_resource_url` do boleto, `metadata.preapproval_id`
 * nos pagamentos do `preapproval` e se o `preapproval` aceita boleto.
 */
final class MercadoPagoGateway implements GatewayDeCobranca
{
    public function __construct(
        private readonly ?string $accessToken,
        private readonly string $baseUrl,
        private readonly string $notificationUrl,
        private readonly string $backUrl,
    ) {}

    public function criarAssinaturaDeCartao(DadosDaAssinatura $dados): ResultadoDeAssinatura
    {
        $resposta = $this->enviar('POST', '/preapproval', [
            'reason' => $dados->descricao,
            'external_reference' => $dados->cobrancaId,
            'payer_email' => $dados->emailDoPagador,
            'card_token_id' => $dados->tokenDoCartao,
            'auto_recurring' => [
                'frequency' => $dados->meses,
                'frequency_type' => 'months',
                'transaction_amount' => $this->reais($dados->valorCentavos),
                'currency_id' => 'BRL',
            ],
            'back_url' => $this->backUrl,
            'status' => 'authorized',
        ], $dados->idempotencia);

        return new ResultadoDeAssinatura((string) $resposta->json('id'), $resposta->json('status') === 'authorized');
    }

    public function criarBoleto(DadosDoBoleto $dados): ResultadoDePagamento
    {
        $documento = (string) preg_replace('/\D/', '', $dados->documento);
        $resposta = $this->enviar('POST', '/v1/payments', [
            'transaction_amount' => $this->reais($dados->valorCentavos),
            'description' => $dados->descricao,
            'payment_method_id' => 'bolbradesco',
            'external_reference' => $dados->cobrancaId,
            'date_of_expiration' => $dados->venceEm->endOfDay()->format('Y-m-d\TH:i:s.vP'),
            'notification_url' => $this->notificationUrl,
            'payer' => [
                'email' => $dados->emailDoPagador,
                'first_name' => $dados->nomeDoPagador,
                'identification' => ['type' => strlen($documento) > 11 ? 'CNPJ' : 'CPF', 'number' => $documento],
            ],
        ], $dados->idempotencia);

        return $this->pagamento($resposta);
    }

    public function buscarPagamento(string $id): ResultadoDePagamento
    {
        return $this->pagamento($this->enviar('GET', '/v1/payments/'.rawurlencode($id)));
    }

    public function cancelarAssinatura(string $id): void
    {
        $this->enviar('PUT', '/preapproval/'.rawurlencode($id), ['status' => 'cancelled']);
    }

    private function pagamento(Response $resposta): ResultadoDePagamento
    {
        $referencia = $resposta->json('external_reference');
        $assinatura = $resposta->json('metadata.preapproval_id');
        $linha = $resposta->json('transaction_details.digitable_line') ?? $resposta->json('barcode.content');
        $url = $resposta->json('transaction_details.external_resource_url');

        return new ResultadoDePagamento(
            (string) $resposta->json('id'),
            $this->status((string) $resposta->json('status')),
            is_string($referencia) && $referencia !== '' ? $referencia : null,
            (int) round(((float) $resposta->json('transaction_amount')) * 100),
            is_string($assinatura) && $assinatura !== '' ? $assinatura : null,
            is_string($url) ? $url : null,
            is_string($linha) ? $linha : null,
        );
    }

    /** Status desconhecido é pendente, nunca aprovado: só `approved` libera o acesso. */
    private function status(string $mp): StatusDoPagamento
    {
        return match ($mp) {
            'approved' => StatusDoPagamento::Aprovado,
            'rejected' => StatusDoPagamento::Recusado,
            'cancelled', 'refunded', 'charged_back' => StatusDoPagamento::Cancelado,
            'expired' => StatusDoPagamento::Vencido,
            default => StatusDoPagamento::Pendente,
        };
    }

    private function reais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    /** @param array<string, mixed> $corpo */
    private function enviar(string $metodo, string $caminho, array $corpo = [], ?string $idempotencia = null): Response
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            throw new BillingException('BILLING_GATEWAY_NAO_CONFIGURADO', 'A cobrança não está configurada nesta instalação (falta o token do Mercado Pago).', 503);
        }

        try {
            $resposta = $this->http($idempotencia)->send($metodo, $caminho, $corpo === [] ? [] : ['json' => $corpo]);
        } catch (ConnectionException $e) {
            Log::warning('Mercado Pago: falha de conexão', ['caminho' => $caminho, 'erro' => $e->getMessage()]);
            throw $this->falha();
        }
        if ($resposta->failed()) {
            // O corpo pode ter dados do pagador: só o status e a mensagem curta vão para o log.
            Log::warning('Mercado Pago: resposta de erro', ['caminho' => $caminho, 'status' => $resposta->status(), 'mensagem' => $resposta->json('message')]);
            throw $this->falha();
        }

        return $resposta;
    }

    private function http(?string $idempotencia): PendingRequest
    {
        $http = Http::withToken((string) $this->accessToken)->baseUrl($this->baseUrl)->acceptJson()->timeout(20);

        return $idempotencia === null ? $http : $http->withHeaders(['X-Idempotency-Key' => $idempotencia]);
    }

    private function falha(): BillingException
    {
        return new BillingException('BILLING_GATEWAY_FALHOU', 'Não foi possível falar com o Mercado Pago. Tente novamente.', 502);
    }
}
