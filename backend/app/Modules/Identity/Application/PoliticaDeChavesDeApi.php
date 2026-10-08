<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** A chave age como quem a criou, por isso só quem gerencia a empresa a cria e revoga. */
final class PoliticaDeChavesDeApi
{
    public const LIMITE_DE_CHAVES_ATIVAS = 20;

    public function garantirPodeGerenciar(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin], true)) {
            throw new AcessoNegadoException('Somente o proprietário ou um administrador podem gerenciar chaves de API.');
        }
    }
}
