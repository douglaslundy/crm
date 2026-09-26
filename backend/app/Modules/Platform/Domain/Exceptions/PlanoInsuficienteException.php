<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class PlanoInsuficienteException extends ErroDeNegocio
{
    /** @param list<array{recurso: string, uso: int, limite: int}> $excessos */
    public function __construct(private readonly array $excessos)
    {
        parent::__construct('O uso atual da empresa passa os limites do plano escolhido.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'PLANO_EXCEDIDO';
    }

    public function extras(): array
    {
        return ['excessos' => $this->excessos];
    }
}
