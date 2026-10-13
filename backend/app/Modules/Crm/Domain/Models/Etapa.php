<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\EtapaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $funil_id
 * @property string $nome
 * @property int $posicao
 * @property int $probabilidade
 * @property bool $ativa
 */
class Etapa extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EtapaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_etapas';

    protected $fillable = ['funil_id', 'nome', 'posicao', 'probabilidade', 'ativa'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['posicao' => 'integer', 'probabilidade' => 'integer', 'ativa' => 'boolean'];
    }

    /** @return BelongsTo<Funil, $this> */
    public function funil(): BelongsTo
    {
        return $this->belongsTo(Funil::class, 'funil_id');
    }

    protected static function newFactory(): EtapaFactory
    {
        return EtapaFactory::new();
    }
}
