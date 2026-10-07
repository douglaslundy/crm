<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use App\Modules\Fiscal\Infrastructure\Nfse\MontadorDeEventoDeCancelamento;
use Nfse\Xml\EventosXmlBuilder;
use Tests\TestCase;

class MontadorDeEventoDeCancelamentoTest extends TestCase
{
    private const CHAVE = '31305072212345678000195000000000000226096821694069';

    public function test_evento_101101_com_chave_de_50_digitos_autor_e_motivo(): void
    {
        $evento = (new MontadorDeEventoDeCancelamento)->montar(self::CHAVE, 'Erro na emissão da nota fiscal', 'HOMOLOGACAO', '11444777000161');

        $xml = (new EventosXmlBuilder)->buildPedRegEvento($evento);

        $this->assertStringContainsString('<chNFSe>'.self::CHAVE.'</chNFSe>', $xml);
        $this->assertStringContainsString('<CNPJAutor>11444777000161</CNPJAutor>', $xml);
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<xDesc>Cancelamento de NFS-e</xDesc>', $xml);
        $this->assertStringContainsString('<cMotivo>1</cMotivo>', $xml);
        $this->assertStringContainsString('<xMotivo>Erro na emissão da nota fiscal</xMotivo>', $xml);
    }

    public function test_aceita_a_chave_com_prefixo_e_usa_producao(): void
    {
        $evento = (new MontadorDeEventoDeCancelamento)->montar('NFS'.self::CHAVE, 'O cliente desistiu da compra', 'PRODUCAO', '11444777000161');

        $xml = (new EventosXmlBuilder)->buildPedRegEvento($evento);

        $this->assertStringContainsString('<chNFSe>'.self::CHAVE.'</chNFSe>', $xml);
        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $xml);
        $this->assertStringContainsString('<cMotivo>9</cMotivo>', $xml);
    }
}
