<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class CpfInvalidoException extends ErroDeNegocio
{
    public static function para(string $entrada): self
    {
        return new self("CPF inválido: {$entrada}.");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'CPF_INVALIDO';
    }
}
