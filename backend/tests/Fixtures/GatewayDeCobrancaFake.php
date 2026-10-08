<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Gateway\DadosDaAssinatura;
use App\Modules\Billing\Domain\Gateway\DadosDoBoleto;
use App\Modules\Billing\Domain\Gateway\DadosDoPagamentoDeCartao;
use App\Modules\Billing\Domain\Gateway\DadosDoPix;
use App\Modules\Billing\Domain\Gateway\ResultadoDeAssinatura;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use Throwable;

final class GatewayDeCobrancaFake implements GatewayDeCobranca
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $chamadas = [];

    /** @var list<ResultadoDePagamento> */
    private array $pagamentos = [];

    /** @var list<ResultadoDePagamento> */
    private array $boletos = [];

    /** @var list<ResultadoDePagamento> */
    private array $cartoes = [];

    /** @var list<ResultadoDePagamento> */
    private array $pixes = [];

    /** @var array<string, Throwable> */
    private array $falhas = [];

    /** Resposta do `preapproval`: false simula uma assinatura criada no gateway mas não autorizada. */
    public bool $autorizar = true;

    private int $sequencia = 0;

    public function enfileirarPagamento(ResultadoDePagamento ...$r): void
    {
        array_push($this->pagamentos, ...$r);
    }

    public function enfileirarBoleto(ResultadoDePagamento ...$r): void
    {
        array_push($this->boletos, ...$r);
    }

    public function enfileirarCartao(ResultadoDePagamento ...$r): void
    {
        array_push($this->cartoes, ...$r);
    }

    public function enfileirarPix(ResultadoDePagamento ...$r): void
    {
        array_push($this->pixes, ...$r);
    }

    public function falharEm(string $metodo, Throwable $erro): void
    {
        $this->falhas[$metodo] = $erro;
    }

    public function criarAssinaturaDeCartao(DadosDaAssinatura $dados): ResultadoDeAssinatura
    {
        $this->registrar('criarAssinaturaDeCartao', [$dados]);

        return new ResultadoDeAssinatura('pre-'.++$this->sequencia, $this->autorizar);
    }

    public function criarBoleto(DadosDoBoleto $dados): ResultadoDePagamento
    {
        $this->registrar('criarBoleto', [$dados]);

        return array_shift($this->boletos) ?? new ResultadoDePagamento(
            'bol-'.++$this->sequencia, StatusDoPagamento::Pendente, $dados->cobrancaId, $dados->valorCentavos,
            boletoUrl: 'https://mp.test/boleto/'.$this->sequencia, linhaDigitavel: '00000.00000 00000.000000',
        );
    }

    public function criarPagamentoDeCartao(DadosDoPagamentoDeCartao $dados): ResultadoDePagamento
    {
        $this->registrar('criarPagamentoDeCartao', [$dados]);

        return array_shift($this->cartoes) ?? new ResultadoDePagamento(
            'car-'.++$this->sequencia, StatusDoPagamento::Aprovado, $dados->cobrancaId, $dados->valorCentavos,
        );
    }

    public function criarPix(DadosDoPix $dados): ResultadoDePagamento
    {
        $this->registrar('criarPix', [$dados]);

        return array_shift($this->pixes) ?? new ResultadoDePagamento(
            'pix-'.++$this->sequencia, StatusDoPagamento::Pendente, $dados->cobrancaId, $dados->valorCentavos,
            pixCopiaECola: '000201PIXFAKE', pixQrCodeBase64: 'QVJDRA==', pixExpiraEm: $dados->expiraEm,
        );
    }

    public function buscarPagamento(string $id): ResultadoDePagamento
    {
        $this->registrar('buscarPagamento', [$id]);

        return array_shift($this->pagamentos) ?? new ResultadoDePagamento($id, StatusDoPagamento::Pendente, null, 0);
    }

    public function cancelarAssinatura(string $id): void
    {
        $this->registrar('cancelarAssinatura', [$id]);
    }

    public function atualizarAssinatura(string $id, int $valorCentavos): void
    {
        $this->registrar('atualizarAssinatura', [$id, $valorCentavos]);
    }

    /** @param list<mixed> $argumentos */
    private function registrar(string $metodo, array $argumentos): void
    {
        $this->chamadas[] = [$metodo, $argumentos];
        if (isset($this->falhas[$metodo])) {
            throw $this->falhas[$metodo];
        }
    }
}
