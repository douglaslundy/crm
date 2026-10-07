<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de idempotência do webhook. Sem tenant: o evento chega antes de se achar o dono do pagamento,
 * e só o `ReceberWebhook` lê esta tabela.
 *
 * @property string $id
 * @property string $gateway_evento_id
 * @property string $tipo
 * @property CarbonInterface $recebido_em
 * @property ?CarbonInterface $processado_em
 */
class WebhookRecebido extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'webhooks_recebidos';

    protected $fillable = ['gateway_evento_id', 'tipo', 'recebido_em'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recebido_em' => 'datetime', 'processado_em' => 'datetime'];
    }
}
