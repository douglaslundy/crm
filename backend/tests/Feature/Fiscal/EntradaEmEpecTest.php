<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\EntrarEmEpec;
use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEpec;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Domain\Exceptions\FalhaDeComunicacaoException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class EntradaEmEpecTest extends TestCase
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

    private function emitir(): Nota
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

    public function test_nfe_com_falha_de_comunicacao_entra_em_epec(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Contingencia, $nota->status);
        $this->assertSame(TipoDeContingencia::Epec, $nota->contingencia_tipo);
        $this->assertContains('prepararEpec', $this->emissor->chamadas);
        $this->assertContains('registrarEpec', $this->emissor->chamadas);
        $this->assertSame('35'.str_pad((string) $nota->numero, 32, '0', STR_PAD_LEFT).'4'.str_repeat('7', 9), $nota->chave);
        $nota->makeVisible(['xml_enviado', 'xml_epec']);
        $this->assertSame('<NFe>epec</NFe>', $nota->xml_enviado);
        $this->assertSame('891260000000001', $nota->epec_protocolo);
        $this->assertSame('2026-10-06 12:00:00', $nota->epec_registrado_em?->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('retEnvEvento', (string) $nota->xml_epec);
        $this->assertSame('2026-10-06 12:00:00', $nota->contingencia_desde?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-13 12:00:00', $nota->contingencia_limite_em?->format('Y-m-d H:i:s'), '168 horas');
        $this->assertStringContainsString('EPEC', (string) $nota->eventos()->where('status_para', 'CONTINGENCIA')->first()?->detalhe);
    }

    public function test_o_prazo_do_epec_vem_da_configuracao(): void
    {
        config(['fiscal.epec_prazo_horas' => 72]);
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;

        $this->assertSame('2026-10-09 12:00:00', $this->emitir()->contingencia_limite_em?->format('Y-m-d H:i:s'));
    }

    public function test_nota_em_epec_nao_conta_na_cota(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emitir();

        $this->assertSame(0, (new ContadorDeDocumentosMes)->contar($this->tenant));
    }

    public function test_consulta_previa_autorizada_nao_entra_em_epec(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000010', '<nfeProc>x</nfeProc>'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertNotContains('prepararEpec', $this->emissor->chamadas);
        $this->assertNotContains('registrarEpec', $this->emissor->chamadas);
    }

    public function test_consulta_que_tambem_falha_ou_nao_encontra_entra_em_epec(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emissor->falharEmConsultar = new FalhaDeComunicacaoException;

        $this->assertSame(StatusNota::Contingencia, $this->emitir()->status);
    }

    public function test_epec_recusado_vira_erro_com_cstat_e_nao_entra_em_contingencia(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emissor->enfileirarEpec(ResultadoDeEpec::recusado('491', 'Dado do EPEC difere'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('491', (string) $nota->mensagem_erro);
        $this->assertNull($nota->contingencia_tipo);
        $this->assertNull($nota->epec_protocolo);
    }

    public function test_ambiente_nacional_tambem_fora_vira_erro_e_preserva_a_chave_original(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emissor->falharEmEpec = new FalhaDeComunicacaoException;

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('Ambiente Nacional', (string) $nota->mensagem_erro);
        $this->assertSame('35'.str_pad((string) $nota->numero, 42, '0', STR_PAD_LEFT), $nota->chave, 'chave original (do preparar normal), não a do EPEC');
        $this->assertNotNull($nota->numero, 'o número alocado foi preservado');
    }

    public function test_dado_fiscal_ausente_no_epec_vira_erro_e_nada_e_registrado(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emissor->falharEmEpec = new RuntimeException('Não foi possível registrar o EPEC: falta o valor do ICMS ST.');

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('falta o valor do ICMS ST', (string) $nota->mensagem_erro);
        $this->assertNull($nota->epec_protocolo);
    }

    public function test_erro_que_nao_e_de_comunicacao_nao_aciona_o_epec(): void
    {
        $this->emissor->falharEmTransmitir = new RuntimeException('XML inválido');

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertNotContains('prepararEpec', $this->emissor->chamadas);
    }

    public function test_nota_que_ja_nao_esta_processando_nao_e_sobrescrita(): void
    {
        $nota = $this->emitir();
        $nota->forceFill(['status' => StatusNota::Autorizada])->save();

        $r = app(EntrarEmEpec::class)->executar($nota->id);

        $this->assertSame(StatusNota::Autorizada, $r->status);
        $this->assertNull($r->refresh()->contingencia_tipo);
    }
}
