<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** Homologação é teste e não consome o plano (spec F3a §2). */
final class ContadorDeDocumentosMes implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::DocumentosMes;
    }

    public function contar(Tenant $tenant): int
    {
        return Nota::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', StatusNota::Autorizada)
            ->where('ambiente', 'PRODUCAO')
            ->whereBetween('emitido_em', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }
}
