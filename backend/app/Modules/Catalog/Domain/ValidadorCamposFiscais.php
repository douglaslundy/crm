<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * Resolver puro: valor malformado vira `null`, nunca o valor cru (lixo
 * nunca parece preenchido). `0` é um valor válido de origem.
 */
final class ValidadorCamposFiscais
{
    public static function ncm(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $limpo = preg_replace('/\D/', '', $valor) ?? '';

        return preg_match('/^\d{8}$/', $limpo) === 1 ? $limpo : null;
    }

    public static function cest(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $limpo = preg_replace('/\D/', '', $valor) ?? '';

        return preg_match('/^\d{7}$/', $limpo) === 1 ? $limpo : null;
    }

    public static function origem(int|string|null $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $v = (int) $valor;

        return $v >= 0 && $v <= 8 ? $v : null;
    }
}
