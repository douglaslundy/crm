<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class ConsultaCnpjIndisponivelException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Não foi possível consultar o CNPJ agora. Preencha os dados manualmente.');
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
