<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $chave
 * @property string $rota
 * @property string $hash_do_corpo
 * @property ?int $status_http
 * @property ?string $corpo
 * @property ?string $tipo_do_conteudo
 * @property ?string $nota_id
 * @property CarbonInterface $expira_em
 */
class Idempotencia extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'idempotencias';

    /** Tudo muda só pelo middleware (forceFill). */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expira_em' => 'datetime', 'status_http' => 'integer'];
    }
}
