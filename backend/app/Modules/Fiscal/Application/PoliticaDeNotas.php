<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem faz o quê com notas (spec F3a §4). LEITURA só consulta. */
final class PoliticaDeNotas
{
    public function garantirPodeRascunhar(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin, Papel::Fiscal, Papel::Vendedor], true)) {
            throw new AcessoNegadoException('Você não tem permissão para criar ou alterar notas.');
        }
    }

    public function garantirPodeEmitir(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin, Papel::Fiscal], true)) {
            throw new AcessoNegadoException('Você não tem permissão para emitir notas.');
        }
    }
}
