<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class AtividadesApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
        $this->cliente = Cliente::factory()->create(['nome' => 'Ana', 'telefone' => '11999990000', 'email' => 'ana@x.com']);
        $this->travelTo('2026-10-14 12:00:00'); // 09:00 em São Paulo
    }

    public function test_cria_lista_conclui_reabre_e_exclui(): void
    {
        $id = $this->spaComo($this->admin)->postJson('/api/app/crm/atividades', [
            'tipo' => 'LIGACAO', 'titulo' => 'Ligar', 'agendada_para' => '2026-10-14T15:00:00Z', 'cliente_id' => $this->cliente->id,
        ])->assertCreated()->assertJsonPath('data.cliente.nome', 'Ana')->assertJsonPath('data.atrasada', false)->json('data.id');

        $this->spaComo($this->admin)->getJson("/api/app/crm/atividades?cliente_id={$this->cliente->id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->spaComo($this->admin)->postJson("/api/app/crm/atividades/{$id}/concluir")
            ->assertOk()->assertJsonPath('data.concluida_em', fn ($v) => $v !== null);
        $this->spaComo($this->admin)->postJson("/api/app/crm/atividades/{$id}/reabrir")
            ->assertOk()->assertJsonPath('data.concluida_em', null);
        $this->spaComo($this->admin)->putJson("/api/app/crm/atividades/{$id}", ['titulo' => 'Ligar de novo'])
            ->assertOk()->assertJsonPath('data.titulo', 'Ligar de novo');
        $this->spaComo($this->admin)->deleteJson("/api/app/crm/atividades/{$id}")->assertNoContent();
    }

    public function test_validacao_do_corpo(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/crm/atividades', ['tipo' => 'X', 'titulo' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['tipo', 'titulo', 'agendada_para']);
    }

    public function test_agenda_traz_atrasadas_e_o_intervalo(): void
    {
        $atrasada = Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-10 12:00:00']);
        $hoje = Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-14 18:00:00']);
        Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-30 12:00:00']);
        Atividade::factory()->concluida()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-14 13:00:00']);

        $r = $this->spaComo($this->admin)->getJson('/api/app/crm/agenda?de=2026-10-14&ate=2026-10-20')->assertOk();

        $this->assertSame([$atrasada->id, $hoje->id], array_column($r->json('data'), 'id'));
        $r->assertJsonPath('data.0.atrasada', true)->assertJsonPath('data.1.atrasada', false);
    }

    public function test_resumo(): void
    {
        Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-10 12:00:00']);
        Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-14 18:00:00']);
        Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-15 18:00:00']);

        $this->spaComo($this->admin)->getJson('/api/app/crm/resumo')
            ->assertOk()->assertJsonPath('data.hoje', 1)->assertJsonPath('data.atrasadas', 1)->assertJsonPath('data.parados', 0);
    }

    public function test_contato_em_um_clique(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/crm/contatos', ['canal' => 'WHATSAPP', 'cliente_id' => $this->cliente->id])
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://wa.me/5511999990000')
            ->assertJsonPath('data.atividade.tipo', 'WHATSAPP');
    }

    public function test_contato_sem_telefone_devolve_422_com_codigo(): void
    {
        $semTelefone = Cliente::factory()->create(['telefone' => null]);

        $this->spaComo($this->admin)->postJson('/api/app/crm/contatos', ['canal' => 'WHATSAPP', 'cliente_id' => $semTelefone->id])
            ->assertStatus(422)->assertJsonPath('codigo', 'TELEFONE_INVALIDO');
    }

    public function test_nota_e_timeline_com_cursor(): void
    {
        foreach (['primeira', 'segunda'] as $texto) {
            $this->spaComo($this->admin)->postJson("/api/app/crm/clientes/{$this->cliente->id}/notas", ['texto' => $texto])
                ->assertCreated();
            $this->travelTo(now()->addMinute());
        }

        $r = $this->spaComo($this->admin)->getJson("/api/app/crm/clientes/{$this->cliente->id}/timeline")->assertOk();

        $this->assertSame(['segunda', 'primeira'], array_column($r->json('data'), 'texto'));
        $r->assertJsonPath('data.0.autor.id', $this->admin->id)->assertJsonPath('next_cursor', null);
    }

    public function test_vendedor_restrito_recebe_404_de_atividade_e_timeline_de_colega(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $alheia = Atividade::factory()->create(['responsavel_id' => $maria->id, 'cliente_id' => $this->cliente->id]);

        $this->spaComo($joao)->postJson("/api/app/crm/atividades/{$alheia->id}/concluir")->assertNotFound();
        $this->spaComo($joao)->putJson("/api/app/crm/atividades/{$alheia->id}", ['titulo' => 'x'])->assertNotFound();
        $this->spaComo($joao)->deleteJson("/api/app/crm/atividades/{$alheia->id}")->assertNotFound();

        RegistroDaTimeline::create([
            'cliente_id' => $this->cliente->id, 'tipo' => TipoDeRegistro::Nota, 'texto' => 'da Maria',
            'autor_id' => $maria->id, 'ocorreu_em' => now(),
        ]);
        $this->spaComo($joao)->getJson("/api/app/crm/clientes/{$this->cliente->id}/timeline")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_papel_fiscal_nao_acessa_e_leitura_nao_escreve(): void
    {
        $this->spaComo($this->usuarioDoPapel(Papel::Fiscal))->getJson('/api/app/crm/agenda')->assertForbidden();
        $this->spaComo($this->usuarioDoPapel(Papel::Leitura))->postJson('/api/app/crm/atividades', [
            'tipo' => 'TAREFA', 'titulo' => 'x', 'agendada_para' => '2026-10-15T12:00:00Z', 'cliente_id' => $this->cliente->id,
        ])->assertForbidden();
    }

    public function test_isolamento_de_tenant(): void
    {
        $plano = Plano::factory()->comModulos(Modulo::Crm);
        $outroTenant = Tenant::factory()->for($plano)->create();
        app(TenantContext::class)->set($outroTenant->id);
        $clienteDeFora = Cliente::factory()->create();
        $atividadeDeFora = Atividade::factory()->create(['cliente_id' => $clienteDeFora->id]);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->postJson("/api/app/crm/atividades/{$atividadeDeFora->id}/concluir")->assertNotFound();
        $this->spaComo($this->admin)->getJson("/api/app/crm/clientes/{$clienteDeFora->id}/timeline")->assertNotFound();
        $this->spaComo($this->admin)->postJson('/api/app/crm/atividades', [
            'tipo' => 'TAREFA', 'titulo' => 'x', 'agendada_para' => '2026-10-15T12:00:00Z', 'cliente_id' => $clienteDeFora->id,
        ])->assertStatus(422)->assertJsonPath('codigo', 'CLIENTE_INVALIDO');
        $this->spaComo($this->admin)->getJson('/api/app/crm/atividades')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filtro_com_id_invalido_devolve_422(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/atividades?cliente_id=nao-e-uuid')->assertStatus(422);
        $this->spaComo($this->admin)->putJson('/api/app/crm/atividades/nao-e-uuid', [])->assertNotFound();
    }
}
