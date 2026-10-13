<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

enum StatusDoNegocio: string
{
    case Aberto = 'ABERTO';
    case Ganho = 'GANHO';
    case Perdido = 'PERDIDO';
}
