<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum StatusInutilizacao: string
{
    case Inutilizada = 'INUTILIZADA';
    case Recusada = 'RECUSADA';
    case Erro = 'ERRO';
}
