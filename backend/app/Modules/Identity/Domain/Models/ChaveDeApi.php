<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\ChaveDeApiFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $usuario_id
 * @property string $nome
 * @property AmbienteDaChave $ambiente
 * @property string $prefixo
 * @property string $ultimos4
 * @property string $hash
 * @property ?CarbonInterface $ultimo_uso_em
 * @property ?CarbonInterface $revogada_em
 * @property-read Usuario $usuario
 */
class ChaveDeApi extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ChaveDeApiFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'chaves_api';

    /** Tudo muda só pelas Actions (forceFill). */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ambiente' => AmbienteDaChave::class,
            'ultimo_uso_em' => 'datetime',
            'revogada_em' => 'datetime',
        ];
    }

    public function estaRevogada(): bool
    {
        return $this->revogada_em !== null;
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    protected static function newFactory(): ChaveDeApiFactory
    {
        return ChaveDeApiFactory::new();
    }
}
