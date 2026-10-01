<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;

/** Sem pré-requisito de dado fiscal: a exigência só existe na emissão (F3). */
final class ConverterEmCliente
{
    public function executar(Cliente $cliente): Cliente
    {
        $cliente->update(['estagio' => EstagioCliente::Cliente]);

        return $cliente;
    }
}
