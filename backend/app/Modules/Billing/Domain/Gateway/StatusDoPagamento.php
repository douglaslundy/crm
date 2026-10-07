<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Gateway;

enum StatusDoPagamento: string
{
    case Aprovado = 'APROVADO';
    case Pendente = 'PENDENTE';
    case Recusado = 'RECUSADO';
    case Cancelado = 'CANCELADO';
    case Vencido = 'VENCIDO';
}
