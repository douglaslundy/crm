<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class MetasApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_admin_define_e_atualiza_a_meta_do_mes(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $corpo = ['usuario_id' => $vendedor->id, 'mes' => '2026-10', 'unidade' => 'VALOR', 'alvo' => 500000];

        $this->spaComo($this->admin)->putJson('/api/app/crm/metas', $corpo)
            ->assertOk()
            ->assertJsonPath('data.mes', '2026-10')
            ->assertJsonPath('data.alvo', 500000)
            ->assertJsonPath('data.usuario_nome', $vendedor->nome);

        $this->spaComo($this->admin)->putJson('/api/app/crm/metas', [...$corpo, 'unidade' => 'QUANTIDADE', 'alvo' => 8])
            ->assertOk()->assertJsonPath('data.unidade', 'QUANTIDADE');

        $this->assertSame(1, MetaDoVendedor::query()->count());
    }

    public function test_lista_por_mes_e_vendedor_so_ve_a_propria(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $outro = $this->usuarioDoPapel(Papel::Vendedor);
        MetaDoVendedor::factory()->create(['usuario_id' => $vendedor->id, 'mes' => '2026-10-01']);
        MetaDoVendedor::factory()->create(['usuario_id' => $outro->id, 'mes' => '2026-10-01']);
        MetaDoVendedor::factory()->create(['usuario_id' => $vendedor->id, 'mes' => '2026-09-01']);

        $this->spaComo($this->admin)->getJson('/api/app/crm/metas?mes=2026-10')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->reiniciarAutenticacao();

        $this->spaComo($vendedor)->getJson('/api/app/crm/metas?mes=2026-10')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.usuario_id', $vendedor->id);
    }

    public function test_alvo_zero_ou_negativo_devolve_422(): void
    {
        $this->spaComo($this->admin)->putJson('/api/app/crm/metas', [
            'usuario_id' => $this->admin->id, 'mes' => '2026-10', 'unidade' => 'VALOR', 'alvo' => 0,
        ])->assertStatus(422);
    }

    public function test_usuario_de_outra_empresa_devolve_422(): void
    {
        $outro = Tenant::factory()->create();
        $alheio = $this->usuarioDoPapel(Papel::Vendedor, $outro);

        $this->spaComo($this->admin)->putJson('/api/app/crm/metas', [
            'usuario_id' => $alheio->id, 'mes' => '2026-10', 'unidade' => 'VALOR', 'alvo' => 100,
        ])->assertStatus(422)->assertJsonPath('codigo', 'META_INVALIDA');
    }

    public function test_mes_mal_formado_na_listagem_devolve_422(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/metas?mes=outubro')
            ->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INVALIDO');
    }

    public function test_vendedor_nao_define_nem_remove(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $meta = MetaDoVendedor::factory()->create(['usuario_id' => $vendedor->id]);

        $this->spaComo($vendedor)->putJson('/api/app/crm/metas', [
            'usuario_id' => $vendedor->id, 'mes' => '2026-10', 'unidade' => 'VALOR', 'alvo' => 100,
        ])->assertForbidden();
        $this->spaComo($vendedor)->deleteJson("/api/app/crm/metas/{$meta->id}")->assertForbidden();
    }

    public function test_remove_e_nao_alcanca_meta_de_outro_tenant(): void
    {
        $meta = MetaDoVendedor::factory()->create(['usuario_id' => $this->admin->id]);
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheia = MetaDoVendedor::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->deleteJson("/api/app/crm/metas/{$alheia->id}")->assertNotFound();
        $this->spaComo($this->admin)->deleteJson("/api/app/crm/metas/{$meta->id}")->assertNoContent();
        $this->assertSame(0, MetaDoVendedor::query()->count());
    }

    public function test_listagem_so_mostra_o_proprio_tenant(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        MetaDoVendedor::factory()->create(['mes' => '2026-10-01']);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->getJson('/api/app/crm/metas?mes=2026-10')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
