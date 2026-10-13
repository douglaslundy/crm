<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\FunilFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $nome
 * @property bool $padrao
 * @property bool $ativo
 * @property int $posicao
 */
class Funil extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FunilFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_funis';

    protected $fillable = ['nome', 'padrao', 'ativo', 'posicao'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['padrao' => 'boolean', 'ativo' => 'boolean', 'posicao' => 'integer'];
    }

    /** @return HasMany<Etapa, $this> */
    public function etapas(): HasMany
    {
        return $this->hasMany(Etapa::class, 'funil_id')->orderBy('posicao');
    }

    protected static function newFactory(): FunilFactory
    {
        return FunilFactory::new();
    }
}
