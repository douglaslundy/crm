<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Crm\Domain\Contracts\CatalogoDeItens;
use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Crm\Domain\ItemDescrito;

final class CatalogoDeItensDoCatalog implements CatalogoDeItens
{
    public function descrever(TipoDeItem $tipo, string $itemId): ?ItemDescrito
    {
        $item = $tipo === TipoDeItem::Produto
            ? Produto::query()->find($itemId)
            : Servico::query()->find($itemId);

        return $item === null ? null : new ItemDescrito($tipo, $item->id, $item->nome, $item->preco_centavos);
    }
}
