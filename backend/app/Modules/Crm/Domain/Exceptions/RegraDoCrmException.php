<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class RegraDoCrmException extends ErroDeNegocio
{
    public function __construct(string $mensagem, private readonly string $codigoDoErro)
    {
        parent::__construct($mensagem);
    }

    public static function negocioFechado(): self
    {
        return new self('Negócio fechado não pode ser alterado.', 'NEGOCIO_FECHADO');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return $this->codigoDoErro;
    }
}
