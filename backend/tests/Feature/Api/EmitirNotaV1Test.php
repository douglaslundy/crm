<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Application\Jobs\EmitirNfseJob;
use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class EmitirNotaV1Test extends TestCase
{
    use CenarioDeApi;
    use CenarioDeNfse;
    use RefreshDatabase;

    private int $chaveN = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse(Modulo::Api, Modulo::FiscalNfe, Modulo::FiscalNfce);
        $plano = $this->tenant->plano;
        foreach ([Recurso::ApiRequisicoesMin->value => -1, Recurso::Produtos->value => 100, Recurso::Clientes->value => 100, Recurso::Servicos->value => 100] as $recurso => $limite) {
            $plano?->limites()->create(['recurso' => $recurso, 'limite' => $limite]);
        }
        $plano?->unsetRelation('limites');
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfce, 'serie' => '1', 'proximo_numero' => 1]);
        $this->emitente->update(['csc_id_homologacao' => '000001', 'csc_token_homologacao_encrypted' => Crypt::encryptString('TOKEN-DE-TESTE')]);
        [$this->chave, $this->textoDaChave] = $this->novaChave($this->admin, AmbienteDaChave::Test);
        Bus::fake();
    }

    private function emitir(array $corpo, ?string $texto = null, ?string $idem = null): TestResponse
    {
        $this->chaveN++;

        return $this->comoApi($texto)->withHeaders(['Idempotency-Key' => $idem ?? 'k-'.$this->chaveN])->postJson('/api/v1/notas', $corpo);
    }

    /** @return array<string, mixed> */
    private function corpoNfe(array $extra = []): array
    {
        return array_replace([
            'modelo' => 'NFE', 'cliente' => ['id' => $this->cliente->id], 'desconto_centavos' => 0,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1']],
            'pagamentos' => [['tpag' => '01', 'valor_centavos' => 1000]],
        ], $extra);
    }

    public function test_nfe_por_referencia_devolve_202_aloca_numero_e_despacha_o_job(): void
    {
        $resposta = $this->emitir($this->corpoNfe())->assertStatus(202)
            ->assertJsonPath('data.status', 'PROCESSANDO')->assertJsonPath('data.modelo', 'NFE');

        $nota = Nota::query()->findOrFail($resposta->json('data.id'));
        $this->assertSame(1, $nota->numero);
        $this->assertSame(AmbienteFiscal::Homologacao->value, $nota->ambiente);
        Bus::assertDispatched(EmitirNotaJob::class, fn (EmitirNotaJob $j): bool => $j->notaId === $nota->id);
        $this->assertSame($this->admin->id, NotaEvento::query()->where('nota_id', $nota->id)->latest('created_at')->firstOrFail()->usuario_id);
    }

    public function test_nfe_com_produto_e_cliente_inline_cadastra_os_dois_e_reaproveita_na_segunda_vez(): void
    {
        $corpo = $this->corpoNfe([
            'cliente' => ['tipo' => 'PF', 'nome' => 'João Inline', 'cpf_cnpj' => '529.982.247-25', 'logradouro' => 'Rua B', 'numero' => '5', 'bairro' => 'Centro', 'cidade' => 'São Paulo', 'uf' => 'SP', 'cep' => '01001000', 'codigo_ibge' => '3550308'],
            'itens' => [['produto' => ['sku' => 'NOVO-1', 'nome' => 'Item Novo', 'unidade' => 'un', 'preco_centavos' => 1000, 'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL'], 'quantidade' => '1']],
        ]);

        $this->emitir($corpo)->assertStatus(202);
        $this->assertSame(1, Produto::query()->where('sku', 'NOVO-1')->count());
        $this->assertSame(1, Cliente::query()->where('cpf_cnpj', '52998224725')->count());

        $this->emitir($corpo)->assertStatus(202);
        $this->assertSame(1, Produto::query()->where('sku', 'NOVO-1')->count());
        $this->assertSame(1, Cliente::query()->where('cpf_cnpj', '52998224725')->count());
        $this->assertSame(2, Nota::query()->count());
    }

    public function test_nfce_sem_cliente(): void
    {
        $this->emitir($this->corpoNfe(['modelo' => 'NFCE', 'cliente' => null]))->assertStatus(202)->assertJsonPath('data.modelo', 'NFCE');
    }

    public function test_nfse_com_servico_inline(): void
    {
        $resposta = $this->emitir([
            'modelo' => 'DPS', 'cliente' => ['id' => $this->cliente->id], 'desconto_centavos' => 0,
            'itens' => [['servico' => ['codigo' => 'S-1', 'nome' => 'Consultoria', 'preco_centavos' => 20000, 'codigo_lc116' => '14.01', 'c_trib_nac' => '140101', 'aliquota_iss' => '5'], 'quantidade' => '1']],
        ])->assertStatus(202)->assertJsonPath('data.modelo', 'DPS');

        $this->assertNotNull(Servico::query()->where('codigo', 'S-1')->first());
        Bus::assertDispatched(EmitirNfseJob::class, fn (EmitirNfseJob $j): bool => $j->notaId === $resposta->json('data.id'));
    }

    public function test_sem_idempotency_key_e_400(): void
    {
        $this->comoApi()->postJson('/api/v1/notas', $this->corpoNfe())->assertStatus(400)->assertJsonPath('codigo', 'IDEMPOTENCY_KEY_AUSENTE');
    }

    public function test_replay_devolve_a_mesma_resposta_e_nao_cria_outra_nota(): void
    {
        $primeira = $this->emitir($this->corpoNfe(), null, 'mesma')->assertStatus(202);
        $segunda = $this->emitir($this->corpoNfe(), null, 'mesma')->assertStatus(202);

        $this->assertSame('true', $segunda->headers->get('Idempotent-Replayed'));
        $this->assertSame($primeira->json('data.id'), $segunda->json('data.id'));
        $this->assertSame(1, Nota::query()->count());
        Bus::assertDispatchedTimes(EmitirNotaJob::class, 1);
        $this->emitir($this->corpoNfe(['desconto_centavos' => 1]), null, 'mesma')->assertStatus(409)->assertJsonPath('codigo', 'IDEMPOTENCIA_CORPO_DIFERENTE');
    }

    public function test_produto_inline_sem_origem_bloqueia_e_nao_deixa_nada_para_tras(): void
    {
        $produtos = Produto::query()->count();
        $clientes = Cliente::query()->count();
        $corpo = $this->corpoNfe([
            'cliente' => ['tipo' => 'PF', 'nome' => 'Novo Cliente', 'cpf_cnpj' => '529.982.247-25', 'logradouro' => 'Rua B', 'numero' => '5', 'bairro' => 'Centro', 'cidade' => 'São Paulo', 'uf' => 'SP', 'cep' => '01001000', 'codigo_ibge' => '3550308'],
            'itens' => [['produto' => ['sku' => 'SEM-ORIGEM', 'nome' => 'Sem origem', 'unidade' => 'UN', 'preco_centavos' => 1000, 'ncm' => '73181500', 'tributacao_icms' => 'NORMAL'], 'quantidade' => '1']],
        ]);

        $this->emitir($corpo)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_PRODUTO_INCOMPLETO');

        $this->assertSame(0, Nota::query()->count());
        $this->assertSame($produtos, Produto::query()->count());
        $this->assertSame($clientes, Cliente::query()->count());
        $this->assertSame(1, EmitenteSerie::query()->where('modelo', ModeloDocumento::Nfe)->firstOrFail()->proximo_numero);
        Bus::assertNothingDispatched();
    }

    public function test_origem_zero_emite(): void
    {
        $this->emitir($this->corpoNfe(['itens' => [['produto' => ['sku' => 'ZERO', 'nome' => 'Origem zero', 'unidade' => 'UN', 'preco_centavos' => 1000, 'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL'], 'quantidade' => '1']]]))
            ->assertStatus(202);
    }

    public function test_inline_divergente_recusa_sem_criar_nada(): void
    {
        $corpo = $this->corpoNfe(['itens' => [['produto' => ['sku' => $this->produto->sku, 'preco_centavos' => 99999], 'quantidade' => '1']]]);

        $this->emitir($corpo)->assertStatus(422)->assertJsonPath('codigo', 'CADASTRO_DIVERGENTE')->assertJsonPath('errors.0.campo', 'preco_centavos');

        $this->assertSame(0, Nota::query()->count());
        $this->assertSame(1000, $this->produto->fresh()?->preco_centavos);
    }

    public function test_item_novo_sem_campos_obrigatorios_da_422_de_validacao_com_o_caminho(): void
    {
        $resposta = $this->emitir($this->corpoNfe(['itens' => [['produto' => ['sku' => 'INCOMPLETO'], 'quantidade' => '1']]]))
            ->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO');

        $this->assertContains('itens.0.produto.nome', array_column($resposta->json('errors'), 'campo'));
    }

    public function test_limite_de_produtos_do_plano_barra_o_inline(): void
    {
        $this->tenant->plano?->limites()->where('recurso', Recurso::Produtos->value)->update(['limite' => 1]);

        $this->emitir($this->corpoNfe(['itens' => [['produto' => ['sku' => 'EXTRA', 'nome' => 'Extra', 'unidade' => 'UN', 'preco_centavos' => 1, 'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL'], 'quantidade' => '1']]]))
            ->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_DO_PLANO');
        $this->assertSame(0, Nota::query()->count());
    }

    public function test_chave_test_com_emitente_em_producao_e_chave_live_em_homologacao_divergem(): void
    {
        [, $textoLive] = $this->novaChave($this->admin, AmbienteDaChave::Live);
        $this->emitir($this->corpoNfe())->assertStatus(202);

        $this->emitente->update(['ambiente_fiscal' => AmbienteFiscal::Producao]);
        $this->emitir($this->corpoNfe())->assertStatus(422)->assertJsonPath('codigo', 'API_AMBIENTE_DIVERGENTE');

        $this->emitente->update(['ambiente_fiscal' => AmbienteFiscal::Homologacao]);
        $this->emitir($this->corpoNfe(), $textoLive)->assertStatus(422)->assertJsonPath('codigo', 'API_AMBIENTE_DIVERGENTE');
        $this->assertSame(1, Nota::query()->count());
    }

    public function test_chave_live_com_emitente_em_producao_passa_no_ambiente(): void
    {
        $this->emitente->update(['ambiente_fiscal' => AmbienteFiscal::Producao]);

        // Passou da checagem de ambiente; a produção ainda exige CSC/certificado etc., que o app bloqueia.
        [, $textoLive] = $this->novaChave($this->admin, AmbienteDaChave::Live);
        $resposta = $this->emitir($this->corpoNfe(), $textoLive);
        $this->assertNotSame('API_AMBIENTE_DIVERGENTE', $resposta->json('codigo'));
    }

    public function test_modulo_do_plano_ausente_e_conta_suspensa(): void
    {
        $this->tenant->plano?->modulos()->where('modulo', Modulo::FiscalNfce->value)->delete();
        $this->tenant->unsetRelation('plano');
        $this->emitir($this->corpoNfe(['modelo' => 'NFCE', 'cliente' => null]))->assertStatus(403)->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');

        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        $this->emitir($this->corpoNfe())->assertStatus(403)->assertJsonPath('codigo', 'ASSINATURA_SEM_ESCRITA');
        $this->assertSame(0, Nota::query()->count());
    }

    public function test_validacao_dos_itens_e_do_cliente(): void
    {
        $base = $this->corpoNfe();
        $casos = [
            'produto_id e produto juntos' => [['produto_id' => $this->produto->id, 'produto' => ['sku' => 'X'], 'quantidade' => '1'], 'itens.0'],
            'item sem produto' => [['quantidade' => '1'], 'itens.0'],
            'servico em nota de produto' => [['servico_id' => $this->servico->id, 'quantidade' => '1'], 'itens.0.servico_id'],
            'quantidade mal formada' => [['produto_id' => $this->produto->id, 'quantidade' => 'abc'], 'itens.0.quantidade'],
        ];
        foreach ($casos as $nome => [$item, $campo]) {
            $resposta = $this->emitir(array_replace($base, ['itens' => [$item]]))->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO');
            $this->assertTrue(collect($resposta->json('errors'))->contains(fn (array $e): bool => str_starts_with($e['campo'], $campo)), $nome);
        }

        $this->emitir(array_replace($base, ['itens' => []]))->assertStatus(422);
        $this->emitir(array_replace($base, ['cliente' => null]))->assertStatus(422);
        $this->emitir(array_replace($base, ['cliente' => ['cpf_cnpj' => '123']]))->assertStatus(422);
        $this->emitir(array_replace($base, ['modelo' => 'DPS']))->assertStatus(422);
        $this->assertSame(0, Nota::query()->count());
    }

    public function test_ids_de_outro_tenant_nao_sao_aceitos(): void
    {
        $alheio = $this->emOutroTenant(fn ($outro) => [Produto::factory()->completo()->for($outro)->create(), Cliente::factory()->cliente()->for($outro)->create()]);

        $this->emitir($this->corpoNfe(['itens' => [['produto_id' => $alheio[0]->id, 'quantidade' => '1']]]))->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO');
        $this->emitir($this->corpoNfe(['cliente' => ['id' => $alheio[1]->id]]))->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO');
        $this->assertSame(0, Nota::query()->count());
    }
}
