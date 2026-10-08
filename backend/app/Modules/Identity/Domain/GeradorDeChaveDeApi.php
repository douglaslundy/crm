<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Domain\Enums\AmbienteDaChave;

final class GeradorDeChaveDeApi
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const TAMANHO = 40;

    public function gerar(AmbienteDaChave $ambiente): ChaveGerada
    {
        $aleatorio = '';
        $limite = strlen(self::ALFABETO);
        while (strlen($aleatorio) < self::TAMANHO) {
            foreach (str_split(random_bytes(self::TAMANHO)) as $byte) {
                // Descarta o resto da divisão desigual para não enviesar os caracteres.
                if (ord($byte) < 248 && strlen($aleatorio) < self::TAMANHO) {
                    $aleatorio .= self::ALFABETO[ord($byte) % $limite];
                }
            }
        }

        $texto = $ambiente->prefixoDaChave().$aleatorio;

        return new ChaveGerada($texto, $this->hashDe($texto), substr($texto, 0, 12), substr($texto, -4));
    }

    public function hashDe(string $texto): string
    {
        return hash('sha256', $texto);
    }
}
