<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use App\Modules\Shared\Domain\Exceptions\CnpjInvalidoException;
use Stringable;

/**
 * CNPJ numérico ou alfanumérico (IN RFB 2.229/2024): 12 posições [0-9A-Z]
 * e 2 dígitos verificadores numéricos. O DV é o módulo 11 de sempre,
 * com o valor de cada caractere = código ASCII − 48.
 */
final class Cnpj implements Stringable
{
    private const PESOS_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private const PESOS_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private function __construct(public readonly string $valor) {}

    public static function de(string $entrada): self
    {
        $valor = strtoupper(str_replace(['.', '/', '-', ' '], '', trim($entrada)));

        if (! self::ehValido($valor)) {
            throw CnpjInvalidoException::para($entrada);
        }

        return new self($valor);
    }

    public static function tentar(string $entrada): ?self
    {
        try {
            return self::de($entrada);
        } catch (CnpjInvalidoException) {
            return null;
        }
    }

    public function formatado(): string
    {
        $v = $this->valor;

        return substr($v, 0, 2).'.'.substr($v, 2, 3).'.'.substr($v, 5, 3).'/'.substr($v, 8, 4).'-'.substr($v, 12, 2);
    }

    public function __toString(): string
    {
        return $this->valor;
    }

    private static function ehValido(string $valor): bool
    {
        if (preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $valor) !== 1 || preg_match('/^(.)\1{13}$/', $valor) === 1) {
            return false;
        }

        $base = substr($valor, 0, 12);
        $dv1 = self::digito($base, self::PESOS_DV1);
        $dv2 = self::digito($base.$dv1, self::PESOS_DV2);

        return substr($valor, 12) === $dv1.$dv2;
    }

    /** @param list<int> $pesos */
    private static function digito(string $base, array $pesos): string
    {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += (ord($base[$i]) - 48) * $peso;
        }
        $resto = $soma % 11;

        return (string) ($resto < 2 ? 0 : 11 - $resto);
    }
}
