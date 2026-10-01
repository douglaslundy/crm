<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum FonteFiscal: string
{
    case Manual = 'MANUAL';
    case Padrao = 'PADRAO';
}
