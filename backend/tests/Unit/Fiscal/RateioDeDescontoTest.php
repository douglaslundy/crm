<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\RateioDeDesconto;
use PHPUnit\Framework\TestCase;

class RateioDeDescontoTest extends TestCase
{
    public function test_total_do_item_arredonda_meio_para_cima(): void
    {
        $this->assertSame(1000, RateioDeDesconto::totalDoItem(1000, 1000));
        $this->assertSame(2500, RateioDeDesconto::totalDoItem(2500, 1000));
        $this->assertSame(335, RateioDeDesconto::totalDoItem(1500, 223)); // 1,5 x 2,23 = 3,345 reais = 334,5 centavos, meio para cima
    }

    public function test_rateio_soma_exatamente_o_desconto(): void
    {
        $partes = RateioDeDesconto::ratear(100, [3333, 3333, 3334]);
        $this->assertSame(100, array_sum($partes));
        $this->assertCount(3, $partes);
    }

    public function test_residuo_vai_para_o_ultimo_item(): void
    {
        $this->assertSame([33, 33, 34], RateioDeDesconto::ratear(100, [1000, 1000, 1000]));
    }

    public function test_desconto_zero_nao_altera_nada(): void
    {
        $this->assertSame([0, 0], RateioDeDesconto::ratear(0, [500, 500]));
    }

    public function test_desconto_igual_ao_subtotal_zera_todos_os_itens(): void
    {
        $this->assertSame([500, 700], RateioDeDesconto::ratear(1200, [500, 700]));
    }

    public function test_nenhum_item_recebe_mais_que_o_proprio_total(): void
    {
        $partes = RateioDeDesconto::ratear(1199, [1, 1198, 1]);
        $this->assertSame(1199, array_sum($partes));
        foreach ([1, 1198, 1] as $i => $total) {
            $this->assertLessThanOrEqual($total, $partes[$i]);
        }
    }

    public function test_desconto_maior_que_o_subtotal_ou_negativo_bloqueia(): void
    {
        foreach ([1201, -1] as $desconto) {
            try {
                RateioDeDesconto::ratear($desconto, [500, 700]);
                $this->fail('Esperava bloqueio.');
            } catch (EmissaoBloqueadaException $e) {
                $this->assertSame('FISCAL_DESCONTO_INVALIDO', $e->codigo());
            }
        }
    }
}
