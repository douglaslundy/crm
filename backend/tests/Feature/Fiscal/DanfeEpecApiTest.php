<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Domain\Emissao\RegistroEpec;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Exceptions\FalhaDeComunicacaoException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class DanfeEpecApiTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private ?RegistroEpec $recebido = null;

    private ?ModeloDocumento $modeloRecebido = null;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-06 12:00:00');
        $this->montarCenario(Modulo::FiscalNfe);
        $emissor = new EmissorFake;
        $emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->app->instance(EmissorDeNfe::class, $emissor);
        $this->app->instance(GeradorDeDanfe::class, new class($this) implements GeradorDeDanfe
        {
            public function __construct(private readonly DanfeEpecApiTest $teste) {}

            public function gerar(string $xml, bool $cancelada, ModeloDocumento $modelo = ModeloDocumento::Nfe, ?RegistroEpec $epec = null): string
            {
                $this->teste->registrar($modelo, $epec);

                return '%PDF-teste';
            }
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function registrar(ModeloDocumento $modelo, ?RegistroEpec $epec): void
    {
        $this->modeloRecebido = $modelo;
        $this->recebido = $epec;
    }

    private function emEpec(): Nota
    {
        $corpo = [
            'modelo' => 'NFE', 'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0, 'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ];
        $id = $this->spaComo($this->admin)->postJson('/api/app/notas', $corpo)->assertCreated()->json('data.id');
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);

        return Nota::query()->findOrFail($id);
    }

    public function test_pdf_da_nfe_em_epec_leva_o_protocolo_e_a_data_do_evento(): void
    {
        $nota = $this->emEpec();

        $this->spaComo($this->admin)->get("/api/app/notas/{$nota->id}/pdf")->assertOk();

        $this->assertSame(ModeloDocumento::Nfe, $this->modeloRecebido);
        $this->assertSame('891260000000001', $this->recebido?->protocolo);
        $this->assertSame('2026-10-06 12:00:00', $this->recebido?->registradoEm->format('Y-m-d H:i:s'));
    }

    public function test_epec_sem_protocolo_gravado_nao_gera_pdf(): void
    {
        $nota = $this->emEpec();
        $nota->forceFill(['epec_protocolo' => null])->save();

        $this->spaComo($this->admin)->get("/api/app/notas/{$nota->id}/pdf")->assertStatus(409);
        $this->assertNull($this->modeloRecebido);
    }

    public function test_contingencia_sem_tipo_continua_sem_registro_epec(): void
    {
        $nota = $this->emEpec();
        $nota->forceFill(['contingencia_tipo' => null, 'epec_protocolo' => null, 'epec_registrado_em' => null])->save();

        $this->spaComo($this->admin)->get("/api/app/notas/{$nota->id}/pdf")->assertOk();
        $this->assertNull($this->recebido);
    }
}
