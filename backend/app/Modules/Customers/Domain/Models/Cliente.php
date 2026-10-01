<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Models;

use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property TipoCliente $tipo
 * @property string $nome
 * @property ?string $cpf_cnpj
 * @property ?string $inscricao_estadual
 * @property bool $ie_isento
 * @property ?string $email
 * @property ?string $telefone
 * @property ?string $uf
 * @property array<int, string> $tags
 * @property ?string $origem
 * @property EstagioCliente $estagio
 */
class Cliente extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ClienteFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'tipo', 'nome', 'cpf_cnpj', 'inscricao_estadual', 'ie_isento', 'email', 'telefone',
        'logradouro', 'numero', 'bairro', 'cidade', 'uf', 'cep', 'codigo_ibge',
        'tags', 'origem', 'estagio',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoCliente::class,
            'ie_isento' => 'boolean',
            'tags' => 'array',
            'estagio' => EstagioCliente::class,
        ];
    }

    /** @return HasMany<Contato, $this> */
    public function contatos(): HasMany
    {
        return $this->hasMany(Contato::class);
    }

    protected static function newFactory(): ClienteFactory
    {
        return ClienteFactory::new();
    }
}
