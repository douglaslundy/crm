<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Os exemplos do guia precisam ao menos compilar: PHP com `php -l`, JavaScript com `node --check`. */
class GuiaDoIntegradorTest extends TestCase
{
    private function guia(): string
    {
        return (string) file_get_contents(dirname(base_path()).'/docs/api/guia-do-integrador.md');
    }

    /** @return list<string> */
    private function blocos(string $linguagem): array
    {
        preg_match_all('/```'.$linguagem."\n(.*?)```/s", $this->guia(), $m);

        return $m[1];
    }

    private function verificar(string $codigo, string $extensao, array $comando): Process
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'guia').'.'.$extensao;
        file_put_contents($arquivo, $codigo);
        $processo = new Process([...$comando, $arquivo]);
        $processo->run();
        @unlink($arquivo);

        return $processo;
    }

    public function test_blocos_php_tem_sintaxe_valida(): void
    {
        $blocos = $this->blocos('php');
        $this->assertGreaterThanOrEqual(2, count($blocos));
        foreach ($blocos as $i => $codigo) {
            $completo = str_starts_with(ltrim($codigo), '<?php') ? $codigo : "<?php\n".$codigo;
            $processo = $this->verificar($completo, 'php', [PHP_BINARY, '-l']);
            $this->assertSame(0, $processo->getExitCode(), "bloco php #{$i}: ".$processo->getOutput().$processo->getErrorOutput());
        }
    }

    public function test_blocos_js_tem_sintaxe_valida(): void
    {
        $blocos = $this->blocos('js');
        $this->assertGreaterThanOrEqual(2, count($blocos));
        foreach ($blocos as $i => $codigo) {
            $processo = $this->verificar($codigo, 'mjs', ['node', '--check']);
            $this->assertSame(0, $processo->getExitCode(), "bloco js #{$i}: ".$processo->getErrorOutput());
        }
    }

    public function test_guia_aponta_para_o_contrato_e_para_a_pagina(): void
    {
        $this->assertStringContainsString('openapi.yaml', $this->guia());
        $this->assertStringContainsString('/docs/api', $this->guia());
        $this->assertFileExists(dirname(base_path()).'/docs/api/openapi.yaml');
    }

    public function test_guia_so_cita_endpoints_que_existem_no_contrato(): void
    {
        preg_match_all('#\$BASE/(?:[a-z-]+)(?:/[A-Z_a-z0-9-]+)?(?:/[a-z-]+)?#', $this->guia(), $m);
        $contrato = (string) file_get_contents(dirname(base_path()).'/docs/api/openapi.yaml');
        foreach (array_unique($m[0]) as $uso) {
            $base = explode('/', substr($uso, strlen('$BASE/')))[0];
            $this->assertStringContainsString("\n  /{$base}", $contrato, "Guia cita /{$base}, que não está no OpenAPI");
        }
    }
}
