<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use PHPUnit\Framework\TestCase;

class GeradorDeChaveDeApiTest extends TestCase
{
    public function test_chave_live_tem_prefixo_e_quarenta_caracteres_alfanumericos(): void
    {
        $chave = (new GeradorDeChaveDeApi)->gerar(AmbienteDaChave::Live);

        $this->assertMatchesRegularExpression('/^live_[A-Za-z0-9]{40}$/', $chave->texto);
    }

    public function test_chave_test_tem_prefixo_test(): void
    {
        $chave = (new GeradorDeChaveDeApi)->gerar(AmbienteDaChave::Test);

        $this->assertMatchesRegularExpression('/^test_[A-Za-z0-9]{40}$/', $chave->texto);
    }

    public function test_duas_chaves_sao_diferentes(): void
    {
        $gerador = new GeradorDeChaveDeApi;

        $this->assertNotSame($gerador->gerar(AmbienteDaChave::Live)->texto, $gerador->gerar(AmbienteDaChave::Live)->texto);
    }

    public function test_hash_e_o_sha256_do_texto_e_hash_de_o_reproduz(): void
    {
        $gerador = new GeradorDeChaveDeApi;
        $chave = $gerador->gerar(AmbienteDaChave::Live);

        $this->assertSame(hash('sha256', $chave->texto), $chave->hash);
        $this->assertSame($chave->hash, $gerador->hashDe($chave->texto));
    }

    public function test_prefixo_tem_doze_caracteres_e_ultimos4_sao_os_quatro_finais(): void
    {
        $chave = (new GeradorDeChaveDeApi)->gerar(AmbienteDaChave::Test);

        $this->assertSame(substr($chave->texto, 0, 12), $chave->prefixo);
        $this->assertSame(12, strlen($chave->prefixo));
        $this->assertSame(substr($chave->texto, -4), $chave->ultimos4);
    }
}
