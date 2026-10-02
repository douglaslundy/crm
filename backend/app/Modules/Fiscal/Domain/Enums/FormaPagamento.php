<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

/** Valor = código `tPag` da NF-e. */
enum FormaPagamento: string
{
    case Dinheiro = '01';
    case Credito = '03';
    case Debito = '04';
    case Pix = '17';
    case Outros = '99';
}
