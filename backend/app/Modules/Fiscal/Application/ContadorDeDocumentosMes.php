<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Contracts\ContadorDeUsoMensal;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonInterface;

/** Homologação é teste e não consome o plano (spec F3a §2). */
final class ContadorDeDocumentosMes implements ContadorDeUsoMensal
{
    public function recurso(): Recurso
    {
        return Recurso::DocumentosMes;
    }

    public function contar(Tenant $tenant): int
    {
        return $this->contarNoMes($tenant, now());
    }

    public function contarNoMes(Tenant $tenant, CarbonInterface $mes): int
    {
        return Nota::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [StatusNota::Autorizada, StatusNota::Cancelando, StatusNota::Cancelada])
            ->where('ambiente', 'PRODUCAO')
            ->whereBetween('emitido_em', [$mes->copy()->startOfMonth(), $mes->copy()->endOfMonth()])
            ->count();
    }
}
