<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Webhooks recebem dados fiscais da empresa, por isso só quem gerencia a empresa os configura. */
final class PoliticaDeWebhooks
{
    public const LIMITE_DE_ENDPOINTS = 5;

    public function garantirPodeGerenciar(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin], true)) {
            throw new AcessoNegadoException('Somente o proprietário ou um administrador podem gerenciar webhooks.');
        }
    }
}
