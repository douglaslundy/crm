<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\MetaDoVendedorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $usuario_id
 * @property CarbonInterface $mes
 * @property UnidadeDaMeta $unidade
 * @property int $alvo
 */
class MetaDoVendedor extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MetaDoVendedorFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_metas';

    protected $fillable = ['usuario_id', 'mes', 'unidade', 'alvo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // `date:Y-m-d` grava só a data, para a igualdade por texto funcionar no SQLite e no PostgreSQL.
        return ['mes' => 'date:Y-m-d', 'unidade' => UnidadeDaMeta::class, 'alvo' => 'integer'];
    }

    protected static function newFactory(): MetaDoVendedorFactory
    {
        return MetaDoVendedorFactory::new();
    }
}
