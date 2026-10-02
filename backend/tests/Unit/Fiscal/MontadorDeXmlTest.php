<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Emissao\DestinatarioParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\EmitenteParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ItemParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\PagamentoParaEmissao;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDeXml;
use NFePHP\Common\Certificate;
use NFePHP\Common\Validator;
use NFePHP\NFe\Tools;
use Tests\TestCase;

class MontadorDeXmlTest extends TestCase
{
    private function nota(string $nomeItem = 'Produto Um', ?string $infCpl = null, int $descontoItem = 0): NotaParaEmissao
    {
        $emitente = new EmitenteParaEmissao('11444777000161', 'Empresa Teste LTDA', null, '110042490114', 1, 'Rua Teste', '100', 'Centro', '3550308', 'São Paulo', 'SP', '01001000', '4520001');
        $dest = new DestinatarioParaEmissao('PF', '52998224725', 'Maria Teste', 9, 1, null, 'Rua A', '10', 'Centro', '3550308', 'São Paulo', 'SP', '01001000', null);
        $total = 2500 - $descontoItem;
        $item = new ItemParaEmissao(1, 'P-1', $nomeItem, 'UN', null, '12345678', null, '5102', 0, '102', 2500, 1000, $descontoItem, 2500);

        return new NotaParaEmissao('nota-1', 'HOMOLOGACAO', '1', 7, 'Venda de mercadoria', 1, $emitente, $dest, [$item], [new PagamentoParaEmissao('01', null, $total)], 2500, $descontoItem, $total, $infCpl);
    }

    private function certificadoDeTeste(): Certificate
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'EMPRESA TESTE:11444777000161'], $chave);
        $cert = openssl_csr_sign($csr, null, $chave, 365);
        openssl_pkcs12_export($cert, $pfx, $chave, 'teste');

        return Certificate::readPfx($pfx, 'teste');
    }

    private function assinarEValidar(string $xml): void
    {
        $tools = new Tools(json_encode([
            'atualizacao' => '2026-10-02 00:00:00', 'tpAmb' => 2, 'razaosocial' => 'Empresa Teste LTDA', 'siglaUF' => 'SP',
            'cnpj' => '11444777000161', 'schemes' => 'PL_009_V4', 'versao' => '4.00',
        ], JSON_THROW_ON_ERROR), $this->certificadoDeTeste());
        $tools->model(55);
        $assinado = $tools->signNFe($xml);

        $xsd = dirname(__DIR__, 3).'/vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/nfe_v4.00.xsd';
        $this->assertTrue(Validator::isValid($assinado, $xsd), 'XML fora do XSD: '.json_encode(Validator::$errors ?? []));
    }

    public function test_monta_xml_valido_contra_o_xsd_e_com_chave_de_44_digitos(): void
    {
        ['xml' => $xml, 'chave' => $chave] = (new MontadorDeXml)->montar($this->nota(), '47382915');

        $this->assertMatchesRegularExpression('/^\d{44}$/', $chave);
        $this->assertStringStartsWith('35', $chave, 'cUF de SP');
        $this->assertStringContainsString('<mod>55</mod>', $xml);
        $this->assertStringContainsString('<CSOSN>102</CSOSN>', $xml);
        $this->assertStringContainsString('<cEAN>SEM GTIN</cEAN>', $xml);
        $this->assertStringContainsString('<vProd>25.00</vProd>', $xml);
        $this->assertStringContainsString('<qCom>2.5000</qCom>', $xml);
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<CRT>1</CRT>', $xml);
        $this->assertSame(1, preg_match('/<ICMS>(.*?)<\/ICMS>/s', $xml, $icms), 'bloco ICMS presente');
        $this->assertStringNotContainsString('<CST>', $icms[1], 'Simples usa CSOSN no ICMS, nunca CST (o CST do PIS/COFINS é outro tributo)');
        // Estrutura da chave: cUF(2) AAMM(4) CNPJ(14) mod(2) série(3) nNF(9) tpEmis(1) cNF(8) cDV(1)
        $this->assertSame('11444777000161', substr($chave, 6, 14));
        $this->assertSame('55', substr($chave, 20, 2), 'modelo');
        $this->assertSame('001', substr($chave, 22, 3), 'série 1');
        $this->assertSame('000000007', substr($chave, 25, 9), 'número 7');
        $this->assertSame('47382915', substr($chave, 35, 8), 'cNF');
        $this->assertStringContainsString('<cNF>47382915</cNF>', $xml);
        $this->assertStringContainsString('Id="NFe'.$chave.'"', $xml);
        $this->assinarEValidar($xml);
    }

    public function test_caracteres_especiais_e_acentos_nao_quebram_o_xml(): void
    {
        $nota = $this->nota('Pão & Café <"especial"> ção ‘aspas’', "Obs: R\$ 10 & 'x' <b> \u{1F600} \t tab\nquebra");
        ['xml' => $xml] = (new MontadorDeXml)->montar($nota, '47382915');

        $this->assertNotFalse(simplexml_load_string($xml), 'XML bem formado');
        $this->assinarEValidar($xml);
    }

    public function test_desconto_do_item_vai_em_vDesc(): void
    {
        ['xml' => $xml] = (new MontadorDeXml)->montar($this->nota(descontoItem: 100), '47382915');
        $this->assertStringContainsString('<vDesc>1.00</vDesc>', $xml);
        $this->assertStringContainsString('<vNF>24.00</vNF>', $xml);
        $this->assinarEValidar($xml);
    }

    public function test_cnpj_do_emitente_vazio_lanca_excecao(): void
    {
        $n = $this->nota();
        $semCnpj = new NotaParaEmissao($n->notaId, $n->ambiente, $n->serie, $n->numero, $n->naturezaOperacao, $n->idDest,
            new EmitenteParaEmissao('', 'X', null, '123', 1, 'R', '1', 'B', '3550308', 'SP', 'SP', '01001000', null),
            $n->destinatario, $n->itens, $n->pagamentos, $n->subtotalCentavos, $n->descontoCentavos, $n->totalCentavos, null);

        $this->expectException(\RuntimeException::class);
        (new MontadorDeXml)->montar($semCnpj);
    }
}
