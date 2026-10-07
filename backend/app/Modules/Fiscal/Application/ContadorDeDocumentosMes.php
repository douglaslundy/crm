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
        return $this->contarNoMes($tenant, now((string) config('app.fuso_negocio')));
    }

    /** O mês é o do fuso de quem pediu (o do negócio); o banco guarda no fuso da aplicação e o Eloquent não converte. */
    private function noFusoDoBanco(CarbonInterface $instante): CarbonInterface
    {
        return $instante->copy()->setTimezone((string) config('app.timezone'));
    }

    public function contarNoMes(Tenant $tenant, CarbonInterface $mes): int
    {
        return Nota::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [StatusNota::Autorizada, StatusNota::Cancelando, StatusNota::Cancelada])
            ->where('ambiente', 'PRODUCAO')
            ->whereBetween('emitido_em', [$this->noFusoDoBanco($mes->copy()->startOfMonth()), $this->noFusoDoBanco($mes->copy()->endOfMonth())])
            ->count();
    }
}
