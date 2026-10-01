<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\Models\Produto;

/** Preenche só os campos vazios do produto (nunca sobrescreve um valor já preenchido). */
final class AplicarCategoriaFiscal
{
    public function executar(Produto $produto, CategoriaFiscalPadrao $categoria): Produto
    {
        $preencheuAlgo = $produto->ncm === null || $produto->origem === null || $produto->tributacao_icms === null;

        $produto->update([
            'ncm' => $produto->ncm ?? $categoria->ncm,
            'origem' => $produto->origem ?? $categoria->origem,
            'tributacao_icms' => $produto->tributacao_icms ?? $categoria->tributacao_icms,
            'fiscal_fonte' => $preencheuAlgo ? FonteFiscal::Padrao : $produto->fiscal_fonte,
            'fiscal_revisado_em' => $preencheuAlgo ? null : $produto->fiscal_revisado_em,
        ]);

        return $produto;
    }
}
