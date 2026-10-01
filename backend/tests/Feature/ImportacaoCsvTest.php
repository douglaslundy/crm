<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacaoCsvTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $plano = Plano::factory()->comLimites([
            Recurso::Clientes->value => 10, Recurso::Produtos->value => 10, Recurso::Servicos->value => 10,
        ])->create();
        $this->tenant = Tenant::factory()->for($plano)->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    public function test_importa_clientes_criando_e_atualizando_por_cpf(): void
    {
        Cliente::factory()->comCpfValido()->for($this->tenant)->create(['cpf_cnpj' => '11144477735', 'nome' => 'Nome Antigo']);
        $csv = "tipo,nome,cpf_cnpj\nPF,Nome Novo,111.444.777-35\nPF,Outra Pessoa,\n";
        $arquivo = UploadedFile::fake()->createWithContent('clientes.csv', $csv);

        $resposta = $this->spa()->actingAs($this->admin)->post('/api/app/clientes/importar', ['arquivo' => $arquivo])->assertOk();

        $resposta->assertJsonPath('data.criados', 1)->assertJsonPath('data.atualizados', 1)->assertJsonPath('data.erros', []);
        $this->assertSame('Nome Novo', Cliente::query()->where('cpf_cnpj', '11144477735')->firstOrFail()->nome);
    }

    public function test_importa_produtos_reportando_linha_invalida_sem_derrubar_o_lote(): void
    {
        $csv = "sku,nome,unidade,preco_centavos\nSKU-1,Parafuso,UN,500\nSKU-2,,UN,500\n";
        $arquivo = UploadedFile::fake()->createWithContent('produtos.csv', $csv);

        $resposta = $this->spa()->actingAs($this->admin)->post('/api/app/produtos/importar', ['arquivo' => $arquivo])->assertOk();

        $resposta->assertJsonPath('data.criados', 1);
        $this->assertSame(3, $resposta->json('data.erros.0.linha'));
        $this->assertDatabaseHas('produtos', ['sku' => 'SKU-1']);
        $this->assertDatabaseMissing('produtos', ['sku' => 'SKU-2']);
    }

    public function test_importa_servicos_atualizando_por_nome(): void
    {
        Servico::factory()->for($this->tenant)->create(['nome' => 'Consultoria', 'preco_centavos' => 1000]);
        $csv = "nome,preco_centavos\nConsultoria,20000\n";
        $arquivo = UploadedFile::fake()->createWithContent('servicos.csv', $csv);

        $this->spa()->actingAs($this->admin)->post('/api/app/servicos/importar', ['arquivo' => $arquivo])
            ->assertOk()->assertJsonPath('data.atualizados', 1);

        $this->assertSame(20000, Servico::query()->where('nome', 'Consultoria')->firstOrFail()->preco_centavos);
    }

    public function test_importacao_respeita_o_limite_do_plano(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Produtos->value => 1]))->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $csv = "sku,nome,unidade,preco_centavos\nSKU-1,A,UN,100\nSKU-2,B,UN,100\n";
        $arquivo = UploadedFile::fake()->createWithContent('produtos.csv', $csv);

        $resposta = $this->spa()->actingAs($admin)->post('/api/app/produtos/importar', ['arquivo' => $arquivo])->assertOk();

        $resposta->assertJsonPath('data.criados', 1);
        $this->assertStringContainsString('plano', $resposta->json('data.erros.0.motivo'));
    }

    public function test_leitura_nao_importa(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        $arquivo = UploadedFile::fake()->createWithContent('clientes.csv', "tipo,nome\nPF,Ana\n");

        $this->spa()->actingAs($leitura)->post('/api/app/clientes/importar', ['arquivo' => $arquivo])->assertForbidden();
    }
}
