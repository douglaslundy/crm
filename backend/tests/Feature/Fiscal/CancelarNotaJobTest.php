<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\AplicarResultadoDoCancelamento;
use App\Modules\Fiscal\Application\Actions\IniciarCancelamento;
use App\Modules\Fiscal\Application\Jobs\CancelarNotaJob;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorDeNfseFake;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class CancelarNotaJobTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Cancelamento por erro de digitação do valor total';

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    private function autorizada(string $ambiente = 'HOMOLOGACAO'): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Autorizada, 'ambiente' => $ambiente, 'serie' => '1', 'numero' => 1,
            'chave' => str_repeat('1', 44), 'protocolo' => '135260000000001', 'xml_autorizado' => '<nfeProc>x</nfeProc>', 'emitido_em' => now(),
        ])->save();

        return $nota->refresh();
    }

    /** Com QUEUE_CONNECTION=sync dos testes, `IniciarCancelamento` já executa o job. */
    private function cancelar(Nota $nota): Nota
    {
        return app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin)->refresh();
    }

    public function test_aceito_vira_cancelada_com_protocolo_e_xml(): void
    {
        $nota = $this->cancelar($this->autorizada());

        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertSame('135260000000099', $nota->cancelamento_protocolo);
        $this->assertSame('135', $nota->cancelamento_cstat);
        $this->assertNotNull($nota->cancelado_em);
        $nota->makeVisible('xml_cancelamento');
        $this->assertSame('<nfeProc>cancelada</nfeProc>', $nota->xml_cancelamento);
        $this->assertSame(['cancelar'], $this->emissor->chamadas);
    }

    public function test_recusa_da_sefaz_volta_a_autorizada_com_cstat_e_motivo(): void
    {
        $this->emissor->enfileirarCancelamento(ResultadoDeCancelamento::recusado('501', 'Pedido de Cancelamento intempestivo'));

        $nota = $this->cancelar($this->autorizada());

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('501', $nota->cancelamento_cstat);
        $this->assertSame('Pedido de Cancelamento intempestivo', $nota->cancelamento_motivo);
        $this->assertNull($nota->cancelado_em);
        $this->assertSame('135260000000001', $nota->protocolo, 'a autorização continua intacta');
    }

    public function test_falha_tecnica_mantem_cancelando_e_registra_o_evento(): void
    {
        $this->emissor->falharEmCancelar = new RuntimeException('cURL error 28: timeout');

        $nota = $this->cancelar($this->autorizada());

        $this->assertSame(StatusNota::Cancelando, $nota->status);
        $ultimo = $nota->eventos()->get()->last();
        $this->assertSame('CANCELANDO', $ultimo->status_para);
        $this->assertStringContainsString('timeout', (string) $ultimo->detalhe);
    }

    public function test_cancela_no_ambiente_da_nota_e_nao_no_do_emitente(): void
    {
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);

        $this->cancelar($this->autorizada('HOMOLOGACAO'));

        $this->assertSame(['HOMOLOGACAO'], $this->emissor->ambientes);
    }

    public function test_job_ignora_nota_que_nao_esta_cancelando(): void
    {
        $nota = $this->autorizada();

        (new CancelarNotaJob($nota->id, $this->tenant->id))->handle(
            $this->emissor, app(AplicarResultadoDoCancelamento::class), app(TenantContext::class), new EmissorDeNfseFake,
        );

        $this->assertSame([], $this->emissor->chamadas);
        $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);
    }

    public function test_resultado_so_e_aplicado_em_nota_cancelando(): void
    {
        $nota = $this->autorizada();

        $r = app(AplicarResultadoDoCancelamento::class)->executar($nota->id, ResultadoDeCancelamento::aceito('1', '<x/>'));

        $this->assertSame(StatusNota::Autorizada, $r->status, 'sem estar CANCELANDO nada muda');
    }

    public function test_trilha_de_eventos_do_cancelamento(): void
    {
        $nota = $this->cancelar($this->autorizada());

        $this->assertSame(['CANCELANDO', 'CANCELADA'], $nota->eventos()->pluck('status_para')->all());
    }
}
