<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Emissao\PreparadorDaNota;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\NotaPagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class NotaParaEmissaoTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    public function test_monta_o_dto_a_partir_do_snapshot(): void
    {
        $this->montarCenario();
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        NotaItem::factory()->create(['nota_id' => $nota->id, 'produto_id' => $this->produto->id, 'ordem' => 1, 'quantidade_milesimos' => 2000, 'valor_unitario_centavos' => 1000, 'total_centavos' => 2000]);
        NotaPagamento::query()->create(['nota_id' => $nota->id, 'tpag' => '01', 'valor_centavos' => 2000]);
        app(PreparadorDaNota::class)->preparar($nota, $this->emitente);
        $nota->forceFill(['ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 7])->save();

        $dto = NotaParaEmissao::deNota($nota->refresh(), $this->emitente->refresh(), $this->tenant);

        $this->assertSame(7, $dto->numero);
        $this->assertSame('11444777000161', $dto->emitente->cnpj);
        $this->assertSame(1, $dto->emitente->crt);
        $this->assertSame(1, $dto->idDest);
        $this->assertSame('5102', $dto->itens[0]->cfop);
        $this->assertSame(0, $dto->itens[0]->origem);
        $this->assertSame(2000, $dto->totalCentavos);
        $this->assertSame('01', $dto->pagamentos[0]->tpag);
    }
}
