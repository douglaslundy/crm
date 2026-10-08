<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Enums\EventoDeWebhook;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookTentativa;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class WebhookModelosTest extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi([], Modulo::FiscalNfe);
    }

    private function endpoint(array $extra = []): WebhookEndpoint
    {
        return WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id] + $extra);
    }

    public function test_da_nota_mapeia_os_oito_status(): void
    {
        $esperado = [
            'AUTORIZADA' => EventoDeWebhook::NotaAutorizada, 'REJEITADA' => EventoDeWebhook::NotaRejeitada,
            'CANCELADA' => EventoDeWebhook::NotaCancelada, 'ERRO' => EventoDeWebhook::NotaErro,
            'CONTINGENCIA' => EventoDeWebhook::NotaContingencia,
            'RASCUNHO' => null, 'PROCESSANDO' => null, 'CANCELANDO' => null,
        ];
        foreach (StatusNota::cases() as $status) {
            $this->assertSame($esperado[$status->value], EventoDeWebhook::daNota($status), $status->value);
        }
        $this->assertCount(8, StatusNota::cases());
        $this->assertSame(
            ['nota.autorizada', 'nota.rejeitada', 'nota.cancelada', 'nota.erro', 'nota.contingencia'],
            EventoDeWebhook::assinaveis(),
        );
    }

    public function test_assina_lista_vazia_parcial_e_teste(): void
    {
        $todos = $this->endpoint(['eventos' => []]);
        $this->assertTrue($todos->assina(EventoDeWebhook::NotaErro));
        $this->assertTrue($todos->assina(EventoDeWebhook::Teste));

        $parcial = $this->endpoint(['eventos' => ['nota.autorizada']]);
        $this->assertTrue($parcial->assina(EventoDeWebhook::NotaAutorizada));
        $this->assertFalse($parcial->assina(EventoDeWebhook::NotaCancelada));
        $this->assertTrue($parcial->assina(EventoDeWebhook::Teste));
    }

    public function test_segredo_fica_criptografado_e_fora_da_serializacao(): void
    {
        $endpoint = $this->endpoint();
        $segredo = $endpoint->segredo;

        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{40}$/', $segredo);
        $cru = (string) DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('segredo');
        $this->assertNotSame($segredo, $cru);
        $this->assertStringNotContainsString($segredo, $cru);
        $this->assertArrayNotHasKey('segredo', $endpoint->toArray());
        $this->assertStringNotContainsString($segredo, $endpoint->toJson());
    }

    public function test_casts_e_padroes(): void
    {
        $endpoint = $this->endpoint(['eventos' => ['nota.erro']])->fresh();

        $this->assertSame(AmbienteFiscal::Homologacao, $endpoint->ambiente);
        $this->assertSame(['nota.erro'], $endpoint->eventos);
        $this->assertTrue($endpoint->ativo);
        $this->assertSame(0, $endpoint->falhas_seguidas);
        $this->assertNull($endpoint->desativado_em);

        $entrega = WebhookEntrega::factory()->create(['webhook_endpoint_id' => $endpoint->id, 'payload' => ['a' => 1]])->fresh();
        $this->assertSame(EstadoDaEntrega::Pendente, $entrega->estado);
        $this->assertSame(['a' => 1], $entrega->payload);
        $this->assertSame(0, $entrega->tentativas);
        $this->assertSame(0, $entrega->tentativas_na_rodada);
    }

    public function test_unico_por_endpoint_e_nota_evento(): void
    {
        $endpoint = $this->endpoint();
        $outro = $this->endpoint();
        WebhookEntrega::factory()->create(['webhook_endpoint_id' => $endpoint->id, 'nota_evento_id' => 7]);
        WebhookEntrega::factory()->create(['webhook_endpoint_id' => $outro->id, 'nota_evento_id' => 7]);
        // O teste (sem nota_evento_id) pode repetir.
        WebhookEntrega::factory()->count(2)->create(['webhook_endpoint_id' => $endpoint->id, 'nota_evento_id' => null]);

        $this->expectException(QueryException::class);
        WebhookEntrega::factory()->create(['webhook_endpoint_id' => $endpoint->id, 'nota_evento_id' => 7]);
    }

    public function test_excluir_o_endpoint_apaga_entregas_e_tentativas(): void
    {
        $endpoint = $this->endpoint();
        $entrega = WebhookEntrega::factory()->create(['webhook_endpoint_id' => $endpoint->id]);
        (new WebhookTentativa)->forceFill([
            'tenant_id' => $this->tenant->id, 'webhook_entrega_id' => $entrega->id, 'numero' => 1, 'status_http' => 500, 'duracao_ms' => 12, 'created_at' => now(),
        ])->save();
        $this->assertSame(1, $entrega->tentativasLog()->count());

        $endpoint->delete();

        $this->assertSame(0, DB::table('webhook_entregas')->count());
        $this->assertSame(0, DB::table('webhook_tentativas')->count());
    }

    public function test_isolamento_por_tenant_nos_tres_models(): void
    {
        $this->emOutroTenant(function (): void {
            $usuario = Usuario::factory()->create();
            $endpoint = WebhookEndpoint::factory()->create(['usuario_id' => $usuario->id]);
            $entrega = WebhookEntrega::factory()->create(['webhook_endpoint_id' => $endpoint->id]);
            (new WebhookTentativa)->forceFill([
                'tenant_id' => $endpoint->tenant_id, 'webhook_entrega_id' => $entrega->id, 'numero' => 1, 'duracao_ms' => 1, 'created_at' => now(),
            ])->save();
        });

        $this->assertSame(0, WebhookEndpoint::query()->count());
        $this->assertSame(0, WebhookEntrega::query()->count());
        $this->assertSame(0, WebhookTentativa::query()->count());
        $this->assertSame(1, DB::table('webhook_endpoints')->count());
    }

    public function test_a_factory_gera_url_e_segredo_validos(): void
    {
        $endpoint = WebhookEndpointFactory::new()->create(['usuario_id' => $this->admin->id]);

        $this->assertSame('https://exemplo.test/hook', $endpoint->url);
        $this->assertSame($this->tenant->id, $endpoint->tenant_id);
    }
}
