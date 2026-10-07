<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Vendas;

use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\RateioDeDesconto;

/**
 * Grava os itens das duas notas da venda e reparte o desconto geral entre elas. Duas passadas: a primeira (sem
 * desconto) calcula os subtotais pelas mesmas contas da nota comum; a segunda grava o desconto de cada uma.
 * Chamar dentro de uma transação.
 */
final class GravadorDaVenda
{
    public function __construct(private readonly CriarRascunhoDeNota $notas) {}

    /**
     * @param  array<string, mixed>  $dados
     * @return array{subtotal: int, desconto: int, total: int}
     */
    public function gravar(Nota $produtos, Nota $servicos, array $dados): array
    {
        $comum = [
            'cliente_id' => $dados['cliente_id'], 'consumidor_final' => null,
            'informacoes_complementares' => $dados['informacoes_complementares'] ?? null,
        ];
        $paraProdutos = $comum + ['itens' => $dados['itens_produtos'], 'pagamentos' => $dados['pagamentos']];
        $paraServicos = $comum + ['itens' => $dados['itens_servicos'], 'pagamentos' => []];

        $this->notas->preencher($produtos, $paraProdutos + ['desconto_centavos' => 0]);
        $this->notas->preencher($servicos, $paraServicos + ['desconto_centavos' => 0]);

        $subtotais = [$produtos->refresh()->subtotal_centavos, $servicos->refresh()->subtotal_centavos];
        $desconto = (int) $dados['desconto_centavos'];
        if ($desconto >= array_sum($subtotais)) {
            throw new EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', 'O desconto da venda deve ser menor que o total dos itens.');
        }
        [$descontoProdutos, $descontoServicos] = RateioDeDesconto::ratear($desconto, $subtotais);

        $this->notas->preencher($produtos, $paraProdutos + ['desconto_centavos' => $descontoProdutos]);
        $this->notas->preencher($servicos, $paraServicos + ['desconto_centavos' => $descontoServicos]);
        $produtos->refresh();
        $servicos->refresh();

        return [
            'subtotal' => $produtos->subtotal_centavos + $servicos->subtotal_centavos,
            'desconto' => $produtos->desconto_centavos + $servicos->desconto_centavos,
            'total' => $produtos->total_centavos + $servicos->total_centavos,
        ];
    }
}
