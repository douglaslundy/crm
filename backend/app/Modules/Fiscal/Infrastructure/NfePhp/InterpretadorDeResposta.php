<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEpec;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use NFePHP\NFe\Complements;
use RuntimeException;
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

        // 100 autorizada; 150 autorizada fora do prazo (a SEFAZ autorizou, só a transmissão passou do limite).
        if (in_array($cStat, ['100', '150'], true)) {
            try {
                return ResultadoDeEmissao::autorizada((string) $prot->nProt, Complements::toAuthorize($xmlAssinado, $respostaXml), $cStat);
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
            in_array($cStat, ['100', '150'], true) => $this->autorizadaNaConsulta($resp, $respostaXml, $xmlEnviado, $cStat),
            in_array($cStat, ['101', '151'], true) => ResultadoDeConsulta::cancelada($cStat),
            in_array($cStat, ResultadoDeConsulta::DENEGADAS, true) => ResultadoDeConsulta::denegada($cStat, $motivo),
            $cStat === '217' => ResultadoDeConsulta::naoEncontrada(),
            default => ResultadoDeConsulta::erro("cStat {$cStat}: {$motivo}"),
        };
    }

    public function cancelamento(string $respostaXml, string $xmlAutorizado): ResultadoDeCancelamento
    {
        $resp = $this->carregar($respostaXml);
        if ($resp === null) {
            throw new RuntimeException('Resposta ilegível da SEFAZ ao cancelar a nota.');
        }
        $resp->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $evento = $resp->xpath('//n:retEvento/n:infEvento')[0] ?? null;

        if ($evento === null) {
            // Sem retEvento: o lote foi recusado antes de chegar ao evento (o cStat de topo é o do lote).
            $cStat = (string) ($resp->xpath('//n:cStat')[0] ?? '');
            if ($cStat === '') {
                throw new RuntimeException('Resposta da SEFAZ sem status ao cancelar a nota.');
            }

            return ResultadoDeCancelamento::recusado($cStat, (string) ($resp->xpath('//n:xMotivo')[0] ?? ''));
        }

        // O cStat que vale é o de dentro de retEvento; o de topo é do lote.
        $cStat = (string) $evento->cStat;
        if (! in_array($cStat, ['135', '136', '155'], true)) {
            return ResultadoDeCancelamento::recusado($cStat, (string) $evento->xMotivo);
        }

        try {
            $xml = Complements::cancelRegister($xmlAutorizado, $respostaXml);
        } catch (Throwable) {
            // A SEFAZ aceitou: continua sendo cancelamento. Sem o nfeProc, guardamos a resposta crua do evento.
            $xml = $respostaXml;
        }

        return ResultadoDeCancelamento::aceito((string) $evento->nProt, $xml, $cStat);
    }

    public function inutilizacao(string $respostaXml): ResultadoDeInutilizacao
    {
        $resp = $this->carregar($respostaXml);
        if ($resp === null) {
            throw new RuntimeException('Resposta ilegível da SEFAZ ao inutilizar a numeração.');
        }
        $resp->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $cStat = (string) ($resp->xpath('//n:infInut/n:cStat')[0] ?? '');
        $motivo = (string) ($resp->xpath('//n:infInut/n:xMotivo')[0] ?? '');
        if ($cStat === '') {
            throw new RuntimeException('Resposta da SEFAZ sem status ao inutilizar a numeração.');
        }

        return match ($cStat) {
            '102' => ResultadoDeInutilizacao::inutilizada((string) ($resp->xpath('//n:infInut/n:nProt')[0] ?? ''), $respostaXml),
            '563' => ResultadoDeInutilizacao::jaInutilizada($motivo),
            default => ResultadoDeInutilizacao::recusada($cStat, $motivo),
        };
    }

    public function epec(string $respostaXml): ResultadoDeEpec
    {
        $resp = $this->carregar($respostaXml);
        if ($resp === null) {
            throw new RuntimeException('Resposta ilegível da SEFAZ ao registrar o EPEC.');
        }
        $resp->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');

        $evento = $resp->xpath('//n:retEvento/n:infEvento')[0] ?? null;
        if ($evento === null) {
            // Sem retEvento o lote foi recusado antes do evento: o cStat de topo é o do lote.
            $cStat = (string) ($resp->xpath('//n:cStat')[0] ?? '');
            if ($cStat === '') {
                throw new RuntimeException('Resposta da SEFAZ sem status ao registrar o EPEC.');
            }

            return ResultadoDeEpec::recusado($cStat, (string) ($resp->xpath('//n:xMotivo')[0] ?? ''));
        }

        // O cStat que vale é o de dentro de retEvento; 135 e 136 são evento registrado.
        $cStat = (string) $evento->cStat;
        if (! in_array($cStat, ['135', '136'], true)) {
            return ResultadoDeEpec::recusado($cStat, (string) $evento->xMotivo);
        }

        return ResultadoDeEpec::registrado((string) $evento->nProt, $respostaXml, $cStat);
    }

    private function autorizadaNaConsulta(SimpleXMLElement $resp, string $respostaXml, ?string $xmlEnviado, string $cStat): ResultadoDeConsulta
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

        return ResultadoDeConsulta::autorizada($protocolo, $xml, $cStat);
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
