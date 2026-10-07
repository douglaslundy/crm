<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Emissao;

use App\Modules\Fiscal\Domain\Models\Nota;
use SimpleXMLElement;

/**
 * O Id da DPS é município + CNPJ + série + nDPS: não tem o código aleatório da chave da NF-e. "A DPS existe na ADN"
 * não prova que é ESTA nota (numeração já usada por outro sistema, ou nota editada depois de um timeout).
 * Antes de adotar a nota que a ADN devolveu, confere o valor do serviço e o documento do tomador.
 */
final class ConferenciaDaDps
{
    /**
     * @return array{mensagem: string, outroTomador: bool}|null nulo quando o XML da ADN corresponde à nota
     */
    public function divergencia(Nota $nota, ?string $xml): ?array
    {
        $infDps = $this->infDps($xml);
        $valorDoXml = $infDps === null ? '' : trim((string) $infDps->valores->vServPrest->vServ);
        $docDoXml = $infDps === null ? '' : $this->documentoDoTomador($infDps);
        $docDaNota = preg_replace('/\D/', '', (string) ($nota->destinatario['documento'] ?? '')) ?? '';

        if ($valorDoXml === '' || $docDoXml === '' || $docDaNota === '') {
            return ['mensagem' => 'A ADN devolveu uma NFS-e para esta DPS, mas não foi possível conferir o valor e o tomador no XML. Confira a nota no portal nacional antes de emitir de novo.', 'outroTomador' => false];
        }

        $outroTomador = $docDoXml !== $docDaNota;
        $centavosDoXml = (int) round(((float) $valorDoXml) * 100);
        if ($outroTomador) {
            return [
                'mensagem' => "O nº {$nota->numero} da série {$nota->serie} já foi usado por outra NFS-e na ADN (tomador diferente). O número foi liberado: emita de novo para usar o próximo e, se a série vem de outro sistema, ajuste o próximo número em Configurações > Fiscal.",
                'outroTomador' => true,
            ];
        }
        if ($centavosDoXml !== $nota->subtotal_centavos) {
            return [
                'mensagem' => 'A ADN já tem uma NFS-e com o nº '.$nota->numero." da série {$nota->serie} para este tomador, mas com o valor R$ ".number_format($centavosDoXml / 100, 2, ',', '.')
                    .' (esta nota tem R$ '.number_format($nota->subtotal_centavos / 100, 2, ',', '.').'). Pode ser uma emissão anterior desta nota, antes de você editá-la: confira no portal nacional e, se for ela, cancele-a lá antes de emitir de novo.',
                'outroTomador' => false,
            ];
        }

        return null;
    }

    private function infDps(?string $xml): ?SimpleXMLElement
    {
        if ($xml === null || $xml === '') {
            return null;
        }
        $x = @simplexml_load_string($xml);
        if ($x === false || ! isset($x->infNFSe->DPS->infDPS)) {
            return null;
        }

        return $x->infNFSe->DPS->infDPS;
    }

    private function documentoDoTomador(SimpleXMLElement $infDps): string
    {
        $cnpj = trim((string) $infDps->toma->CNPJ);

        return preg_replace('/\D/', '', $cnpj !== '' ? $cnpj : (string) $infDps->toma->CPF) ?? '';
    }
}
