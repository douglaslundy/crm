<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\RegistroDeTeste;
use Tests\TestCase;

/** Route model binding de Model de tenant precisa do contexto já definido. */
class BindingComTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('registros_de_teste', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('descricao');
            $table->timestamps();
        });
        Route::middleware(['api', 'auth:sanctum', DefinirTenantDoUsuario::class])
            ->get('/api/teste/registros/{registro}', fn (RegistroDeTeste $registro) => ['id' => $registro->id]);
    }

    private function registroDe(Tenant $tenant): RegistroDeTeste
    {
        $contexto = app(TenantContext::class);
        $contexto->set($tenant->id);
        $registro = RegistroDeTeste::create(['descricao' => 'x']);
        $contexto->clear();

        return $registro;
    }

    public function test_binding_resolve_registro_do_proprio_tenant(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create();
        $registro = $this->registroDe($usuario->tenant);

        $this->spa()->actingAs($usuario)->getJson("/api/teste/registros/{$registro->id}")
            ->assertOk()
            ->assertJsonPath('id', $registro->id);
    }

    public function test_binding_nao_resolve_registro_de_outro_tenant(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create();
        $registro = $this->registroDe(Tenant::factory()->create());

        $this->spa()->actingAs($usuario)->getJson("/api/teste/registros/{$registro->id}")->assertNotFound();
    }
}
