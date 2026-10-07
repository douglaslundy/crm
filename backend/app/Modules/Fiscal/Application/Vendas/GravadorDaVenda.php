<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Vendas;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Exceptions\VendaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Fiscal\Domain\RateioDeDesconto;

/**
 * Grava os itens das duas notas da venda e reparte o desconto geral entre elas. Duas passadas: a primeira (sem
 * desconto) calcula os subtotais pelas mesmas contas da nota comum; a segunda grava o desconto de cada uma.
 * Chamar dentro de uma transação.
 */
final class GravadorDaVenda
{
    public function __construct(private readonly CriarRascunhoDeNota $notas) {}

    /** @param array<string, mixed> $dados */
    public static function exigirOsDoisTipos(array $dados): void
    {
        if (($dados['itens_produtos'] ?? []) === [] || ($dados['itens_servicos'] ?? []) === []) {
            throw new EmissaoBloqueadaException('FISCAL_VENDA_MISTA_INCOMPLETA', 'A venda mista precisa de ao menos um produto e um serviço. Com um só tipo, use a nota comum.');
        }
    }

    /**
     * As duas notas da venda (produtos, serviços), travadas. Para alterar, nenhuma pode ter saído: rascunho, rejeitada
     * ou erro valem (a nota que falhou por dado próprio precisa poder ser corrigida pela venda). Para excluir, só rascunho.
     * Chamar dentro de uma transação.
     *
     * @return array{0: Nota, 1: Nota}
     */
    public function notasEditaveis(Venda $venda, bool $soRascunho = false): array
    {
        $notas = Nota::query()->where('venda_id', $venda->id)->lockForUpdate()->get();
        $produtos = $notas->first(fn (Nota $n): bool => $n->modelo === ModeloDocumento::Nfe);
        $servicos = $notas->first(fn (Nota $n): bool => $n->modelo === ModeloDocumento::Dps);
        $saiu = fn (Nota $n): bool => $soRascunho ? $n->status !== StatusNota::Rascunho : ! $n->status->editavel();
        if ($produtos === null || $servicos === null || $notas->contains($saiu)) {
            throw new VendaNaoEditavelException;
        }

        return [$produtos, $servicos];
    }

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

        $this->exigirServicosDeUmSoCodigo($servicos);

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

        foreach ([[$produtos, 'NF-e'], [$servicos, 'NFS-e']] as [$nota, $rotulo]) {
            if ($nota->total_centavos <= 0) {
                throw new EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', "Com esse desconto a {$rotulo} ficaria sem valor. Reduza o desconto da venda.");
            }
        }
        $pagamentos = (int) array_sum(array_column($dados['pagamentos'], 'valor_centavos'));
        if ($pagamentos !== $produtos->total_centavos) {
            throw new EmissaoBloqueadaException('FISCAL_PAGAMENTO_DIVERGENTE', 'A soma dos pagamentos deve ser igual ao total da NF-e, que é de R$ '.number_format($produtos->total_centavos / 100, 2, ',', '.').'.');
        }

        return [
            'subtotal' => $produtos->subtotal_centavos + $servicos->subtotal_centavos,
            'desconto' => $produtos->desconto_centavos + $servicos->desconto_centavos,
            'total' => $produtos->total_centavos + $servicos->total_centavos,
        ];
    }

    /** Uma só NFS-e por venda: os serviços precisam ter o mesmo código de tributação e a mesma alíquota (como na nota comum). */
    private function exigirServicosDeUmSoCodigo(Nota $servicos): void
    {
        $cadastros = Servico::query()->whereIn('id', $servicos->itens()->pluck('servico_id'))->get();
        $codigos = $cadastros->pluck('c_trib_nac')->unique();
        $aliquotas = $cadastros->map(fn (Servico $s): string => number_format((float) $s->aliquota_iss, 2, '.', ''))->unique();
        if ($codigos->count() > 1 || $aliquotas->count() > 1) {
            throw new EmissaoBloqueadaException('FISCAL_SERVICOS_DIVERGENTES', 'Os serviços da venda têm códigos de tributação ou alíquotas diferentes. A venda gera uma só NFS-e: use serviços com o mesmo código e a mesma alíquota.');
        }
    }
}
