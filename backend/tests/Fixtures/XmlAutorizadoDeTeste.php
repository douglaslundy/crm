<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Fiscal\Domain\Emissao\ConsumidorParaEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDeXml;
use NFePHP\NFe\Complements;

/** nfeProc autorizado (e cancelado) de teste. Exige `AssinaNfeDeTeste` na mesma classe. */
trait XmlAutorizadoDeTeste
{
    /** @return array{xml: string, chave: string} */
    private function nfeProcDeTeste(string $tpAmb = '2', ModeloDocumento $modelo = ModeloDocumento::Nfe): array
    {
        $nfce = $modelo === ModeloDocumento::Nfce;
        $nota = $nfce ? $this->notaDeTeste(modelo: $modelo, consumidor: new ConsumidorParaEmissao('52998224725', 'Joana Consumidora')) : $this->notaDeTeste();
        $xml = (new MontadorDeXml)->montar($nota, '47382915')['xml'];
        if ($tpAmb !== '2') {
            // Em homologação o montador troca o nome do destinatário pelo texto legal; em produção ele é o nome real.
            $xml = str_replace(['<tpAmb>2</tpAmb>', 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL', 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL'], ["<tpAmb>{$tpAmb}</tpAmb>", 'Maria Teste', 'Produto Um'], $xml);
        }
        $assinado = $this->assinar($xml, $nfce ? 65 : 55);
        preg_match('/Id="NFe(\d{44})"/', $assinado, $m);
        $chave = $m[1];
        $resposta = '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>'.$tpAmb.'</tpAmb><verAplic>SP</verAplic><cStat>104</cStat><xMotivo>Lote processado</xMotivo><cUF>35</cUF><dhRecbto>2026-10-02T10:00:00-03:00</dhRecbto>'
            .'<protNFe versao="4.00"><infProt><tpAmb>'.$tpAmb.'</tpAmb><verAplic>SP</verAplic><chNFe>'.$chave.'</chNFe><dhRecbto>2026-10-02T10:00:00-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>'
            .$this->digestDoXmlAssinado($assinado).'</digVal><cStat>100</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo></infProt></protNFe></retEnviNFe>';

        return ['xml' => Complements::toAuthorize($assinado, $resposta), 'chave' => $chave];
    }

    private function nfeProcCancelado(string $nfeProc, string $chave, string $cStatEvento = '135'): string
    {
        $evento = '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><cStat>128</cStat><xMotivo>Lote</xMotivo><retEvento versao="1.00"><infEvento><cStat>'.$cStatEvento.'</cStat><xMotivo>ok</xMotivo><chNFe>'.$chave.'</chNFe><tpEvento>110111</tpEvento><nProt>135260000000555</nProt><dhRegEvento>2026-10-02T11:00:00-03:00</dhRegEvento></infEvento></retEvento></retEnvEvento>';

        return Complements::cancelRegister($nfeProc, $evento);
    }
}
