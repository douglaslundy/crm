<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Application\Emissao\PreparadorDaNotaDeServico;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class NotaParaNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
    }

    /** @param  array<string, mixed>  $corpo */
    private function dto(array $corpo = [], ?string $regime = null): NotaParaNfse
    {
        $nota = $this->rascunhoDeNfse($corpo);
        app(PreparadorDaNotaDeServico::class)->preparar($nota, $this->emitente);
        $nota->forceFill(['numero' => 7, 'serie' => '1', 'ambiente' => 'HOMOLOGACAO'])->save();
        if ($regime !== null) {
            $this->emitente->update(['regime_tributario' => $regime]);
        }

        return NotaParaNfse::deNota($nota->refresh(), $this->emitente->refresh(), Tenant::query()->findOrFail($nota->tenant_id));
    }

    public function test_monta_o_retrato_a_partir_do_snapshot(): void
    {
        $dto = $this->dto(['desconto_centavos' => 1000, 'informacoes_complementares' => 'Placa ABC1D23']);

        $this->assertSame('HOMOLOGACAO', $dto->ambiente);
        $this->assertSame('1', $dto->serie);
        $this->assertSame(7, $dto->numeroDps);
        $this->assertSame('11444777000161', $dto->emitente->cnpj);
        $this->assertSame('3550308', $dto->emitente->codigoIbge);
        $this->assertSame('SIMPLES', $dto->emitente->regime);
        $this->assertSame('Maria Teste', $dto->tomador->nome);
        $this->assertSame('140101', $dto->servico->cTribNac);
        $this->assertSame('Troca de óleo', $dto->servico->descricao);
        $this->assertSame('5.00', $dto->servico->aliquotaIss);
        $this->assertSame(15000, $dto->valorServicosCentavos);
        $this->assertSame(1000, $dto->descontoCentavos);
        $this->assertSame('Placa ABC1D23', $dto->informacoesComplementares);
    }

    public function test_descricao_de_varios_itens_e_composta_com_ponto_e_virgula(): void
    {
        $outro = Servico::factory()->completo()->create(['nome' => 'Alinhamento']);
        $dto = $this->dto(['itens' => [
            ['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null],
            ['servico_id' => $outro->id, 'quantidade' => '1', 'valor_unitario_centavos' => null],
        ]]);

        $this->assertSame('Troca de óleo; Alinhamento', $dto->servico->descricao);
    }

    public function test_mei_vira_regime_mei(): void
    {
        $this->assertSame('MEI', $this->dto([], 'MEI')->emitente->regime);
    }

    public function test_nota_nao_preparada_lanca(): void
    {
        $nota = $this->rascunhoDeNfse();

        $this->expectException(LogicException::class);

        NotaParaNfse::deNota($nota, $this->emitente, Tenant::query()->findOrFail($nota->tenant_id));
    }
}
