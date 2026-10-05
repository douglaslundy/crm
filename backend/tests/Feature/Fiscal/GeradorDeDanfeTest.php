<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Domain\Exceptions\PdfIndisponivelException;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\Fixtures\TextoDePdf;
use Tests\Fixtures\XmlAutorizadoDeTeste;
use Tests\TestCase;

class GeradorDeDanfeTest extends TestCase
{
    use AssinaNfeDeTeste;
    use TextoDePdf;
    use XmlAutorizadoDeTeste;

    private function gerador(): GeradorDeDanfe
    {
        return $this->app->make(GeradorDeDanfe::class);
    }

    public function test_gera_pdf_com_danfe_emitente_e_produto(): void
    {
        $pdf = $this->gerador()->gerar($this->nfeProcDeTeste()['xml'], false);

        $this->assertStringStartsWith('%PDF', $pdf);
        $texto = $this->textoDoPdf($pdf);
        $this->assertStringContainsString('DANFE', $texto);
        $this->assertStringContainsString('Empresa Teste LTDA', $texto);
        $this->assertStringContainsString('Produto Um', $texto);
    }

    public function test_homologacao_imprime_a_marca_e_producao_nao(): void
    {
        $homolog = $this->textoDoPdf($this->gerador()->gerar($this->nfeProcDeTeste('2')['xml'], false));
        $producao = $this->textoDoPdf($this->gerador()->gerar($this->nfeProcDeTeste('1')['xml'], false));

        $this->assertStringContainsString('HOMOLOGA', $homolog);
        $this->assertStringNotContainsString('HOMOLOGA', $producao);
    }

    public function test_cancelada_imprime_a_marca_por_ret_evento_e_por_flag_com_cstat_136(): void
    {
        ['xml' => $proc, 'chave' => $chave] = $this->nfeProcDeTeste();

        $porEvento = $this->textoDoPdf($this->gerador()->gerar($this->nfeProcCancelado($proc, $chave), true));
        $porFlag = $this->textoDoPdf($this->gerador()->gerar($this->nfeProcCancelado($proc, $chave, '136'), true));
        $normal = $this->textoDoPdf($this->gerador()->gerar($proc, false));

        $this->assertStringContainsString('CANCELADA', $porEvento);
        $this->assertStringContainsString('CANCELADA', $porFlag);
        $this->assertStringNotContainsString('CANCELADA', $normal);
    }

    public function test_xml_malformado_vira_pdf_indisponivel(): void
    {
        $this->expectException(PdfIndisponivelException::class);

        $this->gerador()->gerar('<isto nao e um nfeProc', false);
    }
}
