<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $attrs */
    private function usuario(array $attrs = []): Usuario
    {
        return Usuario::factory()->for(Tenant::factory())->create(array_merge([
            'email' => 'ana@empresa.com',
            'password' => 'Senha123',
        ], $attrs));
    }

    public function test_login_valido_devolve_usuario_com_tenant(): void
    {
        $usuario = $this->usuario();

        $this->spa()->postJson('/api/app/auth/login', ['email' => 'ana@empresa.com', 'password' => 'Senha123'])
            ->assertOk()
            ->assertJsonPath('data.id', $usuario->id)
            ->assertJsonPath('data.papel', 'PROPRIETARIO')
            ->assertJsonPath('data.tenant.id', $usuario->tenant_id);

        $this->assertAuthenticatedAs($usuario, 'web');
    }

    public function test_email_com_maiusculas_e_espacos_funciona(): void
    {
        $this->usuario();

        $this->spa()->postJson('/api/app/auth/login', ['email' => '  Ana@Empresa.COM ', 'password' => 'Senha123'])
            ->assertOk();
    }

    public function test_senha_errada_devolve_erro_generico(): void
    {
        $this->usuario();

        $this->spa()->postJson('/api/app/auth/login', ['email' => 'ana@empresa.com', 'password' => 'errada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'E-mail ou senha incorretos.');

        $this->assertGuest('web');
    }

    public function test_usuario_inativo_recebe_o_mesmo_erro_generico(): void
    {
        $this->usuario(['ativo' => false]);

        $this->spa()->postJson('/api/app/auth/login', ['email' => 'ana@empresa.com', 'password' => 'Senha123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'E-mail ou senha incorretos.');
    }

    public function test_bloqueia_apos_cinco_tentativas(): void
    {
        $this->usuario();

        for ($i = 0; $i < 5; $i++) {
            $this->spa()->postJson('/api/app/auth/login', ['email' => 'ana@empresa.com', 'password' => 'errada']);
        }

        $this->spa()->postJson('/api/app/auth/login', ['email' => 'ana@empresa.com', 'password' => 'Senha123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn (string $msg) => str_starts_with($msg, 'Muitas tentativas.'));
    }

    public function test_atras_de_proxy_confiavel_o_bloqueio_e_por_cliente_real(): void
    {
        // phpunit.xml define TRUSTED_PROXIES=127.0.0.1 (o REMOTE_ADDR dos testes).
        $this->usuario();

        for ($i = 0; $i < 5; $i++) {
            $this->spa()->withHeader('X-Forwarded-For', '203.0.113.10')
                ->postJson('/api/app/auth/login', ['email' => 'ana@empresa.com', 'password' => 'errada']);
        }

        $this->spa()->withHeader('X-Forwarded-For', '198.51.100.20')
            ->postJson('/api/app/auth/login', ['email' => 'ana@empresa.com', 'password' => 'Senha123'])
            ->assertOk();
    }

    public function test_me_sem_sessao_devolve_401(): void
    {
        $this->spa()->getJson('/api/app/auth/me')->assertUnauthorized();
    }

    public function test_me_devolve_usuario_autenticado(): void
    {
        $usuario = $this->usuario();

        $this->actingAs($usuario, 'web')->spa()->getJson('/api/app/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ana@empresa.com')
            ->assertJsonPath('data.tenant.cnpj', $usuario->tenant->cnpj);
    }

    public function test_logout_encerra_a_sessao(): void
    {
        $usuario = $this->usuario();

        $this->actingAs($usuario, 'web')->spa()->postJson('/api/app/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_limite_por_ip_soma_tentativas_de_emails_diferentes(): void
    {
        $this->usuario();

        for ($i = 0; $i < 20; $i++) {
            $this->spa()->postJson('/api/app/auth/login', ['email' => "x{$i}@teste.com", 'password' => 'errada'])
                ->assertStatus(422);
        }

        $this->spa()->postJson('/api/app/auth/login', ['email' => 'ana@empresa.com', 'password' => 'Senha123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn (string $m): bool => str_starts_with($m, 'Muitas tentativas'));
        $this->assertGuest('web');
    }
}
