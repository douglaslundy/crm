<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Resolvers\CfopSaidaResolver;
use App\Modules\Fiscal\Domain\Resolvers\CrtResolver;
use App\Modules\Fiscal\Domain\Resolvers\IndicadorIeDestinatarioResolver;
use App\Modules\Fiscal\Domain\Resolvers\TributacaoIcmsSaidaResolver;
use PHPUnit\Framework\TestCase;

class ResolversTest extends TestCase
{
    public function test_crt_simples_e_mei_sao_1(): void
    {
        $this->assertSame(1, (new CrtResolver)->crt('SIMPLES'));
        $this->assertSame(1, (new CrtResolver)->crt('MEI'));
    }

    public function test_crt_regime_normal_bloqueia_com_codigo_proprio(): void
    {
        $this->expectBloqueio('FISCAL_REGIME_NAO_SUPORTADO', fn () => (new CrtResolver)->crt('NORMAL'));
    }

    public function test_crt_nulo_bloqueia_como_emitente_incompleto(): void
    {
        $this->expectBloqueio('FISCAL_EMITENTE_INCOMPLETO', fn () => (new CrtResolver)->crt(null));
    }

    public function test_cfop_da_venda(): void
    {
        $r = new CfopSaidaResolver;
        $this->assertSame('5102', $r->resolver('SP', 'SP', TributacaoIcms::Normal));
        $this->assertSame('5405', $r->resolver('SP', 'SP', TributacaoIcms::St));
        $this->assertSame('6102', $r->resolver('SP', 'MG', TributacaoIcms::Normal));
        $this->assertSame('6404', $r->resolver('SP', 'MG', TributacaoIcms::St));
    }

    public function test_cfop_com_uf_invalida_bloqueia(): void
    {
        $this->expectBloqueio('FISCAL_UF_INVALIDA', fn () => (new CfopSaidaResolver)->resolver('SP', 'XX', TributacaoIcms::Normal));
    }

    public function test_csosn_do_simples(): void
    {
        $r = new TributacaoIcmsSaidaResolver;
        $this->assertSame('102', $r->csosn(TributacaoIcms::Normal));
        $this->assertSame('500', $r->csosn(TributacaoIcms::St));
    }

    public function test_indicador_de_ie(): void
    {
        $r = new IndicadorIeDestinatarioResolver;
        $this->assertSame(9, $r->resolver(TipoCliente::Pf, null, false));
        $this->assertSame(1, $r->resolver(TipoCliente::Pj, '123456', false));
        $this->assertSame(2, $r->resolver(TipoCliente::Pj, null, true));
    }

    public function test_pj_sem_ie_e_sem_isento_bloqueia(): void
    {
        $this->expectBloqueio('FISCAL_DESTINATARIO_SEM_IE', fn () => (new IndicadorIeDestinatarioResolver)->resolver(TipoCliente::Pj, null, false));
        $this->expectBloqueio('FISCAL_DESTINATARIO_SEM_IE', fn () => (new IndicadorIeDestinatarioResolver)->resolver(TipoCliente::Pj, '', false));
    }

    public function test_pj_com_ie_e_isento_ao_mesmo_tempo_bloqueia(): void
    {
        $this->expectBloqueio('FISCAL_DESTINATARIO_IE_CONTRADITORIA', fn () => (new IndicadorIeDestinatarioResolver)->resolver(TipoCliente::Pj, '123', true));
    }

    private function expectBloqueio(string $codigo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Esperava bloqueio {$codigo}.");
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame($codigo, $e->codigo());
        }
    }
}
