<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\EventoDeWebhook;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $usuario_id
 * @property string $url
 * @property ?string $descricao
 * @property list<string> $eventos
 * @property AmbienteFiscal $ambiente
 * @property string $segredo
 * @property bool $ativo
 * @property ?CarbonInterface $desativado_em
 * @property ?string $motivo_desativacao
 * @property int $falhas_seguidas
 */
class WebhookEndpoint extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'webhook_endpoints';

    /** Tudo muda só pelas Actions (forceFill). */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['segredo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'eventos' => 'array',
            'ambiente' => AmbienteFiscal::class,
            'segredo' => 'encrypted',
            'ativo' => 'boolean',
            'desativado_em' => 'datetime',
            'falhas_seguidas' => 'integer',
        ];
    }

    /** Lista vazia assina os cinco eventos de nota; o teste chega sempre. */
    public function assina(EventoDeWebhook $evento): bool
    {
        if ($evento === EventoDeWebhook::Teste) {
            return true;
        }

        return $this->eventos === [] || in_array($evento->value, $this->eventos, true);
    }

    /** @return HasMany<WebhookEntrega, $this> */
    public function entregas(): HasMany
    {
        return $this->hasMany(WebhookEntrega::class, 'webhook_endpoint_id');
    }

    protected static function newFactory(): WebhookEndpointFactory
    {
        return WebhookEndpointFactory::new();
    }
}
