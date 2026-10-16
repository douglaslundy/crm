<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
