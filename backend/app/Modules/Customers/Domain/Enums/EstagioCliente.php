<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Enums;

enum EstagioCliente: string
{
    case Lead = 'LEAD';
    case Cliente = 'CLIENTE';
}
