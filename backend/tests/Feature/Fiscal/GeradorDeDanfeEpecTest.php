<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Domain\Emissao\ContingenciaDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\RegistroEpec;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDeXml;
use Carbon\CarbonImmutable;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\Fixtures\TextoDePdf;
use Tests\TestCase;

class GeradorDeDanfeEpecTest extends TestCase
{
    use AssinaNfeDeTeste;
    use TextoDePdf;

    private function xmlEpecAssinado(): string
    {
        $epec = new ContingenciaDeEmissao('Falha de comunicação com a SEFAZ na emissão; emitida em contingência EPEC.', CarbonImmutable::now(), TipoDeContingencia::Epec);

        return $this->assinar((new MontadorDeXml)->montar($this->notaDeTeste(), '47382915', $epec)['xml'], 55);
    }

    public function test_danfe_em_epec_imprime_o_registro_e_o_protocolo(): void
    {
        $gerador = $this->app->make(GeradorDeDanfe::class);

        $pdf = $gerador->gerar($this->xmlEpecAssinado(), false, ModeloDocumento::Nfe, new RegistroEpec('891260000000001', CarbonImmutable::parse('2026-10-06 12:00:00')));

        $this->assertStringStartsWith('%PDF', $pdf);
        $texto = $this->textoDoPdf($pdf);
        $this->assertStringContainsString('EPEC', $texto);
        $this->assertStringContainsString('891260000000001', $texto);
    }

    public function test_danfe_em_epec_sem_registro_nao_mostra_o_protocolo(): void
    {
        $gerador = $this->app->make(GeradorDeDanfe::class);

        $texto = $this->textoDoPdf($gerador->gerar($this->xmlEpecAssinado(), false));

        $this->assertStringNotContainsString('891260000000001', $texto);
    }
}
