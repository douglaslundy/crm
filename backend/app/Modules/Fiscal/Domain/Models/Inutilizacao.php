<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\InutilizacaoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $emitente_id
 * @property string $serie
 * @property int $numero_inicial
 * @property int $numero_final
 * @property string $justificativa
 * @property string $ambiente
 * @property StatusInutilizacao $status
 * @property ?string $cstat
 * @property ?string $motivo
 * @property ?string $protocolo
 * @property ?string $xml_resposta
 * @property ?string $usuario_id
 * @property CarbonInterface $created_at
 */
class Inutilizacao extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InutilizacaoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'inutilizacoes';

    protected $fillable = [
        'emitente_id', 'serie', 'numero_inicial', 'numero_final', 'justificativa', 'ambiente',
        'status', 'cstat', 'motivo', 'protocolo', 'xml_resposta', 'usuario_id',
    ];

    protected $hidden = ['xml_resposta'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => StatusInutilizacao::class];
    }

    protected static function newFactory(): InutilizacaoFactory
    {
        return InutilizacaoFactory::new();
    }
}
