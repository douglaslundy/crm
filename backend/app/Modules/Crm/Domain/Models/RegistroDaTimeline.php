<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $cliente_id
 * @property ?string $negocio_id
 * @property ?string $atividade_id
 * @property TipoDeRegistro $tipo
 * @property string $texto
 * @property array<string, mixed> $dados
 * @property ?string $autor_id
 * @property CarbonInterface $ocorreu_em
 */
class RegistroDaTimeline extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $table = 'crm_timeline';

    protected $fillable = [
        'cliente_id', 'negocio_id', 'atividade_id', 'tipo', 'texto', 'dados', 'autor_id', 'ocorreu_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tipo' => TipoDeRegistro::class, 'dados' => 'array', 'ocorreu_em' => 'datetime'];
    }

    /**
     * Vendedor restrito vê registros de negócios que enxerga e o que ele mesmo escreveu.
     *
     * @param  Builder<RegistroDaTimeline>  $query
     * @return Builder<RegistroDaTimeline>
     */
    public function scopeVisiveisPara(Builder $query, Usuario $usuario): Builder
    {
        if (! Negocio::restritoParaVendedor($usuario)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($usuario): void {
            $q->whereIn('negocio_id', Negocio::query()->visiveisPara($usuario)->select('id'))
                ->orWhere('autor_id', $usuario->id);
        });
    }
}
