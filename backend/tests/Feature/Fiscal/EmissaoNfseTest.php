<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNfse;
use Tests\Fixtures\EmissorDeNfseFake;
use Tests\TestCase;

class EmissaoNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    private EmissorDeNfseFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
        $this->emissor = new EmissorDeNfseFake;
        $this->app->instance(EmissorDeNfse::class, $this->emissor);
    }

    private function emitir(): Nota
    {
        $nota = $this->rascunhoDeNfse();
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/emitir")->assertStatus(202);

        return $nota->refresh();
    }

    public function test_emissao_autorizada_grava_chave_numero_oficial_xml_e_conta_na_cota(): void
    {
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame(EmissorDeNfseFake::CHAVE, $nota->chave);
        $this->assertSame('1', $nota->numero_nfse);
        $this->assertSame('100', $nota->cstat);
        $this->assertNotNull($nota->emitido_em);
        $nota->makeVisible('xml_autorizado');
        $this->assertSame('<NFSe>fake</NFSe>', $nota->xml_autorizado);
        $this->assertSame(['consultarDps', 'emitir'], $this->emissor->chamadas);
        $this->assertSame('AUTORIZADA', $nota->eventos()->reorder('id', 'desc')->first()?->status_para);
        // A cota só conta produção: a nota de homologação não consome DOCUMENTOS_MES.
        $this->assertSame(0, (new ContadorDeDocumentosMes)->contar($this->tenant));
    }

    public function test_nfse_autorizada_em_producao_conta_na_cota(): void
    {
        $this->notaDeNfsePronta(StatusNota::Autorizada, ['ambiente' => 'PRODUCAO', 'emitido_em' => now(), 'chave' => EmissorDeNfseFake::CHAVE, 'numero_nfse' => '1']);

        $this->assertSame(1, (new ContadorDeDocumentosMes)->contar($this->tenant));
    }

    public function test_rejeicao_da_adn_vira_rejeitada_e_a_nota_volta_a_ser_editavel(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissaoNfse::rejeitada('E0625', 'Alíquota não permitida'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Rejeitada, $nota->status);
        $this->assertSame('E0625', $nota->cstat);
        $this->assertSame('Alíquota não permitida', $nota->motivo);
        $this->assertTrue($nota->status->editavel());
        $this->assertSame(0, (new ContadorDeDocumentosMes)->contar($this->tenant));
    }

    public function test_falha_tecnica_vira_erro(): void
    {
        $this->emissor->falharEmEmitir = new RuntimeException('boom');

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('Falha técnica ao emitir', (string) $nota->mensagem_erro);
    }

    public function test_erro_de_comunicacao_preserva_o_numero_e_o_retry_consulta_a_dps_antes_de_reenviar(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissaoNfse::erro('Falha de comunicação com a ADN'));
        $nota = $this->emitir();
        $this->assertSame(StatusNota::Erro, $nota->status);
        $numero = $nota->numero;

        // A resposta se perdeu, mas a ADN registrou a DPS: o retry concilia sem enviar de novo.
        $this->emissor->chamadas = [];
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '55', '<NFSe>ja-estava</NFSe>'));
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/emitir")->assertStatus(202);

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame($numero, $nota->numero);
        $this->assertSame('55', $nota->numero_nfse);
        $this->assertSame(['consultarDps'], $this->emissor->chamadas);
    }

    public function test_dps_ja_existente_na_adn_nunca_vira_segunda_nota(): void
    {
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '9', '<NFSe>x</NFSe>'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('9', $nota->numero_nfse);
        $this->assertNotContains('emitir', $this->emissor->chamadas);
    }

    public function test_consulta_da_dps_com_incerteza_vira_erro_e_nao_reenvia(): void
    {
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::erro('ADN fora do ar'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('ADN fora do ar', (string) $nota->mensagem_erro);
        $this->assertNotContains('emitir', $this->emissor->chamadas);
    }

    public function test_excecao_na_consulta_da_dps_tambem_nao_reenvia(): void
    {
        $this->emissor->falharEmConsultar = new RuntimeException('timeout');

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertNotContains('emitir', $this->emissor->chamadas);
    }

    public function test_dps_encontrada_ja_cancelada_cancela_a_nota(): void
    {
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::cancelada(EmissorDeNfseFake::CHAVE));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Cancelada, $nota->status);
    }
}
