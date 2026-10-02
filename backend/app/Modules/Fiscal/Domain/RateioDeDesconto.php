<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;

/** Aritmética inteira em centavos. O resíduo do rateio vai para o último item que ainda comporta. */
final class RateioDeDesconto
{
    /** quantidade (milésimos) x valor unitário (centavos), arredondado meio para cima. */
    public static function totalDoItem(int $quantidadeMilesimos, int $valorUnitarioCentavos): int
    {
        return intdiv($quantidadeMilesimos * $valorUnitarioCentavos + 500, 1000);
    }

    /**
     * @param  list<int>  $totaisDosItens
     * @return list<int>
     */
    public static function ratear(int $descontoCentavos, array $totaisDosItens): array
    {
        $subtotal = array_sum($totaisDosItens);
        if ($descontoCentavos < 0 || $descontoCentavos > $subtotal) {
            throw new EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', 'O desconto não pode ser negativo nem maior que o subtotal dos itens.');
        }
        if ($descontoCentavos === 0 || $subtotal === 0) {
            return array_fill(0, count($totaisDosItens), 0);
        }

        $partes = array_map(fn (int $total): int => intdiv($descontoCentavos * $total, $subtotal), $totaisDosItens);
        $residuo = $descontoCentavos - array_sum($partes);

        for ($i = count($partes) - 1; $i >= 0 && $residuo > 0; $i--) {
            $cabe = $totaisDosItens[$i] - $partes[$i];
            $acrescimo = min($residuo, $cabe);
            $partes[$i] += $acrescimo;
            $residuo -= $acrescimo;
        }

        return $partes;
    }
}
