<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum PoliticaExcedente: string
{
    case Bloquear = 'BLOQUEAR';
    case Cobrar = 'COBRAR';
}
