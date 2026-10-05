<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class ContingenciaDadosTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    public function test_status_contingencia_nao_e_editavel(): void
    {
        $this->assertSame('CONTINGENCIA', StatusNota::Contingencia->value);
        $this->assertFalse(StatusNota::Contingencia->editavel());
    }

    public function test_colunas_de_contingencia_sao_gravadas_e_lidas(): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => null]);
        $nota->forceFill([
            'modelo' => ModeloDocumento::Nfce, 'status' => StatusNota::Contingencia,
            'contingencia_desde' => '2026-10-05 10:00:00', 'contingencia_limite_em' => '2026-10-06 10:00:00',
            'ultima_retransmissao_em' => '2026-10-05 12:00:00', 'contingencia_motivo' => 'Falha de comunicação',
        ])->save();

        $lida = Nota::query()->findOrFail($nota->id);

        $this->assertSame(StatusNota::Contingencia, $lida->status);
        $this->assertSame('2026-10-06 10:00:00', $lida->contingencia_limite_em?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 10:00:00', $lida->contingencia_desde?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 12:00:00', $lida->ultima_retransmissao_em?->format('Y-m-d H:i:s'));
        $this->assertSame('Falha de comunicação', $lida->contingencia_motivo);
    }

    public function test_configuracao_de_prazo_e_alerta_tem_valor_padrao(): void
    {
        $this->assertSame(24, config('fiscal.contingencia_prazo_horas'));
        $this->assertSame(6, config('fiscal.contingencia_alerta_horas'));
    }

    public function test_nota_em_contingencia_nao_conta_na_cota_de_documentos(): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => null]);
        $nota->forceFill(['modelo' => ModeloDocumento::Nfce, 'status' => StatusNota::Contingencia, 'ambiente' => 'PRODUCAO', 'emitido_em' => now()])->save();

        $this->assertSame(0, (new ContadorDeDocumentosMes)->contar($this->tenant));
    }
}
