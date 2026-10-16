<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * As libs fiscais nascem com a verificação TLS desligada. O patch (backend/patches) liga; este teste falha se o patch
 * não for aplicado ou se uma atualização de lib o desfizer.
 */
class TlsDasLibsFiscaisTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function arquivos(): array
    {
        return [
            'AdnClient' => ['nfse-nacional/nfse-php/src/Http/Client/AdnClient.php'],
            'CncClient' => ['nfse-nacional/nfse-php/src/Http/Client/CncClient.php'],
            'SefinClient' => ['nfse-nacional/nfse-php/src/Http/Client/SefinClient.php'],
            'SoapCurl' => ['nfephp-org/sped-common/src/Soap/SoapCurl.php'],
        ];
    }

    #[DataProvider('arquivos')]
    public function test_verificacao_tls_nao_esta_desligada(string $relativo): void
    {
        $caminho = base_path('vendor/'.$relativo);
        $this->assertFileExists($caminho);
        $codigo = (string) file_get_contents($caminho);

        $this->assertDoesNotMatchRegularExpression(
            '/CURLOPT_SSL_VERIFY(?:PEER|HOST)\s*(?:,|=>)\s*(?:0|false)\b/',
            $codigo,
            "{$relativo} desliga a verificação TLS: o patch de backend/patches não foi aplicado.",
        );
        $this->assertStringContainsString('FISCAL_CA_BUNDLE', $codigo);
    }
}
