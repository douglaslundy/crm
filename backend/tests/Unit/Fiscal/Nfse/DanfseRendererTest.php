<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use App\Modules\Fiscal\Infrastructure\Pdf\Danfse\DanfseRenderer;
use RuntimeException;
use Tests\TestCase;

/** A fixture é uma NFS-e nacional autorizada ANONIMIZADA (dados fictícios, sem assinatura); a estrutura é a real. */
class DanfseRendererTest extends TestCase
{
    private const CHAVE = '31305072212345678000195000000000000226096821694069';

    private function xml(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/nfse_nacional_autorizada.xml'));
    }

    /** @return list<string> */
    private function textos(string $xml, bool $cancelada = false): array
    {
        $d = (new DanfseRenderer)->dadosParaTemplate($xml, $cancelada, null, 'data:image/png;base64,AA==');

        return array_column($d['textos'], 't');
    }

    public function test_campos_saem_do_xml_como_no_danfse_oficial(): void
    {
        $t = $this->textos($this->xml());

        foreach (['DANFSe v2.0', self::CHAVE, 'Prestador', 'NFS-e Gerada', 'Operação Tributável', 'Não Retido'] as $esperado) {
            $this->assertContains($esperado, $t, "faltou: {$esperado}");
        }
    }

    public function test_a_chave_sai_sem_o_prefixo_nfs(): void
    {
        $this->assertNotContains('NFS'.self::CHAVE, $this->textos($this->xml()));
    }

    public function test_nota_cancelada_mostra_a_situacao_cancelada(): void
    {
        $this->assertContains('NFS-e Cancelada', $this->textos($this->xml(), true));
        $this->assertNotContains('NFS-e Cancelada', $this->textos($this->xml(), false));
    }

    public function test_descricao_longa_quebra_em_linhas_e_empurra_o_resto_da_pagina(): void
    {
        $longa = str_repeat('troca de oleo e filtro com revisao completa do motor ', 30);
        $xml = (string) preg_replace('#<xDescServ>.*?</xDescServ>#s', '<xDescServ>'.$longa.'</xDescServ>', $this->xml());
        $r = new DanfseRenderer;

        $normal = $r->dadosParaTemplate($this->xml(), false, null, 'x');
        $comLonga = $r->dadosParaTemplate($xml, false, null, 'x');

        $yTotal = static fn (array $d): float => (float) array_values(array_filter($d['textos'], static fn (array $t): bool => $t['t'] === 'VALOR TOTAL DA NFS-e'))[0]['y'];
        $this->assertGreaterThan($yTotal($normal) + 15, $yTotal($comLonga), 'o bloco de valores deve descer quando a descrição ocupa mais linhas');
    }

    public function test_desconto_incondicional_sai_do_xml(): void
    {
        $xml = (string) str_replace('</vServPrest>', '</vServPrest><vDescCondIncond><vDescIncond>10.00</vDescIncond></vDescCondIncond>', $this->xml());

        $this->assertContains('R$ 10,00', $this->textos($xml));
    }

    public function test_xml_que_nao_e_nfse_nacional_lanca(): void
    {
        $this->expectException(RuntimeException::class);

        (new DanfseRenderer)->dadosParaTemplate('<qualquer-coisa/>', false, null, 'x');
    }
}
