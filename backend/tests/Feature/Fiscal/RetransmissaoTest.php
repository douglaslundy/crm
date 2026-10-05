<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\RetransmitirNota;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\FalhaDeComunicacaoException;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEmContingenciaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class RetransmissaoTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-05 15:00:00');
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function emContingencia(StatusNota $status = StatusNota::Contingencia): Nota
    {
        $this->seq++;
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => null]);
        $nota->forceFill([
            'modelo' => ModeloDocumento::Nfce, 'status' => $status, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => $this->seq,
            'chave' => str_pad((string) $this->seq, 34, '0', STR_PAD_LEFT).'9'.str_repeat('7', 9), 'xml_enviado' => '<NFe>offline'.$this->seq.'</NFe>',
            'destinatario' => null, 'subtotal_centavos' => 1000, 'total_centavos' => 1000,
            'contingencia_desde' => '2026-10-05 10:00:00', 'contingencia_limite_em' => '2026-10-06 10:00:00', 'contingencia_motivo' => 'Falha',
        ])->save();
        $nota->itens()->create(['produto_id' => $this->produto->id, 'ordem' => 1, 'quantidade_milesimos' => 1000, 'valor_unitario_centavos' => 1000, 'total_centavos' => 1000, 'sku' => 'P-1', 'descricao' => 'Produto Um', 'unidade' => 'UN', 'ncm' => '12345678', 'cfop' => '5102', 'origem' => 0, 'csosn' => '102']);
        $nota->pagamentos()->create(['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]);

        return $nota->refresh();
    }

    public function test_retransmissao_autorizada_vira_autorizada(): void
    {
        $nota = $this->emContingencia();
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::autorizada('135000000000099', '<nfeProc>offline-ok</nfeProc>'));

        $r = app(RetransmitirNota::class)->executar($nota->id);

        $this->assertSame(StatusNota::Autorizada, $r->status);
        $this->assertSame('135000000000099', $r->protocolo);
        $r->makeVisible('xml_autorizado');
        $this->assertSame('<nfeProc>offline-ok</nfeProc>', $r->xml_autorizado);
        $this->assertNotNull($r->emitido_em);
        $this->assertNotNull($r->eventos()->where('status_para', 'AUTORIZADA')->first());
    }

    public function test_retransmissao_rejeitada_vira_rejeitada_com_o_motivo(): void
    {
        $nota = $this->emContingencia();
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('204', 'Duplicidade de NF-e'));

        $r = app(RetransmitirNota::class)->executar($nota->id);

        $this->assertSame(StatusNota::Rejeitada, $r->status);
        $this->assertSame('204', $r->cstat);
        $this->assertSame('Duplicidade de NF-e', $r->motivo);
        $this->assertStringContainsString('consumidor já recebeu o cupom', (string) $r->eventos()->where('status_para', 'REJEITADA')->first()?->detalhe);
    }

    public function test_falha_de_comunicacao_mantem_contingencia_sem_remontar(): void
    {
        $nota = $this->emContingencia();
        $chave = $nota->chave;
        $this->emissor->falharEmTransmitir = new FalhaDeComunicacaoException;

        $r = app(RetransmitirNota::class)->executar($nota->id);

        $this->assertSame(StatusNota::Contingencia, $r->status);
        $this->assertSame('2026-10-05 15:00:00', $r->ultima_retransmissao_em?->format('Y-m-d H:i:s'));
        $this->assertSame($chave, $r->chave);
        $r->makeVisible('xml_enviado');
        $this->assertSame('<NFe>offline1</NFe>', $r->xml_enviado);
        $this->assertSame([], $this->emissor->contingencias, 'nunca remonta o XML');
    }

    public function test_erro_inesperado_mantem_contingencia_e_propaga(): void
    {
        $nota = $this->emContingencia();
        $this->emissor->falharEmTransmitir = new RuntimeException('boom');

        try {
            app(RetransmitirNota::class)->executar($nota->id);
            $this->fail('Deveria propagar o erro inesperado.');
        } catch (RuntimeException) {
            $this->assertSame(StatusNota::Contingencia, $nota->refresh()->status);
        }
    }

    public function test_consulta_previa_autorizada_concilia_sem_reenviar(): void
    {
        $nota = $this->emContingencia();
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000010', '<nfeProc>x</nfeProc>'));

        $r = app(RetransmitirNota::class)->executar($nota->id);

        $this->assertSame(StatusNota::Autorizada, $r->status);
        $this->assertNotContains('transmitir', $this->emissor->chamadas);
    }

    public function test_consulta_previa_cancelada_vira_cancelada(): void
    {
        $nota = $this->emContingencia();
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::cancelada('101'));

        $this->assertSame(StatusNota::Cancelada, app(RetransmitirNota::class)->executar($nota->id)->status);
    }

    public function test_consulta_que_falha_por_comunicacao_segue_para_o_reenvio(): void
    {
        $nota = $this->emContingencia();
        $this->emissor->falharEmConsultar = new FalhaDeComunicacaoException;
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::autorizada('135000000000055', '<nfeProc>ok</nfeProc>'));

        $this->assertSame(StatusNota::Autorizada, app(RetransmitirNota::class)->executar($nota->id)->status);
    }

    public function test_segunda_chamada_nao_reenvia_uma_nota_ja_autorizada(): void
    {
        $nota = $this->emContingencia();
        app(RetransmitirNota::class)->executar($nota->id);
        $transmissoes = count(array_keys($this->emissor->chamadas, 'transmitir', true));

        $this->expectException(NotaNaoEmContingenciaException::class);
        try {
            app(RetransmitirNota::class)->executar($nota->id);
        } finally {
            $this->assertSame($transmissoes, count(array_keys($this->emissor->chamadas, 'transmitir', true)));
        }
    }

    public function test_status_diferente_de_contingencia_da_409(): void
    {
        $nota = $this->emContingencia(StatusNota::Autorizada);

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/retransmitir")
            ->assertStatus(409)->assertJsonPath('codigo', 'NOTA_NAO_EM_CONTINGENCIA');
    }

    public function test_endpoint_retransmite_e_devolve_o_status(): void
    {
        $nota = $this->emContingencia();

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/retransmitir")
            ->assertStatus(202)->assertJsonPath('data.status', 'AUTORIZADA');
    }

    public function test_papeis_e_isolamento_do_endpoint(): void
    {
        $nota = $this->emContingencia();
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        $outro = Tenant::factory()->for(Plano::factory())->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spa()->postJson("/api/app/notas/{$nota->id}/retransmitir")->assertUnauthorized();
        $this->spaComo($leitura)->postJson("/api/app/notas/{$nota->id}/retransmitir")->assertForbidden();
        $this->spaComo($intruso)->postJson("/api/app/notas/{$nota->id}/retransmitir")->assertNotFound();
        $this->assertSame(StatusNota::Contingencia, $nota->refresh()->status);
    }

    public function test_comando_retransmite_notas_de_todos_os_tenants_e_isola_falhas(): void
    {
        $primeira = $this->emContingencia();
        $segunda = $this->emContingencia();
        $this->emissor->falharEmTransmitir = new RuntimeException('só a primeira falha');

        $this->artisan('fiscal:retransmitir-contingencia')->assertSuccessful();
        app(TenantContext::class)->set($this->tenant->id);

        $this->assertSame(StatusNota::Contingencia, $primeira->refresh()->status);
        $this->emissor->falharEmTransmitir = null;
        $this->artisan('fiscal:retransmitir-contingencia')->assertSuccessful();
        app(TenantContext::class)->set($this->tenant->id);
        $this->assertSame(StatusNota::Autorizada, $primeira->refresh()->status);
        $this->assertSame(StatusNota::Autorizada, $segunda->refresh()->status);
    }

    public function test_o_agendador_roda_o_comando_de_hora_em_hora(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($e): bool => str_contains((string) $e->command, 'fiscal:retransmitir-contingencia'));

        $this->assertCount(1, $eventos);
        $this->assertSame('0 * * * *', $eventos->first()->expression);
    }
}
