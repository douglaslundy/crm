<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use Tests\TestCase;

class EnvDeProducaoTest extends TestCase
{
    /** @return array<string, string> */
    private function env(): array
    {
        $arquivo = base_path('../deploy/.env.production.example');
        $this->assertFileExists($arquivo);
        $valores = [];
        foreach (preg_split('/\R/', (string) file_get_contents($arquivo)) ?: [] as $linha) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($linha), $m) === 1) {
                $valores[$m[1]] = $m[2];
            }
        }

        return $valores;
    }

    public function test_template_de_producao_e_seguro(): void
    {
        $env = $this->env();

        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('0', $env['INSTALL_DEV']);
        $this->assertStringStartsWith('https://', $env['APP_URL']);
        $this->assertStringStartsWith('https://', $env['FRONTEND_URL']);
        $this->assertStringStartsWith('https://', $env['NEXT_PUBLIC_API_URL']);
        $this->assertSame('', $env['APP_KEY']);
        $this->assertSame('trocar', $env['DB_PASSWORD']);
    }

    public function test_template_de_producao_declara_todas_as_chaves_do_template_de_staging(): void
    {
        $producao = $this->env();
        $staging = [];
        foreach (preg_split('/\R/', (string) file_get_contents(base_path('../deploy/.env.example'))) ?: [] as $linha) {
            if (preg_match('/^([A-Z0-9_]+)=/', trim($linha), $m) === 1) {
                $staging[] = $m[1];
            }
        }

        // HOST, WEB_PORT e API_PORT só existem porque o staging publica portas; produção entra pelo Caddy.
        $faltando = array_diff($staging, array_keys($producao), ['HOST', 'WEB_PORT', 'API_PORT']);
        $this->assertSame([], array_values($faltando), 'Chaves do staging ausentes no template de produção.');
    }
}
