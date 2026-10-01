<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum TributacaoIcms: string
{
    case Normal = 'NORMAL';
    case St = 'ST';
}
