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

        // tenant_id é imutável depois da criação. insert()/upsert()/query()->update()
        // não disparam eventos de model e ficam fora desta guarda (ver ADR 0002).
        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw TenantNaoDefinidoException::imutavel((string) $model->getOriginal('tenant_id'));
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
