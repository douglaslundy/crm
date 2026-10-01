<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class CertificadoSenhaInvalidaException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('A senha do certificado está incorreta.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'CERTIFICADO_SENHA_INVALIDA';
    }
}
