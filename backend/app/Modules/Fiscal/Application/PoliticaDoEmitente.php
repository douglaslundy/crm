<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem escreve nos dados fiscais do emitente (spec F2 §9). */
final class PoliticaDoEmitente
{
    public function garantirPodeEscrever(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin, Papel::Fiscal], true)) {
            throw new AcessoNegadoException('Você não tem permissão para alterar os dados fiscais do emitente.');
        }
    }
}
