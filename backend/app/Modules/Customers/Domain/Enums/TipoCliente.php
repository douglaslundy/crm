<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Enums;

enum TipoCliente: string
{
    case Pf = 'PF';
    case Pj = 'PJ';
}
