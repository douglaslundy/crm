<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfse;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class DanfseApiTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    /** @var list<array{string, bool}> */
    public array $chamadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
        $this->app->instance(GeradorDeDanfse::class, new class($this) implements GeradorDeDanfse
        {
            public function __construct(private readonly DanfseApiTest $teste) {}

            public function gerar(string $xml, bool $cancelada): string
            {
                $this->teste->chamadas[] = [$xml, $cancelada];

                return '%PDF-teste';
            }
        });
    }

    /** @param  array<string, mixed>  $campos */
    private function nota(StatusNota $status, array $campos = []): Nota
    {
        return $this->notaDeNfsePronta($status, array_replace(['chave' => str_repeat('1', 50), 'numero_nfse' => '42', 'xml_autorizado' => '<NFSe>autorizada</NFSe>'], $campos));
    }

    public function test_autorizada_gera_o_danfse_com_o_numero_oficial_no_nome(): void
    {
        $nota = $this->nota(StatusNota::Autorizada);

        $r = $this->spaComo($this->admin)->get("/api/app/notas/{$nota->id}/pdf")->assertOk();

        $this->assertStringContainsString('DANFSE-42.pdf', (string) $r->headers->get('Content-Disposition'));
        $this->assertSame([['<NFSe>autorizada</NFSe>', false]], $this->chamadas);
    }

    public function test_cancelando_usa_o_xml_autorizado_sem_a_marca_e_cancelada_com_a_marca(): void
    {
        $this->spaComo($this->admin)->get('/api/app/notas/'.$this->nota(StatusNota::Cancelando)->id.'/pdf')->assertOk();
        $this->spaComo($this->admin)->get('/api/app/notas/'.$this->nota(StatusNota::Cancelada)->id.'/pdf')->assertOk();

        $this->assertSame([['<NFSe>autorizada</NFSe>', false], ['<NFSe>autorizada</NFSe>', true]], $this->chamadas);
    }

    public function test_rascunho_e_nota_sem_xml_nao_geram_pdf(): void
    {
        $this->spaComo($this->admin)->get('/api/app/notas/'.$this->notaDeNfsePronta(StatusNota::Rascunho)->id.'/pdf')->assertStatus(409);
        $this->spaComo($this->admin)->get('/api/app/notas/'.$this->nota(StatusNota::Autorizada, ['xml_autorizado' => null])->id.'/pdf')->assertStatus(409);
        $this->assertSame([], $this->chamadas);
    }
}
