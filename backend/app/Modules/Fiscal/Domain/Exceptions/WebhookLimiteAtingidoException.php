<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class WebhookLimiteAtingidoException extends ErroDeNegocio
{
    public function __construct(int $limite)
    {
        parent::__construct("A empresa já tem {$limite} webhooks. Exclua um antes de criar outro.");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'WEBHOOK_LIMITE_ATINGIDO';
    }
}
