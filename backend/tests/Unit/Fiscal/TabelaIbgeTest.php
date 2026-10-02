<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\TabelaIbge;
use PHPUnit\Framework\TestCase;

class TabelaIbgeTest extends TestCase
{
    public function test_codigos_das_27_ufs(): void
    {
        $esperado = [
            'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29, 'CE' => 23, 'DF' => 53, 'ES' => 32, 'GO' => 52,
            'MA' => 21, 'MT' => 51, 'MS' => 50, 'MG' => 31, 'PA' => 15, 'PB' => 25, 'PR' => 41, 'PE' => 26, 'PI' => 22,
            'RJ' => 33, 'RN' => 24, 'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42, 'SP' => 35, 'SE' => 28, 'TO' => 17,
        ];
        foreach ($esperado as $uf => $codigo) {
            $this->assertSame($codigo, TabelaIbge::codigoDaUf($uf), $uf);
        }
    }

    public function test_aceita_minuscula_e_recusa_uf_desconhecida(): void
    {
        $this->assertSame(35, TabelaIbge::codigoDaUf('sp'));
        $this->expectException(EmissaoBloqueadaException::class);
        TabelaIbge::codigoDaUf('XX');
    }
}
