<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class ApiNaoContratadaException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('A API pública não faz parte do plano desta empresa.');
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'API_NAO_CONTRATADA';
    }
}
