<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class VendaNaoEditavelException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Só é possível alterar ou excluir a venda enquanto as duas notas estão em rascunho.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'FISCAL_VENDA_NAO_EDITAVEL';
    }
}
