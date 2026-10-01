<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Contato;

final class AtualizarContato
{
    /** @param array{nome:string,cargo:?string,email:?string,telefone:?string} $dados */
    public function executar(Contato $contato, array $dados): Contato
    {
        $contato->update($dados);

        return $contato;
    }
}
