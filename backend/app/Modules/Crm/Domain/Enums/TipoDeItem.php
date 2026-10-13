<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

enum TipoDeItem: string
{
    case Produto = 'PRODUTO';
    case Servico = 'SERVICO';
}
