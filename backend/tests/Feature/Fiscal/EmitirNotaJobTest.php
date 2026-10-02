<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\AplicarResultadoDaEmissao;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class EmitirNotaJobTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    /** Com QUEUE_CONNECTION=sync dos testes, `IniciarEmissao` já executa o job. */
    private function emitir(): Nota
    {
        $nota = app(CriarRascunhoDeNota::class)->executar([
            'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0,
            'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $this->admin);

        return app(IniciarEmissao::class)->executar($nota->id, $this->admin)->refresh();
    }

    public function test_autorizada_grava_protocolo_xml_e_data(): void
    {
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('135000000000001', $nota->protocolo);
        $this->assertSame('<nfeProc>fake</nfeProc>', $nota->xml_autorizado);
        $this->assertSame('<NFe>fake</NFe>', $nota->xml_enviado);
        $this->assertNotNull($nota->chave);
        $this->assertNotNull($nota->emitido_em);
        $this->assertSame(['preparar', 'transmitir'], $this->emissor->chamadas);
    }

    public function test_rejeitada_grava_cstat_e_motivo_e_preserva_o_numero(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('232', 'IE do destinatário não informada'));
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Rejeitada, $nota->status);
        $this->assertSame('232', $nota->cstat);
        $this->assertSame('IE do destinatário não informada', $nota->motivo);
        $this->assertSame(1, $nota->numero);
    }

    public function test_excecao_inesperada_vira_erro_e_nunca_rejeitada(): void
    {
        $this->emissor->falharEmTransmitir = new RuntimeException('cURL error 28: timeout');
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('timeout', (string) $nota->mensagem_erro);
        $this->assertNotNull($nota->chave, 'a chave foi gravada antes do envio');
        $this->assertSame(1, $nota->numero);
    }

    public function test_falha_ao_preparar_vira_erro_sem_chave(): void
    {
        $this->emissor->falharEmPreparar = new RuntimeException('XSD: elemento inválido');
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertNull($nota->chave);
    }

    public function test_resultado_processando_mantem_processando(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::processando());
        $this->assertSame(StatusNota::Processando, $this->emitir()->status);
    }

    public function test_retry_com_chave_consulta_antes_de_reenviar_e_concilia_autorizada(): void
    {
        $nota = $this->emitir();
        $nota->forceFill(['status' => StatusNota::Erro, 'chave' => str_repeat('3', 44), 'xml_enviado' => '<NFe>x</NFe>'])->save();
        $this->emissor->chamadas = [];
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000009', '<nfeProc>real</nfeProc>'));

        $de_novo = app(IniciarEmissao::class)->executar($nota->id, $this->admin)->refresh();

        $this->assertSame(StatusNota::Autorizada, $de_novo->status);
        $this->assertSame('135000000000009', $de_novo->protocolo);
        $this->assertSame(['consultar'], $this->emissor->chamadas, 'não reenviou uma nota já autorizada');
    }

    public function test_numero_ocupado_por_nota_cancelada_avanca_e_reemite(): void
    {
        $this->emissor->enfileirarEmissao(
            ResultadoDeEmissao::numeroOcupado(str_repeat('9', 44), 'Duplicidade de NF-e'),
            ResultadoDeEmissao::autorizada('135000000000002', '<nfeProc>ok</nfeProc>'),
        );
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::cancelada());

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame(2, $nota->numero, 'pulou o número ocupado por nota cancelada');
        $this->assertSame(1, $nota->tentativas_numero);
    }

    public function test_numero_ocupado_por_nota_autorizada_vira_erro_para_conciliacao_manual(): void
    {
        $chave = str_repeat('8', 44);
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::numeroOcupado($chave, 'Duplicidade de NF-e'));
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000003', null));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString($chave, (string) $nota->mensagem_erro);
        $this->assertSame(1, $nota->numero, 'não avançou: criaria uma duplicata real');
    }

    public function test_numero_ocupado_respeita_o_limite_de_tentativas(): void
    {
        config(['fiscal.tentativas_numero_ocupado' => 2]);
        $this->emissor->enfileirarEmissao(...array_fill(0, 5, ResultadoDeEmissao::numeroOcupado(str_repeat('7', 44), 'Duplicidade')));
        $this->emissor->enfileirarConsulta(...array_fill(0, 5, ResultadoDeConsulta::cancelada()));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertSame(2, $nota->tentativas_numero);
    }

    public function test_job_ignora_nota_que_nao_esta_processando(): void
    {
        $nota = $this->emitir();
        $this->emissor->chamadas = [];
        (new EmitirNotaJob($nota->id, $this->tenant->id))->handle(
            $this->emissor, app(AplicarResultadoDaEmissao::class), app(TenantContext::class),
        );
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_eventos_registram_a_trilha(): void
    {
        $nota = $this->emitir();
        $this->assertSame(['RASCUNHO', 'PROCESSANDO', 'AUTORIZADA'], $nota->eventos()->pluck('status_para')->all());
    }
}
