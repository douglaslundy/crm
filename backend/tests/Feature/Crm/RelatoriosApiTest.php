<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ComCrm;
use Tests\TestCase;

class RelatoriosApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        Carbon::setTestNow('2026-10-20 15:00:00');
    }

    private function ganho(int $valor, ?string $responsavel = null, string $quando = '2026-10-10 15:00:00'): Negocio
    {
        return Negocio::factory()->create([
            'status' => StatusDoNegocio::Ganho, 'fechado_em' => $quando, 'valor_centavos' => $valor,
            'responsavel_id' => $responsavel ?? $this->admin->id,
        ]);
    }

    public function test_devolve_o_relatorio_do_periodo(): void
    {
        $this->ganho(10000);

        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?de=2026-10-01&ate=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.periodo.de', '2026-10-01')
            ->assertJsonPath('data.resumo.ganhos.quantidade', 1)
            ->assertJsonPath('data.resumo.ganhos.valor_centavos', 10000)
            ->assertJsonPath('data.resumo.taxa_de_conversao', 1)
            ->assertJsonPath('data.propostas.enviadas', 0)
            ->assertJsonPath('data.faturado_centavos', null)
            ->assertJsonStructure(['data' => ['resumo', 'funil', 'previsao' => ['no_periodo', 'sem_previsao'], 'motivos_de_perda', 'vendedores']]);
    }

    public function test_sem_periodo_usa_o_mes_corrente(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios')
            ->assertOk()
            ->assertJsonPath('data.periodo.de', '2026-10-01')
            ->assertJsonPath('data.periodo.ate', '2026-10-31');
    }

    public function test_periodo_invalido_devolve_422_com_codigo(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?de=2026-10-31&ate=2026-10-01')
            ->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INVALIDO');
    }

    public function test_vendedor_restrito_ve_so_o_proprio_e_sem_ranking(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->ganho(1000);
        $this->ganho(4000, $vendedor->id);

        $this->spaComo($vendedor)->getJson('/api/app/crm/relatorios?de=2026-10-01&ate=2026-10-31&vendedor_id='.$this->admin->id)
            ->assertOk()
            ->assertJsonPath('data.resumo.ganhos.valor_centavos', 4000)
            ->assertJsonPath('data.vendedores', null);
    }

    public function test_admin_filtra_por_vendedor(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->ganho(1000);
        $this->ganho(4000, $vendedor->id);

        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?de=2026-10-01&ate=2026-10-31&vendedor_id='.$vendedor->id)
            ->assertOk()->assertJsonPath('data.resumo.ganhos.valor_centavos', 4000);
    }

    public function test_isolamento_nao_mostra_numeros_de_outra_empresa(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        Negocio::factory()->create(['status' => StatusDoNegocio::Ganho, 'fechado_em' => '2026-10-10 15:00:00', 'valor_centavos' => 99999]);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?de=2026-10-01&ate=2026-10-31')
            ->assertOk()->assertJsonPath('data.resumo.ganhos.quantidade', 0);
    }

    public function test_funil_de_outro_tenant_devolve_404(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheio = Funil::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?funil_id='.$alheio->id)->assertNotFound();
    }

    public function test_fiscal_e_barrado(): void
    {
        $this->spaComo($this->usuarioDoPapel(Papel::Fiscal))->getJson('/api/app/crm/relatorios')->assertForbidden();
    }

    public function test_resumo_do_painel_traz_funil_previsao_e_meta_do_mes(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'probabilidade' => 40]);
        Negocio::factory()->create([
            'funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id,
            'valor_centavos' => 10000, 'previsao_fechamento' => '2026-10-25',
        ]);
        $this->ganho(3000);
        MetaDoVendedor::factory()->create([
            'usuario_id' => $this->admin->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Valor, 'alvo' => 6000,
        ]);

        $this->spaComo($this->admin)->getJson('/api/app/crm/resumo')
            ->assertOk()
            ->assertJsonPath('data.funil.etapas.0.quantidade', 1)
            ->assertJsonPath('data.previsao_do_mes.ponderada_centavos', 4000)
            ->assertJsonPath('data.meta_do_mes.percentual', 50)
            ->assertJsonPath('data.hoje', 0);
    }

    public function test_resumo_sem_meta_devolve_meta_nula(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/resumo')
            ->assertOk()->assertJsonPath('data.meta_do_mes', null)->assertJsonPath('data.funil', null);
    }
}
