<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class NotaNaoEditavelException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Esta nota não pode mais ser alterada.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 409;
    }

    public function codigo(): string
    {
        return 'NOTA_NAO_EDITAVEL';
    }
}
