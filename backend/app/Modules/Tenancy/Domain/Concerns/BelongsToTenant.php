<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Concerns;

use App\Modules\Tenancy\Domain\Exceptions\TenantNaoDefinidoException;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use App\Modules\Tenancy\Domain\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $contexto = app(TenantContext::class)->require();
            $informado = $model->getAttribute('tenant_id');

            if ($informado === null) {
                $model->setAttribute('tenant_id', $contexto);

                return;
            }

            if ($informado !== $contexto) {
                throw TenantNaoDefinidoException::divergente($contexto, (string) $informado);
            }
        });
    }

    /** @return Builder<static> */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
