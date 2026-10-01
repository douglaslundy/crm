<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\ValidadorCamposFiscais;

final class AtualizarCategoriaFiscal
{
    /** @param array{categoria:string,ncm:?string,origem:int|string|null,tributacao_icms:?string} $dados */
    public function executar(CategoriaFiscalPadrao $categoria, array $dados): CategoriaFiscalPadrao
    {
        $categoria->update([
            'categoria' => $dados['categoria'],
            'ncm' => ValidadorCamposFiscais::ncm($dados['ncm']),
            'origem' => ValidadorCamposFiscais::origem($dados['origem']),
            'tributacao_icms' => $dados['tributacao_icms'],
        ]);

        return $categoria;
    }
}
