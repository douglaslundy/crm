<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\VendaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $cliente_id
 * @property int $desconto_centavos
 * @property int $subtotal_centavos
 * @property int $total_centavos
 */
class Venda extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VendaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'vendas';

    protected $fillable = ['cliente_id', 'desconto_centavos', 'subtotal_centavos', 'total_centavos'];

    /** @return HasMany<Nota, $this> */
    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    protected static function newFactory(): VendaFactory
    {
        return VendaFactory::new();
    }
}
