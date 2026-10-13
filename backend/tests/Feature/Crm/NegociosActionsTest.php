<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Crm\Application\Actions\AtualizarNegocio;
use App\Modules\Crm\Application\Actions\CriarNegocio;
use App\Modules\Crm\Application\Actions\DefinirItensDoNegocio;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioCriado;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\ComCrm;
use Tests\TestCase;

class NegociosActionsTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Funil $funil;

    private Etapa $etapa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->funil = Funil::factory()->padrao()->create();
        $this->etapa = Etapa::factory()->create(['funil_id' => $this->funil->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function dados(array $extra = []): array
    {
        return array_merge([
            'titulo' => 'Reforma',
            'funil_id' => $this->funil->id,
            'etapa_id' => $this->etapa->id,
            'cliente_id' => Cliente::factory()->create()->id,
        ], $extra);
    }

    public function test_cria_negocio_com_cliente_existente_e_responsavel_padrao(): void
    {
        Event::fake([NegocioCriado::class]);

        $negocio = app(CriarNegocio::class)->executar($this->admin, $this->dados(['valor_centavos' => 0]));

        $this->assertSame($this->admin->id, $negocio->responsavel_id);
        $this->assertSame(StatusDoNegocio::Aberto, $negocio->status);
        $this->assertSame(0, $negocio->valor_centavos);
        Event::assertDispatched(NegocioCriado::class, fn (NegocioCriado $e): bool => $e->negocioId === $negocio->id);
    }

    public function test_cria_lead_na_hora_e_posicoes_sequenciais(): void
    {
        $a = app(CriarNegocio::class)->executar($this->admin, $this->dados(['cliente_id' => null, 'novo_lead' => ['nome' => 'Ana', 'telefone' => '11999990000']]));
        $b = app(CriarNegocio::class)->executar($this->admin, $this->dados());

        $lead = Cliente::query()->findOrFail($a->cliente_id);
        $this->assertSame('Ana', $lead->nome);
        $this->assertSame(EstagioCliente::Lead, $lead->estagio);
        $this->assertSame([0, 1], [$a->posicao_na_etapa, $b->posicao_na_etapa]);
    }

    public function test_cliente_de_outra_empresa_e_recusado_e_nada_e_criado(): void
    {
        $outra = Tenant::factory()->create();
        app(TenantContext::class)->set($outra->id);
        $alheio = Cliente::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        try {
            app(CriarNegocio::class)->executar($this->admin, $this->dados(['cliente_id' => $alheio->id]));
            $this->fail('Deveria recusar.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('CLIENTE_NAO_ENCONTRADO', $e->codigo());
        }
        $this->assertSame(0, Negocio::query()->count());
    }

    public function test_falha_depois_do_lead_nao_deixa_cliente_orfao(): void
    {
        $dados = $this->dados([
            'cliente_id' => null,
            'novo_lead' => ['nome' => 'Orfao', 'telefone' => '11999990000'],
            'itens' => [['tipo' => 'PRODUTO', 'item_id' => (string) Str::uuid(), 'quantidade' => 1]],
        ]);
        $antes = Cliente::query()->count();

        try {
            app(CriarNegocio::class)->executar($this->admin, $dados);
            $this->fail('Deveria recusar.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('ITEM_NAO_ENCONTRADO', $e->codigo());
        }

        $this->assertSame($antes, Cliente::query()->count());
    }

    public function test_etapa_de_outro_funil_e_recusada(): void
    {
        $outraEtapa = Etapa::factory()->create();

        $this->expectException(RegraDoCrmException::class);

        app(CriarNegocio::class)->executar($this->admin, $this->dados(['etapa_id' => $outraEtapa->id]));
    }

    public function test_valor_vira_soma_dos_itens_e_ignora_o_enviado(): void
    {
        $caneca = Produto::factory()->create(['preco_centavos' => 2500]);

        $negocio = app(CriarNegocio::class)->executar($this->admin, $this->dados([
            'valor_centavos' => 999999,
            'itens' => [['tipo' => 'PRODUTO', 'item_id' => $caneca->id, 'quantidade' => 3]],
        ]));

        $this->assertSame(7500, $negocio->valor_centavos);
        $this->assertSame(2500, $negocio->itens->first()->valor_unitario_centavos);
    }

    public function test_item_de_outra_empresa_e_recusado(): void
    {
        $outra = Tenant::factory()->create();
        app(TenantContext::class)->set($outra->id);
        $alheio = Produto::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);
        $negocio = Negocio::factory()->create(['funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id, 'responsavel_id' => $this->admin->id]);

        $this->expectException(RegraDoCrmException::class);

        app(DefinirItensDoNegocio::class)->executar($this->admin, $negocio, [['tipo' => 'PRODUTO', 'item_id' => $alheio->id, 'quantidade' => 1]]);
    }

    public function test_vendedor_nao_atribui_outro_responsavel(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $outro = $this->usuarioDoPapel(Papel::Vendedor);

        $this->expectException(AcessoNegadoException::class);

        app(CriarNegocio::class)->executar($vendedor, $this->dados(['responsavel_id' => $outro->id]));
    }

    public function test_admin_atribui_responsavel_da_empresa_mas_nao_de_fora(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $negocio = app(CriarNegocio::class)->executar($this->admin, $this->dados(['responsavel_id' => $vendedor->id]));
        $this->assertSame($vendedor->id, $negocio->responsavel_id);

        $alheio = $this->usuarioDoPapel(Papel::Vendedor, Tenant::factory()->create());
        $this->expectException(RegraDoCrmException::class);
        app(CriarNegocio::class)->executar($this->admin, $this->dados(['responsavel_id' => $alheio->id]));
    }

    public function test_valor_digitado_so_vale_sem_itens(): void
    {
        $caneca = Produto::factory()->create(['preco_centavos' => 1000]);
        $negocio = app(CriarNegocio::class)->executar($this->admin, $this->dados(['itens' => [['tipo' => 'PRODUTO', 'item_id' => $caneca->id, 'quantidade' => 1]]]));

        $this->expectException(RegraDoCrmException::class);

        app(AtualizarNegocio::class)->executar($this->admin, $negocio, ['valor_centavos' => 5]);
    }

    public function test_negocio_fechado_nao_aceita_alteracao(): void
    {
        $negocio = Negocio::factory()->create([
            'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id,
            'responsavel_id' => $this->admin->id, 'status' => StatusDoNegocio::Ganho,
        ]);

        $this->expectException(RegraDoCrmException::class);

        app(AtualizarNegocio::class)->executar($this->admin, $negocio, ['titulo' => 'Novo']);
    }
}
