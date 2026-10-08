<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class CursorInvalidoException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('O cursor informado é inválido. Use o valor de links.proxima sem alterá-lo.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'CURSOR_INVALIDO';
    }
}
