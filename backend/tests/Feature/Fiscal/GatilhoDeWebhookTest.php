<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Jobs\EnviarWebhookJob;
use App\Modules\Fiscal\Application\Webhooks\MontadorDoPayload;
use App\Modules\Fiscal\Application\Webhooks\RegistrarEntregasDeWebhook;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class GatilhoDeWebhookTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private Nota $nota;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario(Modulo::FiscalNfe, Modulo::Api);
        $this->nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $this->nota->forceFill(['ambiente' => 'HOMOLOGACAO', 'xml_autorizado' => '<nfeProc>SEGREDO-XML</nfeProc>', 'xml_enviado' => '<NFe>SEGREDO-ENVIADO</NFe>'])->save();
        Bus::fake();
    }

    private function endpoint(array $extra = []): WebhookEndpoint
    {
        return WebhookEndpoint::factory()->create(['usuario_id' => $this->admin->id] + $extra);
    }

    private function mudar(StatusNota $para, ?StatusNota $de = StatusNota::Processando): NotaEvento
    {
        $this->nota->forceFill(['status' => $para])->save();

        return NotaEvento::registrar($this->nota, $de, $para, 'detalhe');
    }

    public function test_cada_status_de_evento_gera_a_entrega_com_o_tipo_certo(): void
    {
        $this->endpoint();
        $esperado = [
            'AUTORIZADA' => 'nota.autorizada', 'REJEITADA' => 'nota.rejeitada', 'CANCELADA' => 'nota.cancelada',
            'ERRO' => 'nota.erro', 'CONTINGENCIA' => 'nota.contingencia',
        ];

        foreach ($esperado as $status => $tipo) {
            $this->mudar(StatusNota::from($status));
            $entrega = WebhookEntrega::query()->latest('created_at')->latest('id')->where('evento', $tipo)->first();
            $this->assertNotNull($entrega, $tipo);
            $this->assertSame($tipo, $entrega->payload['tipo']);
            $this->assertSame($status, $entrega->payload['data']['nota']['status']);
            $this->assertSame(EstadoDaEntrega::Pendente, $entrega->estado);
        }
        $this->assertSame(5, WebhookEntrega::query()->count());
        Bus::assertDispatchedTimes(EnviarWebhookJob::class, 5);
    }

    public function test_status_sem_evento_nao_gera_entrega(): void
    {
        $this->endpoint();

        $this->mudar(StatusNota::Processando, StatusNota::Rascunho);
        $this->mudar(StatusNota::Cancelando, StatusNota::Autorizada);
        $this->mudar(StatusNota::Rascunho, null);

        $this->assertSame(0, WebhookEntrega::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_evento_sem_mudanca_de_status_nao_gera_entrega(): void
    {
        $this->endpoint();

        $this->mudar(StatusNota::Erro, StatusNota::Erro);

        $this->assertSame(0, WebhookEntrega::query()->count());
    }

    public function test_so_recebe_endpoint_ativo_do_ambiente_que_assina_e_com_modulo(): void
    {
        $this->endpoint(['ativo' => false]);
        $this->endpoint(['ambiente' => 'PRODUCAO']);
        $this->endpoint(['eventos' => ['nota.cancelada']]);
        $elegivel = $this->endpoint(['eventos' => ['nota.autorizada']]);

        $this->mudar(StatusNota::Autorizada);

        $this->assertSame([$elegivel->id], WebhookEntrega::query()->pluck('webhook_endpoint_id')->all());

        $this->tenant->plano_id = Plano::factory()->comModulos(Modulo::FiscalNfe)->create()->id;
        $this->tenant->save();
        $this->mudar(StatusNota::Cancelada, StatusNota::Autorizada);
        $this->assertSame(1, WebhookEntrega::query()->count());
    }

    public function test_nota_sem_ambiente_nao_notifica(): void
    {
        $this->endpoint();
        $this->nota->forceFill(['ambiente' => null])->save();

        $this->mudar(StatusNota::Rejeitada);

        $this->assertSame(0, WebhookEntrega::query()->count());
    }

    public function test_dois_endpoints_elegiveis_recebem_uma_entrega_cada_com_ids_distintos(): void
    {
        $this->endpoint();
        $this->endpoint();

        $this->mudar(StatusNota::Autorizada);

        $entregas = WebhookEntrega::query()->get();
        $this->assertCount(2, $entregas);
        $this->assertCount(2, $entregas->pluck('evento_id')->unique());
        $this->assertSame($entregas[0]->evento_id, $entregas[0]->payload['id']);
        Bus::assertDispatchedTimes(EnviarWebhookJob::class, 2);
    }

    public function test_o_mesmo_nota_evento_processado_duas_vezes_nao_duplica(): void
    {
        $this->endpoint();
        $evento = $this->mudar(StatusNota::Autorizada);

        $segunda = app(RegistrarEntregasDeWebhook::class)->executar($evento);

        $this->assertSame(0, $segunda);
        $this->assertSame(1, WebhookEntrega::query()->count());
        Bus::assertDispatchedTimes(EnviarWebhookJob::class, 1);
    }

    public function test_payload_tem_a_nota_com_itens_sem_xml_nem_segredo(): void
    {
        $endpoint = $this->endpoint();
        (new NotaItem)->forceFill([
            'nota_id' => $this->nota->id, 'tenant_id' => $this->tenant->id, 'ordem' => 1, 'sku' => 'P-1', 'descricao' => 'Produto Um', 'unidade' => 'UN',
            'quantidade_milesimos' => 1000, 'valor_unitario_centavos' => 1000, 'desconto_centavos' => 0, 'total_centavos' => 1000,
        ])->save();

        $this->mudar(StatusNota::Autorizada);

        $entrega = WebhookEntrega::query()->firstOrFail();
        $json = (string) json_encode($entrega->payload);
        $this->assertStringNotContainsString('SEGREDO', $json);
        $this->assertStringNotContainsString('xml_', $json);
        $this->assertStringNotContainsString($endpoint->segredo, $json);
        $this->assertSame($this->nota->id, $entrega->payload['data']['nota']['id']);
        $this->assertSame('HOMOLOGACAO', $entrega->payload['ambiente']);
        $this->assertSame('P-1', $entrega->payload['data']['nota']['itens'][0]['sku']);
        $this->assertArrayHasKey('eventos', $entrega->payload['data']['nota']);
        $this->assertSame($this->nota->id, $entrega->nota_id);
        $this->assertNotNull($entrega->nota_evento_id);
        $this->assertNotNull($entrega->proxima_tentativa_em);
        $this->assertSame($entrega->evento_id, $entrega->payload['id']);
        $this->assertNotFalse(strtotime($entrega->payload['criado_em']));
    }

    public function test_rollback_da_transacao_nao_deixa_entrega(): void
    {
        $this->endpoint();

        try {
            DB::transaction(function (): void {
                $this->mudar(StatusNota::Autorizada);
                throw new RuntimeException('falha depois do evento');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, WebhookEntrega::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_o_job_so_e_despachado_depois_do_commit(): void
    {
        $this->endpoint();

        DB::transaction(function (): void {
            $this->mudar(StatusNota::Autorizada);
            $this->assertSame(1, WebhookEntrega::query()->count());
            Bus::assertNothingDispatched();
        });

        Bus::assertDispatchedTimes(EnviarWebhookJob::class, 1);
    }

    public function test_falha_ao_registrar_nao_impede_a_mudanca_de_status(): void
    {
        $this->endpoint();
        $this->mock(MontadorDoPayload::class, fn ($m) => $m->shouldReceive('paraNota')->andThrow(new RuntimeException('quebrou')));

        $evento = $this->mudar(StatusNota::Autorizada);

        $this->assertNotNull(NotaEvento::query()->find($evento->id));
        $this->assertSame(StatusNota::Autorizada, $this->nota->fresh()?->status);
        $this->assertSame(0, WebhookEntrega::query()->count());
    }

    public function test_isolamento_evento_da_empresa_a_nao_gera_entrega_para_a_b(): void
    {
        $this->emOutroTenantDeNota(function (): void {
            $usuario = Usuario::factory()->create(['papel' => Papel::Admin]);
            WebhookEndpoint::factory()->create(['usuario_id' => $usuario->id]);
        });

        $this->mudar(StatusNota::Autorizada);

        $this->assertSame(0, WebhookEntrega::query()->count());
        $this->assertSame(0, DB::table('webhook_entregas')->count());
        Bus::assertNothingDispatched();
    }

    private function emOutroTenantDeNota(callable $criar): void
    {
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Api, Modulo::FiscalNfe))->create();
        app(TenantContext::class)->set($outro->id);
        try {
            $criar();
        } finally {
            app(TenantContext::class)->set($this->tenant->id);
        }
    }
}
