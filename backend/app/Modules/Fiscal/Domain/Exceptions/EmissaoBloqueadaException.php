<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

/** Dado fiscal ausente ou regra não suportada: a emissão para aqui, com mensagem que diz como resolver. */
final class EmissaoBloqueadaException extends ErroDeNegocio
{
    public function __construct(private readonly string $codigoDoBloqueio, string $mensagem)
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return $this->codigoDoBloqueio;
    }
}
