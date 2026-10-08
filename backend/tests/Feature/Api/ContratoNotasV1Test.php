<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\NotaPagamento;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\ContratoOpenApi;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

/** Consultas, cancelamento e inutilização: respostas reais contra os schemas de `docs/api/openapi.yaml`. */
class ContratoNotasV1Test extends TestCase
{
    use CenarioDeApi;
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario(Modulo::Api, Modulo::FiscalNfe);
        $this->tenant->plano?->limites()->create(['recurso' => Recurso::ApiRequisicoesMin->value, 'limite' => -1]);
        [$this->chave, $this->textoDaChave] = $this->novaChave($this->admin, AmbienteDaChave::Test);
        $this->app->instance(EmissorDeNfe::class, new EmissorFake);
        $this->emitente->series()->update(['proximo_numero' => 10]);
    }

    private function assertContrato(string $schema, mixed $dados): void
    {
        $this->assertSame([], ContratoOpenApi::validar($schema, $dados), "Resposta fora do contrato {$schema}");
    }

    private function autorizada(): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Autorizada, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => $numero = (int) Nota::query()->max('numero') + 1,
            'chave' => str_repeat((string) ($numero % 10), 44), 'protocolo' => '135260000000001', 'xml_autorizado' => '<nfeProc>x</nfeProc>', 'emitido_em' => now(),
        ])->save();
        NotaItem::factory()->create(['nota_id' => $nota->id, 'produto_id' => $this->produto->id, 'descricao' => 'Parafuso', 'unidade' => 'UN', 'quantidade_milesimos' => 2500, 'cfop' => '5102', 'csosn' => '102']);
        NotaPagamento::query()->forceCreate(['nota_id' => $nota->id, 'tpag' => '01', 'valor_centavos' => 1000]);
        NotaEvento::query()->forceCreate(['nota_id' => $nota->id, 'status_de' => 'PROCESSANDO', 'status_para' => 'AUTORIZADA', 'detalhe' => null, 'created_at' => now()]);

        return $nota->refresh();
    }

    public function test_consultas_de_nota_respeitam_o_contrato(): void
    {
        $nota = $this->autorizada();

        $this->assertContrato('RespostaDeNota', $this->comoApi()->getJson("/api/v1/notas/{$nota->id}")->assertOk()->json());
        $this->assertContrato('RespostaDeNotaStatus', $this->comoApi()->getJson("/api/v1/notas/{$nota->id}/status")->assertOk()->json());
        $this->assertContrato('ListaDeNotas', $this->comoApi()->getJson('/api/v1/notas')->assertOk()->json());
    }

    public function test_cancelar_respeita_o_contrato(): void
    {
        $nota = $this->autorizada();

        $resposta = $this->comoApi()->postJson("/api/v1/notas/{$nota->id}/cancelar", ['justificativa' => 'Cancelamento por erro de digitação do valor total'])->assertStatus(202);
        $this->assertContrato('RespostaDeNotaStatus', $resposta->json());
    }

    public function test_inutilizacao_respeita_o_contrato(): void
    {
        $criada = $this->comoApi()->postJson('/api/v1/notas/inutilizacoes', ['serie' => '1', 'numero_inicial' => 3, 'numero_final' => 5, 'justificativa' => 'Numeração perdida por queda do servidor'])->assertCreated();
        $this->assertContrato('RespostaDeInutilizacao', $criada->json());
        $this->assertContrato('ListaDeInutilizacoes', $this->comoApi()->getJson('/api/v1/notas/inutilizacoes')->assertOk()->json());
    }

    public function test_pdf_e_xml_tem_o_tipo_de_conteudo_documentado(): void
    {
        $operacoes = ContratoOpenApi::operacoes();

        $this->assertArrayHasKey('application/pdf', $operacoes['GET /api/v1/notas/{id}/pdf']['op']['responses']['200']['content']);
        $this->assertArrayHasKey('application/xml', $operacoes['GET /api/v1/notas/{id}/xml']['op']['responses']['200']['content']);
        $this->assertArrayHasKey('application/xml', $operacoes['GET /api/v1/notas/{id}/xml-cancelamento']['op']['responses']['200']['content']);
    }

    public function test_erros_de_nota_respeitam_o_contrato(): void
    {
        $nota = $this->autorizada();

        $curta = $this->comoApi()->postJson("/api/v1/notas/{$nota->id}/cancelar", ['justificativa' => 'curta'])->assertUnprocessable();
        $this->assertContrato('Problem', $curta->json());
        $this->assertNotEmpty($curta->json('errors'));
    }
}
