<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Vendas\GravadorDaVenda;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class GravadorDaVendaTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function dados(int $desconto, ?int $pagamento = null): array
    {
        return [
            'cliente_id' => $this->cliente->id, 'desconto_centavos' => $desconto, 'informacoes_complementares' => null,
            'itens_produtos' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'itens_servicos' => [['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => $pagamento ?? 1000 - intdiv($desconto * 1000, 16000)]],
        ];
    }

    /** @return array{0: Nota, 1: Nota} */
    private function rascunhos(): array
    {
        $criar = app(CriarRascunhoDeNota::class);
        $base = ['cliente_id' => $this->cliente->id, 'desconto_centavos' => 0];
        $p = $criar->executar($base + ['modelo' => 'NFE', 'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1']], 'pagamentos' => [['tpag' => '01', 'valor_centavos' => 1000]]], $this->admin);
        $s = $criar->executar($base + ['modelo' => 'DPS', 'itens' => [['servico_id' => $this->servico->id, 'quantidade' => '1']]], $this->admin);

        return [$p, $s];
    }

    public function test_ratea_o_desconto_em_proporcao_aos_subtotais_e_a_soma_fecha(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        $r = app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(1600));

        $this->assertSame(['subtotal' => 16000, 'desconto' => 1600, 'total' => 14400], $r);
        $this->assertSame([100, 1500], [$p->refresh()->desconto_centavos, $s->refresh()->desconto_centavos]);
        $this->assertSame([900, 13500], [$p->total_centavos, $s->total_centavos]);
    }

    public function test_residuo_de_um_centavo_vai_para_a_ultima_nota(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(1));

        $this->assertSame([0, 1], [$p->refresh()->desconto_centavos, $s->refresh()->desconto_centavos]);
    }

    public function test_desconto_zero_e_valido_e_nao_altera_as_notas(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(0));

        $this->assertSame([0, 0], [$p->refresh()->desconto_centavos, $s->refresh()->desconto_centavos]);
        $this->assertSame([1000, 15000], [$p->total_centavos, $s->total_centavos]);
    }

    public function test_desconto_maior_que_o_subtotal_bloqueia(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        $this->expectException(EmissaoBloqueadaException::class);

        app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(16001));
    }

    public function test_desconto_igual_ao_subtotal_bloqueia_porque_a_venda_ficaria_sem_valor(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        $this->expectException(EmissaoBloqueadaException::class);

        app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(16000));
    }

    public function test_pagamentos_que_nao_fecham_com_o_total_da_nfe_bloqueiam_ao_salvar(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        try {
            app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(1600, 1000));
            $this->fail('deveria bloquear');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_PAGAMENTO_DIVERGENTE', $e->codigo());
        }
    }

    public function test_servicos_com_aliquotas_diferentes_bloqueiam_ao_salvar(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();
        $outro = Servico::factory()->completo()->create(['aliquota_iss' => '2.00']);
        $dados = $this->dados(0);
        $dados['itens_servicos'][] = ['servico_id' => $outro->id, 'quantidade' => '1', 'valor_unitario_centavos' => null];

        try {
            app(GravadorDaVenda::class)->gravar($p, $s, $dados);
            $this->fail('deveria bloquear');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_SERVICOS_DIVERGENTES', $e->codigo());
        }
    }

    public function test_rateio_que_zera_uma_das_notas_bloqueia_ao_salvar(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();
        $dados = $this->dados(1000, 1);
        $dados['itens_servicos'][0]['valor_unitario_centavos'] = 1; // serviço de R$ 0,01: o resíduo do desconto o zera

        try {
            app(GravadorDaVenda::class)->gravar($p, $s, $dados);
            $this->fail('deveria bloquear');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_DESCONTO_INVALIDO', $e->codigo());
            $this->assertStringContainsString('NFS-e', $e->getMessage());
        }
    }

    public function test_executar_grava_o_venda_id_quando_informado(): void
    {
        $this->montarCenarioNfse();
        $venda = Venda::factory()->create(['cliente_id' => $this->cliente->id]);

        $nota = app(CriarRascunhoDeNota::class)->executar([
            'modelo' => 'DPS', 'cliente_id' => $this->cliente->id, 'desconto_centavos' => 0,
            'itens' => [['servico_id' => $this->servico->id, 'quantidade' => '1']],
        ], $this->admin, $venda->id);

        $this->assertSame($venda->id, $nota->venda_id);
    }
}
