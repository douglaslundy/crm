<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class DefinirTenantDoUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function executar(?Usuario $usuario): ?string
    {
        $request = Request::create('/api/app/qualquer');
        $request->setUserResolver(fn () => $usuario);
        $capturado = 'nao-executou';

        (new DefinirTenantDoUsuario(app(TenantContext::class)))->handle($request, function () use (&$capturado) {
            $capturado = app(TenantContext::class)->id();

            return new Response;
        });

        return $capturado;
    }

    public function test_define_o_tenant_do_usuario_autenticado(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create();

        $this->assertSame($usuario->tenant_id, $this->executar($usuario));
    }

    public function test_admin_da_plataforma_fica_sem_tenant(): void
    {
        $admin = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Superadmin]);

        $this->assertNull($this->executar($admin));
    }
}
