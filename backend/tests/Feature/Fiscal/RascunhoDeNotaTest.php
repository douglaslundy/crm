<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\AtualizarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\ExcluirRascunhoDeNota;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class RascunhoDeNotaTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    /** @return array<string, mixed> */
    private function dados(): array
    {
        return [
            'cliente_id' => $this->cliente->id,
            'consumidor_final' => null,
            'desconto_centavos' => 100,
            'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '2.5', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 2400]],
        ];
    }

    public function test_cria_rascunho_com_totais_e_preco_do_cadastro(): void
    {
        $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertSame(2500, $nota->subtotal_centavos);
        $this->assertSame(2400, $nota->total_centavos);
        $item = $nota->itens()->first();
        $this->assertSame(2500, $item->quantidade_milesimos);
        $this->assertSame(1000, $item->valor_unitario_centavos);
        $this->assertSame(1, $nota->pagamentos()->count());
        $this->assertSame(1, $nota->eventos()->count());
    }

    public function test_preco_informado_prevalece_sobre_o_do_cadastro(): void
    {
        $dados = $this->dados();
        $dados['itens'][0]['valor_unitario_centavos'] = 800;
        $nota = app(CriarRascunhoDeNota::class)->executar($dados, $this->admin);
        $this->assertSame(800, $nota->itens()->first()->valor_unitario_centavos);
    }

    public function test_atualiza_substituindo_itens_e_pagamentos(): void
    {
        $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
        $dados = $this->dados();
        $dados['desconto_centavos'] = 0;
        $dados['itens'][0]['quantidade'] = '1';
        $dados['pagamentos'] = [['tpag' => '17', 'xpag' => null, 'valor_centavos' => 1000]];

        $nota = app(AtualizarRascunhoDeNota::class)->executar($nota, $dados, $this->admin);

        $this->assertSame(1000, $nota->total_centavos);
        $this->assertSame(1, $nota->itens()->count());
        $this->assertSame('17', $nota->pagamentos()->first()->tpag);
    }

    public function test_nota_autorizada_ou_processando_nao_e_editavel(): void
    {
        foreach ([StatusNota::Autorizada, StatusNota::Processando, StatusNota::Cancelada] as $status) {
            $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
            $nota->forceFill(['status' => $status])->save();
            try {
                app(AtualizarRascunhoDeNota::class)->executar($nota, $this->dados(), $this->admin);
                $this->fail('Esperava NotaNaoEditavelException.');
            } catch (NotaNaoEditavelException $e) {
                $this->assertSame('NOTA_NAO_EDITAVEL', $e->codigo());
            }
        }
    }

    public function test_rejeitada_e_erro_voltam_a_ser_editaveis(): void
    {
        foreach ([StatusNota::Rejeitada, StatusNota::Erro] as $status) {
            $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
            $nota->forceFill(['status' => $status])->save();
            $atualizada = app(AtualizarRascunhoDeNota::class)->executar($nota, $this->dados(), $this->admin);
            $this->assertSame($status, $atualizada->status);
        }
    }

    public function test_so_rascunho_pode_ser_excluido(): void
    {
        $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
        app(ExcluirRascunhoDeNota::class)->executar($nota);
        $this->assertSame(0, Nota::query()->count());

        $outra = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
        $outra->forceFill(['status' => StatusNota::Rejeitada])->save();
        $this->expectException(NotaNaoEditavelException::class);
        app(ExcluirRascunhoDeNota::class)->executar($outra);
    }

    public function test_vendedor_rascunha_e_admin_emite(): void
    {
        $politica = app(PoliticaDeNotas::class);
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $politica->garantirPodeRascunhar($vendedor);
        $politica->garantirPodeEmitir($this->admin);
        $this->addToAssertionCount(2);
    }

    public function test_vendedor_nao_emite(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->expectException(AcessoNegadoException::class);
        app(PoliticaDeNotas::class)->garantirPodeEmitir($vendedor);
    }

    public function test_leitura_nao_rascunha(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->expectException(AcessoNegadoException::class);
        app(PoliticaDeNotas::class)->garantirPodeRascunhar($leitura);
    }
}
