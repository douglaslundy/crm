<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Infrastructure\VerificadorDeAssinaturaDoWebhook;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class VerificadorDeAssinaturaTest extends TestCase
{
    private const SEGREDO = 's3cr3t';

    private function agora(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-07 12:00:00 UTC');
    }

    private function assinatura(string $dataId, string $requestId, int $ts, string $segredo = self::SEGREDO): string
    {
        $manifesto = 'id:'.strtolower($dataId).';request-id:'.$requestId.';ts:'.$ts.';';

        return 'ts='.$ts.',v1='.hash_hmac('sha256', $manifesto, $segredo);
    }

    private function valido(?string $assinatura, ?string $requestId = 'req-1', ?string $dataId = 'PG-1', ?string $segredo = self::SEGREDO): bool
    {
        return (new VerificadorDeAssinaturaDoWebhook)->valido($assinatura, $requestId, $dataId, $segredo, $this->agora());
    }

    public function test_assinatura_correta_e_valida(): void
    {
        $this->assertTrue($this->valido($this->assinatura('PG-1', 'req-1', $this->agora()->timestamp)));
    }

    public function test_ts_em_milissegundos_tambem_vale(): void
    {
        $this->assertTrue($this->valido($this->assinatura('PG-1', 'req-1', $this->agora()->timestamp * 1000)));
    }

    public function test_recusa_header_ausente_ou_malformado(): void
    {
        $this->assertFalse($this->valido(null));
        $this->assertFalse($this->valido(''));
        $this->assertFalse($this->valido('lixo'));
        $this->assertFalse($this->valido('ts=1'));
    }

    public function test_recusa_v1_errado(): void
    {
        $this->assertFalse($this->valido('ts='.$this->agora()->timestamp.',v1=errado'));
    }

    public function test_recusa_ts_fora_da_tolerancia(): void
    {
        $this->assertFalse($this->valido($this->assinatura('PG-1', 'req-1', $this->agora()->timestamp - 601)));
        $this->assertFalse($this->valido($this->assinatura('PG-1', 'req-1', $this->agora()->timestamp + 601)));
    }

    public function test_recusa_quando_o_id_ou_o_request_id_nao_sao_os_assinados(): void
    {
        $assinatura = $this->assinatura('PG-1', 'req-1', $this->agora()->timestamp);

        $this->assertFalse($this->valido($assinatura, 'req-1', 'outro'));
        $this->assertFalse($this->valido($assinatura, 'req-2'));
        $this->assertFalse($this->valido($assinatura, null));
        $this->assertFalse($this->valido($assinatura, 'req-1', null));
    }

    public function test_segredo_vazio_ou_nulo_recusa_mesmo_com_assinatura_calculada_com_ele(): void
    {
        $this->assertFalse($this->valido($this->assinatura('PG-1', 'req-1', $this->agora()->timestamp, ''), 'req-1', 'PG-1', ''));
        $this->assertFalse($this->valido($this->assinatura('PG-1', 'req-1', $this->agora()->timestamp, ''), 'req-1', 'PG-1', null));
    }
}
