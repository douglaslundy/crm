<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class AssinaturaPendenteException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Sua conta está aguardando ativação.');
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'ASSINATURA_PENDENTE';
    }
}
