<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Domain\Enums\TipoDeItem;

/** Item congelado da proposta, como o CRM entrega ao Fiscal. */
final class LinhaDeProposta
{
    public function __construct(
        public readonly TipoDeItem $tipo,
        public readonly string $itemId,
        public readonly int $quantidade,
        public readonly int $valorUnitarioCentavos,
    ) {}
}
