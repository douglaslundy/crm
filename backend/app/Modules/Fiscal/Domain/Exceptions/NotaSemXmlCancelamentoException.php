<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class NotaSemXmlCancelamentoException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Só notas canceladas têm XML de cancelamento para baixar.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 409;
    }

    public function codigo(): string
    {
        return 'NOTA_SEM_XML_CANCELAMENTO';
    }
}
