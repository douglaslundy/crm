<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot do item no momento em que entrou no negócio (spec D3).
 *
 * @property string $id
 * @property string $negocio_id
 * @property TipoDeItem $tipo
 * @property string $item_id
 * @property string $descricao
 * @property int $quantidade
 * @property int $valor_unitario_centavos
 */
class ItemDoNegocio extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'crm_itens_do_negocio';

    protected $fillable = ['tipo', 'item_id', 'descricao', 'quantidade', 'valor_unitario_centavos'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tipo' => TipoDeItem::class, 'quantidade' => 'integer', 'valor_unitario_centavos' => 'integer'];
    }
}
