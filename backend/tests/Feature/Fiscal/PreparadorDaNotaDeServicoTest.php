<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Application\Emissao\PreparadorDaNotaDeServico;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class PreparadorDaNotaDeServicoTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
    }

    private function preparar(Nota $nota): void
    {
        app(PreparadorDaNotaDeServico::class)->preparar($nota->refresh(), $this->emitente);
    }

    private function assertBloqueio(string $codigo, Nota $nota): void
    {
        try {
            $this->preparar($nota);
            $this->fail("Deveria bloquear com {$codigo}.");
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame($codigo, $e->codigo());
        }
    }

    public function test_congela_o_tomador_o_snapshot_do_servico_e_os_totais(): void
    {
        $nota = $this->rascunhoDeNfse(['desconto_centavos' => 1000]);

        $this->preparar($nota);

        $nota = $nota->refresh();
        $this->assertSame(['tipo' => 'PF', 'documento' => preg_replace('/\D/', '', (string) $this->cliente->cpf_cnpj), 'nome' => 'Maria Teste'], $nota->destinatario);
        $this->assertSame(15000, $nota->subtotal_centavos);
        $this->assertSame(14000, $nota->total_centavos);
        $item = $nota->itens()->first();
        $this->assertSame('Troca de óleo', $item->descricao);
        $this->assertSame('140101', $item->c_trib_nac);
        $this->assertSame(1000, $item->desconto_centavos);
        $this->assertSame(15000, $item->total_centavos);
    }

    public function test_tomador_sem_documento_bloqueia(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->cliente->forceFill(['cpf_cnpj' => null])->save();

        $this->assertBloqueio('FISCAL_TOMADOR_INCOMPLETO', $nota);
    }

    public function test_servico_sem_codigo_de_6_digitos_ou_sem_aliquota_bloqueia(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->servico->forceFill(['c_trib_nac' => '14.01'])->save();
        $this->assertBloqueio('FISCAL_SERVICO_INCOMPLETO', $nota);

        $this->servico->forceFill(['c_trib_nac' => '140101', 'aliquota_iss' => null])->save();
        $this->assertBloqueio('FISCAL_SERVICO_INCOMPLETO', $nota);
    }

    public function test_aliquota_zero_e_valida(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->servico->forceFill(['aliquota_iss' => '0.00'])->save();

        $this->preparar($nota);

        $this->assertSame('0.00', $nota->itens()->first()->aliquota_iss);
    }

    public function test_servicos_com_codigos_ou_aliquotas_diferentes_bloqueiam(): void
    {
        $outro = Servico::factory()->completo()->create(['nome' => 'Lavagem', 'c_trib_nac' => '140201', 'preco_centavos' => 15000]);
        $nota = $this->rascunhoDeNfse(['itens' => [
            ['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null],
            ['servico_id' => $outro->id, 'quantidade' => '1', 'valor_unitario_centavos' => null],
        ]]);
        $this->assertBloqueio('FISCAL_SERVICOS_DIVERGENTES', $nota);

        $outro->forceFill(['c_trib_nac' => '140101', 'aliquota_iss' => '2.00'])->save();
        $this->assertBloqueio('FISCAL_SERVICOS_DIVERGENTES', $nota);

        $outro->forceFill(['aliquota_iss' => '5.00'])->save();
        $this->preparar($nota);
        $this->assertSame(30000, $nota->refresh()->subtotal_centavos);
    }

    public function test_descricao_composta_acima_de_2000_bloqueia(): void
    {
        $this->servico->forceFill(['nome' => str_repeat('a', 150)])->save();
        $itens = array_fill(0, 14, ['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]);
        $nota = $this->rascunhoDeNfse(['itens' => $itens]);

        $this->assertBloqueio('FISCAL_DESCRICAO_LONGA', $nota);
    }

    public function test_informacoes_complementares_acima_de_255_bloqueiam(): void
    {
        $nota = $this->rascunhoDeNfse(['informacoes_complementares' => str_repeat('x', 256)]);

        $this->assertBloqueio('FISCAL_INFO_COMPLEMENTAR_LONGA', $nota);
    }

    public function test_desconto_que_zera_ou_passa_do_total_bloqueia(): void
    {
        $nota = $this->rascunhoDeNfse(['desconto_centavos' => 15000]);

        $this->assertBloqueio('FISCAL_DESCONTO_INVALIDO', $nota);
    }
}
