<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura as S;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminEmpresasTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Superadmin]);
    }

    public function test_lista_filtra_por_situacao_e_busca_por_cnpj_ou_nome(): void
    {
        Tenant::factory()->situacao(S::Suspensa)->create(['razao_social' => 'Padaria Pão Bom Ltda', 'cnpj' => '11222333000181']);
        Tenant::factory()->create(['razao_social' => 'Mercado Central']);
        $admin = $this->spa()->actingAs($this->admin);

        $admin->getJson('/api/admin/empresas?situacao=SUSPENSA')->assertOk()->assertJsonCount(1, 'data');
        $admin->getJson('/api/admin/empresas?busca=pão bom')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.razao_social', 'Padaria Pão Bom Ltda');
        $admin->getJson('/api/admin/empresas?busca=11.222.333')->assertJsonCount(1, 'data');
        $admin->getJson('/api/admin/empresas?situacao=INVENTADA')->assertJsonValidationErrors('situacao');
    }

    public function test_paginacao_por_cursor(): void
    {
        Tenant::factory()->count(25)->create();

        $primeira = $this->spa()->actingAs($this->admin)->getJson('/api/admin/empresas')->assertOk()->assertJsonCount(20, 'data');
        $cursor = $primeira->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $this->spa()->actingAs($this->admin)->getJson('/api/admin/empresas?cursor='.$cursor)->assertJsonCount(5, 'data');
    }

    public function test_detalhe_tem_consumo(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Usuarios->value => 3]))->create();
        Usuario::factory()->count(2)->for($tenant)->create();

        $resposta = $this->spa()->actingAs($this->admin)->getJson("/api/admin/empresas/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id);

        // consumo() lista todo recurso com contador registrado; outros módulos (F2+) registram os seus.
        $this->assertContains(['recurso' => 'USUARIOS', 'uso' => 2, 'limite' => 3], $resposta->json('data.consumo'));
    }

    public function test_mudar_situacao_exige_motivo_e_segue_a_maquina_de_estados(): void
    {
        $tenant = Tenant::factory()->situacao(S::Pendente)->create();
        $admin = $this->spa()->actingAs($this->admin);

        $admin->postJson("/api/admin/empresas/{$tenant->id}/situacao", ['situacao' => 'ATIVA'])
            ->assertJsonValidationErrors('motivo');
        $admin->postJson("/api/admin/empresas/{$tenant->id}/situacao", ['situacao' => 'SUSPENSA', 'motivo' => 'Teste'])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'TRANSICAO_INVALIDA');
        $admin->postJson("/api/admin/empresas/{$tenant->id}/situacao", ['situacao' => 'ATIVA', 'motivo' => 'Cliente validado'])
            ->assertOk()
            ->assertJsonPath('data.situacao', 'ATIVA');

        $this->assertSame(1, Activity::query()->where('event', 'situacao_alterada')->count());
    }

    public function test_troca_de_plano_com_excesso_e_recusada_e_nao_troca(): void
    {
        $atual = Plano::factory()->comLimites([Recurso::Usuarios->value => 5])->create();
        $menor = Plano::factory()->comLimites([Recurso::Usuarios->value => 1])->create();
        $tenant = Tenant::factory()->for($atual)->create();
        Usuario::factory()->count(3)->for($tenant)->create();

        $this->spa()->actingAs($this->admin)->postJson("/api/admin/empresas/{$tenant->id}/plano", ['plano_id' => $menor->id])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'PLANO_EXCEDIDO')
            ->assertJsonPath('excessos', [['recurso' => 'USUARIOS', 'uso' => 3, 'limite' => 1]]);

        $this->assertSame($atual->id, $tenant->refresh()->plano_id);
    }

    public function test_troca_de_plano_sem_excesso_e_auditada(): void
    {
        $maior = Plano::factory()->comLimites([Recurso::Usuarios->value => -1])->create(['nome' => 'Maior']);
        $tenant = Tenant::factory()->create();

        $this->spa()->actingAs($this->admin)->postJson("/api/admin/empresas/{$tenant->id}/plano", ['plano_id' => $maior->id])
            ->assertOk()
            ->assertJsonPath('data.plano.nome', 'Maior');

        $this->assertSame(1, Activity::query()->where('event', 'plano_trocado')->count());
    }

    public function test_troca_para_plano_desativado_e_recusada(): void
    {
        $inativo = Plano::factory()->create(['ativo' => false]);
        $tenant = Tenant::factory()->create();

        $this->spa()->actingAs($this->admin)->postJson("/api/admin/empresas/{$tenant->id}/plano", ['plano_id' => $inativo->id])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'PLANO_INDISPONIVEL');
    }

    public function test_metricas(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        $caro = Plano::factory()->create(['preco_mensal_centavos' => 9900]);
        $barato = Plano::factory()->create(['preco_mensal_centavos' => 5000]);
        Tenant::factory()->for($caro)->create();
        Tenant::factory()->for($barato)->situacao(S::Inadimplente)->create();
        Tenant::factory()->for($caro)->situacao(S::Suspensa)->create(['created_at' => now()->subDays(40)]);

        $this->spa()->actingAs($this->admin)->getJson('/api/admin/metricas')
            ->assertOk()
            ->assertJsonPath('data.por_situacao.ATIVA', 1)
            ->assertJsonPath('data.por_situacao.INADIMPLENTE', 1)
            ->assertJsonPath('data.por_situacao.SUSPENSA', 1)
            ->assertJsonPath('data.por_situacao.PENDENTE', 0)
            ->assertJsonPath('data.novas_30_dias', 2)
            ->assertJsonPath('data.mrr_centavos', 14900);
    }

    public function test_suporte_nao_muda_situacao(): void
    {
        $suporte = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Suporte]);
        $tenant = Tenant::factory()->create();

        $this->spa()->actingAs($suporte)->getJson("/api/admin/empresas/{$tenant->id}")->assertOk();
        $this->spa()->actingAs($suporte)
            ->postJson("/api/admin/empresas/{$tenant->id}/situacao", ['situacao' => 'SUSPENSA', 'motivo' => 'x'])
            ->assertForbidden();
    }
}
