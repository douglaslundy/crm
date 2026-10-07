<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Enums;

enum MetodoDePagamento: string
{
    case Cartao = 'CARTAO';
    case Boleto = 'BOLETO';
    case Pix = 'PIX';
}
