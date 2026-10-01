<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use App\Modules\Shared\Domain\Exceptions\CpfInvalidoException;
use Stringable;

/** CPF: 9 posições numéricas + 2 dígitos verificadores (módulo 11). */
final class Cpf implements Stringable
{
    private const PESOS_DV1 = [10, 9, 8, 7, 6, 5, 4, 3, 2];

    private const PESOS_DV2 = [11, 10, 9, 8, 7, 6, 5, 4, 3, 2];

    private function __construct(public readonly string $valor) {}

    public static function de(string $entrada): self
    {
        $valor = preg_replace('/\D/', '', $entrada) ?? '';

        if (! self::ehValido($valor)) {
            throw CpfInvalidoException::para($entrada);
        }

        return new self($valor);
    }

    public static function tentar(string $entrada): ?self
    {
        try {
            return self::de($entrada);
        } catch (CpfInvalidoException) {
            return null;
        }
    }

    public function formatado(): string
    {
        $v = $this->valor;

        return substr($v, 0, 3).'.'.substr($v, 3, 3).'.'.substr($v, 6, 3).'-'.substr($v, 9, 2);
    }

    public function __toString(): string
    {
        return $this->valor;
    }

    private static function ehValido(string $valor): bool
    {
        if (preg_match('/^\d{11}$/', $valor) !== 1 || preg_match('/^(\d)\1{10}$/', $valor) === 1) {
            return false;
        }

        $base = substr($valor, 0, 9);
        $dv1 = self::digito($base, self::PESOS_DV1);
        $dv2 = self::digito($base.$dv1, self::PESOS_DV2);

        return substr($valor, 9) === $dv1.$dv2;
    }

    /** @param list<int> $pesos */
    private static function digito(string $base, array $pesos): string
    {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += ((int) $base[$i]) * $peso;
        }
        $resto = $soma % 11;

        return (string) ($resto < 2 ? 0 : 11 - $resto);
    }
}
