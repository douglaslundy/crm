<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class JustificativaInvalidaException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('A justificativa deve ter de 15 a 255 caracteres (sem contar espaços repetidos nem emojis).');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'JUSTIFICATIVA_INVALIDA';
    }
}
