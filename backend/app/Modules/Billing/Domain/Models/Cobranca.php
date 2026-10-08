<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\CobrancaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $assinatura_id
 * @property TipoDeCobranca $tipo
 * @property int $valor_centavos
 * @property CarbonInterface $vence_em
 * @property StatusDaCobranca $status
 * @property ?MetodoDePagamento $metodo
 * @property ?string $gateway_pagamento_id
 * @property ?string $boleto_url
 * @property ?string $boleto_linha_digitavel
 * @property ?string $pix_qr_code
 * @property ?string $pix_qr_code_base64
 * @property ?CarbonInterface $pix_expira_em
 * @property ?CarbonInterface $mes_referencia
 * @property ?int $documentos_excedentes
 * @property ?int $valor_unitario_centavos
 * @property ?string $plano_destino_id
 * @property ?string $plano_origem_id
 * @property ?CarbonInterface $paga_ate_origem
 * @property ?CarbonInterface $pago_em
 * @property ?CarbonInterface $periodo_inicio
 * @property ?CarbonInterface $periodo_fim
 * @property-read Assinatura $assinatura
 */
class Cobranca extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CobrancaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'cobrancas';

    /** Tudo muda só pelas Actions (forceFill). */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoDeCobranca::class,
            'status' => StatusDaCobranca::class,
            'metodo' => MetodoDePagamento::class,
            'valor_centavos' => 'integer',
            'vence_em' => 'date',
            'pago_em' => 'datetime',
            'periodo_inicio' => 'date',
            'periodo_fim' => 'date',
            'pix_expira_em' => 'datetime',
            'mes_referencia' => 'date',
            'paga_ate_origem' => 'date',
            'documentos_excedentes' => 'integer',
            'valor_unitario_centavos' => 'integer',
        ];
    }

    /** @return BelongsTo<Assinatura, $this> */
    public function assinatura(): BelongsTo
    {
        return $this->belongsTo(Assinatura::class);
    }

    protected static function newFactory(): CobrancaFactory
    {
        return CobrancaFactory::new();
    }
}
