<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Enums;

enum SituacaoAssinatura: string
{
    case Pendente = 'PENDENTE';
    case Teste = 'TESTE';
    case Ativa = 'ATIVA';
    case Inadimplente = 'INADIMPLENTE';
    case Suspensa = 'SUSPENSA';
    case Cancelada = 'CANCELADA';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
