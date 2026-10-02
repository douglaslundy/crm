<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Fiscal\Application\Emissao\PreparadorDaNota;
use App\Modules\Fiscal\Application\Emissao\VerificadorDoEmitente;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\NotaPagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class BloqueiosDeEmissaoTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function nota(int $quantidadeMilesimos = 1000, int $unitario = 1000, int $desconto = 0, ?int $pagamento = null): Nota
    {
        $nota = Nota::factory()->create([
            'emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id, 'desconto_centavos' => $desconto,
        ]);
        $total = intdiv($quantidadeMilesimos * $unitario + 500, 1000);
        NotaItem::factory()->create([
            'nota_id' => $nota->id, 'produto_id' => $this->produto->id, 'ordem' => 1,
            'quantidade_milesimos' => $quantidadeMilesimos, 'valor_unitario_centavos' => $unitario, 'total_centavos' => $total,
        ]);
        NotaPagamento::query()->create(['nota_id' => $nota->id, 'tpag' => '01', 'valor_centavos' => $pagamento ?? ($total - $desconto)]);

        return $nota->refresh();
    }

    private function preparar(Nota $nota): void
    {
        app(PreparadorDaNota::class)->preparar($nota, $this->emitente->refresh());
    }

    private function assertBloqueio(string $codigo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Esperava o bloqueio {$codigo}.");
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame($codigo, $e->codigo(), $e->getMessage());
        }
    }

    public function test_nota_valida_congela_o_snapshot_fiscal(): void
    {
        $nota = $this->nota();
        $this->preparar($nota);

        $item = $nota->itens()->first();
        $this->assertSame('5102', $item->cfop);
        $this->assertSame('102', $item->csosn);
        $this->assertSame(0, $item->origem);
        $this->assertSame('12345678', $item->ncm);
        $this->assertSame(1000, $nota->refresh()->total_centavos);
        $this->assertSame(9, $nota->destinatario['ind_ie_dest']);
        $this->assertSame('SP', $nota->destinatario['uf']);
    }

    public function test_cfop_interestadual_quando_o_cliente_e_de_outra_uf(): void
    {
        $this->cliente->update(['uf' => 'MG', 'codigo_ibge' => '3106200']);
        $nota = $this->nota();
        $this->preparar($nota);
        $this->assertSame('6102', $nota->itens()->first()->cfop);
    }

    public function test_origem_zero_e_valida_mas_origem_nula_bloqueia(): void
    {
        $this->produto->update(['origem' => null]);
        $this->assertBloqueio('FISCAL_PRODUTO_INCOMPLETO', fn () => $this->preparar($this->nota()));
    }

    public function test_produto_sem_ncm_bloqueia_citando_o_produto(): void
    {
        $this->produto->update(['ncm' => null]);
        try {
            $this->preparar($this->nota());
            $this->fail('Esperava bloqueio.');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_PRODUTO_INCOMPLETO', $e->codigo());
            $this->assertStringContainsString('Produto Um', $e->getMessage());
            $this->assertStringContainsString('NCM', $e->getMessage());
        }
    }

    public function test_produto_sem_tributacao_ou_st_sem_cest_bloqueia(): void
    {
        $this->produto->update(['tributacao_icms' => null]);
        $this->assertBloqueio('FISCAL_PRODUTO_INCOMPLETO', fn () => $this->preparar($this->nota()));

        $this->produto->update(['tributacao_icms' => TributacaoIcms::St, 'cest' => null]);
        $this->assertBloqueio('FISCAL_PRODUTO_INCOMPLETO', fn () => $this->preparar($this->nota()));
    }

    public function test_cliente_sem_endereco_ou_sem_uf_bloqueia(): void
    {
        $this->cliente->update(['uf' => null]);
        $this->assertBloqueio('FISCAL_DESTINATARIO_INCOMPLETO', fn () => $this->preparar($this->nota()));
    }

    public function test_cliente_sem_documento_bloqueia(): void
    {
        $this->cliente->update(['cpf_cnpj' => null]);
        $this->assertBloqueio('FISCAL_DESTINATARIO_INCOMPLETO', fn () => $this->preparar($this->nota()));
    }

    public function test_pj_exige_escolha_explicita_de_consumidor_final(): void
    {
        $this->cliente->update(['tipo' => TipoCliente::Pj, 'cpf_cnpj' => '11444777000161', 'inscricao_estadual' => '110042490114']);
        $nota = $this->nota();
        $this->assertBloqueio('FISCAL_CONSUMIDOR_FINAL_NAO_INFORMADO', fn () => $this->preparar($nota));

        $nota->update(['consumidor_final' => false]);
        $this->preparar($nota->refresh());
        $this->assertSame(1, $nota->refresh()->destinatario['ind_ie_dest']);
        $this->assertSame(0, $nota->destinatario['ind_final']);
    }

    public function test_pf_e_sempre_consumidor_final(): void
    {
        $nota = $this->nota();
        $this->preparar($nota);
        $this->assertSame(1, $nota->refresh()->destinatario['ind_final']);
    }

    public function test_soma_dos_pagamentos_diferente_do_total_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_PAGAMENTO_DIVERGENTE', fn () => $this->preparar($this->nota(pagamento: 900)));
    }

    public function test_pagamento_outros_sem_descricao_bloqueia(): void
    {
        $nota = $this->nota();
        $nota->pagamentos()->update(['tpag' => '99', 'xpag' => null]);
        $this->assertBloqueio('FISCAL_PAGAMENTO_SEM_DESCRICAO', fn () => $this->preparar($nota->refresh()));
    }

    public function test_nota_sem_itens_ou_sem_pagamentos_bloqueia(): void
    {
        $semItens = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $this->assertBloqueio('FISCAL_NOTA_SEM_ITENS', fn () => $this->preparar($semItens));

        $nota = $this->nota();
        $nota->pagamentos()->delete();
        $this->assertBloqueio('FISCAL_PAGAMENTO_DIVERGENTE', fn () => $this->preparar($nota->refresh()));
    }

    public function test_desconto_igual_ao_subtotal_bloqueia_nota_de_valor_zero(): void
    {
        $this->assertBloqueio('FISCAL_DESCONTO_INVALIDO', fn () => $this->preparar($this->nota(desconto: 1000)));
    }

    public function test_desconto_maior_que_o_subtotal_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_DESCONTO_INVALIDO', fn () => $this->preparar($this->nota(desconto: 1500)));
    }

    public function test_emitente_apto_passa(): void
    {
        app(VerificadorDoEmitente::class)->garantirApto($this->emitente->refresh(), $this->tenant);
        $this->addToAssertionCount(1);
    }

    public function test_emitente_incompleto_ou_regime_normal_ou_certificado_invalido_bloqueia(): void
    {
        $v = app(VerificadorDoEmitente::class);

        $this->emitente->update(['inscricao_estadual' => null]);
        $this->assertBloqueio('FISCAL_EMITENTE_INCOMPLETO', fn () => $v->garantirApto($this->emitente->refresh(), $this->tenant));
        $this->emitente->update(['inscricao_estadual' => '110042490114', 'regime_tributario' => 'NORMAL']);
        $this->assertBloqueio('FISCAL_REGIME_NAO_SUPORTADO', fn () => $v->garantirApto($this->emitente->refresh(), $this->tenant));
        $this->emitente->update(['regime_tributario' => 'SIMPLES', 'certificado_status' => CertificadoStatus::Vencido]);
        $this->assertBloqueio('FISCAL_CERTIFICADO_INVALIDO', fn () => $v->garantirApto($this->emitente->refresh(), $this->tenant));
        $this->emitente->update(['certificado_status' => CertificadoStatus::Valido, 'certificado_validade' => now()->subDay()->toDateString()]);
        $this->assertBloqueio('FISCAL_CERTIFICADO_INVALIDO', fn () => $v->garantirApto($this->emitente->refresh(), $this->tenant));
    }

    public function test_emitente_sem_serie_nfe_bloqueia(): void
    {
        $this->emitente->series()->delete();
        $this->assertBloqueio('FISCAL_SERIE_NAO_CONFIGURADA', fn () => app(VerificadorDoEmitente::class)->garantirApto($this->emitente->refresh(), $this->tenant));
    }
}
