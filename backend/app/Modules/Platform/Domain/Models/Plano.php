<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Models;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\PoliticaExcedente;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Database\Factories\PlanoFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $nome
 * @property ?string $descricao
 * @property int $preco_mensal_centavos
 * @property ?int $preco_anual_centavos
 * @property int $dias_teste
 * @property PoliticaExcedente $politica_excedente
 * @property ?int $preco_documento_excedente_centavos
 * @property bool $ativo
 * @property bool $visivel
 * @property int $ordem
 * @property-read Collection<int, PlanoModulo> $modulos
 * @property-read Collection<int, PlanoLimite> $limites
 */
class Plano extends Model
{
    /** @use HasFactory<PlanoFactory> */
    use HasFactory;

    use HasUuids;

    public const ILIMITADO = -1;

    protected $table = 'planos';

    protected $fillable = [
        'nome', 'descricao', 'preco_mensal_centavos', 'preco_anual_centavos', 'dias_teste',
        'politica_excedente', 'preco_documento_excedente_centavos', 'ativo', 'visivel', 'ordem',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'preco_mensal_centavos' => 'integer',
            'preco_anual_centavos' => 'integer',
            'dias_teste' => 'integer',
            'politica_excedente' => PoliticaExcedente::class,
            'preco_documento_excedente_centavos' => 'integer',
            'ativo' => 'boolean',
            'visivel' => 'boolean',
            'ordem' => 'integer',
        ];
    }

    /** @return HasMany<PlanoModulo, $this> */
    public function modulos(): HasMany
    {
        return $this->hasMany(PlanoModulo::class);
    }

    /** @return HasMany<PlanoLimite, $this> */
    public function limites(): HasMany
    {
        return $this->hasMany(PlanoLimite::class);
    }

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function temModulo(Modulo $modulo): bool
    {
        return $this->modulos->contains(fn (PlanoModulo $m): bool => $m->modulo === $modulo);
    }

    /** Fail-closed: recurso sem linha vale 0 (bloqueado), nunca ilimitado. */
    public function limiteDe(Recurso $recurso): int
    {
        return $this->limites->first(fn (PlanoLimite $l): bool => $l->recurso === $recurso)->limite ?? 0;
    }

    protected static function newFactory(): PlanoFactory
    {
        return PlanoFactory::new();
    }
}
