<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Models;

use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $plano_id
 * @property Modulo $modulo
 */
class PlanoModulo extends Model
{
    public $timestamps = false;

    protected $table = 'plano_modulos';

    protected $fillable = ['modulo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['modulo' => Modulo::class];
    }
}
