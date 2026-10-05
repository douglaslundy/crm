<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class ModeloDaNotaTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function nota(ModeloDocumento $modelo, string $chave, ?string $clienteId = 'padrao'): Nota
    {
        $nota = Nota::factory()->create([
            'emitente_id' => $this->emitente->id,
            'cliente_id' => $clienteId === 'padrao' ? $this->cliente->id : $clienteId,
        ]);
        $nota->forceFill(['modelo' => $modelo, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 5, 'chave' => $chave])->save();

        return $nota;
    }

    public function test_modelo_padrao_e_nfe(): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);

        $this->assertSame(ModeloDocumento::Nfe, $nota->refresh()->modelo);
    }

    public function test_nfe_e_nfce_com_o_mesmo_numero_e_serie_nao_colidem(): void
    {
        $this->nota(ModeloDocumento::Nfe, str_repeat('1', 44));
        $this->nota(ModeloDocumento::Nfce, str_repeat('2', 44));

        $this->assertSame(2, Nota::query()->where('numero', 5)->count());
    }

    public function test_duas_notas_do_mesmo_modelo_com_o_mesmo_numero_colidem(): void
    {
        $this->nota(ModeloDocumento::Nfe, str_repeat('1', 44));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->nota(ModeloDocumento::Nfe, str_repeat('3', 44));
    }

    public function test_nfce_aceita_nota_sem_cliente(): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => null]);
        $nota->forceFill(['modelo' => ModeloDocumento::Nfce])->save();

        $this->assertNull($nota->refresh()->cliente_id);
        $this->assertSame(ModeloDocumento::Nfce, $nota->modelo);
    }
}
