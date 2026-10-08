<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\AssinaturaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $plano_id
 * @property CicloDeCobranca $ciclo
 * @property MetodoDePagamento $metodo
 * @property StatusDaAssinatura $situacao
 * @property ?string $gateway_assinatura_id
 * @property ?CarbonInterface $paga_ate
 * @property ?CarbonInterface $cancelada_em
 * @property ?int $valor_ciclo_centavos
 * @property ?string $plano_proximo_id
 * @property ?CarbonInterface $plano_proximo_em
 * @property-read Plano $plano
 */
class Assinatura extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AssinaturaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'assinaturas';

    /** Tudo muda só pelas Actions (forceFill). */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ciclo' => CicloDeCobranca::class,
            'metodo' => MetodoDePagamento::class,
            'situacao' => StatusDaAssinatura::class,
            'paga_ate' => 'date',
            'cancelada_em' => 'datetime',
            'valor_ciclo_centavos' => 'integer',
            'plano_proximo_em' => 'date',
        ];
    }

    /** Boleto da renovação já emitido e em aberto: trocar o preço agora deixaria o boleto com o valor errado. */
    public function temBoletoEmitidoEmAberto(): bool
    {
        return $this->metodo === MetodoDePagamento::Boleto
            && $this->cobrancas()->where('tipo', TipoDeCobranca::Assinatura->value)->where('status', StatusDaCobranca::Pendente->value)->whereNotNull('gateway_pagamento_id')->exists();
    }

    /** Preço contratado do ciclo atual; assinatura antiga, sem o valor gravado, vale o preço do plano no ciclo. */
    public function valorDoCiclo(): int
    {
        return $this->valor_ciclo_centavos ?? $this->ciclo->precoDo($this->plano);
    }

    /** @return BelongsTo<Plano, $this> */
    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    /** @return HasMany<Cobranca, $this> */
    public function cobrancas(): HasMany
    {
        return $this->hasMany(Cobranca::class);
    }

    protected static function newFactory(): AssinaturaFactory
    {
        return AssinaturaFactory::new();
    }
}
