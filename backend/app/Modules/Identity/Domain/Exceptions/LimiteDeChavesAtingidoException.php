<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class LimiteDeChavesAtingidoException extends ErroDeNegocio
{
    public function __construct(int $limite)
    {
        parent::__construct("A empresa já tem {$limite} chaves de API ativas. Revogue uma antes de criar outra.");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'API_CHAVES_LIMITE_ATINGIDO';
    }
}
