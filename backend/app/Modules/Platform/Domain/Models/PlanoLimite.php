<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Models;

use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $plano_id
 * @property Recurso $recurso
 * @property int $limite
 */
class PlanoLimite extends Model
{
    public $timestamps = false;

    protected $table = 'plano_limites';

    protected $fillable = ['recurso', 'limite'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recurso' => Recurso::class, 'limite' => 'integer'];
    }
}
