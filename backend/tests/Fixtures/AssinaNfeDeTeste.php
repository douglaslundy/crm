<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Fiscal\Domain\Emissao\DestinatarioParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\EmitenteParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ItemParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\PagamentoParaEmissao;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools;

/** NF-e de teste montada, assinada com certificado autoassinado gerado na hora. Nenhum dado real. */
trait AssinaNfeDeTeste
{
    private function notaDeTeste(string $nomeItem = 'Produto Um', ?string $infCpl = null, int $descontoItem = 0): NotaParaEmissao
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

    private function assinar(string $xml): string
    {
        $tools = new Tools(json_encode([
            'atualizacao' => '2026-10-02 00:00:00', 'tpAmb' => 2, 'razaosocial' => 'Empresa Teste LTDA', 'siglaUF' => 'SP',
            'cnpj' => '11444777000161', 'schemes' => 'PL_009_V4', 'versao' => '4.00',
        ], JSON_THROW_ON_ERROR), $this->certificadoDeTeste());
        $tools->model(55);

        return $tools->signNFe($xml);
    }

    /** Valor de `DigestValue` da assinatura: é o `digVal` que a SEFAZ devolve no protocolo. */
    private function digestDoXmlAssinado(string $assinado): string
    {
        preg_match('/<DigestValue>([^<]+)<\/DigestValue>/', $assinado, $m);

        return $m[1] ?? '';
    }
}
