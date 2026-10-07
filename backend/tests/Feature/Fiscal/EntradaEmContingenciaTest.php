<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Domain\Exceptions\FalhaDeComunicacaoException;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class EntradaEmContingenciaTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-05 12:00:00');
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfce, 'serie' => '1', 'proximo_numero' => 1]);
        $this->emitente->update([
            'csc_id_homologacao' => '000001',
            'csc_token_homologacao_encrypted' => Crypt::encryptString('TOKEN-DE-TESTE'),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function corpo(string $modelo): array
    {
        return [
            'modelo' => $modelo, 'cliente_id' => $modelo === 'NFE' ? $this->cliente->id : null, 'consumidor_final' => null,
            'desconto_centavos' => 0, 'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ];
    }

    private function emitir(string $modelo): Nota
    {
        $id = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo($modelo))->assertCreated()->json('data.id');
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);

        return Nota::query()->findOrFail($id);
    }

    public function test_nfce_com_falha_de_comunicacao_entra_em_contingencia_com_chave_nova_e_limite(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;

        $nota = $this->emitir('NFCE');

        $this->assertSame(StatusNota::Contingencia, $nota->status);
        $this->assertSame(1, $nota->numero);
        $this->assertSame(['prepararContingencia'], array_values(array_diff($this->emissor->chamadas, ['preparar', 'transmitir', 'consultar'])));
        $this->assertSame('35'.str_pad('1', 33, '0', STR_PAD_LEFT).'9'.str_repeat('7', 9), $nota->chave);
        $nota->makeVisible('xml_enviado');
        $this->assertSame('<NFe>contingencia</NFe>', $nota->xml_enviado);
        $this->assertSame('2026-10-05 12:00:00', $nota->contingencia_desde?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06 12:00:00', $nota->contingencia_limite_em?->format('Y-m-d H:i:s'));
        $this->assertNotNull($nota->contingencia_motivo);
        $this->assertSame(TipoDeContingencia::OfflineNfce, $nota->contingencia_tipo);
        $this->assertStringContainsString('contingência', (string) $nota->eventos()->where('status_para', 'CONTINGENCIA')->first()?->detalhe);
    }

    public function test_o_prazo_vem_da_configuracao(): void
    {
        config(['fiscal.contingencia_prazo_horas' => 6]);
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;

        $nota = $this->emitir('NFCE');

        $this->assertSame('2026-10-05 18:00:00', $nota->contingencia_limite_em?->format('Y-m-d H:i:s'));
    }

    public function test_nfe_com_falha_de_comunicacao_vira_erro_e_nao_entra_em_contingencia(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;

        $nota = $this->emitir('NFE');

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertNotContains('prepararContingencia', $this->emissor->chamadas);
    }

    public function test_erro_que_nao_e_de_comunicacao_vira_erro_mesmo_na_nfce(): void
    {
        $this->emissor->falharEmTransmitir = new RuntimeException('XML inválido');

        $nota = $this->emitir('NFCE');

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertNotContains('prepararContingencia', $this->emissor->chamadas);
    }

    public function test_consulta_previa_autorizada_nao_entra_em_contingencia(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000010', '<nfeProc>x</nfeProc>'));

        $nota = $this->emitir('NFCE');

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('135000000000010', $nota->protocolo);
        $this->assertNotContains('prepararContingencia', $this->emissor->chamadas);
    }

    public function test_consulta_que_tambem_falha_por_comunicacao_entra_em_contingencia(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emissor->falharEmConsultar = new FalhaDeComunicacaoException;

        $this->assertSame(StatusNota::Contingencia, $this->emitir('NFCE')->status);
    }

    public function test_consulta_nao_encontrada_entra_em_contingencia(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::naoEncontrada());

        $this->assertSame(StatusNota::Contingencia, $this->emitir('NFCE')->status);
    }

    public function test_nota_em_contingencia_nao_conta_na_cota(): void
    {
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;
        $this->emitir('NFCE');

        $this->assertSame(0, (new ContadorDeDocumentosMes)->contar($this->tenant));
    }
}
