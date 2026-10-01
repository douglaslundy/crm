<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum AmbienteFiscal: string
{
    case Homologacao = 'HOMOLOGACAO';
    case Producao = 'PRODUCAO';
}
