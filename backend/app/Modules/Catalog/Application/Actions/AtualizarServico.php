<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\Servico;

final class AtualizarServico
{
    /** @param array{nome:string,preco_centavos:int,codigo_lc116:?string,c_trib_nac:?string,codigo_municipal:?string,aliquota_iss:?string,nbs:?string} $dados */
    public function executar(Servico $servico, array $dados): Servico
    {
        $servico->update($dados);

        return $servico;
    }
}
