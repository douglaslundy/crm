<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Contracts;

use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Crm\Domain\ItemDescrito;

/** Implementado por Catalog. Devolve null quando o item não existe no tenant. */
interface CatalogoDeItens
{
    public function descrever(TipoDeItem $tipo, string $itemId): ?ItemDescrito;
}
