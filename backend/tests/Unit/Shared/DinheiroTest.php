<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\Dinheiro;
use PHPUnit\Framework\TestCase;

class DinheiroTest extends TestCase
{
    public function test_formata_em_reais(): void
    {
        $this->assertSame('R$ 0,00', Dinheiro::deCentavos(0)->formatado());
        $this->assertSame('R$ 9,90', Dinheiro::deCentavos(990)->formatado());
        $this->assertSame('R$ 1.234.567,89', Dinheiro::deCentavos(123456789)->formatado());
        $this->assertSame('-R$ 1,50', Dinheiro::deCentavos(-150)->formatado());
    }
}
