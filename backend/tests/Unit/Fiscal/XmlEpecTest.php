<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Emissao\ConsumidorParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ContingenciaDeEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDeXml;
use Carbon\CarbonImmutable;
use NFePHP\Common\Validator;
use RuntimeException;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\TestCase;

class XmlEpecTest extends TestCase
{
    use AssinaNfeDeTeste;

    private const JUSTIFICATIVA = 'Falha de comunicação com a SEFAZ na emissão; emitida em contingência EPEC.';

    private function epec(): ContingenciaDeEmissao
    {
        return new ContingenciaDeEmissao(self::JUSTIFICATIVA, CarbonImmutable::parse('2026-10-06 14:30:00', 'America/Sao_Paulo'), TipoDeContingencia::Epec);
    }

    public function test_nfe_em_epec_tem_tpemis_4_dhcont_xjust_e_chave_propria(): void
    {
        ['xml' => $normal, 'chave' => $chaveNormal] = (new MontadorDeXml)->montar($this->notaDeTeste(), '47382915');
        ['xml' => $xml, 'chave' => $chave] = (new MontadorDeXml)->montar($this->notaDeTeste(), '47382915', $this->epec());

        $this->assertStringContainsString('<tpEmis>4</tpEmis>', $xml);
        $this->assertSame(1, preg_match('/<dhCont>2026-10-06T14:30:00-03:00<\/dhCont>/', $xml), 'dhCont com o offset de São Paulo');
        $this->assertStringContainsString('<xJust>'.self::JUSTIFICATIVA.'</xJust>', $xml);
        $this->assertSame('4', $chave[34], 'tpEmis na chave');
        $this->assertNotSame($chaveNormal, $chave);
        $this->assertStringContainsString('<tpEmis>1</tpEmis>', $normal);
        $this->assertStringNotContainsString('<dhCont>', $normal);
    }

    public function test_nfe_em_epec_assinada_valida_no_xsd(): void
    {
        ['xml' => $xml] = (new MontadorDeXml)->montar($this->notaDeTeste(), '47382915', $this->epec());

        $assinado = $this->assinar($xml, 55);

        $xsd = dirname(__DIR__, 3).'/vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/nfe_v4.00.xsd';
        $this->assertTrue(Validator::isValid($assinado, $xsd), 'XML fora do XSD: '.json_encode(Validator::$errors ?? []));
    }

    public function test_nfce_nao_remonta_como_epec(): void
    {
        $nfce = $this->notaDeTeste(modelo: ModeloDocumento::Nfce, consumidor: new ConsumidorParaEmissao('52998224725', 'Joana Consumidora'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EPEC');

        (new MontadorDeXml)->montar($nfce, '47382915', $this->epec());
    }

    public function test_nfe_nao_remonta_como_offline(): void
    {
        $offline = new ContingenciaDeEmissao(self::JUSTIFICATIVA, CarbonImmutable::now());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NFC-e');

        (new MontadorDeXml)->montar($this->notaDeTeste(), '47382915', $offline);
    }
}
