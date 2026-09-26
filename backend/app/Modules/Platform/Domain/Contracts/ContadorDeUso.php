<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** Cada módulo registra o contador do recurso que ele controla. */
interface ContadorDeUso
{
    public function recurso(): Recurso;

    public function contar(Tenant $tenant): int;
}
