<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class LimiteDaApiExcedidoException extends ErroDeNegocio
{
    public function __construct(private readonly int $limite, private readonly int $segundos, private readonly int $reinicioEm)
    {
        parent::__construct("Limite de {$limite} requisições por minuto excedido. Tente novamente em {$segundos} segundos.");
    }

    public function status(): int
    {
        return 429;
    }

    public function codigo(): string
    {
        return 'API_LIMITE_EXCEDIDO';
    }

    public function headers(): array
    {
        return [
            'Retry-After' => (string) $this->segundos,
            'X-RateLimit-Limit' => (string) $this->limite,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $this->reinicioEm,
        ];
    }
}
