<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use Tests\TestCase;

class ConfiguracaoFiscalTest extends TestCase
{
    public function test_valores_padrao_da_configuracao_fiscal(): void
    {
        $this->assertSame(10, config('fiscal.minutos_para_nunca_enviada'));
        $this->assertSame(5, config('fiscal.tentativas_numero_ocupado'));
        $this->assertNotSame('', config('fiscal.ver_proc'));
    }
}
