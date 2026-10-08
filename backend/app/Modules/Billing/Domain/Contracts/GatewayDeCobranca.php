<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Contracts;

use App\Modules\Billing\Domain\Gateway\DadosDaAssinatura;
use App\Modules\Billing\Domain\Gateway\DadosDoBoleto;
use App\Modules\Billing\Domain\Gateway\DadosDoPagamentoDeCartao;
use App\Modules\Billing\Domain\Gateway\DadosDoPix;
use App\Modules\Billing\Domain\Gateway\ResultadoDeAssinatura;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;

/** Fronteira com o Mercado Pago. Falha de comunicação ou recusa lança `BillingException`. */
interface GatewayDeCobranca
{
    /** Assinatura recorrente (`preapproval`) com o token do cartão: o gateway cobra o primeiro ciclo e os seguintes. */
    public function criarAssinaturaDeCartao(DadosDaAssinatura $dados): ResultadoDeAssinatura;

    public function criarBoleto(DadosDoBoleto $dados): ResultadoDePagamento;

    /** Pagamento avulso no cartão (1x): o resultado já traz o status (aprovado ou recusado). */
    public function criarPagamentoDeCartao(DadosDoPagamentoDeCartao $dados): ResultadoDePagamento;

    public function criarPix(DadosDoPix $dados): ResultadoDePagamento;

    /** Única fonte de status confiável de um pagamento. */
    public function buscarPagamento(string $id): ResultadoDePagamento;

    public function cancelarAssinatura(string $id): void;

    /** Muda o valor das próximas cobranças de uma assinatura de cartão (troca de plano). */
    public function atualizarAssinatura(string $id, int $valorCentavos): void;
}
