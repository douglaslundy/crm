<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use App\Modules\Fiscal\Domain\Emissao\EmitenteParaNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ServicoParaNfse;
use App\Modules\Fiscal\Domain\Emissao\TomadorParaNfse;
use App\Modules\Fiscal\Infrastructure\Nfse\MontadorDeDps;
use Carbon\CarbonImmutable;
use Nfse\Xml\DpsXmlBuilder;
use RuntimeException;
use Tests\TestCase;

class MontadorDeDpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 10:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function nota(string $regime = 'SIMPLES', string $documento = '52998224725', int $desconto = 0, ?string $cTribMun = null, ?string $info = null, string $descricao = 'Troca de oleo'): NotaParaNfse
    {
        return new NotaParaNfse(
            'nota-1', 'HOMOLOGACAO', '1', 7,
            new EmitenteParaNfse('11444777000161', '3550308', $regime),
            new TomadorParaNfse($documento, 'Maria Teste'),
            new ServicoParaNfse('140101', $cTribMun, $descricao, '5.00', null),
            15000, $desconto, $info,
        );
    }

    private function xml(NotaParaNfse $nota): string
    {
        return (new DpsXmlBuilder)->build((new MontadorDeDps)->montar($nota));
    }

    public function test_dps_do_simples_segue_as_regras_da_adn(): void
    {
        $xml = $this->xml($this->nota());

        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<serie>1</serie>', $xml);
        $this->assertStringContainsString('<nDPS>7</nDPS>', $xml);
        $this->assertStringContainsString('<dCompet>2026-10-07</dCompet>', $xml);
        $this->assertSame(1, preg_match('/<dhEmi>2026-10-07T10:00:00-03:00<\/dhEmi>/', $xml), 'dhEmi com o offset de São Paulo');
        $this->assertStringContainsString('<tpEmit>1</tpEmit>', $xml);
        $this->assertStringContainsString('<cLocEmi>3550308</cLocEmi>', $xml);
        $this->assertStringContainsString('<cLocPrestacao>3550308</cLocPrestacao>', $xml);
        $this->assertStringContainsString('<opSimpNac>3</opSimpNac>', $xml);
        $this->assertStringContainsString('<regApTribSN>1</regApTribSN>', $xml);
        $this->assertStringContainsString('<cTribNac>140101</cTribNac>', $xml);
        $this->assertStringContainsString('<tribISSQN>1</tribISSQN>', $xml);
        $this->assertStringContainsString('<tpRetISSQN>1</tpRetISSQN>', $xml);
        $this->assertStringContainsString('<pTotTribSN>0.00</pTotTribSN>', $xml);
        $this->assertStringContainsString('<vServ>150.00</vServ>', $xml);
        // Nunca enviados: IM e endereço do prestador (E0120/E0128), alíquota no Simples (E0625), indTotTrib (E0712).
        preg_match('/<prest>.*<\/prest>/s', $xml, $prest);
        $this->assertStringNotContainsString('<IM>', $prest[0]);
        $this->assertStringNotContainsString('<end', $prest[0]);
        $this->assertStringNotContainsString('<pAliq>', $xml);
        $this->assertStringNotContainsString('<indTotTrib>', $xml);
        $this->assertStringNotContainsString('<vDescCondIncond>', $xml);
    }

    public function test_mei_usa_op_simp_nac_2(): void
    {
        $this->assertStringContainsString('<opSimpNac>2</opSimpNac>', $this->xml($this->nota('MEI')));
    }

    public function test_regime_sem_traducao_lanca(): void
    {
        $this->expectException(RuntimeException::class);

        (new MontadorDeDps)->montar($this->nota('NORMAL'));
    }

    public function test_tomador_cpf_e_cnpj(): void
    {
        $this->assertStringContainsString('<CPF>52998224725</CPF>', $this->xml($this->nota()));
        $this->assertStringContainsString('<CNPJ>11222333000181</CNPJ>', $this->xml($this->nota('SIMPLES', '11222333000181')));
    }

    public function test_desconto_vai_entre_o_valor_e_a_tributacao(): void
    {
        $xml = $this->xml($this->nota('SIMPLES', '52998224725', 1000));

        $this->assertStringContainsString('<vDescIncond>10.00</vDescIncond>', $xml);
        $this->assertLessThan(strpos($xml, '<vDescCondIncond>'), strpos($xml, '<vServPrest>'));
        $this->assertLessThan(strpos($xml, '<trib>'), strpos($xml, '<vDescCondIncond>'));
    }

    public function test_c_trib_mun_so_com_exatamente_3_digitos(): void
    {
        $this->assertStringContainsString('<cTribMun>123</cTribMun>', $this->xml($this->nota('SIMPLES', '52998224725', 0, '123')));
        $this->assertStringNotContainsString('<cTribMun>', $this->xml($this->nota('SIMPLES', '52998224725', 0, '1234')));
        $this->assertStringNotContainsString('<cTribMun>', $this->xml($this->nota()));
    }

    public function test_informacoes_complementares_so_quando_ha_texto(): void
    {
        $this->assertStringContainsString('<xInfComp>Placa ABC1D23</xInfComp>', $this->xml($this->nota('SIMPLES', '52998224725', 0, null, 'Placa ABC1D23')));
        $this->assertStringNotContainsString('<xInfComp>', $this->xml($this->nota('SIMPLES', '52998224725', 0, null, '   ')));
        $this->assertStringNotContainsString('<xInfComp>', $this->xml($this->nota()));
    }

    public function test_descricao_e_sanitizada(): void
    {
        $xml = $this->xml($this->nota('SIMPLES', '52998224725', 0, null, null, "Troca\n\n de   oleo\t& filtro"));

        $this->assertStringContainsString('<xDescServ>Troca de oleo &amp; filtro</xDescServ>', $xml);
    }

    public function test_id_da_dps_tem_45_caracteres_e_usa_serie_e_ndps(): void
    {
        $id = MontadorDeDps::idDaDps($this->nota());

        $this->assertSame('DPS3550308211444777000161'.'00001'.'000000000000007', $id);
        $this->assertStringContainsString('Id="'.$id.'"', $this->xml($this->nota()));
    }
}
