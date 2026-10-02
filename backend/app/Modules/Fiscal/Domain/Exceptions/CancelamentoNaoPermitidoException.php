<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class CancelamentoNaoPermitidoException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Só é possível cancelar uma NF-e autorizada.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 409;
    }

    public function codigo(): string
    {
        return 'CANCELAMENTO_NAO_PERMITIDO';
    }
}
