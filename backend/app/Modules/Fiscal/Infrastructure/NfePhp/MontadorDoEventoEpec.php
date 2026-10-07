<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use DOMDocument;
use DOMElement;

/**
 * Corpo do evento EPEC (110140), lido do XML já montado: UF, IE, destinatário e totais que a NF-e autorizada depois
 * precisa repetir. Reproduz `Tools::sefazEPEC()` (inalcançável na sped-nfe 5.2.8) sem completar nenhum dado ausente.
 */
final class MontadorDoEventoEpec
{
    /** @return array{chave: string, tagAdic: string} */
    public function montar(string $xml, string $cOrgaoAutor, string $verAplic): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        if (! @$dom->loadXML($xml)) {
            throw $this->ausente('o XML da NF-e em EPEC é ilegível');
        }

        $infNFe = $this->no($dom, 'infNFe');
        $chave = substr($infNFe->getAttribute('Id'), 3, 44);
        if (strlen($chave) !== 44) {
            throw $this->ausente('a chave de acesso da NF-e');
        }
        if (substr($chave, 0, 2) !== $cOrgaoAutor) {
            throw $this->ausente("a UF do emitente ({$cOrgaoAutor}) não é a UF da chave (".substr($chave, 0, 2).')');
        }

        $emit = $this->no($dom, 'emit');
        $dest = $this->no($dom, 'dest');
        $total = $this->no($dom, 'total');

        $ieEmitente = $this->texto($emit, 'IE', 'a inscrição estadual do emitente');
        $dhEmi = $this->texto($infNFe, 'dhEmi', 'a data de emissão');
        $tpNF = $this->texto($infNFe, 'tpNF', 'o tipo da operação');
        $ufDest = $this->texto($dest, 'UF', 'a UF do destinatário');
        $vNF = $this->texto($total, 'vNF', 'o valor total da nota');
        $vICMS = $this->texto($total, 'vICMS', 'o valor do ICMS');
        $vST = $this->texto($total, 'vST', 'o valor do ICMS ST');

        $idDest = match (true) {
            $this->existe($dest, 'CNPJ') => '<CNPJ>'.$this->texto($dest, 'CNPJ', 'o CNPJ do destinatário').'</CNPJ>',
            $this->existe($dest, 'CPF') => '<CPF>'.$this->texto($dest, 'CPF', 'o CPF do destinatário').'</CPF>',
            $this->existe($dest, 'idEstrangeiro') => '<idEstrangeiro>'.$this->texto($dest, 'idEstrangeiro', 'o documento do destinatário').'</idEstrangeiro>',
            default => throw $this->ausente('o documento do destinatário'),
        };
        $ieDest = $this->existe($dest, 'IE') ? '<IE>'.$this->texto($dest, 'IE', 'a IE do destinatário').'</IE>' : '';

        $tagAdic = "<cOrgaoAutor>{$cOrgaoAutor}</cOrgaoAutor><tpAutor>1</tpAutor><verAplic>".htmlspecialchars($verAplic, ENT_XML1).'</verAplic>'
            ."<dhEmi>{$dhEmi}</dhEmi><tpNF>{$tpNF}</tpNF><IE>{$ieEmitente}</IE>"
            ."<dest><UF>{$ufDest}</UF>{$idDest}{$ieDest}<vNF>{$vNF}</vNF><vICMS>{$vICMS}</vICMS><vST>{$vST}</vST></dest>";

        return ['chave' => $chave, 'tagAdic' => $tagAdic];
    }

    private function no(DOMDocument $dom, string $nome): DOMElement
    {
        $no = $dom->getElementsByTagName($nome)->item(0);
        if (! $no instanceof DOMElement) {
            throw $this->ausente("o grupo {$nome} da NF-e");
        }

        return $no;
    }

    private function existe(DOMElement $pai, string $nome): bool
    {
        $no = $pai->getElementsByTagName($nome)->item(0);

        return $no !== null && trim($no->nodeValue ?? '') !== '';
    }

    private function texto(DOMElement $pai, string $nome, string $descricao): string
    {
        $no = $pai->getElementsByTagName($nome)->item(0);
        $valor = $no === null ? null : trim($no->nodeValue ?? '');
        if ($valor === null || $valor === '') {
            throw $this->ausente($descricao);
        }

        return htmlspecialchars($valor, ENT_XML1);
    }

    private function ausente(string $oQue): EmissaoBloqueadaException
    {
        return new EmissaoBloqueadaException('FISCAL_EPEC_DADO_AUSENTE', "Não foi possível registrar o EPEC: falta {$oQue}. Corrija o cadastro e emita de novo.");
    }
}
