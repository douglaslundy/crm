<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Nfse\Dto\Nfse\DpsData;
use Nfse\Support\IdGenerator;
use Nfse\Xml\DpsXmlBuilder;
use Tests\TestCase;

/** Fixa o que o plano usa da nfse-php: se uma atualização da lib mudar isto, o teste acusa antes da ADN. */
class BibliotecaNfseTest extends TestCase
{
    /** @param  array<string, mixed>  $valores */
    private function dps(array $valores): DpsData
    {
        return new DpsData([
            '@attributes' => ['versao' => '1.01'],
            'infDPS' => [
                '@attributes' => ['Id' => IdGenerator::generateDpsId('11444777000161', '3550308', '1', '7')],
                'tpAmb' => 2, 'dhEmi' => '2026-10-07T10:00:00-03:00', 'verAplic' => 'plataforma-1.0', 'serie' => '1', 'nDPS' => '7',
                'dCompet' => '2026-10-07', 'tpEmit' => 1, 'cLocEmi' => '3550308',
                'prest' => ['CNPJ' => '11444777000161', 'regTrib' => ['opSimpNac' => 3, 'regApTribSN' => 1, 'regEspTrib' => 0]],
                'toma' => ['CPF' => '52998224725', 'xNome' => 'Maria Teste'],
                'serv' => ['locPrest' => ['cLocPrestacao' => '3550308'], 'cServ' => ['cTribNac' => '140101', 'xDescServ' => 'Troca de oleo']],
                'valores' => $valores,
            ],
        ]);
    }

    public function test_o_id_da_dps_tem_45_caracteres_no_formato_esperado(): void
    {
        $id = IdGenerator::generateDpsId('11444777000161', '3550308', '1', '7');

        $this->assertSame('DPS3550308211444777000161'.'00001'.'000000000000007', $id);
        $this->assertSame(45, strlen($id));
    }

    public function test_o_xml_da_dps_leva_o_desconto_entre_o_valor_e_a_tributacao(): void
    {
        $xml = (new DpsXmlBuilder)->build($this->dps([
            'vServPrest' => ['vServ' => 150.0],
            'vDescCondIncond' => ['vDescIncond' => 10.0],
            'trib' => ['tribMun' => ['tribISSQN' => 1, 'tpRetISSQN' => 1], 'totTrib' => ['pTotTribSN' => 0.001]],
        ]));

        $this->assertStringContainsString('<vServ>150.00</vServ>', $xml);
        $this->assertStringContainsString('<vDescIncond>10.00</vDescIncond>', $xml);
        $this->assertStringContainsString('<pTotTribSN>0.00</pTotTribSN>', $xml);
        $this->assertStringNotContainsString('<pAliq>', $xml);
        $this->assertLessThan(strpos($xml, '<vDescCondIncond>'), strpos($xml, '<vServPrest>'));
        $this->assertLessThan(strpos($xml, '<trib>'), strpos($xml, '<vDescCondIncond>'));
    }

    public function test_dompdf_e_qr_code_estao_disponiveis(): void
    {
        $this->assertTrue(class_exists(Pdf::class));
        $this->assertTrue(class_exists(Builder::class));
    }
}
