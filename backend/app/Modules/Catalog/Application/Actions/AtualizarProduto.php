<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\ValidadorCamposFiscais;

final class AtualizarProduto
{
    /** @param array{sku:string,nome:string,unidade:string,preco_centavos:int,gtin:?string,ncm:?string,cest:?string,origem:int|string|null,tributacao_icms:?string} $dados */
    public function executar(Produto $produto, array $dados): Produto
    {
        $produto->update([
            'sku' => $dados['sku'],
            'nome' => $dados['nome'],
            'unidade' => $dados['unidade'],
            'preco_centavos' => $dados['preco_centavos'],
            'gtin' => $dados['gtin'],
            'ncm' => ValidadorCamposFiscais::ncm($dados['ncm']),
            'cest' => ValidadorCamposFiscais::cest($dados['cest']),
            'origem' => ValidadorCamposFiscais::origem($dados['origem']),
            'tributacao_icms' => $dados['tributacao_icms'],
            // Edição manual conta como revisão: a pendência de "fonte padrão não revisada" acaba aqui.
            'fiscal_fonte' => FonteFiscal::Manual,
            'fiscal_revisado_em' => now(),
        ]);

        return $produto;
    }
}
