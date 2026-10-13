<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\ConsultorDeParados;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class NegocioParadoTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Funil $funil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => false]);
        $this->funil = Funil::factory()->padrao()->create();
        // 2026-10-14 12:00 UTC = 09:00 em São Paulo.
        $this->travelTo('2026-10-14 12:00:00');
    }

    /** @param array<string, mixed> $extra */
    private function negocio(?int $diasParaParado, string $criadoEm, array $extra = []): Negocio
    {
        $etapa = Etapa::factory()->create(['funil_id' => $this->funil->id, 'dias_para_parado' => $diasParaParado]);
        $n = Negocio::factory()->create(array_merge([
            'funil_id' => $this->funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id,
        ], $extra));
        $n->forceFill(['created_at' => $criadoEm])->saveQuietly();

        return $n->refresh();
    }

    public function test_exatamente_no_limite_ja_esta_parado(): void
    {
        $n = $this->negocio(3, '2026-10-11 15:00:00'); // 11/10 (SP) -> 3 dias de calendário

        $s = app(ConsultorDeParados::class)->situacao([$n]);

        $this->assertTrue($s[$n->id]['parado']);
        $this->assertSame(3, $s[$n->id]['dias']);
    }

    public function test_um_dia_antes_do_limite_nao_esta_parado(): void
    {
        $n = $this->negocio(3, '2026-10-12 15:00:00');

        $this->assertFalse(app(ConsultorDeParados::class)->situacao([$n])[$n->id]['parado']);
    }

    public function test_etapa_sem_alerta_nunca_para(): void
    {
        $n = $this->negocio(null, '2026-01-01 12:00:00');

        $this->assertFalse(app(ConsultorDeParados::class)->situacao([$n])[$n->id]['parado']);
    }

    public function test_atividade_concluida_zera_o_contador(): void
    {
        $n = $this->negocio(3, '2026-09-01 12:00:00');
        Atividade::factory()->create(['negocio_id' => $n->id, 'cliente_id' => $n->cliente_id, 'concluida_em' => '2026-10-13 12:00:00']);

        $s = app(ConsultorDeParados::class)->situacao([$n])[$n->id];

        $this->assertFalse($s['parado']);
        $this->assertSame(1, $s['dias']);
    }

    public function test_atividade_aberta_nao_conta(): void
    {
        $n = $this->negocio(3, '2026-09-01 12:00:00');
        Atividade::factory()->create(['negocio_id' => $n->id, 'cliente_id' => $n->cliente_id]);

        $this->assertTrue(app(ConsultorDeParados::class)->situacao([$n])[$n->id]['parado']);
    }

    public function test_movimentacao_de_etapa_conta(): void
    {
        $n = $this->negocio(3, '2026-09-01 12:00:00');
        RegistroDaTimeline::create([
            'cliente_id' => $n->cliente_id, 'negocio_id' => $n->id, 'tipo' => TipoDeRegistro::NegocioMovido,
            'texto' => 'x', 'ocorreu_em' => '2026-10-14 10:00:00',
        ]);

        $this->assertFalse(app(ConsultorDeParados::class)->situacao([$n])[$n->id]['parado']);
    }

    public function test_virada_de_dia_usa_o_fuso_de_sao_paulo(): void
    {
        // 14/10 02:00 UTC = 13/10 23:00 em SP. Agora (14/10 09:00 SP): 1 dia, não 0.
        $n = $this->negocio(1, '2026-10-14 02:00:00');

        $s = app(ConsultorDeParados::class)->situacao([$n])[$n->id];

        $this->assertSame(1, $s['dias']);
        $this->assertTrue($s['parado']);
    }

    public function test_ganho_e_perdido_ficam_de_fora(): void
    {
        $n = $this->negocio(1, '2026-01-01 12:00:00', ['status' => StatusDoNegocio::Ganho]);

        $this->assertArrayNotHasKey($n->id, app(ConsultorDeParados::class)->situacao([$n]));
    }

    public function test_api_devolve_parado_e_filtra(): void
    {
        $parado = $this->negocio(3, '2026-10-01 12:00:00');
        $this->negocio(3, '2026-10-14 11:00:00');

        $this->spaComo($this->admin)->getJson("/api/app/crm/negocios?funil_id={$this->funil->id}")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $parado->id, 'parado' => true, 'dias_parado' => 13]);

        $this->spaComo($this->admin)->getJson("/api/app/crm/negocios?funil_id={$this->funil->id}&parado=1")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $parado->id);
    }
}
