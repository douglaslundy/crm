<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\GerarDanfe;
use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Domain\Emissao\RegistroEpec;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
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

    /** @var list<ModeloDocumento> */
    private array $modelos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $chamadas = &$this->chamadas;
        $modelos = &$this->modelos;
        $this->app->instance(GeradorDeDanfe::class, new class($chamadas, $modelos) implements GeradorDeDanfe
        {
            /**
             * @param  list<array{string, bool}>  $chamadas
             * @param  list<ModeloDocumento>  $modelos
             */
            public function __construct(private array &$chamadas, private array &$modelos) {}

            public function gerar(string $xml, bool $cancelada, ModeloDocumento $modelo = ModeloDocumento::Nfe, ?RegistroEpec $epec = null): string
            {
                $this->chamadas[] = [$xml, $cancelada];
                $this->modelos[] = $modelo;

                return '%PDF-fake';
            }
        });
    }

    private function nota(StatusNota $status, ?string $autorizado = '<nfeProc>aut</nfeProc>', ?string $cancelamento = null, int $numero = 7, ModeloDocumento $modelo = ModeloDocumento::Nfe): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'modelo' => $modelo, 'status' => $status, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => $numero,
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

    public function test_nfce_gera_o_cupom_com_o_modelo_repassado_e_nome_proprio(): void
    {
        $arquivo = app(GerarDanfe::class)->executar($this->nota(StatusNota::Autorizada, modelo: ModeloDocumento::Nfce));

        $this->assertSame('CUPOM-7.pdf', $arquivo->nome);
        $this->assertSame([ModeloDocumento::Nfce], $this->modelos);
    }

    public function test_nfce_cancelada_leva_a_marca_e_o_modelo(): void
    {
        $arquivo = app(GerarDanfe::class)->executar($this->nota(StatusNota::Cancelada, '<nfeProc>aut</nfeProc>', '<nfeProc>canc</nfeProc>', 9, ModeloDocumento::Nfce));

        $this->assertSame('CUPOM-9.pdf', $arquivo->nome);
        $this->assertSame([['<nfeProc>canc</nfeProc>', true]], $this->chamadas);
        $this->assertSame([ModeloDocumento::Nfce], $this->modelos);
    }

    public function test_nfe_continua_com_o_modelo_nfe(): void
    {
        app(GerarDanfe::class)->executar($this->nota(StatusNota::Autorizada));

        $this->assertSame([ModeloDocumento::Nfe], $this->modelos);
    }

    public function test_nfce_em_contingencia_usa_o_xml_offline_enviado(): void
    {
        $nota = $this->nota(StatusNota::Contingencia, null, null, 11, ModeloDocumento::Nfce);
        $nota->forceFill(['xml_enviado' => '<NFe>offline</NFe>'])->save();

        $arquivo = app(GerarDanfe::class)->executar(Nota::query()->findOrFail($nota->id));

        $this->assertSame('CUPOM-11.pdf', $arquivo->nome);
        $this->assertSame([['<NFe>offline</NFe>', false]], $this->chamadas);
        $this->assertSame([ModeloDocumento::Nfce], $this->modelos);
    }

    public function test_contingencia_sem_xml_enviado_lanca(): void
    {
        $this->expectException(NotaSemPdfException::class);

        app(GerarDanfe::class)->executar($this->nota(StatusNota::Contingencia, null, null, 12, ModeloDocumento::Nfce));
    }
}
