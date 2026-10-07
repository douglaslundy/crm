<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Enums;

enum StatusDaCobranca: string
{
    case Pendente = 'PENDENTE';
    case Paga = 'PAGA';
    case Vencida = 'VENCIDA';
    case Cancelada = 'CANCELADA';
}
