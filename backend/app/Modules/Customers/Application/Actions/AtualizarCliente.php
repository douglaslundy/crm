<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Cliente;

final class AtualizarCliente
{
    /** @param array<string, mixed> $dados */
    public function executar(Cliente $cliente, array $dados): Cliente
    {
        $cliente->update($dados);

        return $cliente;
    }
}
