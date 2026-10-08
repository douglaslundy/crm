<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Webhooks\AssinadorDeWebhook;
use PHPUnit\Framework\TestCase;

class AssinadorDeWebhookTest extends TestCase
{
    private const SEGREDO = 'whsec_abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    public function test_formato_e_valor_recalculavel(): void
    {
        $corpo = '{"id":"x"}';
        $cabecalho = (new AssinadorDeWebhook)->cabecalho(self::SEGREDO, 1700000000, $corpo);

        $this->assertSame('t=1700000000,v1='.hash_hmac('sha256', '1700000000.{"id":"x"}', self::SEGREDO), $cabecalho);
        $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $cabecalho);
    }

    public function test_verificar_aceita_a_correta(): void
    {
        $a = new AssinadorDeWebhook;
        $cabecalho = $a->cabecalho(self::SEGREDO, time(), '{"a":1}');

        $this->assertTrue($a->verificar(self::SEGREDO, $cabecalho, '{"a":1}'));
    }

    public function test_verificar_recusa_corpo_alterado_segredo_errado_e_formato_invalido(): void
    {
        $a = new AssinadorDeWebhook;
        $cabecalho = $a->cabecalho(self::SEGREDO, time(), '{"a":1}');

        $this->assertFalse($a->verificar(self::SEGREDO, $cabecalho, '{"a":2}'));
        $this->assertFalse($a->verificar('whsec_outro', $cabecalho, '{"a":1}'));
        $this->assertFalse($a->verificar(self::SEGREDO, 'lixo', '{"a":1}'));
        $this->assertFalse($a->verificar(self::SEGREDO, '', '{"a":1}'));
        $this->assertFalse($a->verificar(self::SEGREDO, $cabecalho.',v1=00', '{"a":1}'));
    }

    public function test_verificar_recusa_t_fora_da_tolerancia(): void
    {
        $a = new AssinadorDeWebhook;

        $this->assertFalse($a->verificar(self::SEGREDO, $a->cabecalho(self::SEGREDO, time() - 301, 'x'), 'x'));
        $this->assertFalse($a->verificar(self::SEGREDO, $a->cabecalho(self::SEGREDO, time() + 301, 'x'), 'x'));
        $this->assertTrue($a->verificar(self::SEGREDO, $a->cabecalho(self::SEGREDO, time() - 299, 'x'), 'x'));
        $this->assertTrue($a->verificar(self::SEGREDO, $a->cabecalho(self::SEGREDO, time() - 3000, 'x'), 'x', 4000));
    }
}
