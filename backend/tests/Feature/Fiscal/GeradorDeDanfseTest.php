<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfse;
use App\Modules\Fiscal\Domain\Exceptions\PdfIndisponivelException;
use Tests\Fixtures\TextoDePdf;
use Tests\TestCase;

class GeradorDeDanfseTest extends TestCase
{
    use TextoDePdf;

    private function xml(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/nfse_nacional_autorizada.xml'));
    }

    private function gerador(): GeradorDeDanfse
    {
        return $this->app->make(GeradorDeDanfse::class);
    }

    public function test_gera_o_pdf_a4_do_danfse_com_a_chave_de_50_digitos(): void
    {
        $pdf = $this->gerador()->gerar($this->xml(), false);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+595(\.0+)?\s+842(\.0+)?\s*\]#', $pdf);
        $texto = $this->textoDoPdf($pdf);
        $this->assertStringContainsString('DANFSe', $texto);
        $this->assertStringContainsString('31305072212345678000195000000000000226096821694069', $texto);
        $this->assertStringNotContainsString('NFS31305072212345678000195000000000000226096821694069', $texto);
    }

    public function test_cancelada_imprime_a_situacao(): void
    {
        $this->assertStringContainsString('Cancelada', $this->textoDoPdf($this->gerador()->gerar($this->xml(), true)));
    }

    public function test_pdf_de_homologacao_traz_a_marca_sem_valor_fiscal(): void
    {
        $homologacao = (string) str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', $this->xml());

        $this->assertStringContainsString('SEM VALOR FISCAL', $this->textoDoPdf($this->gerador()->gerar($homologacao, false)));
        $this->assertStringNotContainsString('SEM VALOR FISCAL', $this->textoDoPdf($this->gerador()->gerar($this->xml(), false)));
    }

    public function test_xml_invalido_vira_pdf_indisponivel(): void
    {
        $this->expectException(PdfIndisponivelException::class);

        $this->gerador()->gerar('<isto nao e uma nfse', false);
    }
}
