<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum EstadoDaEntrega: string
{
    case Pendente = 'PENDENTE';
    case Entregue = 'ENTREGUE';
    case Falhou = 'FALHOU';
}
