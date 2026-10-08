<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $webhook_entrega_id
 * @property int $numero
 * @property ?int $status_http
 * @property int $duracao_ms
 * @property ?string $erro
 * @property ?string $resposta
 * @property CarbonInterface $created_at
 */
class WebhookTentativa extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'webhook_tentativas';

    /** Tudo muda só pelas Actions (forceFill). */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['numero' => 'integer', 'status_http' => 'integer', 'duracao_ms' => 'integer'];
    }
}
