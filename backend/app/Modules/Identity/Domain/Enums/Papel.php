<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Enums;

enum Papel: string
{
    case Superadmin = 'SUPERADMIN';
    case Suporte = 'SUPORTE';
    case Proprietario = 'PROPRIETARIO';
    case Admin = 'ADMIN';
    case Fiscal = 'FISCAL';
    case Vendedor = 'VENDEDOR';
    case Leitura = 'LEITURA';

    public function ehDaPlataforma(): bool
    {
        return $this === self::Superadmin || $this === self::Suporte;
    }
}
