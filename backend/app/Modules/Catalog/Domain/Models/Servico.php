<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\ServicoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $nome
 * @property int $preco_centavos
 * @property ?string $codigo_lc116
 * @property ?string $c_trib_nac
 * @property ?string $codigo_municipal
 * @property ?string $aliquota_iss
 * @property ?string $nbs
 */
class Servico extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ServicoFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['nome', 'preco_centavos', 'codigo_lc116', 'c_trib_nac', 'codigo_municipal', 'aliquota_iss', 'nbs'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aliquota_iss' => 'decimal:2'];
    }

    public function pendenteDeRevisaoFiscal(): bool
    {
        return $this->codigo_lc116 === null || $this->c_trib_nac === null || $this->aliquota_iss === null;
    }

    protected static function newFactory(): ServicoFactory
    {
        return ServicoFactory::new();
    }
}
