<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\NegocioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $titulo
 * @property string $cliente_id
 * @property string $funil_id
 * @property string $etapa_id
 * @property string $responsavel_id
 * @property StatusDoNegocio $status
 * @property int $valor_centavos
 * @property ?CarbonInterface $previsao_fechamento
 * @property int $posicao_na_etapa
 * @property ?string $motivo_de_perda_id
 * @property ?string $observacao_da_perda
 * @property ?CarbonInterface $fechado_em
 */
class Negocio extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NegocioFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_negocios';

    protected $fillable = [
        'titulo', 'cliente_id', 'funil_id', 'etapa_id', 'responsavel_id', 'status', 'valor_centavos',
        'previsao_fechamento', 'posicao_na_etapa', 'motivo_de_perda_id', 'observacao_da_perda', 'fechado_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatusDoNegocio::class,
            'valor_centavos' => 'integer',
            'posicao_na_etapa' => 'integer',
            'previsao_fechamento' => 'date',
            'fechado_em' => 'datetime',
        ];
    }

    /** @return HasMany<ItemDoNegocio, $this> */
    public function itens(): HasMany
    {
        return $this->hasMany(ItemDoNegocio::class, 'negocio_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * Visibilidade (spec §5): vendedor com o interruptor ligado só enxerga os próprios negócios.
     *
     * @param  Builder<Negocio>  $query
     * @return Builder<Negocio>
     */
    public function scopeVisiveisPara(Builder $query, Usuario $usuario): Builder
    {
        if ($usuario->papel === Papel::Vendedor && ConfiguracaoDoCrm::vendedorVeSoOsSeus()) {
            $query->where('responsavel_id', $usuario->id);
        }

        return $query;
    }

    public function garantirAberto(): void
    {
        if ($this->status !== StatusDoNegocio::Aberto) {
            throw RegraDoCrmException::negocioFechado();
        }
    }

    protected static function newFactory(): NegocioFactory
    {
        return NegocioFactory::new();
    }
}
