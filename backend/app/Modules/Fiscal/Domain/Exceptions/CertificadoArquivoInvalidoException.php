<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class CertificadoArquivoInvalidoException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Este arquivo não é um certificado A1 (.pfx) válido.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'CERTIFICADO_ARQUIVO_INVALIDO';
    }
}
