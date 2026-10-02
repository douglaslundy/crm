<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class IniciarEmissaoTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        Queue::fake();
    }

    private function rascunho(): Nota
    {
        return app(CriarRascunhoDeNota::class)->executar([
            'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0,
            'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $this->admin);
    }

    public function test_inicia_a_emissao_aloca_numero_e_despacha_o_job(): void
    {
        $nota = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);

        $this->assertSame(StatusNota::Processando, $nota->status);
        $this->assertSame(1, $nota->numero);
        $this->assertSame('1', $nota->serie);
        $this->assertSame('HOMOLOGACAO', $nota->ambiente);
        $this->assertSame(2, $this->emitente->series()->first()->proximo_numero);
        Queue::assertPushed(EmitirNotaJob::class, fn (EmitirNotaJob $j): bool => $j->notaId === $nota->id && $j->tenantId === $this->tenant->id);
    }

    public function test_emissao_duplicada_nao_consome_numero_nem_despacha_segundo_job(): void
    {
        $id = $this->rascunho()->id;
        app(IniciarEmissao::class)->executar($id, $this->admin);
        $segunda = app(IniciarEmissao::class)->executar($id, $this->admin);

        $this->assertSame(StatusNota::Processando, $segunda->status);
        $this->assertSame(2, $this->emitente->series()->first()->proximo_numero);
        Queue::assertPushed(EmitirNotaJob::class, 1);
    }

    public function test_nota_autorizada_nao_reemite(): void
    {
        $nota = $this->rascunho();
        $nota->forceFill(['status' => StatusNota::Autorizada, 'numero' => 5])->save();
        app(IniciarEmissao::class)->executar($nota->id, $this->admin);
        Queue::assertNothingPushed();
    }

    public function test_nota_cancelada_nao_pode_ser_emitida(): void
    {
        $nota = $this->rascunho();
        $nota->forceFill(['status' => StatusNota::Cancelada])->save();
        $this->expectException(NotaNaoEditavelException::class);
        app(IniciarEmissao::class)->executar($nota->id, $this->admin);
    }

    public function test_retry_de_rejeitada_reaproveita_o_numero_e_limpa_o_retorno(): void
    {
        $nota = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
        $nota->forceFill(['status' => StatusNota::Rejeitada, 'cstat' => '999', 'motivo' => 'x', 'chave' => str_repeat('1', 44), 'xml_enviado' => '<x/>'])->save();

        $de_novo = app(IniciarEmissao::class)->executar($nota->id, $this->admin);

        $this->assertSame(1, $de_novo->numero);
        $this->assertSame(2, $this->emitente->series()->first()->proximo_numero);
        $this->assertNull($de_novo->chave);
        $this->assertNull($de_novo->xml_enviado);
        $this->assertNull($de_novo->cstat);
        $this->assertSame(StatusNota::Processando, $de_novo->status);
    }

    public function test_numeros_distintos_para_notas_distintas(): void
    {
        $a = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
        $b = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
        $this->assertSame([1, 2], [$a->numero, $b->numero]);
    }

    public function test_bloqueio_fiscal_nao_consome_numero_nem_muda_status(): void
    {
        $this->produto->update(['ncm' => null]);
        $nota = $this->rascunho();

        try {
            app(IniciarEmissao::class)->executar($nota->id, $this->admin);
            $this->fail('Esperava bloqueio.');
        } catch (EmissaoBloqueadaException) {
            $this->assertSame(StatusNota::Rascunho, $nota->refresh()->status);
            $this->assertNull($nota->numero);
            $this->assertSame(1, $this->emitente->series()->first()->proximo_numero);
            Queue::assertNothingPushed();
        }
    }

    public function test_usa_o_cadastro_atual_do_cliente_e_congela_o_snapshot(): void
    {
        $nota = $this->rascunho();
        $this->cliente->update(['uf' => 'MG', 'codigo_ibge' => '3106200']);
        $emitida = app(IniciarEmissao::class)->executar($nota->id, $this->admin);
        $this->assertSame('MG', $emitida->destinatario['uf']);

        $this->cliente->update(['uf' => 'RJ', 'codigo_ibge' => '3304557']);
        $emitida->forceFill(['status' => StatusNota::Erro])->save();
        $this->assertSame('MG', $emitida->refresh()->destinatario['uf'], 'snapshot não muda sozinho');
    }

    public function test_exige_papel_modulo_e_situacao(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $id = $this->rascunho()->id;
        try {
            app(IniciarEmissao::class)->executar($id, $vendedor);
            $this->fail('Vendedor não emite.');
        } catch (AcessoNegadoException) {
            $this->addToAssertionCount(1);
        }

        $this->tenant->plano->modulos()->delete();
        $this->tenant->unsetRelation('plano');
        try {
            app(IniciarEmissao::class)->executar($id, $this->admin);
            $this->fail('Sem módulo.');
        } catch (ModuloNaoContratadoException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_assinatura_suspensa_nao_emite(): void
    {
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        $this->expectException(AssinaturaSemEscritaException::class);
        app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
    }

    public function test_limite_do_plano_so_vale_em_producao(): void
    {
        $this->tenant->plano->limites()->where('recurso', 'DOCUMENTOS_MES')->update(['limite' => 0]);
        $this->tenant->unsetRelation('plano');

        app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin); // homologação: passa

        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        $this->expectException(LimiteDoPlanoAtingidoException::class);
        app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
    }
}
