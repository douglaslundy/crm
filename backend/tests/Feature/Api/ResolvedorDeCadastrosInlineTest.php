<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Application\ResolvedorDeCadastrosInline;
use App\Modules\Fiscal\Domain\Exceptions\CadastroDivergenteException;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class ResolvedorDeCadastrosInlineTest extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    private ResolvedorDeCadastrosInline $resolvedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi([Recurso::Servicos->value => 10]);
        $this->resolvedor = app(ResolvedorDeCadastrosInline::class);
    }

    /** @return array<string, mixed> */
    private function produto(array $extra = []): array
    {
        return $extra + ['sku' => 'P-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500, 'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL'];
    }

    private function campos(CadastroDivergenteException $e): array
    {
        return array_column($e->extras()['errors'], 'campo');
    }

    public function test_produto_novo_e_cadastrado(): void
    {
        $produto = $this->resolvedor->produto($this->admin, $this->produto());

        $this->assertSame('P-1', $produto->sku);
        $this->assertSame(0, $produto->origem);
        $this->assertSame(1, Produto::query()->count());
    }

    public function test_produto_novo_sem_origem_fica_pendente_e_zero_e_gravado(): void
    {
        $semOrigem = $this->resolvedor->produto($this->admin, ['sku' => 'P-2', 'nome' => 'X', 'unidade' => 'UN', 'preco_centavos' => 1]);
        $comZero = $this->resolvedor->produto($this->admin, $this->produto(['sku' => 'P-3']));

        $this->assertNull($semOrigem->origem);
        $this->assertTrue($semOrigem->pendenteDeRevisaoFiscal());
        $this->assertSame(0, $comZero->origem);
    }

    public function test_produto_existente_igual_ou_omitido_devolve_o_mesmo(): void
    {
        $existente = Produto::factory()->completo()->for($this->tenant)->create(['sku' => 'P-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500, 'ncm' => '73181500']);

        $this->assertSame($existente->id, $this->resolvedor->produto($this->admin, $this->produto())->id);
        $this->assertSame($existente->id, $this->resolvedor->produto($this->admin, ['sku' => 'P-1'])->id);
        $this->assertSame(1, Produto::query()->count());
    }

    public function test_produto_existente_com_dado_divergente_recusa_e_nao_altera(): void
    {
        $existente = Produto::factory()->completo()->for($this->tenant)->create(['sku' => 'P-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500, 'ncm' => '73181500']);

        try {
            $this->resolvedor->produto($this->admin, $this->produto(['preco_centavos' => 600, 'ncm' => '99999999', 'origem' => 1, 'nome' => 'Outro']));
            $this->fail('deveria recusar');
        } catch (CadastroDivergenteException $e) {
            $this->assertSame('CADASTRO_DIVERGENTE', $e->codigo());
            $this->assertSame(422, $e->status());
            $this->assertEqualsCanonicalizing(['preco_centavos', 'ncm', 'origem', 'nome'], $this->campos($e));
            $preco = collect($e->extras()['errors'])->firstWhere('campo', 'preco_centavos');
            $this->assertSame(['campo' => 'preco_centavos', 'enviado' => 600, 'cadastro' => 500], $preco);
        }
        $fresco = $existente->fresh();
        $this->assertSame([500, '73181500', 0, 'Parafuso'], [$fresco?->preco_centavos, $fresco?->ncm, $fresco?->origem, $fresco?->nome]);
    }

    public function test_origem_zero_enviada_contra_cadastro_sem_origem_diverge(): void
    {
        Produto::factory()->for($this->tenant)->create(['sku' => 'P-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500, 'origem' => null]);

        try {
            $this->resolvedor->produto($this->admin, ['sku' => 'P-1', 'origem' => 0]);
            $this->fail('0 não é o mesmo que ausente');
        } catch (CadastroDivergenteException $e) {
            $this->assertSame(['origem'], $this->campos($e));
        }
    }

    public function test_produto_gtin_cest_e_tributacao_divergentes(): void
    {
        Produto::factory()->completo()->for($this->tenant)->create(['sku' => 'P-1', 'gtin' => '7891234567895', 'cest' => '1234567', 'tributacao_icms' => 'NORMAL']);

        try {
            $this->resolvedor->produto($this->admin, ['sku' => 'P-1', 'gtin' => '1234567890123', 'cest' => '7654321', 'tributacao_icms' => 'ST']);
            $this->fail('deveria recusar');
        } catch (CadastroDivergenteException $e) {
            $this->assertEqualsCanonicalizing(['gtin', 'cest', 'tributacao_icms'], $this->campos($e));
        }
    }

    public function test_produto_novo_sem_campos_obrigatorios_da_criacao_da_422_de_validacao(): void
    {
        try {
            $this->resolvedor->produto($this->admin, ['sku' => 'P-9']);
            $this->fail('deveria recusar');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['produto.nome', 'produto.unidade', 'produto.preco_centavos'], array_keys($e->errors()));
        }
        $this->assertSame(0, Produto::query()->count());
    }

    public function test_produto_de_outro_tenant_com_o_mesmo_sku_nao_e_achado(): void
    {
        $this->emOutroTenant(fn ($outro) => Produto::factory()->completo()->for($outro)->create(['sku' => 'P-1', 'preco_centavos' => 999]));

        $produto = $this->resolvedor->produto($this->admin, $this->produto());

        $this->assertSame(500, $produto->preco_centavos);
        $this->assertSame($this->tenant->id, $produto->tenant_id);
    }

    public function test_limite_do_plano_barra_o_cadastro_inline(): void
    {
        $this->tenant->plano?->limites()->where('recurso', Recurso::Produtos->value)->update(['limite' => 1]);
        $this->tenant->unsetRelation('plano');
        $this->resolvedor->produto($this->admin->fresh() ?? $this->admin, $this->produto());

        $this->expectException(LimiteDoPlanoAtingidoException::class);
        $this->resolvedor->produto($this->admin->fresh() ?? $this->admin, $this->produto(['sku' => 'P-2']));
    }

    public function test_servico_novo_existente_e_divergente(): void
    {
        $novo = $this->resolvedor->servico($this->admin, ['codigo' => 'S-1', 'nome' => 'Consultoria', 'preco_centavos' => 10000, 'codigo_lc116' => '14.01', 'c_trib_nac' => '140101', 'aliquota_iss' => '5']);
        $this->assertSame('5.00', $novo->aliquota_iss);

        $igual = $this->resolvedor->servico($this->admin, ['codigo' => 'S-1', 'aliquota_iss' => '5.00', 'nome' => 'Consultoria']);
        $this->assertSame($novo->id, $igual->id);
        $this->assertSame(1, Servico::query()->count());

        try {
            $this->resolvedor->servico($this->admin, ['codigo' => 'S-1', 'aliquota_iss' => '2.50', 'c_trib_nac' => '999999']);
            $this->fail('deveria recusar');
        } catch (CadastroDivergenteException $e) {
            $this->assertEqualsCanonicalizing(['aliquota_iss', 'c_trib_nac'], $this->campos($e));
        }
        $this->assertSame('5.00', $novo->fresh()?->aliquota_iss);
    }

    public function test_servico_novo_sem_nome_ou_preco_da_422(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolvedor->servico($this->admin, ['codigo' => 'S-9']);
    }

    public function test_cliente_novo_e_achado_pelo_documento_mesmo_com_mascara(): void
    {
        $novo = $this->resolvedor->cliente($this->admin, ['tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.444.777-35']);
        $this->assertSame('11144477735', $novo->cpf_cnpj);
        $this->assertSame('CLIENTE', $novo->estagio->value);

        $mesmo = $this->resolvedor->cliente($this->admin, ['cpf_cnpj' => '11144477735']);
        $this->assertSame($novo->id, $mesmo->id);
        $this->assertSame(1, Cliente::query()->count());
    }

    public function test_cliente_existente_com_endereco_ou_nome_diferente_diverge(): void
    {
        $this->resolvedor->cliente($this->admin, ['tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '11144477735', 'uf' => 'SP', 'cep' => '01001000']);

        try {
            $this->resolvedor->cliente($this->admin, ['tipo' => 'PF', 'nome' => 'Maria Souza', 'cpf_cnpj' => '11144477735', 'uf' => 'RJ', 'cep' => '01001-000']);
            $this->fail('deveria recusar');
        } catch (CadastroDivergenteException $e) {
            $this->assertEqualsCanonicalizing(['nome', 'uf'], $this->campos($e));
        }
    }

    public function test_cliente_novo_sem_tipo_ou_nome_da_422(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolvedor->cliente($this->admin, ['cpf_cnpj' => '11144477735']);
    }

    public function test_cliente_de_outro_tenant_com_o_mesmo_documento_nao_e_achado(): void
    {
        $this->emOutroTenant(fn ($outro) => Cliente::factory()->for($outro)->create(['cpf_cnpj' => '11144477735', 'nome' => 'Alheio']));

        $cliente = $this->resolvedor->cliente($this->admin, ['tipo' => 'PF', 'nome' => 'Meu', 'cpf_cnpj' => '11144477735']);

        $this->assertSame('Meu', $cliente->nome);
        $this->assertSame($this->tenant->id, $cliente->tenant_id);
    }
}
