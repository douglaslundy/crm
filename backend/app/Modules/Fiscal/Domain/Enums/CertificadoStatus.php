<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum CertificadoStatus: string
{
    case Pendente = 'PENDENTE';
    case Valido = 'VALIDO';
    case Vencido = 'VENCIDO';
    case Invalido = 'INVALIDO';
}
