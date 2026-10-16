<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\AtualizarSerie;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Http\Resources\EmitenteSerieResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class ContadorPorAmbienteTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function serie(): EmitenteSerie
    {
        return EmitenteSerie::query()->where('emitente_id', $this->emitente->id)->firstOrFail();
    }

    public function test_cada_ambiente_tem_o_seu_contador(): void
    {
        $serie = $this->serie();
        $serie->definirProximoNumero(AmbienteFiscal::Homologacao, 7);

        $serie->refresh();
        $this->assertSame(7, $serie->proximoNumeroDo(AmbienteFiscal::Homologacao));
        $this->assertSame(1, $serie->proximoNumeroDo(AmbienteFiscal::Producao));

        $serie->definirProximoNumero(AmbienteFiscal::Producao, 500);
        $serie->refresh();
        $this->assertSame(7, $serie->proximo_numero);
        $this->assertSame(500, $serie->proximo_numero_producao);
    }

    public function test_coluna_do_numero_por_ambiente(): void
    {
        $this->assertSame('proximo_numero', EmitenteSerie::colunaDoNumero(AmbienteFiscal::Homologacao));
        $this->assertSame('proximo_numero_producao', EmitenteSerie::colunaDoNumero(AmbienteFiscal::Producao));
    }

    public function test_serie_nova_nasce_com_producao_em_1(): void
    {
        $nova = EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfce, 'serie' => '2']);

        $this->assertSame(1, $nova->refresh()->proximo_numero_producao);
    }

    public function test_migration_herda_o_contador_de_quem_ja_estava_em_producao(): void
    {
        // Estado pré-migration: o contador único valia para os dois ambientes.
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        DB::table('emitente_series')->where('emitente_id', $this->emitente->id)->update(['proximo_numero' => 42, 'proximo_numero_producao' => 1]);

        $migration = require database_path('migrations/2026_10_19_000001_add_proximo_numero_producao_to_emitente_series.php');
        $migration->herdarContadorDeQuemJaEstaEmProducao();

        $this->assertSame(42, $this->serie()->proximo_numero_producao);
    }

    public function test_migration_nao_mexe_em_quem_esta_em_homologacao(): void
    {
        DB::table('emitente_series')->where('emitente_id', $this->emitente->id)->update(['proximo_numero' => 42, 'proximo_numero_producao' => 1]);

        $migration = require database_path('migrations/2026_10_19_000001_add_proximo_numero_producao_to_emitente_series.php');
        $migration->herdarContadorDeQuemJaEstaEmProducao();

        $this->assertSame(1, $this->serie()->proximo_numero_producao);
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

    public function test_emissao_em_producao_usa_e_avanca_so_o_contador_de_producao(): void
    {
        Queue::fake();
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        $this->serie()->update(['proximo_numero' => 9, 'proximo_numero_producao' => 500]);

        $nota = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);

        $this->assertSame(500, $nota->numero);
        $this->assertSame('PRODUCAO', $nota->ambiente);
        $this->assertSame(9, $this->serie()->proximo_numero);
        $this->assertSame(501, $this->serie()->proximo_numero_producao);
    }

    public function test_emissao_em_homologacao_nao_move_o_contador_de_producao(): void
    {
        Queue::fake();
        $this->serie()->update(['proximo_numero_producao' => 500]);

        $nota = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);

        $this->assertSame(1, $nota->numero);
        $this->assertSame(2, $this->serie()->proximo_numero);
        $this->assertSame(500, $this->serie()->proximo_numero_producao);
    }

    public function test_atualizar_serie_grava_no_contador_do_ambiente_atual(): void
    {
        $acao = app(AtualizarSerie::class);

        $acao->executar($this->emitente, ModeloDocumento::Nfe, '1', 30);
        $this->assertSame(30, $this->serie()->proximo_numero);
        $this->assertSame(1, $this->serie()->proximo_numero_producao);

        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        $acao->executar($this->emitente->refresh(), ModeloDocumento::Nfe, '1', 80);
        $this->assertSame(30, $this->serie()->proximo_numero);
        $this->assertSame(80, $this->serie()->proximo_numero_producao);
    }

    public function test_resource_da_serie_mostra_o_contador_do_ambiente_atual(): void
    {
        $this->serie()->update(['proximo_numero' => 9, 'proximo_numero_producao' => 500]);
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);

        $json = (new EmitenteSerieResource($this->serie()->refresh()))->resolve();

        $this->assertSame(500, $json['proximo_numero']);
    }
}
