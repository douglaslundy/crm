<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Notifications\ConviteDeUsuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UsuariosDaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $dono;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Usuarios->value => 3]))->create();
        $this->dono = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Proprietario]);
    }

    private function membro(Papel $papel = Papel::Vendedor, bool $ativo = true): Usuario
    {
        return Usuario::factory()->for($this->tenant)->create(['papel' => $papel, 'ativo' => $ativo]);
    }

    public function test_lista_so_usuarios_da_propria_empresa(): void
    {
        $this->membro();
        Usuario::factory()->for(Tenant::factory())->create();

        $this->spa()->actingAs($this->dono)->getJson('/api/app/usuarios')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_vendedor_nao_gerencia_usuarios(): void
    {
        $this->spa()->actingAs($this->membro())->getJson('/api/app/usuarios')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_convite_cria_usuario_e_envia_link_de_72_horas(): void
    {
        $resposta = $this->spa()->actingAs($this->dono)
            ->postJson('/api/app/usuarios', ['nome' => 'Bia', 'email' => 'Bia@X.com', 'papel' => 'FISCAL'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'bia@x.com')
            ->assertJsonPath('data.papel', 'FISCAL');

        $convidado = Usuario::query()->findOrFail($resposta->json('data.id'));
        $this->assertSame($this->tenant->id, $convidado->tenant_id);

        $token = null;
        Notification::assertSentTo($convidado, ConviteDeUsuario::class, function (ConviteDeUsuario $n) use (&$token): bool {
            $token = $n->token;

            return true;
        });
        $this->assertIsString($token);

        $this->travel(71)->hours();
        $this->spa()->postJson('/api/app/auth/aceitar-convite', [
            'token' => $token, 'email' => 'bia@x.com', 'password' => 'Senha123', 'password_confirmation' => 'Senha123',
        ])->assertOk();

        $this->spa()->postJson('/api/app/auth/login', ['email' => 'bia@x.com', 'password' => 'Senha123'])->assertOk();
    }

    public function test_convite_expira_depois_de_72_horas(): void
    {
        $this->spa()->actingAs($this->dono)
            ->postJson('/api/app/usuarios', ['nome' => 'Bia', 'email' => 'bia@x.com', 'papel' => 'FISCAL'])
            ->assertCreated();
        $token = null;
        Notification::assertSentTo(Usuario::query()->where('email', 'bia@x.com')->firstOrFail(), ConviteDeUsuario::class,
            function (ConviteDeUsuario $n) use (&$token): bool {
                $token = $n->token;

                return true;
            });

        $this->travel(73)->hours();
        $this->spa()->postJson('/api/app/auth/aceitar-convite', [
            'token' => $token, 'email' => 'bia@x.com', 'password' => 'Senha123', 'password_confirmation' => 'Senha123',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Convite inválido ou expirado. Use "Esqueci minha senha" na tela de entrada para definir sua senha.');
    }

    public function test_convite_expirado_tem_recuperacao_pelo_esqueci_minha_senha(): void
    {
        $this->spa()->actingAs($this->dono)
            ->postJson('/api/app/usuarios', ['nome' => 'Bia', 'email' => 'bia@x.com', 'papel' => 'FISCAL'])
            ->assertCreated();

        $this->travel(73)->hours();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->spa()->postJson('/api/app/auth/esqueci-senha', ['email' => 'bia@x.com'])->assertOk();
    }

    public function test_convite_respeita_o_limite_do_plano(): void
    {
        $this->membro();
        $this->membro();
        $this->membro(ativo: false); // inativo não conta

        $this->spa()->actingAs($this->dono)
            ->postJson('/api/app/usuarios', ['nome' => 'Bia', 'email' => 'bia@x.com', 'papel' => 'FISCAL'])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'LIMITE_DO_PLANO')
            ->assertJsonPath('message', 'Seu plano permite até 3 usuários ativos.');

        $this->assertDatabaseMissing('usuarios', ['email' => 'bia@x.com']);
    }

    public function test_reativar_respeita_o_limite_do_plano(): void
    {
        $this->membro();
        $this->membro();
        $inativo = $this->membro(ativo: false);

        $this->spa()->actingAs($this->dono)->postJson("/api/app/usuarios/{$inativo->id}/reativar")
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'LIMITE_DO_PLANO');
    }

    public function test_desativar_e_reativar(): void
    {
        $membro = $this->membro();

        $this->spa()->actingAs($this->dono)->postJson("/api/app/usuarios/{$membro->id}/desativar")
            ->assertOk()
            ->assertJsonPath('data.ativo', false);
        $this->spa()->actingAs($this->dono)->postJson("/api/app/usuarios/{$membro->id}/reativar")
            ->assertOk()
            ->assertJsonPath('data.ativo', true);

        $this->assertSame(2, Activity::query()->whereIn('event', ['usuario_desativado', 'usuario_reativado'])->count());
    }

    public function test_admin_nao_concede_papel_de_proprietario_mas_o_proprietario_pode(): void
    {
        $admin = $this->membro(Papel::Admin);
        $membro = $this->membro();

        $this->spa()->actingAs($admin)->putJson("/api/app/usuarios/{$membro->id}", ['nome' => 'X', 'papel' => 'PROPRIETARIO'])
            ->assertForbidden();

        // Troca de usuário autenticado entre requisições: sem isso o Sanctum
        // (AuthenticateSession) responde 401 espúrio na segunda chamada.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->spa()->actingAs($this->dono)->putJson("/api/app/usuarios/{$membro->id}", ['nome' => 'X', 'papel' => 'PROPRIETARIO'])
            ->assertOk()
            ->assertJsonPath('data.papel', 'PROPRIETARIO');
    }

    public function test_ninguem_altera_o_proprietario_nem_desativa_a_si_mesmo(): void
    {
        $admin = $this->membro(Papel::Admin);

        $this->spa()->actingAs($admin)->putJson("/api/app/usuarios/{$this->dono->id}", ['nome' => 'X', 'papel' => 'LEITURA'])
            ->assertForbidden();
        $this->spa()->actingAs($admin)->postJson("/api/app/usuarios/{$this->dono->id}/desativar")->assertForbidden();
        $this->spa()->actingAs($admin)->postJson("/api/app/usuarios/{$admin->id}/desativar")->assertForbidden();
    }

    public function test_papel_da_plataforma_e_recusado(): void
    {
        $this->spa()->actingAs($this->dono)
            ->postJson('/api/app/usuarios', ['nome' => 'Bia', 'email' => 'bia@x.com', 'papel' => 'SUPERADMIN'])
            ->assertJsonValidationErrors('papel');
    }

    public function test_edicao_de_papel_e_auditada(): void
    {
        $membro = $this->membro();

        $this->spa()->actingAs($this->dono)->putJson("/api/app/usuarios/{$membro->id}", ['nome' => 'Novo Nome', 'papel' => 'ADMIN'])
            ->assertOk()
            ->assertJsonPath('data.nome', 'Novo Nome');

        $registro = Activity::query()->where('event', 'usuario_editado')->firstOrFail();
        $this->assertSame('VENDEDOR', $registro->getExtraProperty('papel_de'));
        $this->assertSame('ADMIN', $registro->getExtraProperty('papel_para'));
    }

    public function test_usuario_de_outra_empresa_responde_404(): void
    {
        $alheio = Usuario::factory()->for(Tenant::factory())->create(['papel' => Papel::Vendedor]);
        $comoDono = $this->spa()->actingAs($this->dono);

        $comoDono->putJson("/api/app/usuarios/{$alheio->id}", ['nome' => 'X', 'papel' => 'LEITURA'])->assertNotFound();
        $comoDono->postJson("/api/app/usuarios/{$alheio->id}/desativar")->assertNotFound();
        $comoDono->postJson("/api/app/usuarios/{$alheio->id}/reativar")->assertNotFound();
        $this->assertTrue($alheio->refresh()->ativo);
    }
}
