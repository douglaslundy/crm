<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;

/** Informação financeira: só quem administra a empresa assina, cancela e vê as faturas. */
final class PoliticaDeCobranca
{
    public function garantirPodeGerenciar(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin], true)) {
            throw new BillingException('BILLING_SEM_PERMISSAO', 'Só o proprietário ou um administrador da empresa gerencia a assinatura.', 403);
        }
    }
}
