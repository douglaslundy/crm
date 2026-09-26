<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TenantAssinaturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_cria_tenant_ativo_com_plano(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(SituacaoAssinatura::Ativa, $tenant->situacao);
        $this->assertInstanceOf(Plano::class, $tenant->plano);
    }

    public function test_plano_id_e_situacao_nao_sao_atribuiveis_em_massa(): void
    {
        $tenant = new Tenant(['razao_social' => 'X', 'plano_id' => 'p', 'situacao' => 'ATIVA']);

        $this->assertArrayNotHasKey('plano_id', $tenant->getAttributes());
        $this->assertArrayNotHasKey('situacao', $tenant->getAttributes());
    }

    public function test_me_devolve_os_dados_da_assinatura(): void
    {
        $tenant = Tenant::factory()->emTeste('2026-10-15')->create([
            'razao_social' => 'Padaria Pão Bom Ltda', 'nome_fantasia' => 'Pão Bom',
        ]);
        $usuario = Usuario::factory()->for($tenant)->create();

        $this->spa()->actingAs($usuario)->getJson('/api/app/auth/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.razao_social', 'Padaria Pão Bom Ltda')
            ->assertJsonPath('data.tenant.nome_fantasia', 'Pão Bom')
            ->assertJsonPath('data.tenant.situacao', 'TESTE')
            ->assertJsonPath('data.tenant.teste_termina_em', '2026-10-15');
    }

    public function test_auditoria_grava_causador_e_alvo_uuid(): void
    {
        $tenant = Tenant::factory()->create();
        $usuario = Usuario::factory()->for($tenant)->create();

        activity('teste')->performedOn($tenant)->causedBy($usuario)->log('x');

        $registro = Activity::query()->firstOrFail();
        $this->assertSame($tenant->id, $registro->subject_id);
        $this->assertSame($usuario->id, $registro->causer_id);
    }
}
