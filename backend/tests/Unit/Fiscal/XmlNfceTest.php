<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Emissao\ConsumidorParaEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDeXml;
use NFePHP\Common\Validator;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\TestCase;

class XmlNfceTest extends TestCase
{
    use AssinaNfeDeTeste;

    private function xsdValido(string $assinado): void
    {
        $xsd = dirname(__DIR__, 3).'/vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/nfe_v4.00.xsd';
        $this->assertTrue(Validator::isValid($assinado, $xsd), 'XML fora do XSD: '.json_encode(Validator::$errors ?? []));
    }

    public function test_nfce_sem_identificacao_nao_tem_dest_nem_endereco(): void
    {
        $nota = $this->notaDeTeste(modelo: ModeloDocumento::Nfce);
        ['xml' => $xml, 'chave' => $chave] = (new MontadorDeXml)->montar($nota, '47382915');

        $this->assertStringContainsString('<mod>65</mod>', $xml);
        $this->assertStringContainsString('<tpImp>4</tpImp>', $xml);
        $this->assertStringContainsString('<idDest>1</idDest>', $xml);
        $this->assertStringContainsString('<indFinal>1</indFinal>', $xml);
        $this->assertStringNotContainsString('<dest>', $xml);
        $this->assertStringNotContainsString('<enderDest>', $xml);
        $this->assertSame('65', substr($chave, 20, 2), 'modelo na chave');
        $this->assertSame('000000007', substr($chave, 25, 9));
    }

    public function test_nfce_identificada_leva_cpf_e_nome_sem_endereco(): void
    {
        $nota = $this->notaDeTeste(modelo: ModeloDocumento::Nfce, consumidor: new ConsumidorParaEmissao('52998224725', 'Joana Consumidora'));
        ['xml' => $xml] = (new MontadorDeXml)->montar($nota, '47382915');

        $this->assertSame(1, preg_match('/<dest>(.*?)<\/dest>/s', $xml, $dest), 'grupo dest presente');
        $this->assertStringContainsString('<CPF>52998224725</CPF>', $dest[1]);
        // Em homologação a própria biblioteca troca o nome pelo texto legal da SEFAZ.
        $this->assertStringContainsString('<xNome>NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL</xNome>', $dest[1]);
        $this->assertStringContainsString('<indIEDest>9</indIEDest>', $dest[1]);
        $this->assertStringNotContainsString('<IE>', $dest[1]);
        $this->assertStringNotContainsString('<enderDest>', $xml);
    }

    public function test_nfce_assinada_com_csc_tem_qr_code_e_valida_no_xsd(): void
    {
        $nota = $this->notaDeTeste(modelo: ModeloDocumento::Nfce, consumidor: new ConsumidorParaEmissao('52998224725', 'Joana Consumidora'));
        ['xml' => $xml] = (new MontadorDeXml)->montar($nota, '47382915');

        $assinado = $this->assinar($xml, 65);

        $this->assertStringContainsString('<infNFeSupl>', $assinado);
        $this->assertStringContainsString('<qrCode>', $assinado);
        $this->assertStringContainsString('<urlChave>', $assinado);
        $this->xsdValido($assinado);
    }

    public function test_nfce_sem_cliente_assinada_tambem_valida_no_xsd(): void
    {
        ['xml' => $xml] = (new MontadorDeXml)->montar($this->notaDeTeste(modelo: ModeloDocumento::Nfce), '47382915');

        $this->xsdValido($this->assinar($xml, 65));
    }

    public function test_nfe_continua_igual_com_dest_e_endereco(): void
    {
        ['xml' => $xml] = (new MontadorDeXml)->montar($this->notaDeTeste(), '47382915');

        $this->assertStringContainsString('<mod>55</mod>', $xml);
        $this->assertStringContainsString('<tpImp>1</tpImp>', $xml);
        $this->assertStringContainsString('<enderDest>', $xml);
        $this->assertStringNotContainsString('<infNFeSupl>', $this->assinar($xml));
    }
}
