<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\DecisorDeStatusDoCertificado;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DecisorDeStatusDoCertificadoTest extends TestCase
{
    public function test_validade_futura_e_valido(): void
    {
        $this->assertSame(CertificadoStatus::Valido, DecisorDeStatusDoCertificado::para(CarbonImmutable::now()->addYear()));
    }

    public function test_validade_passada_e_vencido(): void
    {
        $this->assertSame(CertificadoStatus::Vencido, DecisorDeStatusDoCertificado::para(CarbonImmutable::now()->subDay()));
    }
}
