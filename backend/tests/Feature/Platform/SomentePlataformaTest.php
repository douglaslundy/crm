<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SomentePlataformaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['api', 'plataforma'])->prefix('api/admin')->group(function (): void {
            Route::get('teste', fn () => ['ok' => true]);
            Route::post('teste', fn () => ['ok' => true]);
        });
    }

    private function daPlataforma(Papel $papel): Usuario
    {
        return Usuario::factory()->create(['tenant_id' => null, 'papel' => $papel]);
    }

    public function test_superadmin_le_e_escreve(): void
    {
        $admin = $this->daPlataforma(Papel::Superadmin);

        $this->spa()->actingAs($admin)->getJson('/api/admin/teste')->assertOk();
        $this->spa()->actingAs($admin)->postJson('/api/admin/teste')->assertOk();
    }

    public function test_suporte_so_le(): void
    {
        $suporte = $this->daPlataforma(Papel::Suporte);

        $this->spa()->actingAs($suporte)->getJson('/api/admin/teste')->assertOk();
        $this->spa()->actingAs($suporte)->postJson('/api/admin/teste')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_usuario_de_empresa_nao_acessa_o_admin(): void
    {
        $dono = Usuario::factory()->for(Tenant::factory())->create(['papel' => Papel::Proprietario]);

        $this->spa()->actingAs($dono)->getJson('/api/admin/teste')->assertForbidden();
    }
}
