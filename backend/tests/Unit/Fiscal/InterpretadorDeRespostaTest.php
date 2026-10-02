<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Infrastructure\NfePhp\InterpretadorDeResposta;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDeXml;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\TestCase;

class InterpretadorDeRespostaTest extends TestCase
{
    use AssinaNfeDeTeste;

    /** NF-e de verdade, assinada: a lib confere o digest da assinatura contra o `digVal` do protocolo. */
    private function nfeAssinada(): string
    {
        return $this->assinar((new MontadorDeXml)->montar($this->notaDeTeste(), '47382915')['xml']);
    }

    /** Protocolo coerente com a NF-e assinada (mesma chave e mesmo digest). */
    private function protocolo(string $assinado, string $cStat = '100', string $xMotivo = 'Autorizado o uso da NF-e'): string
    {
        preg_match('/Id="NFe(\d{44})"/', $assinado, $m);

        return '<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><chNFe>'.$m[1].'</chNFe><dhRecbto>2026-10-02T10:00:00-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>'
            .$this->digestDoXmlAssinado($assinado)."</digVal><cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo></infProt></protNFe>";
    }

    private function envio(string $assinado, string $cStatProt, string $xMotivo = 'Autorizado o uso da NF-e', bool $comProt = true, string $cStatLote = '104'): string
    {
        $prot = $comProt ? $this->protocolo($assinado, $cStatProt, $xMotivo) : '';

        return '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><verAplic>SP</verAplic>'
            ."<cStat>{$cStatLote}</cStat><xMotivo>Lote processado</xMotivo><cUF>35</cUF><dhRecbto>2026-10-02T10:00:00-03:00</dhRecbto>{$prot}</retEnviNFe>";
    }

    public function test_cstat_100_autoriza_e_monta_o_nfeproc(): void
    {
        $nfe = $this->nfeAssinada();
        $r = (new InterpretadorDeResposta)->emissao($nfe, $this->envio($nfe, '100'));

        $this->assertSame(StatusNota::Autorizada, $r->status);
        $this->assertSame('135260000000001', $r->protocolo);
        $this->assertStringContainsString('<nfeProc', (string) $r->xmlAutorizado);
        $this->assertStringContainsString('<protNFe', (string) $r->xmlAutorizado);
    }

    public function test_outro_cstat_no_protocolo_e_rejeicao_da_sefaz(): void
    {
        $nfe = $this->nfeAssinada();
        $r = (new InterpretadorDeResposta)->emissao($nfe, $this->envio($nfe, '232', 'Rejeicao: IE do destinatario nao informada'));

        $this->assertSame(StatusNota::Rejeitada, $r->status);
        $this->assertSame('232', $r->cstat);
        $this->assertStringContainsString('IE do destinatario', (string) $r->motivo);
    }

    public function test_539_devolve_a_chave_ocupante(): void
    {
        $nfe = $this->nfeAssinada();
        $motivo = 'Rejeicao: Duplicidade de NF-e, com diferenca na Chave de Acesso [chNFe:35261011444777000161550010000000079999999999]';
        $r = (new InterpretadorDeResposta)->emissao($nfe, $this->envio($nfe, '539', $motivo));

        $this->assertSame('539', $r->cstat);
        $this->assertSame('35261011444777000161550010000000079999999999', $r->chaveOcupante);
    }

    public function test_lote_sem_protocolo_com_rejeicao_de_lote_e_rejeitada(): void
    {
        $nfe = $this->nfeAssinada();
        $r = (new InterpretadorDeResposta)->emissao($nfe, $this->envio($nfe, '', 'Rejeicao: Lote invalido', false, '225'));
        $this->assertSame(StatusNota::Rejeitada, $r->status);
        $this->assertSame('225', $r->cstat);
    }

    public function test_lote_em_processamento_mantem_processando(): void
    {
        $nfe = $this->nfeAssinada();
        $r = (new InterpretadorDeResposta)->emissao($nfe, $this->envio($nfe, '', 'Lote em processamento', false, '105'));
        $this->assertSame(StatusNota::Processando, $r->status);
    }

    public function test_resposta_ilegivel_vira_erro(): void
    {
        $r = (new InterpretadorDeResposta)->emissao($this->nfeAssinada(), 'isto nao e xml');
        $this->assertSame(StatusNota::Erro, $r->status);
    }

    public function test_consulta_100_autorizada_com_xml_recomposto(): void
    {
        $nfe = $this->nfeAssinada();
        preg_match('/Id="NFe(\d{44})"/', $nfe, $m);
        $xml = '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><verAplic>SP</verAplic><cStat>100</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo><cUF>35</cUF><dhRecbto>2026-10-02T10:00:00-03:00</dhRecbto><chNFe>'.$m[1].'</chNFe>'
            .$this->protocolo($nfe).'</retConsSitNFe>';

        $r = (new InterpretadorDeResposta)->consulta($xml, $nfe);

        $this->assertSame(SituacaoNaSefaz::Autorizada, $r->situacao);
        $this->assertSame('135260000000001', $r->protocolo);
        $this->assertStringContainsString('<nfeProc', (string) $r->xmlAutorizado);
    }

    public function test_consulta_autorizada_sem_xml_enviado_nao_inventa_xml(): void
    {
        $xml = '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><cStat>100</cStat><xMotivo>Autorizado</xMotivo><protNFe versao="4.00"><infProt><nProt>135260000000001</nProt><cStat>100</cStat></infProt></protNFe></retConsSitNFe>';
        $r = (new InterpretadorDeResposta)->consulta($xml, null);
        $this->assertSame(SituacaoNaSefaz::Autorizada, $r->situacao);
        $this->assertNull($r->xmlAutorizado);
    }

    public function test_consulta_cancelada_nao_encontrada_e_outros(): void
    {
        $i = new InterpretadorDeResposta;
        $mk = fn (string $c): string => '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><cStat>'.$c.'</cStat><xMotivo>m</xMotivo></retConsSitNFe>';

        $this->assertSame(SituacaoNaSefaz::Cancelada, $i->consulta($mk('101'), null)->situacao);
        $this->assertSame(SituacaoNaSefaz::Cancelada, $i->consulta($mk('151'), null)->situacao);
        $this->assertSame(SituacaoNaSefaz::NaoEncontrada, $i->consulta($mk('217'), null)->situacao);
        $this->assertSame(SituacaoNaSefaz::Erro, $i->consulta($mk('999'), null)->situacao);
        $this->assertSame(SituacaoNaSefaz::Erro, $i->consulta('lixo', null)->situacao);
    }
}
