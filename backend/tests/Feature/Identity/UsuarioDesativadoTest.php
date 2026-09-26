<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuarioDesativadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_desativado_perde_o_acesso_na_proxima_requisicao(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create();
        $this->spa()->actingAs($usuario)->getJson('/api/app/auth/me')->assertOk();

        $usuario->forceFill(['ativo' => false])->save();

        $this->spa()->getJson('/api/app/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sua conta foi desativada. Fale com o administrador da empresa.');
        $this->assertGuest('web');
    }
}
