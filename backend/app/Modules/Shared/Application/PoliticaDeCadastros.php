<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem escreve em clientes, produtos e serviços (spec F2 §9). */
final class PoliticaDeCadastros
{
    public function garantirPodeEscrever(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin, Papel::Fiscal, Papel::Vendedor], true)) {
            throw new AcessoNegadoException('Você não tem permissão para alterar cadastros.');
        }
    }
}
