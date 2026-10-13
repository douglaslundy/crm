<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Crm\Domain\Contracts\CatalogoDeItens;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class ContratosDoCrmTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_cria_lead_pelo_contrato_de_clientes(): void
    {
        $id = app(ConsultaDeClientes::class)->criarLead($this->admin, 'Maria', '11999990000', null);

        $cliente = Cliente::query()->findOrFail($id);
        $this->assertSame(EstagioCliente::Lead, $cliente->estagio);
        $this->assertSame('Maria', $cliente->nome);
    }

    public function test_resumos_devolve_so_clientes_do_tenant(): void
    {
        $meu = Cliente::factory()->create();
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheio = Cliente::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $mapa = app(ConsultaDeClientes::class)->resumos([$meu->id, $alheio->id]);

        $this->assertSame([$meu->id], array_keys($mapa));
        $this->assertTrue($mapa[$meu->id]->ehLead);
    }

    public function test_converte_em_cliente(): void
    {
        $lead = Cliente::factory()->create();

        app(ConsultaDeClientes::class)->converterEmCliente($lead->id);

        $this->assertFalse(app(ConsultaDeClientes::class)->resumo($lead->id)?->ehLead);
    }

    public function test_descreve_produto_e_servico_com_preco_em_centavos(): void
    {
        $produto = Produto::factory()->create(['nome' => 'Caneca', 'preco_centavos' => 2500]);
        $servico = Servico::factory()->create(['nome' => 'Instalação', 'preco_centavos' => 9000]);
        $catalogo = app(CatalogoDeItens::class);

        $this->assertSame(2500, $catalogo->descrever(TipoDeItem::Produto, $produto->id)?->valorUnitarioCentavos);
        $this->assertSame('Instalação', $catalogo->descrever(TipoDeItem::Servico, $servico->id)?->descricao);
        $this->assertNull($catalogo->descrever(TipoDeItem::Produto, $servico->id));
    }

    public function test_diretorio_de_usuarios_respeita_o_tenant(): void
    {
        $meu = $this->usuarioDoPapel(Papel::Vendedor);
        $outro = Tenant::factory()->create();
        $alheio = $this->usuarioDoPapel(Papel::Vendedor, $outro);
        $diretorio = app(DiretorioDeUsuarios::class);

        $this->assertTrue($diretorio->ehDaEmpresa($meu->id));
        $this->assertFalse($diretorio->ehDaEmpresa($alheio->id));
        $this->assertSame([$meu->id => $meu->nome], $diretorio->nomes([$meu->id, $alheio->id]));
    }
}
