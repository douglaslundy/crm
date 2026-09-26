<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

/** Valor monetário em centavos inteiros: nunca float. */
final class Dinheiro
{
    private function __construct(public readonly int $centavos) {}

    public static function deCentavos(int $centavos): self
    {
        return new self($centavos);
    }

    public function formatado(): string
    {
        $sinal = $this->centavos < 0 ? '-' : '';
        $absoluto = abs($this->centavos);
        $reais = number_format(intdiv($absoluto, 100), 0, ',', '.');

        return sprintf('%sR$ %s,%02d', $sinal, $reais, $absoluto % 100);
    }
}
