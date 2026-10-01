<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Domain\Models\Contato;

final class CriarContato
{
    /** @param array{nome:string,cargo:?string,email:?string,telefone:?string} $dados */
    public function executar(Cliente $cliente, array $dados): Contato
    {
        return $cliente->contatos()->create($dados);
    }
}
