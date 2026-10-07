<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use Carbon\CarbonImmutable;

final class PeriodoPago
{
    /**
     * Período que um pagamento aprovado cobre. Pagamento antecipado continua do fim do período já pago;
     * pagamento tardio (ou o primeiro) começa hoje, para não cobrir dias em que o acesso ficou suspenso.
     *
     * @return array{inicio: CarbonImmutable, fim: CarbonImmutable}
     */
    public static function proximo(?CarbonImmutable $pagaAte, CarbonImmutable $hoje, CicloDeCobranca $ciclo): array
    {
        $inicio = $pagaAte !== null && $pagaAte->greaterThan($hoje) ? $pagaAte : $hoje;

        return ['inicio' => $inicio, 'fim' => $inicio->addMonthsNoOverflow($ciclo->meses())];
    }
}
