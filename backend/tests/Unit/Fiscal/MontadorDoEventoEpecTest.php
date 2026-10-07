<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Emissao\ContingenciaDeEmissao;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDeXml;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDoEventoEpec;
use Carbon\CarbonImmutable;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\TestCase;

class MontadorDoEventoEpecTest extends TestCase
{
    use AssinaNfeDeTeste;

    private function xmlEpec(): string
    {
        $epec = new ContingenciaDeEmissao('Falha de comunicação com a SEFAZ na emissão; emitida em contingência EPEC.', CarbonImmutable::now(), TipoDeContingencia::Epec);

        return (new MontadorDeXml)->montar($this->notaDeTeste(), '47382915', $epec)['xml'];
    }

    public function test_monta_o_corpo_do_evento_a_partir_do_xml(): void
    {
        $xml = $this->xmlEpec();

        ['chave' => $chave, 'tagAdic' => $tag] = (new MontadorDoEventoEpec)->montar($xml, '35', 'plataforma-1.0');

        $this->assertSame(44, strlen($chave));
        $this->assertSame('4', $chave[34]);
        $this->assertStringContainsString('<cOrgaoAutor>35</cOrgaoAutor><tpAutor>1</tpAutor><verAplic>plataforma-1.0</verAplic>', $tag);
        $this->assertSame(1, preg_match('/<dhEmi>[^<]+<\/dhEmi><tpNF>1<\/tpNF><IE>110042490114<\/IE>/', $tag));
        $this->assertStringContainsString('<dest><UF>SP</UF><CPF>52998224725</CPF><vNF>25.00</vNF><vICMS>0.00</vICMS><vST>0.00</vST></dest>', $tag);
    }

    public function test_dhemi_do_evento_e_o_mesmo_do_xml(): void
    {
        $xml = $this->xmlEpec();
        preg_match('/<dhEmi>([^<]+)<\/dhEmi>/', $xml, $dhEmi);

        $tag = (new MontadorDoEventoEpec)->montar($xml, '35', 'v')['tagAdic'];

        $this->assertStringContainsString("<dhEmi>{$dhEmi[1]}</dhEmi>", $tag);
    }

    public function test_dado_ausente_bloqueia_em_vez_de_assumir_default(): void
    {
        $casos = [
            'sem destinatário' => fn (string $x): string => (string) preg_replace('/<dest>.*<\/dest>/s', '', $x),
            'sem vST' => fn (string $x): string => str_replace('<vST>0.00</vST>', '', $x),
            'sem vICMS' => fn (string $x): string => str_replace('<vICMS>0.00</vICMS>', '', $x),
            'sem IE do emitente' => fn (string $x): string => str_replace('<IE>110042490114</IE>', '', $x),
        ];
        foreach ($casos as $nome => $quebrar) {
            try {
                (new MontadorDoEventoEpec)->montar($quebrar($this->xmlEpec()), '35', 'v');
                $this->fail("Deveria bloquear: {$nome}");
            } catch (EmissaoBloqueadaException $e) {
                $this->assertSame('FISCAL_EPEC_DADO_AUSENTE', $e->codigo(), $nome);
            }
        }
    }

    public function test_uf_da_chave_diferente_da_uf_do_autor_bloqueia(): void
    {
        $this->expectException(EmissaoBloqueadaException::class);

        (new MontadorDoEventoEpec)->montar($this->xmlEpec(), '31', 'v');
    }
}
