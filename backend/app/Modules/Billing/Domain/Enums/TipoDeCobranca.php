<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Enums;

enum TipoDeCobranca: string
{
    case Assinatura = 'ASSINATURA';
    case Excedente = 'EXCEDENTE';

    /** Só a cobrança da assinatura estende o período pago; o excedente apenas é quitado. */
    public function estendePeriodo(): bool
    {
        return match ($this) {
            self::Assinatura => true,
            self::Excedente => false,
        };
    }
}
