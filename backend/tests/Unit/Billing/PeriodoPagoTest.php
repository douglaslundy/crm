<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\PeriodoPago;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PeriodoPagoTest extends TestCase
{
    public function test_primeiro_pagamento_comeca_hoje(): void
    {
        $p = PeriodoPago::proximo(null, CarbonImmutable::parse('2026-10-07'), CicloDeCobranca::Mensal);

        $this->assertSame(['2026-10-07', '2026-11-07'], [$p['inicio']->toDateString(), $p['fim']->toDateString()]);
    }

    public function test_pagamento_antecipado_continua_do_fim_do_periodo_ja_pago(): void
    {
        $p = PeriodoPago::proximo(CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-07'), CicloDeCobranca::Mensal);

        $this->assertSame(['2026-10-20', '2026-11-20'], [$p['inicio']->toDateString(), $p['fim']->toDateString()]);
    }

    public function test_pagamento_tardio_comeca_hoje_e_nao_no_vencimento_antigo(): void
    {
        $p = PeriodoPago::proximo(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-07'), CicloDeCobranca::Anual);

        $this->assertSame(['2026-10-07', '2027-10-07'], [$p['inicio']->toDateString(), $p['fim']->toDateString()]);
    }

    public function test_fim_de_mes_nao_transborda(): void
    {
        $p = PeriodoPago::proximo(null, CarbonImmutable::parse('2026-01-31'), CicloDeCobranca::Mensal);

        $this->assertSame('2026-02-28', $p['fim']->toDateString());
    }
}
