<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Gateway\DadosDaAssinatura;
use App\Modules\Billing\Domain\Gateway\DadosDoBoleto;
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

    /** @var array<string, Throwable> */
    private array $falhas = [];

    private int $sequencia = 0;

    public function enfileirarPagamento(ResultadoDePagamento ...$r): void
    {
        array_push($this->pagamentos, ...$r);
    }

    public function enfileirarBoleto(ResultadoDePagamento ...$r): void
    {
        array_push($this->boletos, ...$r);
    }

    public function falharEm(string $metodo, Throwable $erro): void
    {
        $this->falhas[$metodo] = $erro;
    }

    public function criarAssinaturaDeCartao(DadosDaAssinatura $dados): ResultadoDeAssinatura
    {
        $this->registrar('criarAssinaturaDeCartao', [$dados]);

        return new ResultadoDeAssinatura('pre-'.++$this->sequencia, true);
    }

    public function criarBoleto(DadosDoBoleto $dados): ResultadoDePagamento
    {
        $this->registrar('criarBoleto', [$dados]);

        return array_shift($this->boletos) ?? new ResultadoDePagamento(
            'bol-'.++$this->sequencia, StatusDoPagamento::Pendente, $dados->cobrancaId, $dados->valorCentavos,
            boletoUrl: 'https://mp.test/boleto/'.$this->sequencia, linhaDigitavel: '00000.00000 00000.000000',
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

    /** @param list<mixed> $argumentos */
    private function registrar(string $metodo, array $argumentos): void
    {
        $this->chamadas[] = [$metodo, $argumentos];
        if (isset($this->falhas[$metodo])) {
            throw $this->falhas[$metodo];
        }
    }
}
