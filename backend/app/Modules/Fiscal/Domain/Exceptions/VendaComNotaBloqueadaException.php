<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Fiscal\Application\Vendas\ResultadoDaVenda;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

/** Nenhuma nota foi emitida: ao menos uma está com pendência e o usuário não confirmou emitir só a válida. */
final class VendaComNotaBloqueadaException extends ErroDeNegocio
{
    public function __construct(private readonly ResultadoDaVenda $resultado)
    {
        parent::__construct('Uma das notas da venda está com pendência. Corrija e emita de novo, ou emita só a nota válida.');
    }

    public function status(): int
    {
        return 409;
    }

    public function codigo(): string
    {
        return 'FISCAL_VENDA_NOTA_BLOQUEADA';
    }

    /** @return array<string, mixed> */
    public function extras(): array
    {
        return ['notas' => $this->resultado->notas()];
    }
}
