<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $nota_id
 * @property ?string $status_de
 * @property string $status_para
 * @property ?string $detalhe
 * @property ?string $usuario_id
 * @property CarbonInterface $created_at
 */
class NotaEvento extends Model
{
    use BelongsToTenant;

    protected $table = 'nota_eventos';

    protected $fillable = ['nota_id', 'status_de', 'status_para', 'detalhe', 'usuario_id'];

    public static function registrar(Nota $nota, ?StatusNota $de, StatusNota $para, ?string $detalhe = null, ?string $usuarioId = null): self
    {
        return self::query()->create([
            'nota_id' => $nota->id,
            'status_de' => $de?->value,
            'status_para' => $para->value,
            'detalhe' => $detalhe,
            'usuario_id' => $usuarioId,
        ]);
    }
}
