<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class UsuarioJaInativoException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Este usuário já está desativado.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'USUARIO_JA_INATIVO';
    }
}
