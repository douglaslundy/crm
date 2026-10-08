<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class WebhookUrlInvalidaException extends ErroDeNegocio
{
    public function __construct(private readonly string $motivo)
    {
        parent::__construct("A URL do webhook não pode ser usada: {$motivo}");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'WEBHOOK_URL_INVALIDA';
    }

    public function extras(): array
    {
        return ['errors' => [['campo' => 'url', 'mensagem' => $this->motivo]]];
    }
}
