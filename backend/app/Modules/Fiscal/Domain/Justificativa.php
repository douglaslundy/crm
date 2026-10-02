<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use App\Modules\Fiscal\Domain\Exceptions\JustificativaInvalidaException;

/** Justificativa de cancelamento ou inutilização: 15 a 255 caracteres depois de sanear (regra da SEFAZ). */
final readonly class Justificativa
{
    private const MINIMO = 15;

    private const MAXIMO = 255;

    private function __construct(public string $texto) {}

    public static function de(string $texto): self
    {
        $limpo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto) ?? '';
        $limpo = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $limpo) ?? '';
        $limpo = trim((string) preg_replace('/\s+/u', ' ', $limpo));

        $tamanho = mb_strlen($limpo);
        if ($tamanho < self::MINIMO || $tamanho > self::MAXIMO) {
            throw new JustificativaInvalidaException;
        }

        return new self($limpo);
    }
}
