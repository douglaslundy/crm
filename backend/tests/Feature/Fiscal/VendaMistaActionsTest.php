<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\AtualizarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\AtualizarVendaMista;
use App\Modules\Fiscal\Application\Actions\CriarVendaMista;
use App\Modules\Fiscal\Application\Actions\ExcluirRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\ExcluirVendaMista;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Exceptions\VendaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeVenda;
use Tests\TestCase;

class VendaMistaActionsTest extends TestCase
{
    use CenarioDeVenda;
    use RefreshDatabase;

    /** @param  array<string, mixed>  $sobrescrever */
    private function criar(array $sobrescrever = []): Venda
    {
        return app(CriarVendaMista::class)->executar($this->corpoVenda($sobrescrever), $this->admin);
    }

    public function test_cria_a_venda_e_as_duas_notas_ligadas_com_o_desconto_rateado(): void
    {
        $this->montarCenarioVenda();

        $venda = $this->criar(['desconto_centavos' => 1600, 'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 900]]]);

        $this->assertSame([16000, 1600, 14400], [$venda->subtotal_centavos, $venda->desconto_centavos, $venda->total_centavos]);
        $notas = $venda->notas()->orderBy('modelo')->get();
        $this->assertSame(['DPS', 'NFE'], $notas->map(fn ($n) => $n->modelo->value)->all());
        $this->assertSame([1500, 100], $notas->pluck('desconto_centavos')->all());
        $this->assertSame([13500, 900], $notas->pluck('total_centavos')->all());
        $this->assertSame([$venda->id, $venda->id], $notas->pluck('venda_id')->all());
    }

    public function test_sem_servico_ou_sem_produto_bloqueia(): void
    {
        $this->montarCenarioVenda();
        foreach (['itens_servicos', 'itens_produtos'] as $lado) {
            try {
                $this->criar([$lado => []]);
                $this->fail('deveria bloquear');
            } catch (EmissaoBloqueadaException $e) {
                $this->assertSame('FISCAL_VENDA_MISTA_INCOMPLETA', $e->codigo());
            }
        }
        $this->assertSame(0, Venda::query()->count());
        $this->assertSame(0, Nota::query()->count());
    }

    public function test_plano_sem_nfse_bloqueia_sem_criar_nada(): void
    {
        $this->montarCenarioVenda();
        $this->removerModuloNfse();

        try {
            $this->criar();
            $this->fail('deveria bloquear');
        } catch (ModuloNaoContratadoException) {
            $this->assertSame(0, Venda::query()->count());
            $this->assertSame(0, Nota::query()->count());
        }
    }

    public function test_atualizar_refaz_itens_e_rateio_das_duas_notas(): void
    {
        $this->montarCenarioVenda();
        $venda = $this->criar();

        $venda = app(AtualizarVendaMista::class)->executar($venda, $this->corpoVenda([
            'desconto_centavos' => 1600, 'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 900]],
        ]), $this->admin);

        $this->assertSame([16000, 1600, 14400], [$venda->subtotal_centavos, $venda->desconto_centavos, $venda->total_centavos]);
        $this->assertSame(2, $venda->notas()->count());
        $this->assertSame(2, NotaItem::query()->count());
    }

    public function test_atualizar_so_com_as_duas_notas_em_rascunho(): void
    {
        $this->montarCenarioVenda();
        $venda = $this->criar();
        $venda->notas()->where('modelo', 'DPS')->firstOrFail()->forceFill(['status' => StatusNota::Processando])->save();

        $this->expectException(VendaNaoEditavelException::class);

        app(AtualizarVendaMista::class)->executar($venda, $this->corpoVenda(), $this->admin);
    }

    public function test_excluir_remove_a_venda_e_as_duas_notas(): void
    {
        $this->montarCenarioVenda();
        $venda = $this->criar();

        app(ExcluirVendaMista::class)->executar($venda, $this->admin);

        $this->assertSame(0, Venda::query()->count());
        $this->assertSame(0, Nota::query()->count());
    }

    public function test_excluir_so_com_as_duas_notas_em_rascunho(): void
    {
        $this->montarCenarioVenda();
        $venda = $this->criar();
        $venda->notas()->firstOrFail()->forceFill(['status' => StatusNota::Autorizada])->save();

        $this->expectException(VendaNaoEditavelException::class);

        app(ExcluirVendaMista::class)->executar($venda, $this->admin);
    }

    public function test_nota_da_venda_nao_se_edita_nem_se_exclui_pelas_acoes_de_nota(): void
    {
        $this->montarCenarioVenda();
        $nota = $this->criar()->notas()->firstOrFail();

        foreach ([
            fn () => app(AtualizarRascunhoDeNota::class)->executar($nota, ['desconto_centavos' => 0], $this->admin),
            fn () => app(ExcluirRascunhoDeNota::class)->executar($nota),
        ] as $acao) {
            try {
                $acao();
                $this->fail('deveria bloquear');
            } catch (EmissaoBloqueadaException $e) {
                $this->assertSame('FISCAL_NOTA_DA_VENDA', $e->codigo());
            }
        }
    }
}
