<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Middleware\GarantirUsuarioAtivo;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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

    /**
     * A checagem de usuário ativo precisa rodar antes da resolução de tenant e do
     * route model binding; senão um usuário desativado ainda define contexto de
     * tenant e resolve bindings antes de ser barrado.
     */
    public function test_garantir_usuario_ativo_roda_antes_do_tenant_e_do_binding_na_rota_me(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/app/auth/me', 'GET'));

        $middlewares = app('router')->gatherRouteMiddleware($route);

        $indiceAtivo = array_search(GarantirUsuarioAtivo::class, $middlewares, true);
        $indiceTenant = array_search(DefinirTenantDoUsuario::class, $middlewares, true);
        $indiceBinding = array_search(SubstituteBindings::class, $middlewares, true);

        $this->assertNotFalse($indiceAtivo, 'GarantirUsuarioAtivo não está na lista de middlewares da rota.');
        $this->assertNotFalse($indiceTenant, 'DefinirTenantDoUsuario não está na lista de middlewares da rota.');
        $this->assertNotFalse($indiceBinding, 'SubstituteBindings não está na lista de middlewares da rota.');
        $this->assertLessThan($indiceTenant, $indiceAtivo);
        $this->assertLessThan($indiceBinding, $indiceTenant);
    }
}
