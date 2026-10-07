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
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '55', $this->xmlDaNfse()));
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/emitir")->assertStatus(202);

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame($numero, $nota->numero);
        $this->assertSame('55', $nota->numero_nfse);
        $this->assertSame(['consultarDps'], $this->emissor->chamadas);
    }

    public function test_dps_ja_existente_na_adn_nunca_vira_segunda_nota(): void
    {
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '9', $this->xmlDaNfse()));

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

    // --- Correções da revisão final ---

    public function test_dps_de_outro_tomador_vira_erro_e_libera_o_numero(): void
    {
        // A numeração já foi usada por outra NFS-e (outro sistema): nunca adotar uma nota alheia.
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '9', $this->xmlDaNfse('11222333000181')));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('outra NFS-e', (string) $nota->mensagem_erro);
        $this->assertNull($nota->numero, 'o número ocupado por outra nota é liberado para a próxima tentativa');
        $this->assertNull($nota->chave);
        $this->assertNull($nota->numero_nfse);
        $this->assertNotContains('emitir', $this->emissor->chamadas);
    }

    public function test_dps_do_mesmo_tomador_com_valor_diferente_vira_erro_e_preserva_o_numero(): void
    {
        // Provável NFS-e anterior desta mesma nota, editada depois de um timeout: ambíguo, então preserva o número (sem duplicar).
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '9', $this->xmlDaNfse(null, '99.00')));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('valor', (string) $nota->mensagem_erro);
        $this->assertSame(1, $nota->numero);
        $this->assertNull($nota->numero_nfse);
        $this->assertNotContains('emitir', $this->emissor->chamadas);
    }

    public function test_xml_sem_valor_ou_tomador_nao_pode_ser_adotado(): void
    {
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '9', '<NFSe><infNFSe/></NFSe>'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('conferir', (string) $nota->mensagem_erro);
    }

    public function test_rejeicao_com_a_dps_ja_autorizada_na_adn_concilia_em_vez_de_rejeitar(): void
    {
        // A ADN ainda processava o envio anterior: o reenvio é recusado por duplicidade, mas a nota existe.
        $this->emissor->enfileirarConsultaDps(
            ResultadoDeConsultaNfse::naoEncontrada(),
            ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '12', $this->xmlDaNfse()),
        );
        $this->emissor->enfileirarEmissao(ResultadoDeEmissaoNfse::rejeitada('E0014', 'DPS já existente'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('12', $nota->numero_nfse);
        $this->assertSame(['consultarDps', 'emitir', 'consultarDps'], $this->emissor->chamadas);
    }

    public function test_rejeicao_sem_a_dps_na_adn_continua_rejeitada(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissaoNfse::rejeitada('E0625', 'Alíquota não permitida'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Rejeitada, $nota->status);
        $this->assertSame(['consultarDps', 'emitir', 'consultarDps'], $this->emissor->chamadas);
    }

    public function test_autorizacao_tardia_depois_de_a_reconciliacao_marcar_erro_nao_se_perde(): void
    {
        // O envio demora; a reconciliação marca ERRO ("vencida"); a ADN autoriza depois.
        $this->emissor->aoEmitir = fn () => Nota::query()->update(['status' => StatusNota::Erro, 'mensagem_erro' => 'A ADN não recebeu a DPS.']);

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame(EmissorDeNfseFake::CHAVE, $nota->chave);
        $this->assertNull($nota->mensagem_erro);
    }
}
