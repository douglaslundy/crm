<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Infrastructure\NfePhp\ModeloDaChave;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ModeloDaChaveTest extends TestCase
{
    public function test_le_o_modelo_nas_posicoes_21_e_22(): void
    {
        $this->assertSame(55, ModeloDaChave::de('35261011444777000161550010000000071473829151'));
        $this->assertSame(65, ModeloDaChave::de('35261011444777000161650010000000071473829151'));
    }

    public function test_modelo_desconhecido_ou_chave_curta_lanca(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ModeloDaChave::de('35261011444777000161570010000000071473829151');
    }

    public function test_chave_curta_lanca(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ModeloDaChave::de('123');
    }
}
