<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\MotivoDePerdaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $nome
 * @property bool $ativo
 */
class MotivoDePerda extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MotivoDePerdaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_motivos_de_perda';

    protected $fillable = ['nome', 'ativo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    protected static function newFactory(): MotivoDePerdaFactory
    {
        return MotivoDePerdaFactory::new();
    }
}
