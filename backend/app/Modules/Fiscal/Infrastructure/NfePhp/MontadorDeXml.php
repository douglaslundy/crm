<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Emissao\ContingenciaDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Domain\Quantidade;
use App\Modules\Fiscal\Domain\TabelaIbge;
use NFePHP\NFe\Make;
use RuntimeException;
use stdClass;

/** Só traduz formato: tudo que chega aqui já foi resolvido e validado (CFOP, CSOSN, indIEDest...). */
final class MontadorDeXml
{
    /** @return array{xml: string, chave: string} */
    public function montar(NotaParaEmissao $nota, ?string $cNF = null, ?ContingenciaDeEmissao $contingencia = null): array
    {
        $e = $nota->emitente;
        if (trim($e->cnpj) === '') {
            throw new RuntimeException('CNPJ do emitente vazio: a Make geraria a chave com zeros.');
        }
        $cUF = TabelaIbge::codigoDaUf($e->uf);
        $tpAmb = $nota->ambiente === 'PRODUCAO' ? 1 : 2;
        $nfce = $nota->modelo === ModeloDocumento::Nfce;
        $destinatario = $nota->destinatario;
        if ($contingencia !== null && $contingencia->tipo === TipoDeContingencia::OfflineNfce && ! $nfce) {
            throw new RuntimeException('Contingência offline só se aplica à NFC-e.');
        }
        if ($contingencia !== null && $contingencia->tipo === TipoDeContingencia::Epec && $nfce) {
            throw new RuntimeException('O EPEC só se aplica à NF-e.');
        }
        if (! $nfce && $destinatario === null) {
            throw new RuntimeException('NF-e sem destinatário: o Preparador deveria ter bloqueado.');
        }

        $make = new Make;
        $make->taginfNFe($this->std(['versao' => '4.00', 'Id' => null, 'pk_nItem' => '']));

        $ide = ['cUF' => $cUF, 'natOp' => $this->texto($nota->naturezaOperacao, 60), 'mod' => $nfce ? 65 : 55, 'serie' => (int) $nota->serie, 'nNF' => $nota->numero,
            'dhEmi' => now()->setTimezone('America/Sao_Paulo')->format('c'), 'tpNF' => 1, 'idDest' => $nota->idDest, 'cMunFG' => $e->codigoIbge,
            'tpImp' => $nfce ? 4 : 1, 'tpEmis' => match ($contingencia?->tipo) {
                null => 1, TipoDeContingencia::Epec => 4, TipoDeContingencia::OfflineNfce => 9
            }, 'tpAmb' => $tpAmb, 'finNFe' => 1, 'indFinal' => $nfce ? 1 : $destinatario->indFinal, 'indPres' => 1,
            'procEmi' => 0, 'verProc' => config('fiscal.ver_proc')];
        if ($cNF !== null) {
            $ide['cNF'] = $cNF;
        }
        if ($contingencia !== null) {
            $ide['dhCont'] = $contingencia->em->setTimezone('America/Sao_Paulo')->format('c');
            $ide['xJust'] = $this->texto($contingencia->justificativa, 255);
        }
        $make->tagide($this->std($ide));

        $make->tagemit($this->std(['CNPJ' => $e->cnpj, 'xNome' => $this->texto($e->razaoSocial, 60), 'xFant' => $e->nomeFantasia === null ? null : $this->texto($e->nomeFantasia, 60), 'IE' => $e->ie, 'CRT' => $e->crt]));
        $make->tagenderEmit($this->std([
            'xLgr' => $this->texto($e->logradouro, 60), 'nro' => $this->texto($e->numero, 60), 'xBairro' => $this->texto($e->bairro, 60),
            'cMun' => $e->codigoIbge, 'xMun' => $this->texto($e->cidade, 60), 'UF' => $e->uf, 'CEP' => $e->cep, 'cPais' => 1058, 'xPais' => 'Brasil',
        ]));

        if ($nfce) {
            // NFC-e: consumidor só com documento e nome (indIEDest 9), sem endereço; sem identificação, sem grupo dest.
            $c = $nota->consumidor;
            if ($c !== null) {
                $dest = ['xNome' => $this->texto($c->nome, 60), 'indIEDest' => 9];
                $dest[strlen($c->documento) > 11 ? 'CNPJ' : 'CPF'] = $c->documento;
                $make->tagdest($this->std($dest));
            }
        } else {
            $d = $destinatario;
            $dest = ['xNome' => $this->texto($d->nome, 60), 'indIEDest' => $d->indIeDest];
            $dest[strlen($d->documento) > 11 ? 'CNPJ' : 'CPF'] = $d->documento;
            if ($d->indIeDest === 1 && $d->ie !== null) {
                $dest['IE'] = $d->ie;
            }
            if ($d->email !== null) {
                $dest['email'] = $d->email;
            }
            $make->tagdest($this->std($dest));
            $make->tagenderDest($this->std([
                'xLgr' => $this->texto($d->logradouro, 60), 'nro' => $this->texto($d->numero, 60), 'xBairro' => $this->texto($d->bairro, 60),
                'cMun' => $d->codigoIbge, 'xMun' => $this->texto($d->cidade, 60), 'UF' => $d->uf, 'CEP' => $d->cep, 'cPais' => 1058, 'xPais' => 'Brasil',
            ]));
        }

        foreach ($nota->itens as $i) {
            $vUn = $this->reais($i->valorUnitarioCentavos);
            $prod = ['item' => $i->ordem, 'cProd' => $this->texto($i->sku, 60), 'cEAN' => $i->gtin ?? 'SEM GTIN', 'cEANTrib' => $i->gtin ?? 'SEM GTIN',
                'xProd' => $this->texto($i->descricao, 120), 'NCM' => $i->ncm, 'CFOP' => $i->cfop, 'uCom' => $i->unidade, 'uTrib' => $i->unidade,
                'qCom' => Quantidade::formatar($i->quantidadeMilesimos, 4), 'qTrib' => Quantidade::formatar($i->quantidadeMilesimos, 4),
                'vUnCom' => $vUn, 'vUnTrib' => $vUn, 'vProd' => $this->reais($i->totalCentavos), 'indTot' => 1];
            if ($i->cest !== null) {
                $prod['CEST'] = $i->cest;
            }
            if ($i->descontoCentavos > 0) {
                $prod['vDesc'] = $this->reais($i->descontoCentavos);
            }
            $make->tagprod($this->std($prod));
            $make->tagimposto($this->std(['item' => $i->ordem]));
            $make->tagICMSSN($this->std(['item' => $i->ordem, 'orig' => $i->origem, 'CSOSN' => $i->csosn]));
            $make->tagPIS($this->std(['item' => $i->ordem, 'CST' => '49', 'vBC' => 0, 'pPIS' => 0, 'vPIS' => 0]));
            $make->tagCOFINS($this->std(['item' => $i->ordem, 'CST' => '49', 'vBC' => 0, 'pCOFINS' => 0, 'vCOFINS' => 0]));
        }

        $make->tagICMSTot($this->std([
            'vBC' => 0, 'vICMS' => 0, 'vICMSDeson' => 0, 'vFCP' => 0, 'vBCST' => 0, 'vST' => 0, 'vFCPST' => 0, 'vFCPSTRet' => 0,
            'vProd' => $this->reais($nota->subtotalCentavos), 'vFrete' => 0, 'vSeg' => 0, 'vDesc' => $this->reais($nota->descontoCentavos),
            'vII' => 0, 'vIPI' => 0, 'vIPIDevol' => 0, 'vPIS' => 0, 'vCOFINS' => 0, 'vOutro' => 0, 'vNF' => $this->reais($nota->totalCentavos),
        ]));
        $make->tagtransp($this->std(['modFrete' => 9]));
        $make->tagpag($this->std([]));
        foreach ($nota->pagamentos as $p) {
            $det = ['indPag' => 0, 'tPag' => $p->tpag, 'vPag' => $this->reais($p->valorCentavos)];
            if ($p->tpag === '99') {
                $det['xPag'] = $this->texto((string) $p->xpag, 60);
            }
            $make->tagdetPag($this->std($det));
        }
        if ($nota->informacoesComplementares !== null && trim($nota->informacoesComplementares) !== '') {
            $make->taginfAdic($this->std(['infCpl' => $this->texto($nota->informacoesComplementares, 5000)]));
        }

        $xml = $make->getXML();
        $erros = $make->getErrors();
        if ($erros !== []) {
            throw new RuntimeException('Falha ao montar o XML da '.($nfce ? 'NFC-e' : 'NF-e').': '.implode(' | ', $erros));
        }

        return ['xml' => $xml, 'chave' => $make->getChave()];
    }

    /** @param array<string, mixed> $campos */
    private function std(array $campos): stdClass
    {
        return (object) $campos;
    }

    /** Dinheiro em centavos inteiros para "0.00", sem float. */
    private function reais(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Sanitiza texto livre ao que o schema aceita: sem caracteres de controle, espaços colapsados, corte no limite.
     * O escape de `&`, `<` e aspas fica a cargo da DOM da biblioteca.
     */
    private function texto(string $valor, int $limite): string
    {
        $limpo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valor) ?? '';
        $limpo = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $limpo) ?? '';
        $limpo = trim((string) preg_replace('/\s+/u', ' ', $limpo));

        return mb_substr($limpo, 0, $limite);
    }
}
