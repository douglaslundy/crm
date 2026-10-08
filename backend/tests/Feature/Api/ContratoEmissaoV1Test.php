<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\CenarioDeNfse;
use Tests\Fixtures\ContratoOpenApi;
use Tests\TestCase;

/** Resposta 202 da emissão e erros de idempotência contra `docs/api/openapi.yaml`. */
class ContratoEmissaoV1Test extends TestCase
{
    use CenarioDeApi;
    use CenarioDeNfse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse(Modulo::Api, Modulo::FiscalNfe, Modulo::FiscalNfce);
        $plano = $this->tenant->plano;
        foreach ([Recurso::ApiRequisicoesMin->value => -1, Recurso::Produtos->value => 100, Recurso::Clientes->value => 100, Recurso::Servicos->value => 100] as $recurso => $limite) {
            $plano?->limites()->create(['recurso' => $recurso, 'limite' => $limite]);
        }
        $plano?->unsetRelation('limites');
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfce, 'serie' => '1', 'proximo_numero' => 1]);
        [$this->chave, $this->textoDaChave] = $this->novaChave($this->admin, AmbienteDaChave::Test);
        Bus::fake();
    }

    private function assertContrato(string $schema, mixed $dados): void
    {
        $this->assertSame([], ContratoOpenApi::validar($schema, $dados), "Resposta fora do contrato {$schema}");
    }

    public function test_emissao_respeita_o_contrato(): void
    {
        $corpo = [
            'modelo' => 'NFE', 'cliente' => ['id' => $this->cliente->id], 'desconto_centavos' => 0,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1']],
            'pagamentos' => [['tpag' => '01', 'valor_centavos' => 1000]],
        ];

        $this->assertContrato('Problem', $this->comoApi()->postJson('/api/v1/notas', $corpo)->assertStatus(400)->json());

        $resposta = $this->comoApi()->withHeaders(['Idempotency-Key' => 'contrato-1'])->postJson('/api/v1/notas', $corpo)->assertStatus(202);
        $this->assertContrato('RespostaDeEmissao', $resposta->json());

        $this->assertContrato('Problem', $this->comoApi()->withHeaders(['Idempotency-Key' => 'contrato-1'])->postJson('/api/v1/notas', ['cliente' => null] + $corpo)->assertStatus(409)->json());
    }

    public function test_cadastro_divergente_respeita_o_contrato(): void
    {
        $corpo = [
            'modelo' => 'NFE', 'cliente' => ['id' => $this->cliente->id], 'desconto_centavos' => 0,
            'itens' => [['produto' => ['sku' => $this->produto->sku, 'preco_centavos' => 99999], 'quantidade' => '1']],
            'pagamentos' => [['tpag' => '01', 'valor_centavos' => 1000]],
        ];

        $resposta = $this->comoApi()->withHeaders(['Idempotency-Key' => 'contrato-div'])->postJson('/api/v1/notas', $corpo)->assertStatus(422);
        $this->assertSame('CADASTRO_DIVERGENTE', $resposta->json('codigo'));
        $this->assertContrato('Problem', $resposta->json());
    }
}
