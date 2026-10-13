<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;

/** Monta `wa.me` e `mailto`. Dado ausente ou fora do padrão bloqueia: nunca se chuta número. */
final class LinkDeContato
{
    public function whatsapp(?string $telefone): string
    {
        $digitos = preg_replace('/\D+/', '', $telefone ?? '') ?? '';

        $numero = in_array(strlen($digitos), [12, 13], true) && str_starts_with($digitos, '55')
            ? substr($digitos, 2)
            : $digitos;

        // Nacional: DDD de 2 dígitos (sem zero à esquerda) + 8 ou 9 dígitos do número.
        if (! in_array(strlen($numero), [10, 11], true) || $numero[0] === '0') {
            throw new RegraDoCrmException(
                'Cadastre o telefone do cliente com DDD (10 ou 11 dígitos) para abrir o WhatsApp.',
                'TELEFONE_INVALIDO',
            );
        }

        return "https://wa.me/55{$numero}";
    }

    public function email(?string $email): string
    {
        $limpo = trim($email ?? '');

        if ($limpo === '' || filter_var($limpo, FILTER_VALIDATE_EMAIL) === false) {
            throw new RegraDoCrmException('Cadastre um e-mail válido para o cliente antes de enviar.', 'EMAIL_INVALIDO');
        }

        return "mailto:{$limpo}";
    }
}
