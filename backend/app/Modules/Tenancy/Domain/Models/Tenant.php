<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Carbon\CarbonInterface;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $razao_social
 * @property ?string $nome_fantasia
 * @property string $cnpj
 * @property ?string $plano_id
 * @property SituacaoAssinatura $situacao
 * @property ?CarbonInterface $teste_termina_em
 * @property ?CarbonInterface $situacao_alterada_em
 * @property CarbonInterface $created_at
 * @property-read ?Plano $plano
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;

    /** plano_id e situacao mudam só pelas Actions (forceFill), nunca por fill(). */
    protected $fillable = ['razao_social', 'nome_fantasia', 'cnpj'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'situacao' => SituacaoAssinatura::class,
            'teste_termina_em' => 'date',
            'situacao_alterada_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plano, $this> */
    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    public function nomeDeExibicao(): string
    {
        return $this->nome_fantasia ?? $this->razao_social;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
