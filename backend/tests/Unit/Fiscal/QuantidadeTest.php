<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Quantidade;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QuantidadeTest extends TestCase
{
    public function test_converte_texto_em_milesimos(): void
    {
        $this->assertSame(1000, Quantidade::paraMilesimos('1'));
        $this->assertSame(2500, Quantidade::paraMilesimos('2.5'));
        $this->assertSame(1, Quantidade::paraMilesimos('0.001'));
    }

    public function test_recusa_formato_invalido_e_zero(): void
    {
        foreach (['', 'abc', '1.2345', '-1', '1,5', '0', '0.000'] as $texto) {
            try {
                Quantidade::paraMilesimos($texto);
                $this->fail("Aceitou '{$texto}'.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_formata_com_casas_fixas(): void
    {
        $this->assertSame('2.5000', Quantidade::formatar(2500, 4));
        $this->assertSame('2.500', Quantidade::formatar(2500, 3));
        $this->assertSame('0.0010', Quantidade::formatar(1, 4));
    }
}
