<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Resolvers\CfopConsumidorResolver;
use PHPUnit\Framework\TestCase;

class CfopConsumidorResolverTest extends TestCase
{
    public function test_normal_na_mesma_uf_e_5102(): void
    {
        $this->assertSame('5102', (new CfopConsumidorResolver)->resolver('SP', 'SP', TributacaoIcms::Normal));
    }

    public function test_st_na_mesma_uf_e_5405(): void
    {
        $this->assertSame('5405', (new CfopConsumidorResolver)->resolver('SP', 'SP', TributacaoIcms::St));
    }

    public function test_venda_sem_identificacao_e_operacao_interna(): void
    {
        $this->assertSame('5102', (new CfopConsumidorResolver)->resolver('SP', null, TributacaoIcms::Normal));
        $this->assertSame('5405', (new CfopConsumidorResolver)->resolver('SP', null, TributacaoIcms::St));
    }

    public function test_outra_uf_bloqueia_sem_chutar_6108(): void
    {
        try {
            (new CfopConsumidorResolver)->resolver('SP', 'RJ', TributacaoIcms::Normal);
            $this->fail('Deveria bloquear a NFC-e interestadual.');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_NFCE_INTERESTADUAL', $e->codigo());
        }
    }

    public function test_uf_invalida_bloqueia(): void
    {
        try {
            (new CfopConsumidorResolver)->resolver('SP', 'XX', TributacaoIcms::Normal);
            $this->fail('Deveria bloquear a UF inválida.');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_UF_INVALIDA', $e->codigo());
        }
    }
}
