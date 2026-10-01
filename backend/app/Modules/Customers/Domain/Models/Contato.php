<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\ContatoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $cliente_id
 * @property string $nome
 * @property ?string $cargo
 * @property ?string $email
 * @property ?string $telefone
 */
class Contato extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContatoFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['cliente_id', 'nome', 'cargo', 'email', 'telefone'];

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    protected static function newFactory(): ContatoFactory
    {
        return ContatoFactory::new();
    }
}
