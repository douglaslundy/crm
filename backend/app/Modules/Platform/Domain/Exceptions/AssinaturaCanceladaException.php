<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

/** A API não atende conta cancelada nem para leitura (no app o cliente ainda baixa os dados pela tela). */
final class AssinaturaCanceladaException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('A assinatura desta empresa está cancelada.');
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'ASSINATURA_CANCELADA';
    }
}
