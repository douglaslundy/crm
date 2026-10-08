<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class WebhookInativoException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('O webhook está desativado. Reative-o antes de enviar.');
    }

    public function status(): int
    {
        return 409;
    }

    public function codigo(): string
    {
        return 'WEBHOOK_INATIVO';
    }
}
