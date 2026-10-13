<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Domain\Enums\TipoDeItem;

final class ItemDescrito
{
    public function __construct(
        public readonly TipoDeItem $tipo,
        public readonly string $itemId,
        public readonly string $descricao,
        public readonly int $valorUnitarioCentavos,
    ) {}
}
