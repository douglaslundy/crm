<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Requisição do frontend como o usuário dado. Trocar de usuário com `actingAs()` no mesmo teste dá 401
     * espúrio (AuthenticateSession do Sanctum), então limpamos os guards e a sessão antes.
     */
    protected function spaComo(Authenticatable $usuario): static
    {
        $this->reiniciarAutenticacao();

        return $this->spa()->actingAs($usuario);
    }

    /** Esquece guards e sessão: a próxima requisição do teste começa sem usuário nem cookie. */
    protected function reiniciarAutenticacao(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /** Simula uma requisição vinda do frontend (domínio stateful do Sanctum). */
    protected function spa(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ]);
    }
}
