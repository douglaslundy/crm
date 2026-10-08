<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Jobs\EnviarWebhookJob;
use App\Modules\Fiscal\Application\Webhooks\AssinadorDeWebhook;
use App\Modules\Fiscal\Application\Webhooks\EntregadorDeWebhook;
use App\Modules\Fiscal\Application\Webhooks\ResolvedorDeDns;
use App\Modules\Fiscal\Application\Webhooks\ValidacaoDeUrl;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookTentativa;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class EnvioDeWebhookTest extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    private WebhookEndpoint $endpoint;

    private object $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi([], Modulo::FiscalNfe);
        $this->dns = new class implements ResolvedorDeDns
        {
            /** @var array<string, list<string>> */
            public array $mapa = ['exemplo.test' => ['93.184.216.34']];

            public function resolver(string $host): array
            {
                return $this->mapa[$host] ?? [];
            }
        };
        $this->app->instance(ResolvedorDeDns::class, $this->dns);
        $this->endpoint = WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id]);
        $this->freezeSecond();
    }

    private function entrega(array $extra = [], ?WebhookEndpoint $endpoint = null): WebhookEntrega
    {
        $id = (string) Str::uuid();

        return WebhookEntrega::factory()->create($extra + [
            'webhook_endpoint_id' => ($endpoint ?? $this->endpoint)->id,
            'evento' => 'nota.autorizada',
            'evento_id' => $id,
            'payload' => ['id' => $id, 'tipo' => 'nota.autorizada', 'criado_em' => '2026-10-08T10:00:00-03:00', 'ambiente' => 'HOMOLOGACAO', 'data' => ['nota' => ['id' => 'n1', 'descricao' => 'Água & café — ação']]],
        ]);
    }

    private function enviar(WebhookEntrega $e, bool $reservada = false): WebhookEntrega
    {
        app(EntregadorDeWebhook::class)->enviar($e->id, $reservada);

        return $e->fresh() ?? $this->fail('entrega sumiu');
    }

    /** Troca as respostas simuladas (o `Http::fake` acumula e a primeira que casa vence). */
    private function fakeHttp(mixed $resposta): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(['*' => $resposta]);
    }

    /** @return list<Request> */
    private function enviadas(): array
    {
        return array_map(fn (array $par): Request => $par[0], Http::recorded()->all());
    }

    public function test_2xx_entrega_com_cabecalhos_e_corpo_exato(): void
    {
        Http::fake(['*' => Http::response('ok', 204)]);
        $e = $this->entrega();

        $e = $this->enviar($e);

        $this->assertSame(EstadoDaEntrega::Entregue, $e->estado);
        $this->assertNotNull($e->entregue_em);
        $this->assertNull($e->proxima_tentativa_em);
        $this->assertSame(1, $e->tentativas);
        $this->assertCount(1, $this->enviadas());
        $req = $this->enviadas()[0];
        $this->assertSame('POST', $req->method());
        $this->assertSame('https://exemplo.test/hook', $req->url());
        $this->assertSame($e->evento_id, $req->header('X-Webhook-Id')[0]);
        $this->assertSame('nota.autorizada', $req->header('X-Webhook-Event')[0]);
        $this->assertSame('PlataformaFiscal-Webhook/1', $req->header('User-Agent')[0]);
        $this->assertStringContainsString('application/json', $req->header('Content-Type')[0]);
        $this->assertSame(json_encode($e->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $req->body());
        $this->assertStringContainsString('Água & café — ação', $req->body());
        $this->assertTrue((new AssinadorDeWebhook)->verificar($this->endpoint->segredo, $req->header('X-Signature')[0], $req->body()));

        $tentativa = $e->tentativasLog()->get();
        $this->assertCount(1, $tentativa);
        $this->assertSame(204, $tentativa[0]->status_http);
        $this->assertSame(1, $tentativa[0]->numero);
        $this->assertNull($tentativa[0]->erro);
    }

    public function test_falhas_agendam_nos_intervalos_e_a_setima_encerra(): void
    {
        $respostas = [Http::response('x', 500), Http::response('x', 404), fn () => throw new ConnectionException('cURL error 28: Operation timed out'), Http::response('x', 502), Http::response('x', 429), Http::response('x', 500), Http::response('x', 503)];
        $intervalos = [60, 300, 1800, 7200, 21600, 43200];
        $e = $this->entrega();

        foreach ($respostas as $i => $resposta) {
            $this->fakeHttp($resposta);
            $inicio = now();
            $e = $this->enviar($e);

            $this->assertSame($i + 1, $e->tentativas);
            if ($i < 6) {
                $this->assertSame(EstadoDaEntrega::Pendente, $e->estado);
                $this->assertTrue($e->proxima_tentativa_em?->equalTo($inicio->copy()->addSeconds($intervalos[$i])), "degrau {$i}");
                $this->travelTo($e->proxima_tentativa_em);
            }
        }

        $this->assertSame(EstadoDaEntrega::Falhou, $e->estado);
        $this->assertNull($e->proxima_tentativa_em);
        $this->assertSame(7, $e->tentativas);
        $this->assertSame(7, $e->tentativasLog()->count());
        $this->assertSame(1, $this->endpoint->fresh()?->falhas_seguidas);
        $this->assertNotNull($e->tentativasLog()->get()[2]->erro);
        $this->assertNull($e->tentativasLog()->get()[2]->status_http);
    }

    public function test_id_e_corpo_estaveis_e_assinatura_muda_a_cada_tentativa(): void
    {
        Http::fake(['*' => Http::response('x', 500)]);
        $e = $this->entrega();

        $e = $this->enviar($e);
        $this->travelTo($e->proxima_tentativa_em);
        $this->enviar($e);

        [$a, $b] = $this->enviadas();
        $this->assertSame($a->header('X-Webhook-Id'), $b->header('X-Webhook-Id'));
        $this->assertSame($a->body(), $b->body());
        $this->assertNotSame($a->header('X-Signature'), $b->header('X-Signature'));
        $this->assertTrue((new AssinadorDeWebhook)->verificar($this->endpoint->segredo, $b->header('X-Signature')[0], $b->body()));
    }

    public function test_dez_entregas_falhando_desativam_o_endpoint_e_uma_entregue_zera(): void
    {
        $this->endpoint->forceFill(['falhas_seguidas' => 8])->save();
        Http::fake(['*' => Http::response('x', 500)]);
        $e = $this->entrega(['tentativas_na_rodada' => 6, 'tentativas' => 6]);
        $e = $this->enviar($e);
        $this->assertSame(EstadoDaEntrega::Falhou, $e->estado);
        $this->assertTrue($this->endpoint->fresh()?->ativo);
        $this->assertSame(9, $this->endpoint->fresh()?->falhas_seguidas);

        $this->enviar($this->entrega(['tentativas_na_rodada' => 6, 'tentativas' => 6]));

        $endpoint = $this->endpoint->fresh();
        $this->assertFalse($endpoint?->ativo);
        $this->assertSame(10, $endpoint?->falhas_seguidas);
        $this->assertNotNull($endpoint?->desativado_em);
        $this->assertSame('10 entregas seguidas sem sucesso', $endpoint?->motivo_desativacao);

        $outro = WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id, 'falhas_seguidas' => 5]);
        $this->fakeHttp(Http::response('ok', 200));
        $this->enviar($this->entrega([], $outro));
        $this->assertSame(0, $outro->fresh()?->falhas_seguidas);
    }

    public function test_resposta_e_truncada_em_1024_bytes_validos(): void
    {
        Http::fake(['*' => Http::response(str_repeat('é', 2000), 500)]);

        $e = $this->enviar($this->entrega());

        $resposta = (string) $e->tentativasLog()->first()?->resposta;
        $this->assertLessThanOrEqual(1024, strlen($resposta));
        $this->assertGreaterThan(1000, strlen($resposta));
        $this->assertTrue(mb_check_encoding($resposta, 'UTF-8'));
    }

    public function test_redirecionamento_nao_e_seguido_e_conta_como_falha(): void
    {
        Http::fake([
            'https://exemplo.test/*' => Http::response('', 301, ['Location' => 'https://exemplo.test/outro']),
            'https://exemplo.test/outro' => Http::response('ok', 200),
        ]);

        $e = $this->enviar($this->entrega());

        $this->assertSame(EstadoDaEntrega::Pendente, $e->estado);
        $this->assertSame(301, $e->tentativasLog()->first()?->status_http);
        $this->assertCount(1, $this->enviadas());
    }

    public function test_opcoes_de_conexao_nao_seguem_redirecionamento_e_fixam_o_ip(): void
    {
        $entregador = app(EntregadorDeWebhook::class);

        $comNome = $entregador->opcoesDeConexao(new ValidacaoDeUrl('https://exemplo.test/h', 'exemplo.test', 443, ['93.184.216.34', '2606:2800::1']));
        $this->assertFalse($comNome['allow_redirects']);
        $this->assertSame(['exemplo.test:443:93.184.216.34,[2606:2800::1]'], $comNome['curl'][CURLOPT_RESOLVE]);

        $literal = $entregador->opcoesDeConexao(new ValidacaoDeUrl('https://8.8.8.8/h', '8.8.8.8', 443, ['8.8.8.8']));
        $this->assertFalse($literal['allow_redirects']);
        $this->assertArrayNotHasKey('curl', $literal);
    }

    public function test_rebinding_para_ip_interno_e_bloqueado_sem_chamar_o_destino(): void
    {
        Http::fake();
        $this->dns->mapa['exemplo.test'] = ['10.0.0.9'];

        $e = $this->enviar($this->entrega());

        Http::assertNothingSent();
        $this->assertSame(EstadoDaEntrega::Pendente, $e->estado);
        $this->assertSame(1, $e->tentativas);
        $this->assertStringContainsString('interno', (string) $e->tentativasLog()->first()?->erro);
        $this->assertNull($e->tentativasLog()->first()?->status_http);
    }

    public function test_endpoint_inativo_ambiente_diferente_e_plano_sem_modulo_descartam_sem_retentativa(): void
    {
        Http::fake();
        $inativo = WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id, 'ativo' => false]);
        $producao = WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id, 'ambiente' => 'PRODUCAO']);

        foreach ([$this->entrega([], $inativo), $this->entrega([], $producao)] as $e) {
            $e = $this->enviar($e);
            $this->assertSame(EstadoDaEntrega::Falhou, $e->estado);
            $this->assertNull($e->proxima_tentativa_em);
            $this->assertNotNull($e->tentativasLog()->first()?->erro);
        }

        $this->tenant->plano_id = Plano::factory()->comModulos(Modulo::FiscalNfe)->create()->id;
        $this->tenant->save();
        $semModulo = $this->enviar($this->entrega());
        $this->assertSame(EstadoDaEntrega::Falhou, $semModulo->estado);

        Http::assertNothingSent();
        $this->assertSame(0, $this->endpoint->fresh()?->falhas_seguidas);
        $this->assertSame(0, $inativo->fresh()?->falhas_seguidas);
    }

    public function test_so_envia_pendente_e_vencida(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $entregue = $this->entrega(['estado' => EstadoDaEntrega::Entregue, 'proxima_tentativa_em' => null]);
        $futura = $this->entrega(['proxima_tentativa_em' => now()->addMinute()]);

        $this->enviar($entregue);
        $this->enviar($futura);

        Http::assertNothingSent();
        $this->assertSame(0, $futura->fresh()?->tentativas);
    }

    public function test_reserva_impede_envio_duplicado(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $e = $this->entrega();
        // Duas chamadas seguidas antes do resultado: a segunda encontra a reserva e não envia.
        DB::table('webhook_entregas')->where('id', $e->id)->update(['proxima_tentativa_em' => now()->addMinutes(5)]);

        $this->enviar($e);

        Http::assertNothingSent();
    }

    public function test_evento_de_teste_nao_retenta_nem_conta_falha(): void
    {
        Http::fake(['*' => Http::response('x', 500)]);
        $e = $this->entrega(['evento' => 'webhook.teste']);

        $e = $this->enviar($e);

        $this->assertSame(EstadoDaEntrega::Falhou, $e->estado);
        $this->assertNull($e->proxima_tentativa_em);
        $this->assertSame(0, $this->endpoint->fresh()?->falhas_seguidas);
    }

    public function test_job_restaura_o_tenant_e_envia(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $e = $this->entrega();
        app(TenantContext::class)->set(null);

        EnviarWebhookJob::dispatchSync($e->id, $this->tenant->id);

        $this->assertNull(app(TenantContext::class)->id());
        app(TenantContext::class)->set($this->tenant->id);
        $this->assertSame(EstadoDaEntrega::Entregue, $e->fresh()?->estado);
    }

    public function test_comando_despacha_so_vencidas_reserva_e_retoma_a_travada(): void
    {
        Bus::fake();
        $vencida = $this->entrega();
        $this->entrega(['proxima_tentativa_em' => now()->addMinutes(2)]);
        $this->entrega(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null]);

        $this->artisan('webhooks:reenviar-pendentes')->assertSuccessful();
        $this->artisan('webhooks:reenviar-pendentes')->assertSuccessful();

        Bus::assertDispatchedTimes(EnviarWebhookJob::class, 1);
        Bus::assertDispatched(EnviarWebhookJob::class, fn (EnviarWebhookJob $j): bool => $j->entregaId === $vencida->id && $j->tenantId === $this->tenant->id && $j->reservada);
        $this->assertTrue($vencida->fresh()?->proxima_tentativa_em?->equalTo(now()->addMinutes(5)));

        // O worker morreu: passados 5 minutos a entrega volta a ser despachada.
        $this->travelTo(now()->addMinutes(5));
        $this->artisan('webhooks:reenviar-pendentes')->assertSuccessful();

        Bus::assertDispatchedTimes(EnviarWebhookJob::class, 3); // a vencida de novo + a que estava a 2 minutos
    }

    public function test_job_reservado_pelo_comando_envia_mesmo_com_a_reserva_ativa(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $e = $this->entrega();

        $this->artisan('webhooks:reenviar-pendentes')->assertSuccessful();

        $this->assertSame(EstadoDaEntrega::Entregue, $e->fresh()?->estado);
        $this->assertCount(1, $this->enviadas());
    }

    public function test_limpar_respeita_30_dias_e_preserva_pendente(): void
    {
        $velha = $this->entrega(['estado' => EstadoDaEntrega::Entregue, 'proxima_tentativa_em' => null]);
        $velhaFalhou = $this->entrega(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null]);
        $velhaPendente = $this->entrega();
        $recente = $this->entrega(['estado' => EstadoDaEntrega::Entregue, 'proxima_tentativa_em' => null]);
        (new WebhookTentativa)->forceFill([
            'tenant_id' => $this->tenant->id, 'webhook_entrega_id' => $velha->id, 'numero' => 1, 'duracao_ms' => 1, 'created_at' => now(),
        ])->save();
        DB::table('webhook_entregas')->whereIn('id', [$velha->id, $velhaFalhou->id, $velhaPendente->id])->update(['updated_at' => now()->subDays(31)]);
        DB::table('webhook_entregas')->where('id', $recente->id)->update(['updated_at' => now()->subDays(29)]);

        $this->artisan('webhooks:limpar')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$velhaPendente->id, $recente->id], WebhookEntrega::query()->pluck('id')->all());
        $this->assertSame(0, DB::table('webhook_tentativas')->count());
    }
}
