<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Throwable;

/**
 * A SEFAZ não respondeu: timeout, conexão recusada, HTTP diferente de 200 ou corpo vazio/ilegível.
 * É a ÚNICA causa que pode levar uma NFC-e à contingência: rejeição, schema e configuração nunca entram.
 */
final class FalhaDeComunicacaoException extends ErroDeNegocio
{
    public function __construct(?Throwable $causa = null)
    {
        parent::__construct('Falha de comunicação com a SEFAZ.', 0, $causa);
    }

    public function status(): int
    {
        return 503;
    }

    public function codigo(): string
    {
        return 'SEFAZ_INDISPONIVEL';
    }
}
