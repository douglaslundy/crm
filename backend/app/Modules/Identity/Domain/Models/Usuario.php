<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Não usa BelongsToTenant: o login precisa localizar o usuário antes de
 * existir contexto de tenant, e o admin da plataforma não tem tenant.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $nome
 * @property string $email
 * @property string $password
 * @property Papel $papel
 * @property bool $ativo
 * @property-read ?Tenant $tenant
 */
class Usuario extends Authenticatable
{
    /** @use HasFactory<UsuarioFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    protected $table = 'usuarios';

    /** tenant_id e papel são atribuídos só pelas Actions, via forceFill. */
    protected $fillable = ['nome', 'email', 'password', 'ativo'];

    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'papel' => Papel::class,
            'ativo' => 'boolean',
        ];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $valor): string => mb_strtolower(trim($valor)));
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Usuario não tem TenantScope (ver ADR 0002): toda busca de usuário da empresa passa por aqui.
     *
     * @param  Builder<Usuario>  $query
     */
    public function scopeDaEmpresa(Builder $query, string $tenantId): void
    {
        $query->where('tenant_id', $tenantId);
    }

    protected static function newFactory(): UsuarioFactory
    {
        return UsuarioFactory::new();
    }
}
