<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use InvalidArgumentException;

/** Quantidade em milésimos inteiros: nada de float em dado fiscal. */
final class Quantidade
{
    public static function paraMilesimos(string $texto): int
    {
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/', $texto, $m) !== 1) {
            throw new InvalidArgumentException('Quantidade inválida: use até 3 casas decimais com ponto.');
        }
        $milesimos = ((int) $m[1]) * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
        if ($milesimos <= 0) {
            throw new InvalidArgumentException('A quantidade deve ser maior que zero.');
        }

        return $milesimos;
    }

    public static function formatar(int $milesimos, int $casas): string
    {
        $inteiro = intdiv($milesimos, 1000);
        $fracao = str_pad((string) ($milesimos % 1000), 3, '0', STR_PAD_LEFT);

        return $inteiro.'.'.str_pad($fracao, $casas, '0');
    }
}
