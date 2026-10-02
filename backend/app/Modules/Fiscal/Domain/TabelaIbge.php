<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;

final class TabelaIbge
{
    private const CODIGOS = [
        'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29, 'CE' => 23, 'DF' => 53, 'ES' => 32, 'GO' => 52,
        'MA' => 21, 'MT' => 51, 'MS' => 50, 'MG' => 31, 'PA' => 15, 'PB' => 25, 'PR' => 41, 'PE' => 26, 'PI' => 22,
        'RJ' => 33, 'RN' => 24, 'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42, 'SP' => 35, 'SE' => 28, 'TO' => 17,
    ];

    public static function codigoDaUf(string $uf): int
    {
        return self::CODIGOS[strtoupper($uf)]
            ?? throw new EmissaoBloqueadaException('FISCAL_UF_INVALIDA', "UF \"{$uf}\" inválida. Corrija o endereço.");
    }
}
