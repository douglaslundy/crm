<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Charada de soma ("3 + 4") conferida no servidor: o token criptografado leva a resposta e a
 * validade, então o cliente não consegue forjar nem pular o desafio.
 * shortcut: o token vale para várias tentativas dentro da validade; o limite de requisições da rota segura o abuso.
 */
final class DesafioAntiRobo
{
    private const VALIDADE_SEGUNDOS = 1800;

    /** @return array{pergunta: string, token: string} */
    public function gerar(): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);

        return [
            'pergunta' => "$a + $b",
            'token' => Crypt::encryptString(($a + $b).'|'.(time() + self::VALIDADE_SEGUNDOS)),
        ];
    }

    public function conferir(string $token, string $resposta): bool
    {
        try {
            [$esperada, $expira] = explode('|', Crypt::decryptString($token)) + [1 => '0'];
        } catch (DecryptException) {
            return false;
        }

        return (int) $expira >= time() && trim($resposta) === $esperada;
    }
}
