<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Emissao\ConsumidorParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ContingenciaDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDeXml;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use NFePHP\Common\Validator;
use RuntimeException;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\TestCase;

class XmlContingenciaTest extends TestCase
{
    use AssinaNfeDeTeste;

    private const JUSTIFICATIVA = 'Falha de comunicação com a SEFAZ na emissão; emitida em contingência offline.';

    private function contingencia(): ContingenciaDeEmissao
    {
        return new ContingenciaDeEmissao(self::JUSTIFICATIVA, CarbonImmutable::parse('2026-10-05 14:30:00', 'America/Sao_Paulo'));
    }

    private function nfce(): NotaParaEmissao
    {
        return $this->notaDeTeste(modelo: ModeloDocumento::Nfce, consumidor: new ConsumidorParaEmissao('52998224725', 'Joana Consumidora'));
    }

    public function test_nfce_offline_tem_tpemis_9_dhcont_xjust_e_chave_propria(): void
    {
        ['xml' => $normal, 'chave' => $chaveNormal] = (new MontadorDeXml)->montar($this->nfce(), '47382915');
        ['xml' => $xml, 'chave' => $chave] = (new MontadorDeXml)->montar($this->nfce(), '47382915', $this->contingencia());

        $this->assertStringContainsString('<tpEmis>9</tpEmis>', $xml);
        $this->assertSame(1, preg_match('/<dhCont>2026-10-05T14:30:00-03:00<\/dhCont>/', $xml), 'dhCont com o offset de São Paulo');
        $this->assertStringContainsString('<xJust>'.self::JUSTIFICATIVA.'</xJust>', $xml);
        $this->assertSame('9', $chave[34], 'tpEmis na chave');
        $this->assertNotSame($chaveNormal, $chave);
        $this->assertStringContainsString('<tpEmis>1</tpEmis>', $normal);
        $this->assertStringNotContainsString('<dhCont>', $normal);
    }

    public function test_nfce_offline_assinada_com_csc_tem_qr_code_e_valida_no_xsd(): void
    {
        ['xml' => $xml] = (new MontadorDeXml)->montar($this->nfce(), '47382915', $this->contingencia());

        $assinado = $this->assinar($xml, 65);

        $this->assertStringContainsString('<qrCode>', $assinado);
        $xsd = dirname(__DIR__, 3).'/vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/nfe_v4.00.xsd';
        $this->assertTrue(Validator::isValid($assinado, $xsd), 'XML fora do XSD: '.json_encode(Validator::$errors ?? []));
    }

    public function test_so_a_nfce_remonta_offline(): void
    {
        $this->expectException(RuntimeException::class);

        (new MontadorDeXml)->montar($this->notaDeTeste(), '47382915', $this->contingencia());
    }

    public function test_justificativa_curta_ou_longa_demais_lanca(): void
    {
        foreach (['curta', str_repeat('a', 256)] as $texto) {
            try {
                new ContingenciaDeEmissao($texto, CarbonImmutable::now());
                $this->fail('Deveria recusar a justificativa de '.mb_strlen($texto).' caracteres.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
