<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Infrastructure\NfePhp\InterpretadorDeResposta;
use RuntimeException;
use Tests\TestCase;

class InterpretadorEpecTest extends TestCase
{
    private function resposta(string $cStatEvento, string $motivo = 'Evento registrado', string $nProt = '891260000000001'): string
    {
        return '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>1</idLote><tpAmb>2</tpAmb><verAplic>AN</verAplic><cOrgao>91</cOrgao>'
            .'<cStat>128</cStat><xMotivo>Lote de Evento Processado</xMotivo><retEvento versao="1.00"><infEvento><tpAmb>2</tpAmb><verAplic>AN</verAplic><cOrgao>91</cOrgao>'
            ."<cStat>{$cStatEvento}</cStat><xMotivo>{$motivo}</xMotivo><tpEvento>110140</tpEvento><nProt>{$nProt}</nProt></infEvento></retEvento></retEnvEvento>";
    }

    private function interpretador(): InterpretadorDeResposta
    {
        return $this->app->make(InterpretadorDeResposta::class);
    }

    public function test_135_e_136_sao_epec_registrado_com_o_protocolo(): void
    {
        foreach (['135', '136'] as $cStat) {
            $r = $this->interpretador()->epec($this->resposta($cStat));

            $this->assertTrue($r->registrado, $cStat);
            $this->assertSame('891260000000001', $r->protocolo);
            $this->assertSame($cStat, $r->cstat);
            $this->assertStringContainsString('retEvento', (string) $r->xmlEvento);
        }
    }

    public function test_outro_cstat_do_evento_e_recusa_com_motivo(): void
    {
        $r = $this->interpretador()->epec($this->resposta('491', 'Rejeicao: dado do EPEC difere'));

        $this->assertFalse($r->registrado);
        $this->assertSame('491', $r->cstat);
        $this->assertSame('Rejeicao: dado do EPEC difere', $r->motivo);
        $this->assertNull($r->protocolo);
    }

    public function test_lote_recusado_sem_ret_evento_e_recusa_com_o_cstat_do_lote(): void
    {
        $xml = '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>1</idLote><tpAmb>2</tpAmb><verAplic>AN</verAplic><cOrgao>91</cOrgao><cStat>215</cStat><xMotivo>Rejeicao: Falha no schema XML</xMotivo></retEnvEvento>';

        $r = $this->interpretador()->epec($xml);

        $this->assertFalse($r->registrado);
        $this->assertSame('215', $r->cstat);
        $this->assertSame('Rejeicao: Falha no schema XML', $r->motivo);
    }

    public function test_resposta_ilegivel_ou_sem_status_lanca(): void
    {
        foreach (['<isto nao e xml', '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe"/>'] as $xml) {
            try {
                $this->interpretador()->epec($xml);
                $this->fail('Deveria lançar.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
