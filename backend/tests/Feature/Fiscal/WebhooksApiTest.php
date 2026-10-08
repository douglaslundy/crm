<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Webhooks\ResolvedorDeDns;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

/** A API do app (`/api/app/webhooks`, sessão do usuário). A v1 tem o mesmo comportamento em WebhooksV1Test. */
class WebhooksApiTest extends TestCase
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

    public function test_criar_devolve_201_com_o_segredo_uma_unica_vez(): void
    {
        $resposta = $this->spaComo($this->admin)->postJson('/api/app/webhooks', $this->corpo())
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://exemplo.test/hook')
            ->assertJsonPath('data.ambiente', 'HOMOLOGACAO')
            ->assertJsonPath('data.eventos', ['nota.autorizada'])
            ->assertJsonPath('data.ativo', true)
            ->assertJsonStructure(['data' => ['id', 'url', 'descricao', 'eventos', 'ambiente', 'ativo', 'desativado_em', 'motivo_desativacao', 'segredo_final', 'segredo', 'criado_em']]);
        $segredo = (string) $resposta->json('data.segredo');
        $id = (string) $resposta->json('data.id');
        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{40}$/', $segredo);
        $this->assertSame(substr($segredo, -4), $resposta->json('data.segredo_final'));

        $cru = (string) DB::table('webhook_endpoints')->value('segredo');
        foreach (['/api/app/webhooks', "/api/app/webhooks/{$id}"] as $url) {
            $leitura = (string) $this->spaComo($this->admin)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($segredo, $leitura);
            $this->assertStringNotContainsString($cru, $leitura);
            $this->assertStringNotContainsString('"segredo"', $leitura);
        }
    }

    public function test_validacao_devolve_422(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/webhooks', $this->corpo(['url' => 'https://interno.test/hook']))
            ->assertUnprocessable()->assertJsonPath('codigo', 'WEBHOOK_URL_INVALIDA')->assertJsonPath('errors.0.campo', 'url');
        $this->spaComo($this->admin)->postJson('/api/app/webhooks', $this->corpo(['eventos' => ['nota.nada']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['eventos.0']);
        $this->spaComo($this->admin)->postJson('/api/app/webhooks', $this->corpo(['ambiente' => 'TESTE']))
            ->assertUnprocessable()->assertJsonValidationErrors(['ambiente']);
        $this->spaComo($this->admin)->postJson('/api/app/webhooks', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['url', 'ambiente']);
        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_atualizar_e_excluir(): void
    {
        $e = $this->endpoint();

        $this->spaComo($this->admin)->putJson("/api/app/webhooks/{$e->id}", ['descricao' => 'Novo', 'ativo' => false])
            ->assertOk()->assertJsonPath('data.descricao', 'Novo')->assertJsonPath('data.ativo', false)
            ->assertJsonPath('data.motivo_desativacao', 'Desativado manualmente.');
        $this->spaComo($this->admin)->putJson("/api/app/webhooks/{$e->id}", ['ativo' => 'talvez'])
            ->assertUnprocessable()->assertJsonValidationErrors(['ativo']);

        $this->spaComo($this->admin)->deleteJson("/api/app/webhooks/{$e->id}")->assertNoContent();
        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_limite_de_cinco(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->spaComo($this->admin)->postJson('/api/app/webhooks', $this->corpo())->assertCreated();
        }

        $this->spaComo($this->admin)->postJson('/api/app/webhooks', $this->corpo())
            ->assertUnprocessable()->assertJsonPath('codigo', 'WEBHOOK_LIMITE_ATINGIDO');
    }

    public function test_papeis_sem_permissao_recebem_403_em_cada_verbo(): void
    {
        $e = $this->endpoint();
        $entrega = WebhookEntrega::factory()->create(['webhook_endpoint_id' => $e->id]);
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        foreach ([
            ['getJson', '/api/app/webhooks'], ['postJson', '/api/app/webhooks', $this->corpo()],
            ['getJson', "/api/app/webhooks/{$e->id}"], ['putJson', "/api/app/webhooks/{$e->id}", ['ativo' => false]],
            ['deleteJson', "/api/app/webhooks/{$e->id}"], ['getJson', "/api/app/webhooks/{$e->id}/entregas"],
            ['postJson', "/api/app/webhooks/{$e->id}/testar"], ['getJson', "/api/app/webhooks/entregas/{$entrega->id}"],
            ['postJson', "/api/app/webhooks/entregas/{$entrega->id}/reenviar"],
        ] as $caso) {
            [$metodo, $url] = $caso;
            $dados = $caso[2] ?? [];
            $this->spaComo($vendedor)->{$metodo}($url, $dados)->assertForbidden();
        }
        $this->assertSame(1, WebhookEndpoint::query()->count());
    }

    public function test_plano_sem_modulo_api_recusa_a_criacao(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::FiscalNfe))->create();
        app(TenantContext::class)->set($tenant->id);
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spaComo($admin)->postJson('/api/app/webhooks', $this->corpo())->assertForbidden();
    }

    public function test_entregas_testar_reenviar_e_inativo(): void
    {
        $e = $this->endpoint();
        $f1 = WebhookEntrega::factory()->create(['webhook_endpoint_id' => $e->id, 'estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null, 'payload' => ['id' => 'p', 'tipo' => 'nota.autorizada', 'ambiente' => 'HOMOLOGACAO', 'data' => []]]);
        WebhookEntrega::factory()->create(['webhook_endpoint_id' => $e->id, 'estado' => EstadoDaEntrega::Entregue, 'proxima_tentativa_em' => null]);

        $lista = $this->spaComo($this->admin)->getJson("/api/app/webhooks/{$e->id}/entregas?limite=1")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonStructure(['data', 'links' => ['proxima'], 'meta' => ['limite']]);
        $this->assertArrayNotHasKey('payload', $lista->json('data.0'));
        $this->assertNotNull($lista->json('links.proxima'));
        $this->spaComo($this->admin)->getJson("/api/app/webhooks/{$e->id}/entregas?estado=FALHOU")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f1->id);
        $this->spaComo($this->admin)->getJson("/api/app/webhooks/{$e->id}/entregas?estado=XX")->assertUnprocessable();

        $this->spaComo($this->admin)->getJson("/api/app/webhooks/entregas/{$f1->id}")->assertOk()
            ->assertJsonPath('data.payload.id', 'p')->assertJsonStructure(['data' => ['tentativas_log']]);

        $this->spaComo($this->admin)->postJson("/api/app/webhooks/{$e->id}/testar")->assertStatus(202)
            ->assertJsonPath('data.evento', 'webhook.teste')->assertJsonPath('data.estado', 'ENTREGUE');

        $this->spaComo($this->admin)->postJson("/api/app/webhooks/entregas/{$f1->id}/reenviar")->assertStatus(202)
            ->assertJsonPath('data.evento_id', $f1->evento_id)->assertJsonPath('data.estado', 'ENTREGUE');

        $e->forceFill(['ativo' => false])->save();
        $this->spaComo($this->admin)->postJson("/api/app/webhooks/{$e->id}/testar")->assertStatus(409)->assertJsonPath('codigo', 'WEBHOOK_INATIVO');
        $this->spaComo($this->admin)->postJson("/api/app/webhooks/entregas/{$f1->id}/reenviar")->assertStatus(409)->assertJsonPath('codigo', 'WEBHOOK_INATIVO');
    }

    public function test_isolamento_em_cada_rota(): void
    {
        [$alheio, $entregaAlheia] = $this->emOutroTenant(function (): array {
            $u = Usuario::factory()->create(['papel' => Papel::Admin]);
            $endpoint = WebhookEndpoint::factory()->create(['usuario_id' => $u->id]);

            return [$endpoint, WebhookEntrega::factory()->create(['webhook_endpoint_id' => $endpoint->id])];
        });

        foreach ([
            ['getJson', "/api/app/webhooks/{$alheio->id}"], ['putJson', "/api/app/webhooks/{$alheio->id}", ['ativo' => false]],
            ['deleteJson', "/api/app/webhooks/{$alheio->id}"], ['getJson', "/api/app/webhooks/{$alheio->id}/entregas"],
            ['postJson', "/api/app/webhooks/{$alheio->id}/testar"], ['getJson', "/api/app/webhooks/entregas/{$entregaAlheia->id}"],
            ['postJson', "/api/app/webhooks/entregas/{$entregaAlheia->id}/reenviar"],
        ] as $caso) {
            [$metodo, $url] = $caso;
            $dados = $caso[2] ?? [];
            $this->spaComo($this->admin)->{$metodo}($url, $dados)->assertNotFound();
        }
        $this->spaComo($this->admin)->getJson('/api/app/webhooks')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, DB::table('webhook_endpoints')->count());
        $this->assertSame(1, DB::table('webhook_entregas')->count());
    }

    public function test_o_app_recusa_chave_de_api(): void
    {
        $this->comoApi()->getJson('/api/app/webhooks')->assertUnauthorized();
    }
}
