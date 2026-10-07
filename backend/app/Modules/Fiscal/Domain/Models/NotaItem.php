<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\NotaItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $nota_id
 * @property ?string $produto_id
 * @property ?string $servico_id
 * @property ?string $c_trib_nac
 * @property ?string $codigo_municipal
 * @property ?string $aliquota_iss
 * @property ?string $nbs
 * @property int $ordem
 * @property int $quantidade_milesimos
 * @property int $valor_unitario_centavos
 * @property int $desconto_centavos
 * @property int $total_centavos
 * @property ?string $sku
 * @property ?string $descricao
 * @property ?string $unidade
 * @property ?string $gtin
 * @property ?string $ncm
 * @property ?string $cest
 * @property ?string $cfop
 * @property ?int $origem
 * @property ?string $tributacao_icms
 * @property ?string $csosn
 */
class NotaItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NotaItemFactory> */
    use HasFactory;

    protected $table = 'nota_itens';

    protected $fillable = [
        'nota_id', 'produto_id', 'ordem', 'quantidade_milesimos', 'valor_unitario_centavos', 'desconto_centavos', 'total_centavos',
        'sku', 'descricao', 'unidade', 'gtin', 'ncm', 'cest', 'cfop', 'origem', 'tributacao_icms', 'csosn',
        'servico_id', 'c_trib_nac', 'codigo_municipal', 'aliquota_iss', 'nbs',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['origem' => 'integer', 'aliquota_iss' => 'decimal:2'];
    }

    /** @return BelongsTo<Nota, $this> */
    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    protected static function newFactory(): NotaItemFactory
    {
        return NotaItemFactory::new();
    }
}
