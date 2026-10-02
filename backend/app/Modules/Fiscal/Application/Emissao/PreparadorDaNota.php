<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Emissao;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\FormaPagamento;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\RateioDeDesconto;
use App\Modules\Fiscal\Domain\Resolvers\CfopSaidaResolver;
use App\Modules\Fiscal\Domain\Resolvers\IndicadorIeDestinatarioResolver;
use App\Modules\Fiscal\Domain\Resolvers\TributacaoIcmsSaidaResolver;

/**
 * Valida o que depende do cliente, dos produtos e dos pagamentos e congela o resultado na nota
 * (snapshot fiscal). Assim a nota não muda se o cadastro for editado depois.
 */
final class PreparadorDaNota
{
    public function __construct(
        private readonly CfopSaidaResolver $cfop,
        private readonly TributacaoIcmsSaidaResolver $csosn,
        private readonly IndicadorIeDestinatarioResolver $indicadorIe,
    ) {}

    public function preparar(Nota $nota, Emitente $emitente): void
    {
        $itens = $nota->itens()->get();
        if ($itens->isEmpty()) {
            throw new EmissaoBloqueadaException('FISCAL_NOTA_SEM_ITENS', 'Adicione ao menos um item à nota.');
        }

        $cliente = Cliente::query()->findOrFail($nota->cliente_id);
        $destinatario = $this->destinatario($nota, $cliente);

        $totais = $itens->map(fn ($i): int => RateioDeDesconto::totalDoItem($i->quantidade_milesimos, $i->valor_unitario_centavos))->all();
        $descontos = RateioDeDesconto::ratear($nota->desconto_centavos, array_values($totais));
        $subtotal = array_sum($totais);
        $total = $subtotal - $nota->desconto_centavos;
        if ($total <= 0) {
            throw new EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', 'O total da nota deve ser maior que zero.');
        }

        foreach ($itens->values() as $i => $item) {
            $produto = Produto::query()->findOrFail($item->produto_id);
            $tributacao = $this->exigirProduto($produto);
            $item->update([
                'sku' => $produto->sku,
                'descricao' => $produto->nome,
                'unidade' => $produto->unidade,
                'gtin' => $produto->gtin,
                'ncm' => $produto->ncm,
                'cest' => $produto->cest,
                'origem' => $produto->origem,
                'tributacao_icms' => $tributacao->value,
                'cfop' => $this->cfop->resolver((string) $emitente->uf, $destinatario['uf'], $tributacao),
                'csosn' => $this->csosn->csosn($tributacao),
                'desconto_centavos' => $descontos[$i],
                'total_centavos' => $totais[$i],
            ]);
        }

        $this->exigirPagamentos($nota, $total);

        $nota->forceFill(['subtotal_centavos' => $subtotal, 'total_centavos' => $total, 'destinatario' => $destinatario])->save();
    }

    private function exigirProduto(Produto $produto): TributacaoIcms
    {
        $faltas = [];
        if ($produto->ncm === null || trim($produto->ncm) === '') {
            $faltas[] = 'NCM';
        }
        if ($produto->origem === null) {
            $faltas[] = 'origem da mercadoria';
        }
        if ($produto->tributacao_icms === null) {
            $faltas[] = 'tributação de ICMS';
        } elseif ($produto->tributacao_icms->value === 'ST' && ($produto->cest === null || trim($produto->cest) === '')) {
            $faltas[] = 'CEST (obrigatório para ST)';
        }
        if ($faltas !== []) {
            throw new EmissaoBloqueadaException('FISCAL_PRODUTO_INCOMPLETO', "O produto \"{$produto->nome}\" está sem: ".implode(', ', $faltas).'. Complete em Produtos.');
        }

        return $produto->tributacao_icms;
    }

    /** @return array<string, mixed> */
    private function destinatario(Nota $nota, Cliente $c): array
    {
        $documento = $c->cpf_cnpj === null ? '' : preg_replace('/\D/', '', $c->cpf_cnpj);
        $faltas = [];
        if ($documento === '') {
            $faltas[] = 'CPF/CNPJ';
        }
        foreach (['logradouro' => 'logradouro', 'numero' => 'número', 'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'UF', 'cep' => 'CEP', 'codigo_ibge' => 'código IBGE'] as $campo => $rotulo) {
            $valor = $c->getAttribute($campo);
            if ($valor === null || trim((string) $valor) === '') {
                $faltas[] = $rotulo;
            }
        }
        if ($faltas !== []) {
            throw new EmissaoBloqueadaException('FISCAL_DESTINATARIO_INCOMPLETO', "O cliente \"{$c->nome}\" está sem: ".implode(', ', $faltas).'. Complete em Clientes.');
        }

        $indIeDest = $this->indicadorIe->resolver($c->tipo, $c->inscricao_estadual, $c->ie_isento);

        if ($c->tipo === TipoCliente::Pf) {
            $indFinal = 1;
        } elseif ($nota->consumidor_final === null) {
            throw new EmissaoBloqueadaException('FISCAL_CONSUMIDOR_FINAL_NAO_INFORMADO', 'Informe se a venda é para consumidor final (cliente pessoa jurídica).');
        } else {
            $indFinal = $nota->consumidor_final ? 1 : 0;
        }

        return [
            'tipo' => $c->tipo->value, 'documento' => $documento, 'nome' => $c->nome,
            'ind_ie_dest' => $indIeDest, 'ind_final' => $indFinal,
            'ie' => $indIeDest === 1 ? preg_replace('/\D/', '', (string) $c->inscricao_estadual) : null,
            'logradouro' => $c->logradouro, 'numero' => $c->numero, 'bairro' => $c->bairro,
            'codigo_ibge' => $c->codigo_ibge, 'cidade' => $c->cidade, 'uf' => strtoupper((string) $c->uf), 'cep' => preg_replace('/\D/', '', (string) $c->cep),
            'email' => $c->email,
        ];
    }

    private function exigirPagamentos(Nota $nota, int $total): void
    {
        $pagamentos = $nota->pagamentos()->get();
        if ($pagamentos->sum('valor_centavos') !== $total) {
            throw new EmissaoBloqueadaException('FISCAL_PAGAMENTO_DIVERGENTE', 'A soma dos pagamentos deve ser igual ao total da nota.');
        }
        foreach ($pagamentos as $p) {
            if ($p->tpag === FormaPagamento::Outros->value && ($p->xpag === null || trim($p->xpag) === '')) {
                throw new EmissaoBloqueadaException('FISCAL_PAGAMENTO_SEM_DESCRICAO', 'Informe a descrição da forma de pagamento "Outros".');
            }
        }
    }
}
