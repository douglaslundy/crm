<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Exceptions\JustificativaInvalidaException;
use App\Modules\Fiscal\Domain\Justificativa;
use PHPUnit\Framework\TestCase;

class JustificativaTest extends TestCase
{
    public function test_aceita_exatamente_15_e_255_caracteres(): void
    {
        $this->assertSame(str_repeat('a', 15), Justificativa::de(str_repeat('a', 15))->texto);
        $this->assertSame(255, mb_strlen(Justificativa::de(str_repeat('b', 255))->texto));
    }

    public function test_recusa_14_e_256_caracteres(): void
    {
        foreach ([14, 256] as $tamanho) {
            try {
                Justificativa::de(str_repeat('a', $tamanho));
                $this->fail("Aceitou {$tamanho} caracteres.");
            } catch (JustificativaInvalidaException $e) {
                $this->assertSame('JUSTIFICATIVA_INVALIDA', $e->codigo());
                $this->assertStringContainsString('15', $e->getMessage());
                $this->assertStringContainsString('255', $e->getMessage());
            }
        }
    }

    public function test_so_espacos_ou_so_emoji_nao_conta_como_texto(): void
    {
        foreach ([str_repeat(' ', 40), str_repeat("\u{1F600}", 20), "\t\n\r".str_repeat(' ', 30)] as $texto) {
            try {
                Justificativa::de($texto);
                $this->fail('Aceitou texto sem conteúdo.');
            } catch (JustificativaInvalidaException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_acentos_sao_preservados_e_espacos_colapsados(): void
    {
        $j = Justificativa::de("  Cancelamento   por erro de   digitação \u{1F600} do valor\n total ");
        $this->assertSame('Cancelamento por erro de digitação do valor total', $j->texto);
    }

    public function test_o_tamanho_conta_depois_de_sanear(): void
    {
        // 7 + 1 + 6 = 14 caracteres úteis depois de colapsar os espaços: inválida.
        $this->expectException(JustificativaInvalidaException::class);
        Justificativa::de('abcdefg        hijklm');
    }
}
