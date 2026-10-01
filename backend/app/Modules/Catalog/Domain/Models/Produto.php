<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\ProdutoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $sku
 * @property string $nome
 * @property string $unidade
 * @property int $preco_centavos
 * @property ?string $gtin
 * @property ?string $ncm
 * @property ?string $cest
 * @property ?int $origem
 * @property ?TributacaoIcms $tributacao_icms
 * @property FonteFiscal $fiscal_fonte
 * @property ?CarbonInterface $fiscal_revisado_em
 */
class Produto extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProdutoFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'sku', 'nome', 'unidade', 'preco_centavos', 'gtin', 'ncm', 'cest',
        'origem', 'tributacao_icms', 'fiscal_fonte', 'fiscal_revisado_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'origem' => 'integer',
            'tributacao_icms' => TributacaoIcms::class,
            'fiscal_fonte' => FonteFiscal::class,
            'fiscal_revisado_em' => 'datetime',
        ];
    }

    /** Dado ausente nunca é chutado: `=== null`, nunca `empty()`. */
    public function pendenteDeRevisaoFiscal(): bool
    {
        return $this->ncm === null
            || $this->origem === null
            || $this->tributacao_icms === null
            || ($this->fiscal_fonte === FonteFiscal::Padrao && $this->fiscal_revisado_em === null);
    }

    protected static function newFactory(): ProdutoFactory
    {
        return ProdutoFactory::new();
    }
}
