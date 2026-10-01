<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Models\Emitente;

final class AtualizarDadosDaEmpresa
{
    /** @param array{logradouro:?string,numero:?string,bairro:?string,cidade:?string,uf:?string,cep:?string,codigo_ibge:?string} $dados */
    public function executar(Emitente $emitente, array $dados): Emitente
    {
        $emitente->update($dados);

        return $emitente;
    }
}
