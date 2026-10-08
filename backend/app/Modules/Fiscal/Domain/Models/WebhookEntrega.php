<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\WebhookEntregaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $webhook_endpoint_id
 * @property ?int $nota_evento_id
 * @property ?string $nota_id
 * @property string $evento
 * @property string $evento_id
 * @property array<string, mixed> $payload
 * @property EstadoDaEntrega $estado
 * @property int $tentativas
 * @property int $tentativas_na_rodada
 * @property ?CarbonInterface $proxima_tentativa_em
 * @property ?CarbonInterface $entregue_em
 * @property CarbonInterface $created_at
 */
class WebhookEntrega extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WebhookEntregaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'webhook_entregas';

    /** Tudo muda só pelas Actions (forceFill). */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'estado' => EstadoDaEntrega::class,
            'tentativas' => 'integer',
            'tentativas_na_rodada' => 'integer',
            'nota_evento_id' => 'integer',
            'proxima_tentativa_em' => 'datetime',
            'entregue_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /** @return HasMany<WebhookTentativa, $this> */
    public function tentativasLog(): HasMany
    {
        return $this->hasMany(WebhookTentativa::class, 'webhook_entrega_id')->orderBy('numero');
    }

    protected static function newFactory(): WebhookEntregaFactory
    {
        return WebhookEntregaFactory::new();
    }
}
