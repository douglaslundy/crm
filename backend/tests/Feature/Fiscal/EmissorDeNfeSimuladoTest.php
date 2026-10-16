<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Emissao\PreparadorDaNota;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\NotaPagamento;
use App\Modules\Fiscal\Infrastructure\Simulado\EmissorDeNfeSimulado;
use App\Modules\Fiscal\Providers\FiscalServiceProvider;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class EmissorDeNfeSimuladoTest extends TestCase
{
    use CenarioDeApi;
    use CenarioDeNota;
    use RefreshDatabase;

    public function test_o_provider_aborta_com_a_flag_ligada_fora_do_staging(): void
    {
        foreach (['producao', 'Staging', ''] as $ambiente) {
            config(['app.ambiente' => $ambiente, 'fiscal.emissor_simulado' => true]);
            try {
                (new FiscalServiceProvider($this->app))->register();
                $this->fail("Subiu com APP_AMBIENTE='{$ambiente}'");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('APP_AMBIENTE=staging', $e->getMessage());
            }
        }
    }

    public function test_no_staging_com_a_flag_o_contrato_resolve_para_o_simulado(): void
    {
        config(['app.ambiente' => 'staging', 'fiscal.emissor_simulado' => true]);
        (new FiscalServiceProvider($this->app))->register();

        $this->assertInstanceOf(EmissorDeNfeSimulado::class, $this->app->make(EmissorDeNfe::class));
    }

    public function test_emissao_pela_api_passa_pela_fila_e_termina_autorizada(): void
    {
        config(['queue.default' => 'sync']);
        $this->montarCenario(Modulo::Api, Modulo::FiscalNfe);
        $this->tenant->plano?->limites()->create(['recurso' => Recurso::ApiRequisicoesMin->value, 'limite' => -1]);
        [, $texto] = $this->novaChave($this->admin, AmbienteDaChave::Test);
        $this->app->instance(EmissorDeNfe::class, new EmissorDeNfeSimulado);

        $resposta = $this->comoApi($texto)->withHeaders(['Idempotency-Key' => 'carga-1'])->postJson('/api/v1/notas', [
            'modelo' => 'NFE', 'cliente' => ['id' => $this->cliente->id], 'desconto_centavos' => 0,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1']],
            'pagamentos' => [['tpag' => '01', 'valor_centavos' => 1000]],
        ])->assertStatus(202);

        $nota = Nota::query()->findOrFail($resposta->json('data.id'));
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame(44, strlen((string) $nota->chave));
    }

    public function test_chave_nao_colide_entre_empresas_com_o_mesmo_numero(): void
    {
        $this->montarCenario(Modulo::FiscalNfe);
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        NotaItem::factory()->create(['nota_id' => $nota->id, 'produto_id' => $this->produto->id, 'ordem' => 1, 'quantidade_milesimos' => 1000, 'valor_unitario_centavos' => 1000, 'total_centavos' => 1000]);
        NotaPagamento::query()->create(['nota_id' => $nota->id, 'tpag' => '01', 'valor_centavos' => 1000]);
        app(PreparadorDaNota::class)->preparar($nota, $this->emitente);
        $nota->forceFill(['ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 7])->save();
        $dto = NotaParaEmissao::deNota($nota->refresh(), $this->emitente->refresh(), $this->tenant);

        $outro = $this->emitente->replicate();
        $outro->id = '00000000-0000-4000-8000-000000000002';
        $emissor = new EmissorDeNfeSimulado;

        $this->assertSame(44, strlen($emissor->preparar($this->emitente, $dto)->chave));
        $this->assertNotSame($emissor->preparar($this->emitente, $dto)->chave, $emissor->preparar($outro, $dto)->chave);
    }
}
