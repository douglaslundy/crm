<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Domain\ValidadorCamposFiscais;
use PHPUnit\Framework\TestCase;

class ValidadorCamposFiscaisTest extends TestCase
{
    public function test_ncm_valido_de_8_digitos(): void
    {
        $this->assertSame('12345678', ValidadorCamposFiscais::ncm('12345678'));
    }

    public function test_ncm_malformado_vira_null_nunca_o_valor_cru(): void
    {
        $this->assertNull(ValidadorCamposFiscais::ncm('123'));
        $this->assertNull(ValidadorCamposFiscais::ncm('abcdefgh'));
    }

    public function test_ncm_nulo_permanece_nulo(): void
    {
        $this->assertNull(ValidadorCamposFiscais::ncm(null));
    }

    public function test_cest_valido_de_7_digitos(): void
    {
        $this->assertSame('1234567', ValidadorCamposFiscais::cest('1234567'));
    }

    public function test_cest_malformado_vira_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::cest('123'));
    }

    public function test_origem_zero_e_tratada_como_preenchida_nunca_como_ausente(): void
    {
        $this->assertSame(0, ValidadorCamposFiscais::origem(0));
        $this->assertSame(0, ValidadorCamposFiscais::origem('0'));
    }

    public function test_origem_valida_de_0_a_8(): void
    {
        $this->assertSame(8, ValidadorCamposFiscais::origem(8));
    }

    public function test_origem_fora_da_faixa_vira_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::origem(9));
        $this->assertNull(ValidadorCamposFiscais::origem(-1));
    }

    public function test_origem_ausente_permanece_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::origem(null));
        $this->assertNull(ValidadorCamposFiscais::origem(''));
    }
}
