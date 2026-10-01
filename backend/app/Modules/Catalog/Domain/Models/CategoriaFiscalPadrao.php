<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\CategoriaFiscalPadraoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $categoria
 * @property ?string $ncm
 * @property ?int $origem
 * @property ?TributacaoIcms $tributacao_icms
 */
class CategoriaFiscalPadrao extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<CategoriaFiscalPadraoFactory> */
    use HasFactory;
    use HasUuids;

    protected $table = 'categorias_fiscais_padrao';

    protected $fillable = ['categoria', 'ncm', 'origem', 'tributacao_icms'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['origem' => 'integer', 'tributacao_icms' => TributacaoIcms::class];
    }

    protected static function newFactory(): CategoriaFiscalPadraoFactory
    {
        return CategoriaFiscalPadraoFactory::new();
    }
}
