<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Webhooks\Actions\EnviarTesteDeWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\ListarEntregasDeWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\ReenviarEntrega;
use App\Modules\Fiscal\Application\Webhooks\AssinadorDeWebhook;
use App\Modules\Fiscal\Application\Webhooks\ResolvedorDeDns;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Exceptions\WebhookInativoException;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookTentativa;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Shared\Http\PaginadorPorCursor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class EntregasDeWebhookTest extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    private WebhookEndpoint $endpoint;

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
        $this->endpoint = WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id]);
        $this->freezeSecond();
    }

    private function fakeHttp(mixed $resposta): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(['*' => $resposta]);
    }

    private function entrega(array $extra = []): WebhookEntrega
    {
        return WebhookEntrega::factory()->create($extra + [
            'webhook_endpoint_id' => $this->endpoint->id,
            'evento' => 'nota.autorizada',
            'payload' => ['id' => 'x', 'tipo' => 'nota.autorizada', 'ambiente' => 'HOMOLOGACAO', 'data' => ['nota' => ['id' => 'n1']]],
        ]);
    }

    private function listar(string $query = '', ?Usuario $autor = null, ?string $webhookId = null): array
    {
        $request = Request::create('/api/v1/webhooks/x/entregas'.$query, 'GET');

        return app(ListarEntregasDeWebhook::class)->executar(
            $autor ?? $this->admin, $webhookId ?? $this->endpoint->id, $request, app(PaginadorPorCursor::class),
            fn (WebhookEntrega $e): array => ['id' => $e->id, 'estado' => $e->estado->value, 'tentativas_log' => $e->tentativasLog->map(fn (WebhookTentativa $t): array => ['status_http' => $t->status_http])->all(), 'tem_payload' => array_key_exists('payload', $e->getAttributes())],
        );
    }

    public function test_lista_pagina_por_cursor_filtra_por_estado_e_traz_o_log(): void
    {
        $this->entrega(['estado' => EstadoDaEntrega::Entregue, 'proxima_tentativa_em' => null]);
        $falhou = $this->entrega(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null]);
        $this->entrega(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null]);
        (new WebhookTentativa)->forceFill(['tenant_id' => $this->tenant->id, 'webhook_entrega_id' => $falhou->id, 'numero' => 1, 'status_http' => 500, 'duracao_ms' => 5, 'created_at' => now()])->save();

        $primeira = $this->listar('?limite=2');
        $this->assertCount(2, $primeira['data']);
        $this->assertNotNull($primeira['links']['proxima']);
        parse_str((string) parse_url($primeira['links']['proxima'], PHP_URL_QUERY), $query);
        $segunda = $this->listar('?limite=2&cursor='.urlencode($query['cursor']));
        $this->assertCount(1, $segunda['data']);

        $falhas = $this->listar('?estado=FALHOU');
        $this->assertCount(2, $falhas['data']);
        $log = collect($falhas['data'])->firstWhere('id', $falhou->id);
        $this->assertSame([['status_http' => 500]], $log['tentativas_log']);
    }

    public function test_estado_invalido_e_422(): void
    {
        $this->expectException(ValidationException::class);
        $this->listar('?estado=QUALQUER');
    }

    public function test_reenviar_falhou_mantem_o_evento_id_e_envia_com_o_mesmo_id(): void
    {
        $this->fakeHttp(Http::response('ok', 200));
        $e = $this->entrega(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null, 'tentativas' => 7, 'tentativas_na_rodada' => 7]);
        $eventoId = $e->evento_id;

        $r = app(ReenviarEntrega::class)->executar($this->admin, $e->id);

        $this->assertSame($eventoId, $r->evento_id);
        $this->assertSame(EstadoDaEntrega::Entregue, $r->fresh()?->estado);
        $this->assertSame(8, $r->fresh()?->tentativas);
        Http::assertSent(fn ($req): bool => $req->header('X-Webhook-Id')[0] === $eventoId);
        Http::assertSentCount(1);
    }

    public function test_reenviar_abre_nova_rodada_de_tentativas(): void
    {
        $this->fakeHttp(Http::response('x', 500));
        $e = $this->entrega(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null, 'tentativas' => 7, 'tentativas_na_rodada' => 7]);

        app(ReenviarEntrega::class)->executar($this->admin, $e->id);

        $e = $e->fresh();
        $this->assertSame(EstadoDaEntrega::Pendente, $e?->estado);
        $this->assertSame(1, $e?->tentativas_na_rodada);
        $this->assertTrue($e?->proxima_tentativa_em?->equalTo(now()->addSeconds(60)));
    }

    public function test_reenviar_entregue_e_permitido(): void
    {
        $this->fakeHttp(Http::response('ok', 200));
        $e = $this->entrega(['estado' => EstadoDaEntrega::Entregue, 'proxima_tentativa_em' => null, 'entregue_em' => now()->subDay(), 'tentativas' => 1, 'tentativas_na_rodada' => 1]);

        app(ReenviarEntrega::class)->executar($this->admin, $e->id);

        $this->assertSame(2, $e->fresh()?->tentativas);
        Http::assertSentCount(1);
    }

    public function test_reenviar_pendente_nao_duplica_o_envio(): void
    {
        $this->fakeHttp(Http::response('ok', 200));
        $e = $this->entrega(['proxima_tentativa_em' => now()->addMinutes(5)]);

        app(ReenviarEntrega::class)->executar($this->admin, $e->id);

        Http::assertNothingSent();
        $this->assertTrue($e->fresh()?->proxima_tentativa_em?->equalTo(now()->addMinutes(5)));
    }

    public function test_endpoint_inativo_recusa_reenvio_e_teste(): void
    {
        $this->endpoint->forceFill(['ativo' => false])->save();
        $e = $this->entrega(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null]);

        foreach ([
            fn () => app(ReenviarEntrega::class)->executar($this->admin, $e->id),
            fn () => app(EnviarTesteDeWebhook::class)->executar($this->admin, $this->endpoint->id),
        ] as $acao) {
            try {
                $acao();
                $this->fail('Deveria recusar');
            } catch (WebhookInativoException $ex) {
                $this->assertSame('WEBHOOK_INATIVO', $ex->codigo());
                $this->assertSame(409, $ex->status());
            }
        }
        $this->assertSame(EstadoDaEntrega::Falhou, $e->fresh()?->estado);
    }

    public function test_teste_envia_webhook_teste_com_assinatura_valida(): void
    {
        $this->fakeHttp(Http::response('ok', 200));

        $entrega = app(EnviarTesteDeWebhook::class)->executar($this->admin, $this->endpoint->id);

        $this->assertSame('webhook.teste', $entrega->evento);
        $this->assertNull($entrega->nota_id);
        $this->assertNull($entrega->nota_evento_id);
        $this->assertSame(EstadoDaEntrega::Entregue, $entrega->estado);
        Http::assertSent(function ($req) use ($entrega): bool {
            $corpo = json_decode($req->body(), true);

            return $req->header('X-Webhook-Event')[0] === 'webhook.teste'
                && $corpo['tipo'] === 'webhook.teste' && $corpo['id'] === $entrega->evento_id
                && isset($corpo['data']['mensagem']) && $corpo['ambiente'] === 'HOMOLOGACAO'
                && (new AssinadorDeWebhook)->verificar($this->endpoint->segredo, $req->header('X-Signature')[0], $req->body());
        });
    }

    public function test_teste_que_falha_aparece_como_falhou_sem_desativar(): void
    {
        $this->fakeHttp(Http::response('x', 500));

        $entrega = app(EnviarTesteDeWebhook::class)->executar($this->admin, $this->endpoint->id);

        $this->assertSame(EstadoDaEntrega::Falhou, $entrega->estado);
        $this->assertTrue($this->endpoint->fresh()?->ativo);
    }

    public function test_so_proprietario_e_admin(): void
    {
        $e = $this->entrega(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null]);
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        foreach ([
            fn () => app(ReenviarEntrega::class)->executar($vendedor, $e->id),
            fn () => app(EnviarTesteDeWebhook::class)->executar($vendedor, $this->endpoint->id),
            fn () => $this->listar('', $vendedor),
        ] as $acao) {
            try {
                $acao();
                $this->fail('VENDEDOR não deveria');
            } catch (AcessoNegadoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_isolamento_entrega_e_endpoint_de_outro_tenant(): void
    {
        [$endpointAlheio, $entregaAlheia] = $this->emOutroTenant(function (): array {
            $u = Usuario::factory()->create(['papel' => Papel::Admin]);
            $endpoint = WebhookEndpoint::factory()->create(['usuario_id' => $u->id]);

            return [$endpoint, WebhookEntrega::factory()->create(['webhook_endpoint_id' => $endpoint->id])];
        });

        foreach ([
            fn () => app(ReenviarEntrega::class)->executar($this->admin, $entregaAlheia->id),
            fn () => app(EnviarTesteDeWebhook::class)->executar($this->admin, $endpointAlheio->id),
            fn () => $this->listar('', null, $endpointAlheio->id),
        ] as $acao) {
            try {
                $acao();
                $this->fail('Deveria ser 404');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, DB::table('webhook_entregas')->count());
    }

    public function test_auditoria_de_reenvio_e_teste_nao_grava_o_segredo(): void
    {
        $this->fakeHttp(Http::response('ok', 200));
        $e = $this->entrega(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null]);

        app(ReenviarEntrega::class)->executar($this->admin, $e->id);
        app(EnviarTesteDeWebhook::class)->executar($this->admin, $this->endpoint->id);

        $registros = Activity::query()->where('log_name', 'webhooks')->get();
        $this->assertSame(['webhook_reenviado', 'webhook_testado'], $registros->pluck('event')->all());
        $this->assertStringNotContainsString('whsec_', $registros->toJson());
    }
}
