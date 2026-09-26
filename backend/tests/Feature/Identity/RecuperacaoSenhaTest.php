<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RecuperacaoSenhaTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAGEM = 'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.';

    public function test_email_inexistente_responde_200_com_a_mesma_mensagem(): void
    {
        Notification::fake();

        $this->spa()->postJson('/api/app/auth/esqueci-senha', ['email' => 'ninguem@x.com'])
            ->assertOk()
            ->assertJsonPath('message', self::MENSAGEM);

        Notification::assertNothingSent();
    }

    public function test_envio_do_link_acontece_depois_da_resposta(): void
    {
        // Enviar o e-mail dentro da requisição deixaria a resposta mais lenta só
        // para e-mails cadastrados — o tempo revelaria quem tem conta.
        Bus::fake();
        Notification::fake();
        Usuario::factory()->for(Tenant::factory())->create(['email' => 'ana@empresa.com']);

        $this->spa()->postJson('/api/app/auth/esqueci-senha', ['email' => 'ana@empresa.com'])->assertOk();

        Notification::assertNothingSent();
        Bus::assertDispatchedAfterResponse(CallQueuedClosure::class);
    }

    public function test_fluxo_completo_redefine_a_senha(): void
    {
        Notification::fake();
        $usuario = Usuario::factory()->for(Tenant::factory())->create(['email' => 'ana@empresa.com']);

        $this->spa()->postJson('/api/app/auth/esqueci-senha', ['email' => 'ana@empresa.com'])
            ->assertOk()
            ->assertJsonPath('message', self::MENSAGEM);

        $token = null;
        Notification::assertSentTo($usuario, ResetPassword::class, function (ResetPassword $n) use (&$token): bool {
            $token = $n->token;

            return true;
        });

        $this->spa()->postJson('/api/app/auth/redefinir-senha', [
            'token' => $token,
            'email' => 'ana@empresa.com',
            'password' => 'NovaSenha1',
            'password_confirmation' => 'NovaSenha1',
        ])->assertOk();

        $this->assertTrue(Hash::check('NovaSenha1', $usuario->fresh()->password));
    }

    public function test_token_invalido_devolve_422_e_nao_muda_a_senha(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create(['email' => 'ana@empresa.com']);
        $hashAntes = $usuario->password;

        $this->spa()->postJson('/api/app/auth/redefinir-senha', [
            'token' => 'token-adulterado',
            'email' => 'ana@empresa.com',
            'password' => 'NovaSenha1',
            'password_confirmation' => 'NovaSenha1',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Link inválido ou expirado.');

        $this->assertSame($hashAntes, $usuario->fresh()->password);
    }

    public function test_senha_fraca_e_recusada(): void
    {
        $this->spa()->postJson('/api/app/auth/redefinir-senha', [
            'token' => 'x',
            'email' => 'ana@empresa.com',
            'password' => 'fraca',
            'password_confirmation' => 'fraca',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    private function redefinir(string $token): TestResponse
    {
        return $this->spa()->postJson('/api/app/auth/redefinir-senha', [
            'token' => $token, 'email' => 'ana@empresa.com',
            'password' => 'NovaSenha1', 'password_confirmation' => 'NovaSenha1',
        ]);
    }

    public function test_token_expirado_e_recusado(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create(['email' => 'ana@empresa.com']);
        $token = Password::broker()->createToken($usuario);

        $this->travel(61)->minutes();

        $this->redefinir($token)->assertStatus(422)->assertJsonPath('errors.email.0', 'Link inválido ou expirado.');
    }

    public function test_token_nao_pode_ser_reutilizado(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create(['email' => 'ana@empresa.com']);
        $token = Password::broker()->createToken($usuario);

        $this->redefinir($token)->assertOk();
        $this->redefinir($token)->assertStatus(422)->assertJsonPath('errors.email.0', 'Link inválido ou expirado.');
    }
}
