<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use RuntimeException;

/**
 * Erro esperado de regra de negócio. Vira JSON {message, codigo, ...extras}
 * num único ponto (bootstrap/app.php) e não é reportado como falha.
 */
abstract class ErroDeNegocio extends RuntimeException
{
    abstract public function status(): int;

    abstract public function codigo(): string;

    /** @return array<string, mixed> */
    public function extras(): array
    {
        return [];
    }
}
