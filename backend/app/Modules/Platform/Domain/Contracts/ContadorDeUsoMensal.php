<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonInterface;

/** Contador de um recurso que se mede por mês (o fechamento do excedente conta o mês que acabou). */
interface ContadorDeUsoMensal extends ContadorDeUso
{
    /** Uso no mês civil de `$mes` (qualquer dia dele), no fuso da aplicação. */
    public function contarNoMes(Tenant $tenant, CarbonInterface $mes): int;
}
