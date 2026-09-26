<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;

final class TransicaoDeSituacaoInvalidaException extends ErroDeNegocio
{
    public static function de(SituacaoAssinatura $origem, SituacaoAssinatura $destino): self
    {
        return new self("Não é possível mudar a situação de {$origem->value} para {$destino->value}.");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'TRANSICAO_INVALIDA';
    }
}
