<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class AcessoNegadoException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Você não tem permissão para esta ação.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'ACESSO_NEGADO';
    }
}
