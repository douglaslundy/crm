<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\ResumoFiscal;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class ResumoFiscalTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private CarbonImmutable $agora;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->agora = CarbonImmutable::parse('2026-10-15 12:00', 'America/Sao_Paulo');
    }

    private function nota(StatusNota $status, int $total, ?string $emitidoEm = null, string $ambiente = 'HOMOLOGACAO', ModeloDocumento $modelo = ModeloDocumento::Nfe, ?string $criadaEm = null): Nota
    {
        $this->seq++;
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'modelo' => $modelo, 'status' => $status, 'ambiente' => $ambiente, 'serie' => '1', 'numero' => $this->seq,
            'chave' => str_pad((string) $this->seq, 44, '0', STR_PAD_LEFT), 'total_centavos' => $total, 'subtotal_centavos' => $total,
            'emitido_em' => $emitidoEm, 'cstat' => '232', 'motivo' => 'Motivo '.$this->seq,
        ]);
        if ($criadaEm !== null) {
            $nota->created_at = CarbonImmutable::parse($criadaEm);
        }
        $nota->save();

        return $nota;
    }

    /** @return array<string, mixed> */
    private function resumo(): array
    {
        return app(ResumoFiscal::class)->executar($this->agora);
    }

    public function test_doze_meses_ordenados_e_preenchidos_com_zero(): void
    {
        $meses = $this->resumo()['faturamento_12_meses'];

        $this->assertCount(12, $meses);
        $this->assertSame('2025-11', $meses[0]['mes']);
        $this->assertSame('2026-10', $meses[11]['mes']);
        foreach ($meses as $m) {
            $this->assertSame(0, $m['valor_centavos']);
            $this->assertSame(0, $m['quantidade']);
        }
    }

    public function test_soma_autorizadas_e_cancelando_e_ignora_canceladas_nos_meses_certos(): void
    {
        $this->nota(StatusNota::Autorizada, 1000, '2026-09-10 12:00:00');
        $this->nota(StatusNota::Autorizada, 2500, '2026-10-02 12:00:00');
        $this->nota(StatusNota::Cancelando, 700, '2026-10-03 12:00:00');
        $this->nota(StatusNota::Cancelada, 9999, '2026-10-04 12:00:00');

        $r = $this->resumo();

        $this->assertSame(3200, $r['mes']['valor_emitido_centavos']);
        $this->assertSame(['mes' => '2026-09', 'valor_centavos' => 1000, 'quantidade' => 1], $r['faturamento_12_meses'][10]);
        $this->assertSame(['mes' => '2026-10', 'valor_centavos' => 3200, 'quantidade' => 2], $r['faturamento_12_meses'][11]);
    }

    public function test_so_o_ambiente_atual_entra(): void
    {
        $this->nota(StatusNota::Autorizada, 1000, '2026-10-02 12:00:00', 'HOMOLOGACAO');
        $this->nota(StatusNota::Autorizada, 5000, '2026-10-02 12:00:00', 'PRODUCAO');

        $r = $this->resumo();

        $this->assertSame('HOMOLOGACAO', $r['ambiente']);
        $this->assertSame(1000, $r['mes']['valor_emitido_centavos']);
    }

    public function test_notas_do_mes_por_modelo_e_status_so_com_quantidade(): void
    {
        $this->nota(StatusNota::Autorizada, 100, '2026-10-02 12:00:00', modelo: ModeloDocumento::Nfe);
        $this->nota(StatusNota::Autorizada, 100, '2026-10-03 12:00:00', modelo: ModeloDocumento::Nfce);
        $this->nota(StatusNota::Autorizada, 100, '2026-10-04 12:00:00', modelo: ModeloDocumento::Nfce);
        $this->nota(StatusNota::Rascunho, 0, null, criadaEm: '2026-10-05 12:00:00');
        $this->nota(StatusNota::Rejeitada, 0, null, criadaEm: '2026-09-05 12:00:00');

        $notas = collect($this->resumo()['mes']['notas'])->mapWithKeys(fn (array $n): array => [$n['modelo'].'/'.$n['status'] => $n['quantidade']])->all();

        $this->assertEquals(['NFE/AUTORIZADA' => 1, 'NFCE/AUTORIZADA' => 2, 'NFE/RASCUNHO' => 1], $notas);
        $this->assertSame('2026-10', $this->resumo()['mes']['periodo']);
    }

    public function test_nota_emitida_as_23h30_de_31_de_marco_em_sao_paulo_conta_em_marco(): void
    {
        $this->agora = CarbonImmutable::parse('2026-04-10 12:00', 'America/Sao_Paulo');
        $this->nota(StatusNota::Autorizada, 4200, '2026-04-01 02:30:00');

        $meses = collect($this->resumo()['faturamento_12_meses'])->keyBy('mes');

        $this->assertSame(4200, $meses['2026-03']['valor_centavos']);
        $this->assertSame(0, $meses['2026-04']['valor_centavos']);
    }

    public function test_rejeicoes_total_sem_limite_e_cinco_recentes_da_mais_nova(): void
    {
        foreach (range(1, 7) as $i) {
            $this->nota($i % 2 === 0 ? StatusNota::Erro : StatusNota::Rejeitada, 0, null, criadaEm: "2026-10-0{$i} 10:00:00");
        }

        $rej = $this->resumo()['rejeicoes'];

        $this->assertSame(7, $rej['quantidade']);
        $this->assertCount(5, $rej['recentes']);
        $this->assertSame('Motivo 7', $rej['recentes'][0]['motivo']);
        $this->assertSame('Motivo 3', $rej['recentes'][4]['motivo']);
        $this->assertArrayHasKey('modelo', $rej['recentes'][0]);
        $this->assertArrayHasKey('numero', $rej['recentes'][0]);
        $this->assertArrayHasKey('status', $rej['recentes'][0]);
    }

    public function test_certificado_futuro_vencido_e_sem_validade(): void
    {
        $this->emitente->update(['certificado_validade' => '2026-10-25']);
        $this->assertSame(10, $this->resumo()['certificado']['dias_restantes']);
        $this->assertSame('2026-10-25', $this->resumo()['certificado']['validade']);

        $this->emitente->update(['certificado_validade' => '2026-10-10']);
        $this->assertSame(-5, $this->resumo()['certificado']['dias_restantes']);

        $this->emitente->forceFill(['certificado_validade' => null])->save();
        $this->assertNull($this->resumo()['certificado']['validade']);
        $this->assertNull($this->resumo()['certificado']['dias_restantes']);
    }

    public function test_notas_de_outro_tenant_nao_aparecem(): void
    {
        $this->nota(StatusNota::Autorizada, 1000, '2026-10-02 12:00:00');
        $outro = Tenant::factory()->for(Plano::factory())->create();
        app(TenantContext::class)->set($outro->id);

        $r = $this->resumo();

        $this->assertSame(0, $r['mes']['valor_emitido_centavos']);
        $this->assertSame(0, $r['rejeicoes']['quantidade']);
    }

    private function emContingencia(string $limite, string $ambiente = 'HOMOLOGACAO'): void
    {
        $nota = $this->nota(StatusNota::Contingencia, 0, null, $ambiente, ModeloDocumento::Nfce);
        $nota->forceFill(['contingencia_desde' => '2026-10-15 08:00:00', 'contingencia_limite_em' => $limite])->save();
    }

    public function test_contingencias_com_alerta_e_vencidas(): void
    {
        config(['fiscal.contingencia_alerta_horas' => 6]);
        // agora = 2026-10-15 12:00 em São Paulo = 15:00 UTC (como o banco guarda)
        $this->emContingencia('2026-10-16 01:00:00'); // +10h: fora do alerta
        $this->emContingencia('2026-10-15 18:00:00'); // +3h: em alerta
        $this->emContingencia('2026-10-15 14:00:00'); // -1h: vencida

        $c = $this->resumo()['contingencias'];

        $this->assertSame(3, $c['quantidade']);
        $this->assertSame(1, $c['em_alerta']);
        $this->assertSame(1, $c['vencidas']);
        $this->assertSame('2026-10-15T14:00:00+00:00', $c['limite_mais_proximo']);
    }

    public function test_sem_contingencias_o_bloco_vem_zerado_e_sem_data(): void
    {
        $this->assertSame(['quantidade' => 0, 'em_alerta' => 0, 'vencidas' => 0, 'limite_mais_proximo' => null], $this->resumo()['contingencias']);
    }

    private function emEpec(string $limite): void
    {
        $nota = $this->nota(StatusNota::Contingencia, 0, null, 'HOMOLOGACAO', ModeloDocumento::Nfe);
        $nota->forceFill(['contingencia_tipo' => TipoDeContingencia::Epec, 'contingencia_desde' => '2026-10-15 08:00:00', 'contingencia_limite_em' => $limite])->save();
    }

    public function test_o_alerta_do_epec_usa_as_48_horas_e_o_da_offline_continua_em_6(): void
    {
        config(['fiscal.contingencia_alerta_horas' => 6, 'fiscal.epec_alerta_horas' => 48]);
        // agora = 2026-10-15 15:00 UTC (como o banco guarda)
        $this->emEpec('2026-10-17 12:00:00');         // +45h: alerta do EPEC (48 h)
        $this->emEpec('2026-10-19 15:00:00');         // +96h: fora do alerta
        $this->emContingencia('2026-10-15 21:00:00'); // offline +6h exatas: fora do alerta (a regra é "menos de 6 h")
        $this->emContingencia('2026-10-15 20:00:00'); // offline +5h: em alerta

        $c = $this->resumo()['contingencias'];

        $this->assertSame(4, $c['quantidade']);
        $this->assertSame(2, $c['em_alerta']);
        $this->assertSame(0, $c['vencidas']);
        $this->assertSame('2026-10-15T20:00:00+00:00', $c['limite_mais_proximo']);
    }

    public function test_epec_vencido_conta_como_vencida(): void
    {
        $this->emEpec('2026-10-15 14:00:00');

        $c = $this->resumo()['contingencias'];

        $this->assertSame(1, $c['vencidas']);
        $this->assertSame(0, $c['em_alerta']);
    }

    public function test_nota_legada_sem_tipo_e_tratada_como_offline(): void
    {
        config(['fiscal.contingencia_alerta_horas' => 6, 'fiscal.epec_alerta_horas' => 48]);
        $this->emContingencia('2026-10-16 09:00:00'); // +18h: fora do alerta offline (6 h), mas dentro dos 48 h do EPEC

        $this->assertSame(0, $this->resumo()['contingencias']['em_alerta']);
    }

    public function test_contingencia_de_outro_ambiente_ou_tenant_nao_conta(): void
    {
        $this->emContingencia('2026-10-15 18:00:00', 'PRODUCAO');
        $outro = Tenant::factory()->for(Plano::factory())->create();
        app(TenantContext::class)->set($outro->id);

        $this->assertSame(0, $this->resumo()['contingencias']['quantidade']);
    }
}
