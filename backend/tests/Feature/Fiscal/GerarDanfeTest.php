<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\GerarDanfe;
use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaSemPdfException;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class GerarDanfeTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    /** @var list<array{string, bool}> */
    private array $chamadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $chamadas = &$this->chamadas;
        $this->app->instance(GeradorDeDanfe::class, new class($chamadas) implements GeradorDeDanfe
        {
            /** @param list<array{string, bool}> $chamadas */
            public function __construct(private array &$chamadas) {}

            public function gerar(string $xml, bool $cancelada): string
            {
                $this->chamadas[] = [$xml, $cancelada];

                return '%PDF-fake';
            }
        });
    }

    private function nota(StatusNota $status, ?string $autorizado = '<nfeProc>aut</nfeProc>', ?string $cancelamento = null, int $numero = 7): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => $status, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => $numero,
            'chave' => str_pad((string) $numero, 44, '0', STR_PAD_LEFT), 'protocolo' => '135260000000001',
            'xml_autorizado' => $autorizado, 'xml_cancelamento' => $cancelamento,
        ])->save();

        return Nota::findOrFail($nota->id);
    }

    public function test_autorizada_e_cancelando_usam_o_xml_autorizado_sem_marca_de_cancelada(): void
    {
        foreach ([[StatusNota::Autorizada, 7], [StatusNota::Cancelando, 8]] as [$status, $numero]) {
            $this->chamadas = [];
            $arquivo = app(GerarDanfe::class)->executar($this->nota($status, numero: $numero));

            $this->assertSame("DANFE-{$numero}.pdf", $arquivo->nome);
            $this->assertSame('%PDF-fake', $arquivo->conteudo);
            $this->assertSame([['<nfeProc>aut</nfeProc>', false]], $this->chamadas);
        }
    }

    public function test_cancelada_usa_o_nfeproc_do_cancelamento_com_a_marca(): void
    {
        app(GerarDanfe::class)->executar($this->nota(StatusNota::Cancelada, '<nfeProc>aut</nfeProc>', '<nfeProc>canc</nfeProc>'));

        $this->assertSame([['<nfeProc>canc</nfeProc>', true]], $this->chamadas);
    }

    public function test_cancelada_com_xml_cru_de_evento_volta_para_o_autorizado_com_a_marca(): void
    {
        app(GerarDanfe::class)->executar($this->nota(StatusNota::Cancelada, '<nfeProc>aut</nfeProc>', '<retEnvEvento>cru</retEnvEvento>'));

        $this->assertSame([['<nfeProc>aut</nfeProc>', true]], $this->chamadas);
    }

    /** @return array<string, array{StatusNota}> */
    public static function statusSemPdf(): array
    {
        return [
            'rascunho' => [StatusNota::Rascunho], 'processando' => [StatusNota::Processando],
            'rejeitada' => [StatusNota::Rejeitada], 'erro' => [StatusNota::Erro],
        ];
    }

    #[DataProvider('statusSemPdf')]
    public function test_status_sem_pdf_lanca_excecao(StatusNota $status): void
    {
        $this->expectException(NotaSemPdfException::class);

        app(GerarDanfe::class)->executar($this->nota($status));
    }

    public function test_autorizada_sem_xml_lanca_excecao(): void
    {
        $this->expectException(NotaSemPdfException::class);

        app(GerarDanfe::class)->executar($this->nota(StatusNota::Autorizada, null));
    }

    public function test_nao_grava_nada(): void
    {
        $nota = $this->nota(StatusNota::Autorizada);
        $antes = Nota::query()->whereKey($nota->id)->first()?->updated_at;

        app(GerarDanfe::class)->executar($nota);

        $depois = Nota::query()->whereKey($nota->id)->first();
        $this->assertEquals($antes, $depois?->updated_at);
        $this->assertFalse($nota->isDirty());
    }
}
