<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\EmitenteSerieFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $emitente_id
 * @property ModeloDocumento $modelo
 * @property string $serie
 * @property int $proximo_numero Contador de homologação
 * @property int $proximo_numero_producao
 */
class EmitenteSerie extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EmitenteSerieFactory> */
    use HasFactory;

    protected $fillable = ['emitente_id', 'modelo', 'serie', 'proximo_numero', 'proximo_numero_producao'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['modelo' => ModeloDocumento::class];
    }

    /** Homologação usa `proximo_numero`; produção usa `proximo_numero_producao` (contadores independentes). */
    public static function colunaDoNumero(AmbienteFiscal $ambiente): string
    {
        return $ambiente === AmbienteFiscal::Producao ? 'proximo_numero_producao' : 'proximo_numero';
    }

    public function proximoNumeroDo(AmbienteFiscal $ambiente): int
    {
        return (int) $this->getAttribute(self::colunaDoNumero($ambiente));
    }

    public function definirProximoNumero(AmbienteFiscal $ambiente, int $numero): void
    {
        $this->update([self::colunaDoNumero($ambiente) => $numero]);
    }

    /** @return BelongsTo<Emitente, $this> */
    public function emitente(): BelongsTo
    {
        return $this->belongsTo(Emitente::class);
    }

    protected static function newFactory(): EmitenteSerieFactory
    {
        return EmitenteSerieFactory::new();
    }
}
