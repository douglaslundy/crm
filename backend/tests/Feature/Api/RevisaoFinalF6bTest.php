<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Fiscal\Application\Actions\EmitirNotaPelaApi;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Shared\Domain\Models\Idempotencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class RevisaoFinalF6bTest extends TestCase
{
    use CenarioDeApi;
    use CenarioDeNota;
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario(Modulo::Api, Modulo::FiscalNfe);
        foreach ([Recurso::ApiRequisicoesMin->value => -1, Recurso::Produtos->value => 100, Recurso::Clientes->value => 100] as $recurso => $limite) {
            $this->tenant->plano?->limites()->create(['recurso' => $recurso, 'limite' => $limite]);
        }
        [$this->chave, $this->textoDaChave] = $this->novaChave($this->admin, AmbienteDaChave::Test);
        Bus::fake();
    }

    private function emitir(array $corpo, ?string $texto = null, ?string $idem = null): TestResponse
    {
        $this->n++;

        return $this->comoApi($texto)->withHeaders(['Idempotency-Key' => $idem ?? 'r-'.$this->n])->postJson('/api/v1/notas', $corpo);
    }

    /** @return array<string, mixed> */
    private function corpo(array $extra = []): array
    {
        return array_replace([
            'modelo' => 'NFE', 'cliente' => ['id' => $this->cliente->id], 'desconto_centavos' => 0,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1']],
            'pagamentos' => [['tpag' => '01', 'valor_centavos' => 1000]],
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function produtoInline(array $extra = []): array
    {
        return $extra + ['sku' => 'G-1', 'nome' => 'Com GTIN', 'unidade' => 'UN', 'preco_centavos' => 1000, 'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL'];
    }

    public function test_c1_gtin_enviado_e_gravado_e_a_segunda_chamada_nao_diverge(): void
    {
        $corpo = $this->corpo(['itens' => [['produto' => $this->produtoInline(['gtin' => '7891234567895']), 'quantidade' => '1']]]);

        $this->emitir($corpo)->assertStatus(202);
        $this->assertSame('7891234567895', Produto::query()->where('sku', 'G-1')->firstOrFail()->gtin);

        $this->emitir($corpo)->assertStatus(202);
        $this->assertSame(1, Produto::query()->where('sku', 'G-1')->count());
    }

    public function test_i1_origem_invalida_e_422_e_nao_vira_zero(): void
    {
        foreach (['abc', true, [1], '9', -1, '1.5'] as $origem) {
            $this->emitir($this->corpo(['itens' => [['produto' => $this->produtoInline(['origem' => $origem]), 'quantidade' => '1']]]))
                ->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO');
        }
        $this->assertSame(0, Produto::query()->where('sku', 'G-1')->count());

        foreach ([0, '0', 8, '8'] as $origem) {
            $this->emitir($this->corpo(['itens' => [['produto' => $this->produtoInline(['sku' => "O-{$origem}", 'origem' => $origem]), 'quantidade' => '1']]]))->assertStatus(202);
        }
    }

    public function test_i1_o_app_tambem_recusa_origem_invalida(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/produtos', ['sku' => 'X', 'nome' => 'X', 'unidade' => 'UN', 'preco_centavos' => 1, 'origem' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('origem');
    }

    public function test_i2_blocos_vazios_ou_com_tipo_errado_sao_422_nunca_500(): void
    {
        $this->emitir($this->corpo(['itens' => [['produto' => [], 'quantidade' => '1']]]))->assertStatus(422);
        $this->emitir($this->corpo(['modelo' => 'DPS', 'itens' => [['servico' => [], 'quantidade' => '1']]]))->assertStatus(422);
        $this->emitir($this->corpo(['modelo' => ['x']]))->assertStatus(422);
        $this->emitir($this->corpo(['cliente' => ['cpf_cnpj' => ['1']]]))->assertStatus(422);
        $this->emitir($this->corpo(['cliente' => ['cpf_cnpj' => '52998224725', 'tipo' => ['PF']]]))->assertStatus(422);
        $this->emitir($this->corpo(['cliente' => 'texto']))->assertStatus(422);
        $this->emitir($this->corpo(['itens' => ['texto']]))->assertStatus(422);
        $this->assertSame(0, Nota::query()->count());
    }

    public function test_i3_ids_que_nao_sao_uuid_param_na_validacao_sem_consultar_o_banco(): void
    {
        $consultas = [];
        DB::listen(function ($q) use (&$consultas): void {
            if (str_contains($q->sql, 'from "clientes"') && str_contains($q->sql, '"id"')) {
                $consultas[] = $q->bindings;
            }
        });

        $this->emitir($this->corpo(['cliente' => ['id' => 'abc']]))->assertStatus(422);
        $this->emitir($this->corpo(['itens' => [['produto_id' => 'x', 'quantidade' => '1']]]))->assertStatus(422);

        $this->assertNotContains(['abc'], array_map(fn (array $b): array => array_slice($b, 0, 1), $consultas));
    }

    public function test_i4_reserva_com_nota_criada_nunca_e_descartada_e_aponta_a_nota(): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $reserva = new Idempotencia;
        $corpo = $this->corpo();
        $reserva->forceFill([
            'chave' => 'orfa', 'rota' => 'POST api/v1/notas', 'hash_do_corpo' => hash('sha256', 'api/v1/notas|'.json_encode($this->ordenado($corpo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'|[]'),
            'nota_id' => $nota->id, 'expira_em' => now()->addDay(),
        ])->save();
        $reserva->forceFill(['created_at' => now()->subSeconds(300)])->save();

        $this->emitir($corpo, null, 'orfa')->assertStatus(409)
            ->assertJsonPath('codigo', 'IDEMPOTENCIA_EM_ANDAMENTO')->assertJsonPath('nota_id', $nota->id);
        $this->assertSame(1, Idempotencia::query()->count());
    }

    public function test_i4_a_acao_avisa_o_id_da_nota_dentro_da_transacao_do_rascunho(): void
    {
        $capturado = null;
        $nota = app(EmitirNotaPelaApi::class)->executar($this->admin, AmbienteDaChave::Test, $this->corpo(), function (string $id) use (&$capturado): void {
            $capturado = $id;
        });

        $this->assertSame($nota->id, $capturado);
    }

    public function test_i5_chave_test_nao_cancela_nota_de_producao_nem_inutiliza_producao(): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Autorizada, 'ambiente' => AmbienteFiscal::Producao->value, 'serie' => '1', 'numero' => 7,
            'chave' => str_repeat('7', 44), 'protocolo' => '1', 'xml_autorizado' => '<nfeProc>x</nfeProc>', 'emitido_em' => now(),
        ])->save();
        $justificativa = 'Cancelamento por erro de digitação do valor';

        $this->comoApi()->postJson("/api/v1/notas/{$nota->id}/cancelar", ['justificativa' => $justificativa])
            ->assertStatus(422)->assertJsonPath('codigo', 'API_AMBIENTE_DIVERGENTE');
        $this->assertSame(StatusNota::Autorizada, $nota->fresh()?->status);

        $this->emitente->update(['ambiente_fiscal' => AmbienteFiscal::Producao]);
        EmitenteSerie::query()->update(['proximo_numero' => 10]);
        $this->comoApi()->postJson('/api/v1/notas/inutilizacoes', ['serie' => '1', 'numero_inicial' => 3, 'numero_final' => 5, 'justificativa' => 'Numeração perdida por queda do servidor'])
            ->assertStatus(422)->assertJsonPath('codigo', 'API_AMBIENTE_DIVERGENTE');
    }

    public function test_m3_a_query_string_faz_parte_do_hash_da_idempotencia(): void
    {
        $corpo = $this->corpo();
        $this->comoApi()->withHeaders(['Idempotency-Key' => 'q-1'])->postJson('/api/v1/notas?x=1', $corpo)->assertStatus(202);

        $this->comoApi()->withHeaders(['Idempotency-Key' => 'q-1'])->postJson('/api/v1/notas?x=2', $corpo)->assertStatus(409)
            ->assertJsonPath('codigo', 'IDEMPOTENCIA_CORPO_DIFERENTE');
    }

    public function test_m4_cliente_com_id_e_outros_campos_e_recusado(): void
    {
        $this->emitir($this->corpo(['cliente' => ['id' => $this->cliente->id, 'nome' => 'Outro']]))->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO');
    }

    /** @param array<string, mixed> $v @return array<string, mixed> */
    private function ordenado(array $v): array
    {
        $f = function (mixed $x) use (&$f): mixed {
            if (! is_array($x)) {
                return $x;
            }
            $x = array_map($f, $x);
            if (! array_is_list($x)) {
                ksort($x);
            }

            return $x;
        };

        return $f($v);
    }
}
