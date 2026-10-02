<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use NFePHP\NFe\Complements;
use SimpleXMLElement;
use Throwable;

/** Funções puras: traduzem a resposta da SEFAZ nos resultados do domínio. Nada de rede aqui. */
final class InterpretadorDeResposta
{
    public function emissao(string $xmlAssinado, string $respostaXml): ResultadoDeEmissao
    {
        $resp = $this->carregar($respostaXml);
        if ($resp === null) {
            return ResultadoDeEmissao::erro('Resposta ilegível da SEFAZ ao autorizar a nota.');
        }
        $resp->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $prot = $resp->xpath('//n:protNFe/n:infProt')[0] ?? null;

        if ($prot === null) {
            $cStat = (string) ($resp->xpath('//n:cStat')[0] ?? '');
            $motivo = (string) ($resp->xpath('//n:xMotivo')[0] ?? '');
            if (in_array($cStat, ['103', '105'], true)) {
                return ResultadoDeEmissao::processando();
            }

            return $cStat === '' ? ResultadoDeEmissao::erro('Resposta da SEFAZ sem protocolo nem status.') : ResultadoDeEmissao::rejeitada($cStat, $motivo);
        }

        $cStat = (string) $prot->cStat;
        $motivo = (string) $prot->xMotivo;

        if ($cStat === '100') {
            try {
                return ResultadoDeEmissao::autorizada((string) $prot->nProt, Complements::toAuthorize($xmlAssinado, $respostaXml));
            } catch (Throwable $e) {
                return ResultadoDeEmissao::erro('A SEFAZ autorizou a nota (protocolo '.(string) $prot->nProt.') mas o XML autorizado não pôde ser montado: '.$e->getMessage());
            }
        }

        if ($cStat === '539' && preg_match('/chNFe:(\d{44})/', $motivo, $m) === 1) {
            return ResultadoDeEmissao::numeroOcupado($m[1], $motivo);
        }

        return ResultadoDeEmissao::rejeitada($cStat, $motivo);
    }

    public function consulta(string $respostaXml, ?string $xmlEnviado): ResultadoDeConsulta
    {
        $resp = $this->carregar($respostaXml);
        if ($resp === null) {
            return ResultadoDeConsulta::erro('Resposta ilegível da SEFAZ na consulta.');
        }
        $resp->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        // O primeiro cStat do documento é o do retConsSitNFe; os de protNFe/retCancNFe vêm depois.
        $cStat = (string) ($resp->xpath('//n:cStat')[0] ?? '');
        $motivo = (string) ($resp->xpath('//n:xMotivo')[0] ?? '');

        return match (true) {
            $cStat === '100' => $this->autorizadaNaConsulta($resp, $respostaXml, $xmlEnviado),
            in_array($cStat, ['101', '151'], true) => ResultadoDeConsulta::cancelada($cStat),
            $cStat === '217' => ResultadoDeConsulta::naoEncontrada(),
            default => ResultadoDeConsulta::erro("cStat {$cStat}: {$motivo}"),
        };
    }

    private function autorizadaNaConsulta(SimpleXMLElement $resp, string $respostaXml, ?string $xmlEnviado): ResultadoDeConsulta
    {
        $protocolo = (string) ($resp->xpath('//n:protNFe/n:infProt/n:nProt')[0] ?? '');
        $xml = null;
        if ($xmlEnviado !== null) {
            try {
                $xml = Complements::toAuthorize($xmlEnviado, $respostaXml);
            } catch (Throwable) {
                $xml = null;
            }
        }

        return ResultadoDeConsulta::autorizada($protocolo, $xml);
    }

    private function carregar(string $xml): ?SimpleXMLElement
    {
        $anterior = libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return $obj === false ? null : $obj;
    }
}
