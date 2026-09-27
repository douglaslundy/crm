<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

/** Requisição sem sessão stateful (Origin/Referer fora do domínio configurado do Sanctum). */
final class SessaoIndisponivelException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Não foi possível iniciar a sessão. Acesse pelo site oficial e tente novamente.');
    }

    public function status(): int
    {
        return 400;
    }

    public function codigo(): string
    {
        return 'SESSAO_INDISPONIVEL';
    }
}
