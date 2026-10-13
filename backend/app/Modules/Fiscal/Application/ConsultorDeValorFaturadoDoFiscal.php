<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Crm\Domain\Contracts\ConsultorDeValorFaturado;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;

/** Ponte Fiscal → CRM de leitura: o CRM nunca importa o Model da nota. */
final class ConsultorDeValorFaturadoDoFiscal implements ConsultorDeValorFaturado
{
    public function __construct(private readonly EntitlementService $planos) {}

    public function disponivel(Usuario $autor): bool
    {
        return $autor->tenant !== null
            && ($this->planos->temModulo($autor->tenant, Modulo::FiscalNfe) || $this->planos->temModulo($autor->tenant, Modulo::FiscalNfse));
    }

    public function totalAutorizadoCentavos(array $notasIds): int
    {
        if ($notasIds === []) {
            return 0;
        }

        return (int) Nota::query()->whereIn('id', $notasIds)->where('status', StatusNota::Autorizada)->sum('total_centavos');
    }
}
