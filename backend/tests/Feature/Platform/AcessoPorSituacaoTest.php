<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura as S;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AcessoPorSituacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['api', 'empresa'])->prefix('api/app')->group(function (): void {
            Route::get('teste-leitura', fn () => ['ok' => true]);
            Route::post('teste-escrita', fn () => ['ok' => true]);
        });
    }

    private function usuarioEm(S $situacao): Usuario
    {
        return Usuario::factory()->for(Tenant::factory()->situacao($situacao))->create();
    }

    /** @return array<string, array{S}> */
    public static function semEscrita(): array
    {
        return ['pendente' => [S::Pendente], 'suspensa' => [S::Suspensa], 'cancelada' => [S::Cancelada]];
    }

    #[DataProvider('semEscrita')]
    public function test_escrita_bloqueada(S $situacao): void
    {
        $this->spa()->actingAs($this->usuarioEm($situacao))->postJson('/api/app/teste-escrita')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ASSINATURA_SEM_ESCRITA');
    }

    /** @return array<string, array{S}> */
    public static function comEscrita(): array
    {
        return ['teste' => [S::Teste], 'ativa' => [S::Ativa], 'inadimplente' => [S::Inadimplente]];
    }

    #[DataProvider('comEscrita')]
    public function test_escrita_liberada(S $situacao): void
    {
        $this->spa()->actingAs($this->usuarioEm($situacao))->postJson('/api/app/teste-escrita')->assertOk();
    }

    /**
     * Duas situações checadas em métodos separados: com sessão stateful (Sanctum
     * AuthenticateSession), trocar de usuário autenticado dentro do mesmo teste
     * mantendo os cookies da chamada anterior dispara a detecção de hijacking
     * (hash de senha da sessão não bate com o novo usuário) e lança 401 antes
     * de a rota rodar — nada a ver com a regra de negócio sob teste aqui.
     */
    public function test_leitura_liberada_em_suspensa(): void
    {
        $this->spa()->actingAs($this->usuarioEm(S::Suspensa))->getJson('/api/app/teste-leitura')->assertOk();
    }

    public function test_leitura_liberada_em_cancelada(): void
    {
        $this->spa()->actingAs($this->usuarioEm(S::Cancelada))->getJson('/api/app/teste-leitura')->assertOk();
    }

    public function test_pendente_so_le_a_assinatura(): void
    {
        $usuario = $this->usuarioEm(S::Pendente);

        $this->spa()->actingAs($usuario)->getJson('/api/app/teste-leitura')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ASSINATURA_PENDENTE');
        $this->spa()->actingAs($usuario)->getJson('/api/app/assinatura')->assertOk();
        $this->spa()->actingAs($usuario)->getJson('/api/app/auth/me')->assertOk();
    }

    public function test_admin_da_plataforma_nao_acessa_rota_de_empresa(): void
    {
        $admin = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Superadmin]);

        $this->spa()->actingAs($admin)->getJson('/api/app/teste-leitura')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_sem_sessao_responde_401(): void
    {
        $this->spa()->getJson('/api/app/teste-leitura')->assertUnauthorized();
    }
}
