<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property bool $vendedor_ve_so_os_seus
 */
class ConfiguracaoDoCrm extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'crm_configuracoes';

    protected $fillable = ['vendedor_ve_so_os_seus'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vendedor_ve_so_os_seus' => 'boolean'];
    }

    /** Sem linha de configuração vale o padrão: vendedor vê só os seus. */
    public static function vendedorVeSoOsSeus(): bool
    {
        $valor = self::query()->value('vendedor_ve_so_os_seus');

        return $valor === null ? true : (bool) $valor;
    }
}
