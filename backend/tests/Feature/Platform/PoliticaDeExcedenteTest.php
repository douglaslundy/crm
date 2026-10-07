<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\PoliticaExcedente;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class PoliticaDeExcedenteTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->tenant->plano->limites()->where('recurso', Recurso::DocumentosMes->value)->update(['limite' => 1]);
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill(['status' => StatusNota::Autorizada, 'ambiente' => 'PRODUCAO', 'emitido_em' => now()])->save();
    }

    private function politica(PoliticaExcedente $politica, ?int $preco): void
    {
        $this->tenant->plano->forceFill(['politica_excedente' => $politica, 'preco_documento_excedente_centavos' => $preco])->save();
        $this->tenant->unsetRelation('plano');
    }

    private function capacidade(Recurso $recurso = Recurso::DocumentosMes): void
    {
        app(EntitlementService::class)->garantirCapacidade($this->tenant->refresh(), $recurso);
    }

    public function test_bloquear_continua_bloqueando_acima_do_limite(): void
    {
        $this->politica(PoliticaExcedente::Bloquear, 100);

        $this->expectException(LimiteDoPlanoAtingidoException::class);
        $this->capacidade();
    }

    public function test_cobrar_com_preco_libera_documentos_acima_do_limite(): void
    {
        $this->politica(PoliticaExcedente::Cobrar, 100);

        $this->capacidade();
        $this->assertTrue($this->tenant->refresh()->plano->permiteExcederDocumentos());
    }

    public function test_cobrar_sem_preco_ou_com_preco_zero_continua_bloqueando(): void
    {
        foreach ([null, 0] as $preco) {
            $this->politica(PoliticaExcedente::Cobrar, $preco);
            $this->assertFalse($this->tenant->refresh()->plano->permiteExcederDocumentos());
            try {
                $this->capacidade();
                $this->fail('deveria bloquear com preço '.var_export($preco, true));
            } catch (LimiteDoPlanoAtingidoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cobrar_com_preco_nao_libera_outros_recursos(): void
    {
        $this->politica(PoliticaExcedente::Cobrar, 100);
        $this->tenant->plano->limites()->where('recurso', Recurso::Usuarios->value)->update(['limite' => 0]);
        $this->tenant->unsetRelation('plano');

        $this->expectException(LimiteDoPlanoAtingidoException::class);
        $this->capacidade(Recurso::Usuarios);
    }
}
