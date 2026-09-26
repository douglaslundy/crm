<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;

final class AssinaturaSemEscritaException extends ErroDeNegocio
{
    public static function para(SituacaoAssinatura $situacao): self
    {
        return new self(match ($situacao) {
            SituacaoAssinatura::Pendente => 'Sua conta está aguardando ativação.',
            SituacaoAssinatura::Cancelada => 'Sua conta está cancelada. Você ainda pode consultar e baixar seus dados.',
            default => 'Sua conta está suspensa. Você ainda pode consultar e baixar seus dados.',
        });
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'ASSINATURA_SEM_ESCRITA';
    }
}
