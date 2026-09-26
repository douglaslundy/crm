<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Model só de teste para exercitar o isolamento por tenant. */
class RegistroDeTeste extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'registros_de_teste';

    protected $fillable = ['descricao', 'tenant_id'];
}
