<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class NotaNaoEmContingenciaException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Só é possível retransmitir uma nota que está em contingência.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 409;
    }

    public function codigo(): string
    {
        return 'NOTA_NAO_EM_CONTINGENCIA';
    }
}
