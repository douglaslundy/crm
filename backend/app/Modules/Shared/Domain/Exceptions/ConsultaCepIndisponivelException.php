<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class ConsultaCepIndisponivelException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Não foi possível consultar o CEP agora. Preencha o endereço manualmente.');
    }

    public function status(): int
    {
        return 503;
    }

    public function codigo(): string
    {
        return 'CONSULTA_INDISPONIVEL';
    }
}
