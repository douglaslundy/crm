<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\RetransmitirNota;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\FalhaDeComunicacaoException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class RetransmissaoEpecTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-06 12:00:00');
        $this->montarCenario(Modulo::FiscalNfe);
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function emEpec(): Nota
    {
        $corpo = [
            'modelo' => 'NFE', 'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0, 'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ];
        $id = $this->spaComo($this->admin)->postJson('/api/app/notas', $corpo)->assertCreated()->json('data.id');
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);
        $this->emissor->falharEmTransmitir = null;
        $this->emissor->chamadas = [];

        $nota = Nota::query()->findOrFail($id);
        $this->assertSame(StatusNota::Contingencia, $nota->status);

        return $nota;
    }

    public function test_retransmissao_autorizada_reenvia_o_mesmo_xml_e_registra_apos_epec(): void
    {
        $nota = $this->emEpec();
        $chaveEpec = $nota->chave;
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::autorizada('135000000000077', '<nfeProc>epec-ok</nfeProc>'));

        $r = app(RetransmitirNota::class)->executar($nota->id);

        $this->assertSame(StatusNota::Autorizada, $r->status);
        $this->assertSame('135000000000077', $r->protocolo);
        $this->assertSame($chaveEpec, $r->chave, 'a chave do DANFE não muda');
        $this->assertNotContains('prepararEpec', $this->emissor->chamadas, 'não remonta nem registra o EPEC de novo');
        $this->assertNotContains('registrarEpec', $this->emissor->chamadas);
        $this->assertStringContainsString('Autorizada após EPEC', (string) $r->eventos()->where('status_para', 'AUTORIZADA')->latest('id')->first()?->detalhe);
    }

    public function test_rejeitada_apos_epec_explica_que_os_dados_precisam_bater_com_o_evento(): void
    {
        $nota = $this->emEpec();
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('475', 'Dados diferem do EPEC'));

        $r = app(RetransmitirNota::class)->executar($nota->id);

        $this->assertSame(StatusNota::Rejeitada, $r->status);
        $detalhe = (string) $r->eventos()->where('status_para', 'REJEITADA')->latest('id')->first()?->detalhe;
        $this->assertStringContainsString('Rejeitada após EPEC (cStat 475)', $detalhe);
        $this->assertStringContainsString('evento EPEC', $detalhe);
    }

    public function test_falha_de_comunicacao_mantem_em_epec_com_o_prazo_original(): void
    {
        $nota = $this->emEpec();
        $limite = $nota->contingencia_limite_em;
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;

        $r = app(RetransmitirNota::class)->executar($nota->id);

        $this->assertSame(StatusNota::Contingencia, $r->status);
        $this->assertEquals($limite, $r->contingencia_limite_em);
        $this->assertNotNull($r->ultima_retransmissao_em);
    }

    public function test_consulta_previa_autorizada_concilia_sem_reenviar(): void
    {
        $nota = $this->emEpec();
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000088', '<nfeProc>ja-estava</nfeProc>'));

        $r = app(RetransmitirNota::class)->executar($nota->id);

        $this->assertSame(StatusNota::Autorizada, $r->status);
        $this->assertNotContains('transmitir', $this->emissor->chamadas);
    }

    public function test_nota_legada_sem_tipo_usa_o_texto_offline(): void
    {
        $nota = $this->emEpec();
        $nota->forceFill(['contingencia_tipo' => null])->save();
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('204', 'Duplicidade'));

        $r = app(RetransmitirNota::class)->executar($nota->id);

        $this->assertStringContainsString('o consumidor já recebeu o cupom', (string) $r->eventos()->where('status_para', 'REJEITADA')->latest('id')->first()?->detalhe);
    }
}
