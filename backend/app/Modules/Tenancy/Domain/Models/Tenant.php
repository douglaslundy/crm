<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $nome
 * @property string $cnpj
 * @property string $status
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = ['nome', 'cnpj', 'status'];

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
