<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class ConfiguracaoApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_lista_semeia_o_funil_padrao_na_primeira_vez(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/funis')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Vendas')
            ->assertJsonPath('data.0.padrao', true)
            ->assertJsonCount(3, 'data.0.etapas');
    }

    public function test_cria_funil_etapa_e_reordena(): void
    {
        $id = $this->spaComo($this->admin)->postJson('/api/app/crm/funis', ['nome' => 'Pós-venda'])
            ->assertCreated()->json('data.id');
        $a = $this->spaComo($this->admin)->postJson("/api/app/crm/funis/{$id}/etapas", ['nome' => 'A', 'probabilidade' => 0])
            ->assertCreated()->json('data.id');
        $b = $this->spaComo($this->admin)->postJson("/api/app/crm/funis/{$id}/etapas", ['nome' => 'B', 'probabilidade' => 50])
            ->assertCreated()->json('data.id');

        $this->spaComo($this->admin)->putJson("/api/app/crm/funis/{$id}/etapas/ordem", ['etapas' => [$b, $a]])
            ->assertOk();
        $this->spaComo($this->admin)->putJson("/api/app/crm/etapas/{$a}", ['probabilidade' => 20])
            ->assertOk()->assertJsonPath('data.probabilidade', 20);
    }

    public function test_probabilidade_invalida_devolve_422(): void
    {
        $funil = Funil::factory()->create();

        $this->spaComo($this->admin)->postJson("/api/app/crm/funis/{$funil->id}/etapas", ['nome' => 'X', 'probabilidade' => 101])
            ->assertStatus(422);
    }

    public function test_motivos_e_configuracao(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/crm/motivos-de-perda', ['nome' => 'Prazo'])->assertCreated();
        $this->spaComo($this->admin)->getJson('/api/app/crm/motivos-de-perda')->assertOk()->assertJsonFragment(['nome' => 'Prazo']);
        $this->spaComo($this->admin)->putJson('/api/app/crm/configuracao', ['vendedor_ve_so_os_seus' => false])
            ->assertOk()->assertJsonPath('data.vendedor_ve_so_os_seus', false);
    }

    public function test_vendedor_le_mas_nao_configura(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);

        $this->spaComo($vendedor)->getJson('/api/app/crm/funis')->assertOk();
        $this->spaComo($vendedor)->postJson('/api/app/crm/funis', ['nome' => 'X'])->assertForbidden();
        $this->spaComo($vendedor)->putJson('/api/app/crm/configuracao', ['vendedor_ve_so_os_seus' => false])->assertForbidden();
    }

    public function test_fiscal_e_barrado_ate_na_leitura(): void
    {
        $this->spaComo($this->usuarioDoPapel(Papel::Fiscal))->getJson('/api/app/crm/funis')->assertForbidden();
    }

    public function test_empresa_sem_modulo_crm_recebe_403_com_codigo(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($tenant->id);

        $this->spaComo($admin)->getJson('/api/app/crm/funis')
            ->assertForbidden()->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_isolamento_funil_de_outra_empresa_da_404(): void
    {
        $outra = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Crm))->create();
        app(TenantContext::class)->set($outra->id);
        $alheio = Funil::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->putJson("/api/app/crm/funis/{$alheio->id}", ['nome' => 'Invadido'])->assertNotFound();
        $this->spaComo($this->admin)->postJson("/api/app/crm/funis/{$alheio->id}/etapas", ['nome' => 'X', 'probabilidade' => 1])
            ->assertNotFound();
    }
}
