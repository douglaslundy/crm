<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Relatorio\PeriodoDoRelatorio;
use App\Modules\Crm\Application\Relatorio\RelatorioDeNegocios;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Enums\Papel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class RelatorioDeNegociosTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private RelatorioDeNegocios $relatorio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->relatorio = app(RelatorioDeNegocios::class);
    }

    private function periodoDeOutubro(): PeriodoDoRelatorio
    {
        return PeriodoDoRelatorio::entre('2026-10-01', '2026-10-31');
    }

    /** @param array<string, mixed> $extra */
    private function fechado(StatusDoNegocio $status, string $quando, int $valor, array $extra = []): Negocio
    {
        return Negocio::factory()->create([
            'status' => $status, 'fechado_em' => $quando, 'valor_centavos' => $valor, 'responsavel_id' => $this->admin->id, ...$extra,
        ]);
    }

    public function test_periodo_valida_formato_ordem_e_tamanho(): void
    {
        foreach ([['2026-10-31', '2026-10-01'], ['2026-02-31', '2026-03-01'], ['x', '2026-10-01'], ['2025-01-01', '2026-01-02']] as [$de, $ate]) {
            try {
                PeriodoDoRelatorio::entre($de, $ate);
                $this->fail("Aceitou {$de} a {$ate}");
            } catch (RegraDoCrmException $e) {
                $this->assertSame('PERIODO_INVALIDO', $e->codigo());
            }
        }

        $this->assertSame('2026-12-31', PeriodoDoRelatorio::entre('2026-01-01', '2026-12-31')->fimData());
    }

    public function test_periodo_usa_o_fuso_do_negocio_nas_bordas(): void
    {
        $periodo = $this->periodoDeOutubro();

        $this->assertSame('2026-10-01 03:00:00', $periodo->inicioUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 02:59:59', $periodo->fimUtc()->format('Y-m-d H:i:s'));
        $this->assertTrue($periodo->ehMesInteiro());
        $this->assertFalse(PeriodoDoRelatorio::entre('2026-10-02', '2026-10-31')->ehMesInteiro());
    }

    public function test_resumo_conta_ganhos_perdidos_e_abertos_e_calcula_conversao(): void
    {
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 10000);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-11 15:00:00', 5000);
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-12 15:00:00', 7000);
        $this->fechado(StatusDoNegocio::Ganho, '2026-09-30 15:00:00', 99999); // fora do período
        Negocio::factory()->create(['valor_centavos' => 3000, 'responsavel_id' => $this->admin->id]);

        $r = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), null);

        $this->assertSame(['quantidade' => 2, 'valor_centavos' => 15000], $r['ganhos']);
        $this->assertSame(['quantidade' => 1, 'valor_centavos' => 7000], $r['perdidos']);
        $this->assertSame(['quantidade' => 1, 'valor_centavos' => 3000], $r['abertos']);
        $this->assertSame(0.6667, $r['taxa_de_conversao']);
    }

    public function test_sem_negocio_fechado_a_taxa_e_nula_e_os_totais_sao_zero(): void
    {
        $r = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), null);

        $this->assertNull($r['taxa_de_conversao']);
        $this->assertSame(['quantidade' => 0, 'valor_centavos' => 0], $r['ganhos']);
    }

    public function test_so_perdidos_da_taxa_zero_e_nao_nula(): void
    {
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-12 15:00:00', 100);

        $r = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), null);

        $this->assertSame(0.0, $r['taxa_de_conversao']);
    }

    public function test_fechamento_as_23h30_do_ultimo_dia_cai_no_mes_certo(): void
    {
        // 23h30 de 31/10 em São Paulo = 02h30 de 01/11 em UTC.
        $this->fechado(StatusDoNegocio::Ganho, '2026-11-01 02:30:00', 1000);

        $outubro = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), null);
        $novembro = $this->relatorio->resumo($this->admin, PeriodoDoRelatorio::entre('2026-11-01', '2026-11-30'), null);

        $this->assertSame(1, $outubro['ganhos']['quantidade']);
        $this->assertSame(0, $novembro['ganhos']['quantidade']);
    }

    public function test_filtro_por_vendedor_e_visibilidade_do_vendedor_restrito(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 1000);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 4000, ['responsavel_id' => $vendedor->id]);

        $porAdmin = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), $vendedor->id);
        $restrito = $this->relatorio->resumo($vendedor, $this->periodoDeOutubro(), null);

        $this->assertSame(4000, $porAdmin['ganhos']['valor_centavos']);
        $this->assertSame(4000, $restrito['ganhos']['valor_centavos']);
        $this->assertNull($this->relatorio->vendedores($vendedor, $this->periodoDeOutubro(), null));
    }

    public function test_funil_mostra_so_etapas_ativas_do_funil_padrao_com_negocios_abertos(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $a = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 0, 'probabilidade' => 10, 'nome' => 'A']);
        $b = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 1, 'probabilidade' => 50, 'nome' => 'B']);
        Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 2, 'ativa' => false, 'nome' => 'Inativa']);
        Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $a->id, 'valor_centavos' => 1000, 'responsavel_id' => $this->admin->id]);
        Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $a->id, 'valor_centavos' => 2000, 'responsavel_id' => $this->admin->id]);

        $f = $this->relatorio->funil($this->admin, null, null);

        $this->assertNotNull($f);
        $this->assertSame($funil->id, $f['id']);
        $this->assertSame(['A', 'B'], array_column($f['etapas'], 'nome'));
        $this->assertSame(2, $f['etapas'][0]['quantidade']);
        $this->assertSame(3000, $f['etapas'][0]['valor_centavos']);
        $this->assertSame(0, $f['etapas'][1]['quantidade']);
        $this->assertSame($b->id, $f['etapas'][1]['id']);
    }

    public function test_sem_funil_padrao_o_bloco_e_nulo(): void
    {
        $this->assertNull($this->relatorio->funil($this->admin, null, null));
    }

    public function test_previsao_pondera_pela_probabilidade_e_separa_os_sem_data(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'probabilidade' => 50]);
        $base = ['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id];
        Negocio::factory()->create([...$base, 'valor_centavos' => 10000, 'previsao_fechamento' => '2026-10-31']);
        Negocio::factory()->create([...$base, 'valor_centavos' => 4001, 'previsao_fechamento' => '2026-10-01']);
        Negocio::factory()->create([...$base, 'valor_centavos' => 9999, 'previsao_fechamento' => '2026-11-01']);
        Negocio::factory()->create([...$base, 'valor_centavos' => 700, 'previsao_fechamento' => null]);

        $p = $this->relatorio->previsao($this->admin, $this->periodoDeOutubro(), null);

        $this->assertSame(2, $p['no_periodo']['quantidade']);
        $this->assertSame(14001, $p['no_periodo']['valor_centavos']);
        $this->assertSame(7000, $p['no_periodo']['ponderada_centavos']);
        $this->assertSame(['quantidade' => 1, 'valor_centavos' => 700], $p['sem_previsao']);
    }

    public function test_motivos_de_perda_agrupa_e_nomeia_o_sem_motivo(): void
    {
        $preco = MotivoDePerda::query()->create(['nome' => 'Preço', 'ativo' => true]);
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-10 15:00:00', 1000, ['motivo_de_perda_id' => $preco->id]);
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-11 15:00:00', 3000, ['motivo_de_perda_id' => $preco->id]);
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-12 15:00:00', 500, ['motivo_de_perda_id' => null]);

        $m = $this->relatorio->motivosDePerda($this->admin, $this->periodoDeOutubro(), null);

        $this->assertSame('Preço', $m[0]['nome']);
        $this->assertSame(2, $m[0]['quantidade']);
        $this->assertSame(4000, $m[0]['valor_centavos']);
        $this->assertSame('Sem motivo', $m[1]['nome']);
        $this->assertNull($m[1]['motivo_id']);
    }

    public function test_vendedores_trazem_meta_so_quando_o_periodo_e_um_mes_inteiro(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 25000, ['responsavel_id' => $vendedor->id]);
        MetaDoVendedor::factory()->create([
            'usuario_id' => $vendedor->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Valor, 'alvo' => 100000,
        ]);
        MetaDoVendedor::factory()->create([
            'usuario_id' => $this->admin->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Quantidade, 'alvo' => 5,
        ]);

        $mes = collect($this->relatorio->vendedores($this->admin, $this->periodoDeOutubro(), null))->keyBy('usuario_id');
        $parcial = collect($this->relatorio->vendedores($this->admin, PeriodoDoRelatorio::entre('2026-10-01', '2026-10-15'), null))->keyBy('usuario_id');

        $this->assertSame(['unidade' => 'VALOR', 'alvo' => 100000, 'realizado' => 25000, 'percentual' => 25], $mes[$vendedor->id]['meta']);
        $this->assertSame(0, $mes[$this->admin->id]['meta']['realizado']);
        $this->assertNull($parcial[$vendedor->id]['meta']);
    }

    public function test_meta_batida_passa_de_cem_por_cento(): void
    {
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 1000);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-11 15:00:00', 1000);
        MetaDoVendedor::factory()->create([
            'usuario_id' => $this->admin->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Quantidade, 'alvo' => 1,
        ]);

        $meta = $this->relatorio->metaDoUsuario($this->admin, $this->periodoDeOutubro());

        $this->assertNotNull($meta);
        $this->assertSame(200, $meta['percentual']);
    }
}
