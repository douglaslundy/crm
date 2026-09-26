<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Platform\Domain\Enums\Recurso;
use LogicException;

/** Erro de programação: o módulo dono do recurso não registrou o contador. */
final class RecursoSemContadorException extends LogicException
{
    public static function para(Recurso $recurso): self
    {
        return new self("Nenhum contador de uso registrado para {$recurso->value}.");
    }
}
