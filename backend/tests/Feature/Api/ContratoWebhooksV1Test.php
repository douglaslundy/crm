<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Fiscal\Application\Webhooks\MontadorDoPayload;
use App\Modules\Fiscal\Application\Webhooks\ResolvedorDeDns;
use App\Modules\Fiscal\Domain\Enums\EventoDeWebhook;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\ContratoOpenApi;
use Tests\TestCase;

/** Webhooks (gestão e eventos enviados) contra os schemas de `docs/api/openapi.yaml`. */
class ContratoWebhooksV1Test extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi([], Modulo::FiscalNfe);
        $this->app->instance(ResolvedorDeDns::class, new class implements ResolvedorDeDns
        {
            public function resolver(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    private function assertContrato(string $schema, mixed $dados): void
    {
        $this->assertSame([], ContratoOpenApi::validar($schema, $dados), "Resposta fora do contrato {$schema}");
    }

    public function test_gestao_de_webhooks_respeita_o_contrato(): void
    {
        $criado = $this->comoApi()->postJson('/api/v1/webhooks', ['url' => 'https://exemplo.test/hook', 'ambiente' => 'HOMOLOGACAO', 'eventos' => ['nota.autorizada'], 'descricao' => 'ERP'])->assertCreated();
        $this->assertContrato('RespostaDeWebhookComSegredo', $criado->json());
        $this->assertStringStartsWith('whsec_', (string) $criado->json('data.segredo'));
        $id = (string) $criado->json('data.id');

        $mostrado = $this->comoApi()->getJson("/api/v1/webhooks/{$id}")->assertOk();
        $this->assertContrato('RespostaDeWebhook', $mostrado->json());
        $this->assertArrayNotHasKey('segredo', $mostrado->json('data'));

        $this->assertContrato('ListaDeWebhooks', $this->comoApi()->getJson('/api/v1/webhooks')->assertOk()->json());
        $this->assertContrato('RespostaDeWebhook', $this->comoApi()->putJson("/api/v1/webhooks/{$id}", ['ativo' => false])->assertOk()->json());
        $this->comoApi()->deleteJson("/api/v1/webhooks/{$id}")->assertNoContent();
    }

    public function test_entregas_respeitam_o_contrato(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id]);

        $teste = $this->comoApi()->withHeaders(['Idempotency-Key' => 'teste-1'])->postJson("/api/v1/webhooks/{$endpoint->id}/testar")->assertStatus(202);
        $this->assertContrato('RespostaDeEntrega', $teste->json());
        $entregaId = (string) $teste->json('data.id');

        $this->assertContrato('RespostaDeEntrega', $this->comoApi()->getJson("/api/v1/webhooks/entregas/{$entregaId}")->assertOk()->json());
        $this->assertContrato('ListaDeEntregas', $this->comoApi()->getJson("/api/v1/webhooks/{$endpoint->id}/entregas")->assertOk()->json());
        $this->assertContrato('EventoDeTeste', $teste->json('data.payload'));
    }

    public function test_corpo_dos_eventos_respeita_o_contrato(): void
    {
        $montador = app(MontadorDoPayload::class);
        $nota = Nota::factory()->create(['status' => StatusNota::Autorizada]);

        foreach (array_filter(EventoDeWebhook::cases(), fn (EventoDeWebhook $e): bool => $e !== EventoDeWebhook::Teste) as $evento) {
            $this->assertContrato('EventoDeNota', $montador->paraNota($nota, $evento, 'evt_contrato'));
        }
        $this->assertContrato('EventoDeTeste', $montador->paraTeste('evt_contrato', 'HOMOLOGACAO'));
    }

    public function test_erros_de_webhook_respeitam_o_contrato(): void
    {
        $invalida = $this->comoApi()->postJson('/api/v1/webhooks', ['url' => 'https://10.0.0.5/hook', 'ambiente' => 'PRODUCAO'])->assertUnprocessable();
        $this->assertContrato('Problem', $invalida->json());
        $this->assertSame('WEBHOOK_URL_INVALIDA', $invalida->json('codigo'));

        $inativo = WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id, 'ativo' => false]);
        $conflito = $this->comoApi()->withHeaders(['Idempotency-Key' => 'teste-2'])->postJson("/api/v1/webhooks/{$inativo->id}/testar")->assertStatus(409);
        $this->assertContrato('Problem', $conflito->json());
    }

    public function test_webhook_sem_eventos_devolve_lista_vazia_e_respeita_o_contrato(): void
    {
        $criado = $this->comoApi()->postJson('/api/v1/webhooks', ['url' => 'https://exemplo.test/hook', 'ambiente' => 'HOMOLOGACAO'])->assertCreated();

        $this->assertSame([], $criado->json('data.eventos'));
        $this->assertContrato('RespostaDeWebhookComSegredo', $criado->json());
    }
}
