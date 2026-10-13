<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class NegociosApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Funil $funil;

    private Etapa $etapa;

    private Etapa $segunda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->funil = Funil::factory()->padrao()->create();
        $this->etapa = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 0]);
        $this->segunda = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 1]);
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
    }

    /** @param array<string, mixed> $extra */
    private function negocioDe(Usuario $responsavel, array $extra = []): Negocio
    {
        return Negocio::factory()->create(array_merge([
            'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id, 'responsavel_id' => $responsavel->id,
        ], $extra));
    }

    public function test_cria_negocio_com_lead_na_hora_e_lista_para_o_kanban(): void
    {
        $caneca = Produto::factory()->create(['nome' => 'Caneca', 'preco_centavos' => 2500]);

        $this->spaComo($this->admin)->postJson('/api/app/crm/negocios', [
            'titulo' => 'Brindes', 'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id,
            'novo_lead' => ['nome' => 'Ana', 'telefone' => '11999990000'],
            'itens' => [['tipo' => 'PRODUTO', 'item_id' => $caneca->id, 'quantidade' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.cliente.nome', 'Ana')
            ->assertJsonPath('data.cliente.estagio', 'LEAD')
            ->assertJsonPath('data.valor_centavos', 5000)
            ->assertJsonPath('data.responsavel.id', $this->admin->id)
            ->assertJsonPath('data.itens.0.descricao', 'Caneca');

        $this->spaComo($this->admin)->getJson("/api/app/crm/negocios?funil_id={$this->funil->id}&status=ABERTO")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_lead_sem_telefone_nem_email_devolve_422(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/crm/negocios', [
            'titulo' => 'X', 'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id,
            'novo_lead' => ['nome' => 'Ana'],
        ])->assertStatus(422)->assertJsonValidationErrors('novo_lead.telefone');
    }

    public function test_fluxo_mover_ganhar(): void
    {
        $negocio = $this->negocioDe($this->admin);

        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/mover", ['etapa_id' => $this->segunda->id, 'posicao' => 0])
            ->assertOk()->assertJsonPath('data.etapa_id', $this->segunda->id);
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/ganhar")
            ->assertOk()->assertJsonPath('data.status', 'GANHO');
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/mover", ['etapa_id' => $this->etapa->id, 'posicao' => 0])
            ->assertStatus(422)->assertJsonPath('codigo', 'NEGOCIO_FECHADO');
    }

    public function test_perder_exige_motivo(): void
    {
        $negocio = $this->negocioDe($this->admin);
        $motivo = MotivoDePerda::factory()->create();

        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/perder", [])->assertStatus(422);
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/perder", ['motivo_de_perda_id' => $motivo->id])
            ->assertOk()->assertJsonPath('data.status', 'PERDIDO');
    }

    public function test_vendedor_so_ve_e_mexe_nos_proprios_negocios_com_interruptor_ligado(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $deJoao = $this->negocioDe($joao);
        $deMaria = $this->negocioDe($maria);

        $this->spaComo($joao)->getJson('/api/app/crm/negocios')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deJoao->id);
        $this->spaComo($joao)->getJson("/api/app/crm/negocios/{$deMaria->id}")->assertNotFound();
        $this->spaComo($joao)->putJson("/api/app/crm/negocios/{$deMaria->id}", ['titulo' => 'x'])->assertNotFound();
        $this->spaComo($joao)->putJson("/api/app/crm/negocios/{$deMaria->id}/itens", ['itens' => []])->assertNotFound();
        $this->spaComo($joao)->postJson("/api/app/crm/negocios/{$deMaria->id}/mover", ['etapa_id' => $this->segunda->id, 'posicao' => 0])->assertNotFound();
        $this->spaComo($joao)->postJson("/api/app/crm/negocios/{$deMaria->id}/ganhar")->assertNotFound();
        $this->spaComo($joao)->postJson("/api/app/crm/negocios/{$deMaria->id}/perder", ['motivo_de_perda_id' => MotivoDePerda::factory()->create()->id])->assertNotFound();
    }

    public function test_com_interruptor_desligado_vendedor_ve_todos(): void
    {
        ConfiguracaoDoCrm::query()->update(['vendedor_ve_so_os_seus' => false]);
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $this->negocioDe($this->admin);
        $this->negocioDe($joao);

        $this->spaComo($joao)->getJson('/api/app/crm/negocios')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_leitura_ve_todos_mas_nao_escreve(): void
    {
        $leitor = $this->usuarioDoPapel(Papel::Leitura);
        $negocio = $this->negocioDe($this->admin);

        $this->spaComo($leitor)->getJson('/api/app/crm/negocios')->assertOk()->assertJsonCount(1, 'data');
        $this->spaComo($leitor)->postJson("/api/app/crm/negocios/{$negocio->id}/ganhar")->assertForbidden();
    }

    public function test_fiscal_e_empresa_sem_modulo_sao_barrados(): void
    {
        $this->spaComo($this->usuarioDoPapel(Papel::Fiscal))->getJson('/api/app/crm/negocios')->assertForbidden();

        $semModulo = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($semModulo)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($semModulo->id);
        $this->spaComo($admin)->getJson('/api/app/crm/negocios')->assertForbidden()->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_isolamento_negocio_de_outra_empresa_da_404_em_todos_os_endpoints(): void
    {
        $outra = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Crm))->create();
        $donoAlheio = Usuario::factory()->for($outra)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($outra->id);
        $alheio = Negocio::factory()->create(['responsavel_id' => $donoAlheio->id]);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->getJson("/api/app/crm/negocios/{$alheio->id}")->assertNotFound();
        $this->spaComo($this->admin)->putJson("/api/app/crm/negocios/{$alheio->id}", ['titulo' => 'x'])->assertNotFound();
        $this->spaComo($this->admin)->putJson("/api/app/crm/negocios/{$alheio->id}/itens", ['itens' => []])->assertNotFound();
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$alheio->id}/mover", ['etapa_id' => $this->segunda->id, 'posicao' => 0])->assertNotFound();
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$alheio->id}/ganhar")->assertNotFound();
        $this->spaComo($this->admin)->getJson('/api/app/crm/negocios')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cliente_de_outra_empresa_no_post_devolve_422(): void
    {
        $outra = Tenant::factory()->create();
        app(TenantContext::class)->set($outra->id);
        $alheio = Cliente::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->postJson('/api/app/crm/negocios', [
            'titulo' => 'X', 'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id, 'cliente_id' => $alheio->id,
        ])->assertStatus(422)->assertJsonPath('codigo', 'CLIENTE_NAO_ENCONTRADO');
    }
}
