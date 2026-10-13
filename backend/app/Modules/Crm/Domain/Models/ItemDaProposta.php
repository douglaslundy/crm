<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot do item do negócio no momento em que a proposta foi criada.
 *
 * @property string $id
 * @property string $proposta_id
 * @property TipoDeItem $tipo
 * @property string $item_id
 * @property string $descricao
 * @property int $quantidade
 * @property int $valor_unitario_centavos
 * @property int $ordem
 */
class ItemDaProposta extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'crm_itens_da_proposta';

    protected $fillable = ['tipo', 'item_id', 'descricao', 'quantidade', 'valor_unitario_centavos', 'ordem'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoDeItem::class, 'quantidade' => 'integer', 'valor_unitario_centavos' => 'integer', 'ordem' => 'integer',
        ];
    }

    public function totalCentavos(): int
    {
        return $this->quantidade * $this->valor_unitario_centavos;
    }
}
