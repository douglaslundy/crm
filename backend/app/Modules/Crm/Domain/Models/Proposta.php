<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $negocio_id
 * @property string $cliente_id
 * @property int $numero
 * @property StatusDaProposta $status
 * @property int $validade_dias
 * @property ?CarbonInterface $validade_em
 * @property int $desconto_centavos
 * @property ?string $observacoes
 * @property ?string $token_hash
 * @property ?string $token
 * @property ?CarbonInterface $enviada_em
 * @property ?CarbonInterface $respondida_em
 * @property ?string $aceite_nome
 * @property ?string $aceite_ip
 * @property ?string $motivo_recusa
 * @property ?list<string> $notas_ids
 * @property string $criado_por
 */
class Proposta extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'crm_propostas';

    protected $fillable = [
        'negocio_id', 'cliente_id', 'numero', 'status', 'validade_dias', 'validade_em', 'desconto_centavos',
        'observacoes', 'criado_por',
    ];

    protected $hidden = ['token', 'token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatusDaProposta::class,
            'numero' => 'integer',
            'validade_dias' => 'integer',
            'desconto_centavos' => 'integer',
            'validade_em' => 'datetime',
            'enviada_em' => 'datetime',
            'respondida_em' => 'datetime',
            'token' => 'encrypted',
            'notas_ids' => 'array',
        ];
    }

    /** @return HasMany<ItemDaProposta, $this> */
    public function itens(): HasMany
    {
        return $this->hasMany(ItemDaProposta::class, 'proposta_id')->orderBy('ordem');
    }

    /** @return BelongsTo<Negocio, $this> */
    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function expirada(): bool
    {
        return $this->status === StatusDaProposta::Enviada && $this->validade_em !== null && $this->validade_em->isPast();
    }

    public function statusApresentado(): string
    {
        return $this->expirada() ? 'EXPIRADA' : $this->status->value;
    }

    public function subtotalCentavos(): int
    {
        return (int) $this->itens->sum(fn (ItemDaProposta $i): int => $i->totalCentavos());
    }

    public function totalCentavos(): int
    {
        return $this->subtotalCentavos() - $this->desconto_centavos;
    }
}
