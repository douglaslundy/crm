<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class EpecDadosTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    public function test_prazo_e_alerta_padrao_de_cada_tipo(): void
    {
        $this->assertSame(168, TipoDeContingencia::Epec->prazoEmHoras());
        $this->assertSame(48, TipoDeContingencia::Epec->alertaEmHoras());
        $this->assertSame(24, TipoDeContingencia::OfflineNfce->prazoEmHoras());
        $this->assertSame(6, TipoDeContingencia::OfflineNfce->alertaEmHoras());
    }

    public function test_prazo_e_alerta_vem_da_configuracao(): void
    {
        config(['fiscal.epec_prazo_horas' => 72, 'fiscal.epec_alerta_horas' => 12]);

        $this->assertSame(72, TipoDeContingencia::Epec->prazoEmHoras());
        $this->assertSame(12, TipoDeContingencia::Epec->alertaEmHoras());
    }

    public function test_rotulos(): void
    {
        $this->assertSame('EPEC', TipoDeContingencia::Epec->rotulo());
        $this->assertSame('contingência offline', TipoDeContingencia::OfflineNfce->rotulo());
    }

    public function test_nota_guarda_o_tipo_o_protocolo_e_o_xml_do_epec_e_o_resource_expoe_sem_o_xml(): void
    {
        $this->montarCenario(Modulo::FiscalNfe);
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id]);
        $nota->forceFill([
            'status' => StatusNota::Contingencia, 'contingencia_tipo' => TipoDeContingencia::Epec,
            'epec_protocolo' => '891260000000001', 'epec_registrado_em' => '2026-10-06 12:00:00', 'xml_epec' => '<retEvento/>',
        ])->save();

        $nota = $nota->refresh();
        $this->assertSame(TipoDeContingencia::Epec, $nota->contingencia_tipo);
        $this->assertSame('2026-10-06 12:00:00', $nota->epec_registrado_em?->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey('xml_epec', $nota->toArray());

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$nota->id}")
            ->assertOk()
            ->assertJsonPath('data.contingencia_tipo', 'EPEC')
            ->assertJsonPath('data.epec_protocolo', '891260000000001')
            ->assertJsonMissingPath('data.xml_epec');
    }

    public function test_nota_sem_tipo_continua_valida_e_o_resource_devolve_nulo(): void
    {
        $this->montarCenario(Modulo::FiscalNfe);
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id]);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$nota->id}")
            ->assertOk()
            ->assertJsonPath('data.contingencia_tipo', null)
            ->assertJsonPath('data.epec_protocolo', null);
    }
}
