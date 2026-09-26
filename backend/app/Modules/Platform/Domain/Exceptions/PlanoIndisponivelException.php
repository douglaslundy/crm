<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class PlanoIndisponivelException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Este plano está desativado.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'PLANO_INDISPONIVEL';
    }
}
