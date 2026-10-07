<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

/** Erro de cobrança com código estável e mensagem que diz o que fazer. */
final class BillingException extends ErroDeNegocio
{
    public function __construct(private readonly string $codigoDoErro, string $mensagem, private readonly int $statusHttp = 422)
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return $this->statusHttp;
    }

    public function codigo(): string
    {
        return $this->codigoDoErro;
    }
}
