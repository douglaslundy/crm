<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Enums;

enum StatusDaAssinatura: string
{
    case Pendente = 'PENDENTE';
    case Ativa = 'ATIVA';
    case Cancelada = 'CANCELADA';
}
