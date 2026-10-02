<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;
use App\Modules\Fiscal\Infrastructure\NfePhp\InterpretadorDeResposta;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InterpretadorCancelamentoTest extends TestCase
{
    private const CHAVE = '35261011444777000161550010000000071123456789';

    private function nfeProc(): string
    {
        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe'.self::CHAVE.'"/></NFe>'
            .'<protNFe versao="4.00"><infProt><chNFe>'.self::CHAVE.'</chNFe><nProt>135260000000001</nProt><cStat>100</cStat></infProt></protNFe></nfeProc>';
    }

    private function evento(string $cStat, string $motivo = 'Evento registrado e vinculado a NF-e', string $cStatLote = '128'): string
    {
        return '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><cStat>'.$cStatLote.'</cStat><xMotivo>Lote de Evento Processado</xMotivo>'
            .'<retEvento versao="1.00"><infEvento><cStat>'.$cStat.'</cStat><xMotivo>'.$motivo.'</xMotivo><chNFe>'.self::CHAVE.'</chNFe><tpEvento>110111</tpEvento><nProt>135260000000555</nProt></infEvento></retEvento></retEnvEvento>';
    }

    public function test_135_136_e_155_sao_cancelamento_aceito_e_o_xml_traz_o_evento(): void
    {
        foreach (['135', '136', '155'] as $cStat) {
            $r = (new InterpretadorDeResposta)->cancelamento($this->evento($cStat), $this->nfeProc());

            $this->assertTrue($r->aceito, $cStat);
            $this->assertSame($cStat, $r->cstat);
            $this->assertSame('135260000000555', $r->protocolo);
            $this->assertStringContainsString('<nfeProc', (string) $r->xmlCancelamento);
            $this->assertStringContainsString('<retEvento', (string) $r->xmlCancelamento);
        }
    }

    public function test_recusa_da_sefaz_traz_cstat_e_motivo(): void
    {
        $r = (new InterpretadorDeResposta)->cancelamento($this->evento('501', 'Rejeicao: Pedido de Cancelamento intempestivo'), $this->nfeProc());

        $this->assertFalse($r->aceito);
        $this->assertSame('501', $r->cstat);
        $this->assertStringContainsString('intempestivo', (string) $r->motivo);
        $this->assertNull($r->xmlCancelamento);
    }

    public function test_lote_rejeitado_sem_retevento_e_recusa(): void
    {
        $xml = '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><cStat>215</cStat><xMotivo>Rejeicao: Falha no schema XML</xMotivo></retEnvEvento>';
        $r = (new InterpretadorDeResposta)->cancelamento($xml, $this->nfeProc());

        $this->assertFalse($r->aceito);
        $this->assertSame('215', $r->cstat);
    }

    public function test_aceito_sem_conseguir_montar_o_xml_guarda_a_resposta_crua(): void
    {
        $r = (new InterpretadorDeResposta)->cancelamento($this->evento('135'), '<nao-e-nfeproc/>');

        $this->assertTrue($r->aceito, 'a SEFAZ aceitou: continua sendo cancelamento');
        $this->assertStringContainsString('<retEvento', (string) $r->xmlCancelamento);
    }

    public function test_resposta_ilegivel_ou_sem_status_lanca_excecao(): void
    {
        foreach (['isto nao e xml', '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe"/>'] as $xml) {
            try {
                (new InterpretadorDeResposta)->cancelamento($xml, $this->nfeProc());
                $this->fail('Esperava RuntimeException.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_inutilizacao_102_563_e_outros(): void
    {
        $mk = fn (string $c, string $m = 'm', string $prot = ''): string => '<retInutNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><infInut><tpAmb>2</tpAmb><cStat>'.$c.'</cStat><xMotivo>'.$m.'</xMotivo>'
            .($prot !== '' ? '<nProt>'.$prot.'</nProt>' : '').'</infInut></retInutNFe>';
        $i = new InterpretadorDeResposta;

        $ok = $i->inutilizacao($mk('102', 'Inutilizacao de numero homologado', '135260000000777'));
        $this->assertSame(StatusInutilizacao::Inutilizada, $ok->status);
        $this->assertSame('135260000000777', $ok->protocolo);
        $this->assertStringContainsString('<retInutNFe', (string) $ok->xmlResposta);

        $dup = $i->inutilizacao($mk('563', 'Rejeicao: Ja existe pedido de Inutilizacao com a mesma faixa de inutilizacao'));
        $this->assertSame(StatusInutilizacao::Recusada, $dup->status);
        $this->assertSame('563', $dup->cstat);
        $this->assertStringContainsString('já foi inutilizada', (string) $dup->motivo);

        $rec = $i->inutilizacao($mk('241', 'Rejeicao: Um numero da faixa ja foi utilizado'));
        $this->assertSame(StatusInutilizacao::Recusada, $rec->status);
        $this->assertSame('241', $rec->cstat);
    }

    public function test_inutilizacao_ilegivel_lanca_excecao(): void
    {
        $this->expectException(RuntimeException::class);
        (new InterpretadorDeResposta)->inutilizacao('lixo');
    }
}
