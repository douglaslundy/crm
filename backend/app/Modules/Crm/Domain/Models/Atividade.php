<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\AtividadeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $cliente_id
 * @property ?string $negocio_id
 * @property TipoDeAtividade $tipo
 * @property string $titulo
 * @property ?string $descricao
 * @property CarbonInterface $agendada_para
 * @property ?CarbonInterface $concluida_em
 * @property string $responsavel_id
 * @property string $criado_por
 */
class Atividade extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AtividadeFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_atividades';

    protected $fillable = [
        'cliente_id', 'negocio_id', 'tipo', 'titulo', 'descricao', 'agendada_para', 'concluida_em',
        'responsavel_id', 'criado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoDeAtividade::class,
            'agendada_para' => 'datetime',
            'concluida_em' => 'datetime',
        ];
    }

    public function estaConcluida(): bool
    {
        return $this->concluida_em !== null;
    }

    /**
     * Vendedor restrito vê as atividades em que é responsável e as de negócios que enxerga.
     *
     * @param  Builder<Atividade>  $query
     * @return Builder<Atividade>
     */
    public function scopeVisiveisPara(Builder $query, Usuario $usuario): Builder
    {
        if (! Negocio::restritoParaVendedor($usuario)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($usuario): void {
            $q->where('responsavel_id', $usuario->id)
                ->orWhereIn('negocio_id', Negocio::query()->visiveisPara($usuario)->select('id'));
        });
    }

    protected static function newFactory(): AtividadeFactory
    {
        return AtividadeFactory::new();
    }
}
