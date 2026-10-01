<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum ModeloDocumento: string
{
    case Nfe = 'NFE';
    case Nfce = 'NFCE';
    case Dps = 'DPS';
}
