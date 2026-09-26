<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class ModuloNaoContratadoException extends ErroDeNegocio
{
    public static function para(Modulo $modulo): self
    {
        return new self("O módulo {$modulo->rotulo()} não faz parte do seu plano.");
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'MODULO_NAO_CONTRATADO';
    }
}
