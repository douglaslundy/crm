<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use InvalidArgumentException;

/** O modelo (55 ou 65) mora nas posições 21 e 22 da chave de 44 dígitos: cUF(2) AAMM(4) CNPJ(14) mod(2)... */
final class ModeloDaChave
{
    public static function de(string $chave): int
    {
        if (preg_match('/^\d{44}$/', $chave) !== 1) {
            throw new InvalidArgumentException('Chave de acesso inválida: esperados 44 dígitos.');
        }
        $modelo = (int) substr($chave, 20, 2);
        if ($modelo !== 55 && $modelo !== 65) {
            throw new InvalidArgumentException("Modelo {$modelo} não suportado (esperado 55 ou 65).");
        }

        return $modelo;
    }
}
