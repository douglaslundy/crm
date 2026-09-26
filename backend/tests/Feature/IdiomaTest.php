<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdiomaTest extends TestCase
{
    use RefreshDatabase;

    public function test_mensagens_de_validacao_sao_em_portugues(): void
    {
        $this->spa()->postJson('/api/app/auth/login', ['email' => '', 'password' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn (string $msg) => str_contains($msg, 'campo'));
    }

    public function test_limite_de_requisicoes_responde_em_portugues(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->spa()->postJson('/api/app/auth/esqueci-senha', ['email' => 'ninguem@x.com']);
        }

        $this->spa()->postJson('/api/app/auth/esqueci-senha', ['email' => 'ninguem@x.com'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Muitas tentativas. Aguarde um pouco e tente novamente.');
    }
}
