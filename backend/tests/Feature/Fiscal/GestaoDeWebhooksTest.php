<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Webhooks\Actions\AtualizarWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\CriarWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\ExcluirWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\ListarWebhooks;
use App\Modules\Fiscal\Application\Webhooks\ResolvedorDeDns;
use App\Modules\Fiscal\Domain\Exceptions\WebhookLimiteAtingidoException;
use App\Modules\Fiscal\Domain\Exceptions\WebhookUrlInvalidaException;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class GestaoDeWebhooksTest extends TestCase
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
    }

    /** @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        return array_replace(['url' => 'https://exemplo.test/hook', 'ambiente' => 'HOMOLOGACAO', 'eventos' => ['nota.autorizada']], $extra);
    }

    private function criar(array $extra = []): WebhookEndpoint
    {
        return app(CriarWebhook::class)->executar($this->admin, $this->dados($extra))->modelo;
    }

    public function test_criar_devolve_modelo_e_segredo_e_o_banco_guarda_criptografado(): void
    {
        $r = app(CriarWebhook::class)->executar($this->admin, $this->dados(['descricao' => 'ERP']));

        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{40}$/', $r->segredo);
        $this->assertSame($r->segredo, $r->modelo->fresh()?->segredo);
        $this->assertStringNotContainsString($r->segredo, (string) DB::table('webhook_endpoints')->value('segredo'));
        $this->assertSame($this->admin->id, $r->modelo->usuario_id);
        $this->assertSame('ERP', $r->modelo->descricao);
        $this->assertTrue($r->modelo->ativo);
        $this->assertSame(['nota.autorizada'], $r->modelo->eventos);
    }

    public function test_url_insegura_e_recusada(): void
    {
        $this->expectException(WebhookUrlInvalidaException::class);
        $this->criar(['url' => 'https://interno.test/hook']);
    }

    public function test_eventos_invalidos_sao_recusados(): void
    {
        foreach ([['nota.inexistente'], ['webhook.teste'], 'nota.autorizada', [1], ['nota.erro', 'nota.erro']] as $eventos) {
            try {
                $this->criar(['eventos' => $eventos]);
                $this->fail('Deveria recusar os eventos');
            } catch (ValidationException $e) {
                $this->assertNotEmpty(array_filter(array_keys($e->errors()), fn (string $campo): bool => str_starts_with($campo, 'eventos')));
            }
        }
        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_eventos_vazios_ou_omitidos_assinam_todos(): void
    {
        $this->assertSame([], $this->criar(['eventos' => []])->eventos);
        $dados = $this->dados();
        unset($dados['eventos']);
        $this->assertSame([], app(CriarWebhook::class)->executar($this->admin, $dados)->modelo->eventos);
    }

    public function test_ambiente_invalido_e_recusado(): void
    {
        $this->expectException(ValidationException::class);
        $this->criar(['ambiente' => 'TESTE']);
    }

    public function test_descricao_longa_e_recusada(): void
    {
        $this->expectException(ValidationException::class);
        $this->criar(['descricao' => str_repeat('a', 81)]);
    }

    public function test_limite_de_cinco_endpoints_e_excluir_libera(): void
    {
        $criados = [];
        for ($i = 0; $i < 5; $i++) {
            $criados[] = $this->criar();
        }

        try {
            $this->criar();
            $this->fail('O sexto deveria falhar');
        } catch (WebhookLimiteAtingidoException $e) {
            $this->assertSame('WEBHOOK_LIMITE_ATINGIDO', $e->codigo());
            $this->assertSame(422, $e->status());
        }

        app(ExcluirWebhook::class)->executar($this->admin, $criados[0]->id);
        $this->criar();
        $this->assertSame(5, WebhookEndpoint::query()->count());
    }

    public function test_so_proprietario_e_admin_gerenciam(): void
    {
        $existente = $this->criar();
        foreach ([Papel::Fiscal, Papel::Vendedor, Papel::Leitura] as $papel) {
            $usuario = Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);
            foreach ([
                fn () => app(CriarWebhook::class)->executar($usuario, $this->dados()),
                fn () => app(AtualizarWebhook::class)->executar($usuario, $existente->id, ['ativo' => false]),
                fn () => app(ExcluirWebhook::class)->executar($usuario, $existente->id),
                fn () => app(ListarWebhooks::class)->executar($usuario),
            ] as $acao) {
                try {
                    $acao();
                    $this->fail("{$papel->value} não deveria gerenciar webhooks");
                } catch (AcessoNegadoException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $proprietario = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Proprietario]);
        $this->assertCount(1, app(ListarWebhooks::class)->executar($proprietario));
    }

    public function test_plano_sem_modulo_api_e_recusado(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::FiscalNfe))->create();
        app(TenantContext::class)->set($tenant->id);
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->expectException(ModuloNaoContratadoException::class);
        app(CriarWebhook::class)->executar($admin, $this->dados());
    }

    public function test_atualizar_muda_os_campos_mas_nao_o_segredo(): void
    {
        $r = app(CriarWebhook::class)->executar($this->admin, $this->dados());

        $atualizado = app(AtualizarWebhook::class)->executar($this->admin, $r->modelo->id, [
            'url' => 'https://outro.test/h', 'descricao' => 'Novo', 'eventos' => ['nota.erro'], 'ambiente' => 'PRODUCAO', 'segredo' => 'whsec_hack',
        ]);

        $this->assertSame('https://outro.test/h', $atualizado->url);
        $this->assertSame('Novo', $atualizado->descricao);
        $this->assertSame(['nota.erro'], $atualizado->eventos);
        $this->assertSame('PRODUCAO', $atualizado->ambiente->value);
        $this->assertSame($r->segredo, $atualizado->fresh()?->segredo);
    }

    public function test_atualizar_parcial_mantem_o_resto(): void
    {
        $e = $this->criar(['descricao' => 'Meu']);

        $atualizado = app(AtualizarWebhook::class)->executar($this->admin, $e->id, ['ativo' => false]);

        $this->assertFalse($atualizado->ativo);
        $this->assertSame('Meu', $atualizado->descricao);
        $this->assertSame('https://exemplo.test/hook', $atualizado->url);
    }

    public function test_reativar_zera_as_falhas_e_limpa_o_motivo(): void
    {
        $e = $this->criar();
        $e->forceFill(['ativo' => false, 'falhas_seguidas' => 10, 'desativado_em' => now(), 'motivo_desativacao' => '10 falhas'])->save();

        $r = app(AtualizarWebhook::class)->executar($this->admin, $e->id, ['ativo' => true]);

        $this->assertTrue($r->ativo);
        $this->assertSame(0, $r->falhas_seguidas);
        $this->assertNull($r->desativado_em);
        $this->assertNull($r->motivo_desativacao);
    }

    public function test_desativar_manualmente_registra_o_instante(): void
    {
        $e = $this->criar();

        $r = app(AtualizarWebhook::class)->executar($this->admin, $e->id, ['ativo' => false]);

        $this->assertNotNull($r->desativado_em);
        $this->assertSame('Desativado manualmente.', $r->motivo_desativacao);
    }

    public function test_atualizar_a_url_revalida(): void
    {
        $e = $this->criar();

        $this->expectException(WebhookUrlInvalidaException::class);
        app(AtualizarWebhook::class)->executar($this->admin, $e->id, ['url' => 'https://interno.test/x']);
    }

    public function test_atualizar_com_evento_invalido_e_recusado(): void
    {
        $e = $this->criar();

        $this->expectException(ValidationException::class);
        app(AtualizarWebhook::class)->executar($this->admin, $e->id, ['eventos' => ['x']]);
    }

    public function test_excluir_apaga(): void
    {
        $e = $this->criar();

        app(ExcluirWebhook::class)->executar($this->admin, $e->id);

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_listar_traz_so_os_da_empresa_sem_segredo(): void
    {
        $this->criar();
        $this->criar(['url' => 'https://exemplo.test/2']);
        $this->emOutroTenant(function (): void {
            $u = Usuario::factory()->create(['papel' => Papel::Admin]);
            WebhookEndpoint::factory()->create(['usuario_id' => $u->id]);
        });

        $lista = app(ListarWebhooks::class)->executar($this->admin);

        $this->assertCount(2, $lista);
        $this->assertStringNotContainsString('whsec_', $lista->toJson());
    }

    public function test_auditoria_nao_grava_o_segredo(): void
    {
        $r = app(CriarWebhook::class)->executar($this->admin, $this->dados());
        $cru = (string) DB::table('webhook_endpoints')->value('segredo');
        $this->assertNotSame('', $cru);
        app(AtualizarWebhook::class)->executar($this->admin, $r->modelo->id, ['descricao' => 'x']);
        app(ExcluirWebhook::class)->executar($this->admin, $r->modelo->id);

        $registros = Activity::query()->where('log_name', 'webhooks')->get();
        $this->assertSame(['webhook_criado', 'webhook_alterado', 'webhook_excluido'], $registros->pluck('event')->all());
        $texto = $registros->toJson();
        $this->assertStringNotContainsString($r->segredo, $texto);
        $this->assertStringNotContainsString('whsec_', $texto);
        $this->assertStringNotContainsString($cru, $texto);
    }

    public function test_isolamento_endpoint_de_outro_tenant_nao_e_acessivel(): void
    {
        $alheio = $this->emOutroTenant(function (): WebhookEndpoint {
            $u = Usuario::factory()->create(['papel' => Papel::Admin]);

            return WebhookEndpoint::factory()->create(['usuario_id' => $u->id]);
        });

        foreach ([
            fn () => app(AtualizarWebhook::class)->executar($this->admin, $alheio->id, ['ativo' => false]),
            fn () => app(ExcluirWebhook::class)->executar($this->admin, $alheio->id),
        ] as $acao) {
            try {
                $acao();
                $this->fail('Deveria ser 404');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, DB::table('webhook_endpoints')->count());
    }
}
