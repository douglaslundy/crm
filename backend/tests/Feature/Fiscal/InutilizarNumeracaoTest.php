<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\InutilizarNumeracao;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Exceptions\JustificativaInvalidaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class InutilizarNumeracaoTest extends TestCase
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
        // Números 1 a 9 já alocados: o próximo é o 10.
        $this->emitente->series()->update(['proximo_numero' => 10]);
    }

    /**
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    private function dados(array $sobrescrever = []): array
    {
        return array_replace([
            'serie' => '1', 'numero_inicial' => 3, 'numero_final' => 5,
            'justificativa' => 'Numeração perdida por queda do servidor',
        ], $sobrescrever);
    }

    private function assertBloqueio(string $codigo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Esperava o bloqueio {$codigo}.");
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame($codigo, $e->codigo(), $e->getMessage());
            $this->assertSame([], $this->emissor->chamadas, 'bloqueio local não chama a SEFAZ');
        }
    }

    private function notaNaFaixa(int $numero, StatusNota $status, string $ambiente = 'HOMOLOGACAO'): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill(['status' => $status, 'ambiente' => $ambiente, 'serie' => '1', 'numero' => $numero])->save();
    }

    public function test_inutiliza_a_faixa_e_grava_o_resultado(): void
    {
        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusInutilizacao::Inutilizada, $r->status);
        $this->assertSame('135260000000098', $r->protocolo);
        $this->assertSame([3, 5], [$r->numero_inicial, $r->numero_final]);
        $this->assertSame('HOMOLOGACAO', $r->ambiente);
        $this->assertSame($this->admin->id, $r->usuario_id);
        $this->assertSame(['inutilizar'], $this->emissor->chamadas);
        $this->assertSame(10, $this->emitente->series()->first()->proximo_numero, 'não mexe na numeração');
    }

    public function test_faixa_invalida_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_FAIXA_INVALIDA', fn () => app(InutilizarNumeracao::class)->executar($this->dados(['numero_inicial' => 6, 'numero_final' => 5]), $this->admin));
        $this->assertBloqueio('FISCAL_FAIXA_INVALIDA', fn () => app(InutilizarNumeracao::class)->executar($this->dados(['numero_inicial' => 0]), $this->admin));
    }

    public function test_faixa_futura_ou_nao_alocada_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_FAIXA_NAO_ALOCADA', fn () => app(InutilizarNumeracao::class)->executar($this->dados(['numero_inicial' => 8, 'numero_final' => 10]), $this->admin));
    }

    public function test_serie_inexistente_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_SERIE_NAO_CONFIGURADA', fn () => app(InutilizarNumeracao::class)->executar($this->dados(['serie' => '9']), $this->admin));
    }

    public function test_faixa_com_nota_autorizada_em_processamento_ou_cancelada_bloqueia_citando_o_numero(): void
    {
        foreach ([StatusNota::Autorizada, StatusNota::Processando, StatusNota::Cancelando, StatusNota::Cancelada] as $status) {
            Nota::query()->delete();
            $this->notaNaFaixa(4, $status);
            try {
                app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);
                $this->fail("Inutilizou faixa com nota {$status->value}.");
            } catch (EmissaoBloqueadaException $e) {
                $this->assertSame('FISCAL_FAIXA_COM_NOTA', $e->codigo());
                $this->assertStringContainsString('4', $e->getMessage());
            }
        }
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_nota_rejeitada_ou_de_outro_ambiente_na_faixa_nao_bloqueia(): void
    {
        $this->notaNaFaixa(3, StatusNota::Rejeitada);
        $this->notaNaFaixa(4, StatusNota::Autorizada, 'PRODUCAO'); // o emitente está em HOMOLOGACAO

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusInutilizacao::Inutilizada, $r->status);
    }

    public function test_recusa_da_sefaz_e_gravada_com_cstat_e_motivo(): void
    {
        $this->emissor->enfileirarInutilizacao(ResultadoDeInutilizacao::recusada('241', 'Um numero da faixa ja foi utilizado'));

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusInutilizacao::Recusada, $r->status);
        $this->assertSame('241', $r->cstat);
    }

    public function test_duplicidade_563_diz_que_ja_foi_inutilizada(): void
    {
        $this->emissor->enfileirarInutilizacao(ResultadoDeInutilizacao::jaInutilizada('Ja existe pedido'));

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame('563', $r->cstat);
        $this->assertStringContainsString('já foi inutilizada', (string) $r->motivo);
    }

    public function test_falha_tecnica_vira_erro_gravado_e_nao_propaga(): void
    {
        $this->emissor->falharEmInutilizar = new RuntimeException('cURL error 28: timeout');

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusInutilizacao::Erro, $r->status);
        $this->assertStringContainsString('timeout', (string) $r->motivo);
    }

    public function test_usa_o_ambiente_atual_do_emitente(): void
    {
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame('PRODUCAO', $r->ambiente);
        $this->assertSame(['PRODUCAO'], $this->emissor->ambientes);
    }

    public function test_justificativa_papel_e_modulo(): void
    {
        try {
            app(InutilizarNumeracao::class)->executar($this->dados(['justificativa' => 'curta']), $this->admin);
            $this->fail('Justificativa curta passou.');
        } catch (JustificativaInvalidaException) {
            $this->addToAssertionCount(1);
        }

        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        try {
            app(InutilizarNumeracao::class)->executar($this->dados(), $vendedor);
            $this->fail('Vendedor inutilizou.');
        } catch (AcessoNegadoException) {
            $this->addToAssertionCount(1);
        }

        $this->tenant->plano->modulos()->delete();
        $this->tenant->unsetRelation('plano');
        $this->expectException(ModuloNaoContratadoException::class);
        app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin->refresh());
    }
}
