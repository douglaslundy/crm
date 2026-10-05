<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class NotaParaEmissaoNfceTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    /** @param array<string, mixed>|null $destinatario */
    private function nota(ModeloDocumento $modelo, ?array $destinatario): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'modelo' => $modelo, 'status' => StatusNota::Processando, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 3,
            'destinatario' => $destinatario, 'subtotal_centavos' => 1000, 'total_centavos' => 1000,
        ])->save();
        $nota->itens()->create([
            'produto_id' => $this->produto->id, 'ordem' => 1, 'quantidade_milesimos' => 1000, 'valor_unitario_centavos' => 1000,
            'total_centavos' => 1000, 'sku' => 'P-1', 'descricao' => 'Produto Um', 'unidade' => 'UN', 'ncm' => '12345678',
            'cfop' => '5102', 'origem' => 0, 'csosn' => '102',
        ]);
        $nota->pagamentos()->create(['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]);

        return $nota->refresh();
    }

    private function dto(Nota $nota): NotaParaEmissao
    {
        return NotaParaEmissao::deNota($nota, $this->emitente, Tenant::query()->findOrFail($nota->tenant_id));
    }

    public function test_nfce_identificada_leva_so_documento_e_nome(): void
    {
        $dto = $this->dto($this->nota(ModeloDocumento::Nfce, ['tipo' => 'PF', 'documento' => '52998224725', 'nome' => 'Maria Teste', 'uf' => 'SP']));

        $this->assertSame(ModeloDocumento::Nfce, $dto->modelo);
        $this->assertSame(1, $dto->idDest);
        $this->assertNull($dto->destinatario);
        $this->assertSame('52998224725', $dto->consumidor?->documento);
        $this->assertSame('Maria Teste', $dto->consumidor?->nome);
    }

    public function test_nfce_sem_identificacao_nao_tem_destinatario_nem_consumidor(): void
    {
        $dto = $this->dto($this->nota(ModeloDocumento::Nfce, null));

        $this->assertNull($dto->destinatario);
        $this->assertNull($dto->consumidor);
        $this->assertSame(1, $dto->idDest);
    }

    public function test_nfe_sem_snapshot_do_destinatario_lanca(): void
    {
        $nota = $this->nota(ModeloDocumento::Nfe, null);

        $this->expectException(LogicException::class);
        $this->dto($nota);
    }
}
