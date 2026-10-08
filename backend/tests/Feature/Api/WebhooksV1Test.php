<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Fiscal\Application\Webhooks\ResolvedorDeDns;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

/** `/api/v1/webhooks`, autenticado por chave de API; usa as mesmas Actions da API do app (WebhooksApiTest). */
class WebhooksV1Test extends TestCase
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
                return $host === 'interno.test' ? ['10.0.0.5'] : ['93.184.216.34'];
            }
        });
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    /** @return array<string, mixed> */
    private function corpo(array $extra = []): array
    {
        return array_replace(['url' => 'https://exemplo.test/hook', 'ambiente' => 'HOMOLOGACAO', 'eventos' => ['nota.autorizada'], 'descricao' => 'ERP'], $extra);
    }

    private function endpoint(): WebhookEndpoint
    {
        return WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id]);
    }

    private function assertProblem(TestResponse $resposta, int $status, string $codigo): void
    {
        $resposta->assertStatus($status)->assertJsonPath('codigo', $codigo);
        $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
    }

    public function test_criar_devolve_201_com_o_segredo_uma_unica_vez(): void
    {
        $resposta = $this->comoApi()->postJson('/api/v1/webhooks', $this->corpo())->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'url', 'eventos', 'ambiente', 'ativo', 'segredo_final', 'segredo']]);
        $segredo = (string) $resposta->json('data.segredo');
        $id = (string) $resposta->json('data.id');
        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{40}$/', $segredo);

        $cru = (string) DB::table('webhook_endpoints')->value('segredo');
        foreach (['/api/v1/webhooks', "/api/v1/webhooks/{$id}"] as $url) {
            $leitura = (string) $this->comoApi()->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($segredo, $leitura);
            $this->assertStringNotContainsString($cru, $leitura);
            $this->assertStringNotContainsString('"segredo"', $leitura);
        }
        // A resposta da criação não fica guardada na tabela de idempotência (levaria o segredo junto).
        $this->assertSame(0, DB::table('idempotencias')->count());
    }

    public function test_validacao_devolve_422_em_problem_json(): void
    {
        $r = $this->comoApi()->postJson('/api/v1/webhooks', $this->corpo(['url' => 'https://interno.test/hook']));
        $this->assertProblem($r, 422, 'WEBHOOK_URL_INVALIDA');
        $r->assertJsonPath('errors.0.campo', 'url');

        $r = $this->comoApi()->postJson('/api/v1/webhooks', $this->corpo(['eventos' => ['x']]));
        $this->assertProblem($r, 422, 'VALIDACAO');
        $r = $this->comoApi()->postJson('/api/v1/webhooks', $this->corpo(['ambiente' => 'TESTE']));
        $this->assertProblem($r, 422, 'VALIDACAO');
        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_atualizar_excluir_e_limite(): void
    {
        $e = $this->endpoint();
        $this->comoApi()->putJson("/api/v1/webhooks/{$e->id}", ['descricao' => 'Novo', 'eventos' => []])
            ->assertOk()->assertJsonPath('data.descricao', 'Novo')->assertJsonPath('data.eventos', []);
        $this->comoApi()->deleteJson("/api/v1/webhooks/{$e->id}")->assertNoContent();

        for ($i = 0; $i < 5; $i++) {
            $this->comoApi()->postJson('/api/v1/webhooks', $this->corpo())->assertCreated();
        }
        $this->assertProblem($this->comoApi()->postJson('/api/v1/webhooks', $this->corpo()), 422, 'WEBHOOK_LIMITE_ATINGIDO');
    }

    public function test_entregas_testar_reenviar_e_inativo(): void
    {
        $e = $this->endpoint();
        $f1 = WebhookEntrega::factory()->create(['webhook_endpoint_id' => $e->id, 'estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null, 'payload' => ['id' => 'p', 'tipo' => 'nota.autorizada', 'ambiente' => 'HOMOLOGACAO', 'data' => []]]);

        $lista = $this->comoApi()->getJson("/api/v1/webhooks/{$e->id}/entregas")->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('payload', $lista->json('data.0'));
        $this->comoApi()->getJson("/api/v1/webhooks/entregas/{$f1->id}")->assertOk()->assertJsonPath('data.payload.id', 'p');

        $this->comoApi()->postJson("/api/v1/webhooks/{$e->id}/testar")->assertStatus(202)->assertJsonPath('data.evento', 'webhook.teste');
        $this->comoApi()->postJson("/api/v1/webhooks/entregas/{$f1->id}/reenviar")->assertStatus(202)
            ->assertJsonPath('data.evento_id', $f1->evento_id);

        $e->forceFill(['ativo' => false])->save();
        $this->assertProblem($this->comoApi()->postJson("/api/v1/webhooks/{$e->id}/testar"), 409, 'WEBHOOK_INATIVO');
    }

    public function test_idempotencia_opcional_no_testar(): void
    {
        $e = $this->endpoint();

        $a = $this->comoApi()->withHeaders(['Idempotency-Key' => 'teste-1'])->postJson("/api/v1/webhooks/{$e->id}/testar")->assertStatus(202);
        $b = $this->comoApi()->withHeaders(['Idempotency-Key' => 'teste-1'])->postJson("/api/v1/webhooks/{$e->id}/testar")->assertStatus(202);

        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame('true', $b->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, WebhookEntrega::query()->count());
    }

    public function test_isolamento_em_cada_rota_em_problem_json(): void
    {
        [$alheio, $entregaAlheia] = $this->emOutroTenant(function (): array {
            $u = Usuario::factory()->create(['papel' => Papel::Admin]);
            $endpoint = WebhookEndpoint::factory()->create(['usuario_id' => $u->id]);

            return [$endpoint, WebhookEntrega::factory()->create(['webhook_endpoint_id' => $endpoint->id])];
        });

        foreach ([
            ['getJson', "/api/v1/webhooks/{$alheio->id}"], ['putJson', "/api/v1/webhooks/{$alheio->id}", ['ativo' => false]],
            ['deleteJson', "/api/v1/webhooks/{$alheio->id}"], ['getJson', "/api/v1/webhooks/{$alheio->id}/entregas"],
            ['postJson', "/api/v1/webhooks/{$alheio->id}/testar"], ['getJson', "/api/v1/webhooks/entregas/{$entregaAlheia->id}"],
            ['postJson', "/api/v1/webhooks/entregas/{$entregaAlheia->id}/reenviar"],
        ] as $caso) {
            [$metodo, $url] = $caso;
            $dados = $caso[2] ?? [];
            $this->assertProblem($this->comoApi()->{$metodo}($url, $dados), 404, 'RECURSO_NAO_ENCONTRADO');
        }
        $this->comoApi()->getJson('/api/v1/webhooks')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, DB::table('webhook_endpoints')->count());
    }

    public function test_a_v1_recusa_sessao_do_app(): void
    {
        $this->spaComo($this->admin)->getJson('/api/v1/webhooks')->assertUnauthorized();
    }
}
