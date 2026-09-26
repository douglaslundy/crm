<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum Modulo: string
{
    case FiscalNfe = 'FISCAL_NFE';
    case FiscalNfce = 'FISCAL_NFCE';
    case FiscalNfse = 'FISCAL_NFSE';
    case Crm = 'CRM';
    case Api = 'API';

    public function rotulo(): string
    {
        return match ($this) {
            self::FiscalNfe => 'NF-e',
            self::FiscalNfce => 'NFC-e',
            self::FiscalNfse => 'NFS-e',
            self::Crm => 'CRM',
            self::Api => 'API pública',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
