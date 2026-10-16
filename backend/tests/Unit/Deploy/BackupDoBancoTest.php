<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use Tests\TestCase;

class BackupDoBancoTest extends TestCase
{
    private function script(string $nome): string
    {
        $arquivo = base_path('../deploy/'.$nome);
        $this->assertFileExists($arquivo);

        return (string) file_get_contents($arquivo);
    }

    public function test_backup_para_na_primeira_falha(): void
    {
        $sh = $this->script('backup.sh');

        $this->assertStringStartsWith("#!/usr/bin/env bash\n", $sh);
        $this->assertStringContainsString('set -euo pipefail', $sh);
        $this->assertStringNotContainsString("\r", $sh, 'Fim de linha CRLF quebra o bash na VPS.');
    }

    public function test_backup_gera_dump_custom_valida_e_so_depois_renomeia_e_apaga_antigos(): void
    {
        $sh = $this->script('backup.sh');

        $dump = strpos($sh, 'pg_dump');
        $valida = strpos($sh, 'pg_restore --list');
        $renomeia = strpos($sh, 'mv "$tmp" "$final"');
        $retencao = strpos($sh, '-mtime +"$RETENCAO_DIAS"');

        foreach ([$dump, $valida, $renomeia, $retencao] as $posicao) {
            $this->assertNotFalse($posicao);
        }
        $this->assertStringContainsString('-Fc', $sh);
        $this->assertTrue($dump < $valida && $valida < $renomeia && $renomeia < $retencao, 'Ordem: dump, validar, renomear, apagar antigos.');
        $this->assertStringContainsString('RETENCAO_DIAS=14', $sh);
    }

    public function test_backup_recusa_dump_vazio_limpa_o_tmp_e_so_apaga_dumps_de_backup(): void
    {
        $sh = $this->script('backup.sh');

        $this->assertStringContainsString('[ -s "$tmp" ]', $sh);
        $this->assertStringContainsString("trap 'rm -f \"\$tmp\"' EXIT", $sh);
        $this->assertStringContainsString("-name 'plataforma-*.dump'", $sh);
        $this->assertStringContainsString('chmod 700 "$BACKUP_DIR"', $sh);
        $this->assertStringContainsString('umask 077', $sh);
    }

    public function test_pasta_de_backups_fica_fora_do_git(): void
    {
        $ignore = (string) file_get_contents(base_path('../.gitignore'));

        $this->assertMatchesRegularExpression('#^/?backups/$#m', $ignore);
    }

    public function test_restauracao_para_na_primeira_falha_e_exige_o_arquivo(): void
    {
        $sh = $this->script('restaurar.sh');

        $this->assertStringStartsWith("#!/usr/bin/env bash\n", $sh);
        $this->assertStringContainsString('set -euo pipefail', $sh);
        $this->assertStringNotContainsString("\r", $sh);
        $this->assertStringContainsString('[ -f "$arquivo" ]', $sh);
    }

    public function test_restauracao_valida_confirma_faz_dump_de_seguranca_e_so_depois_restaura(): void
    {
        $sh = $this->script('restaurar.sh');

        $valida = strpos($sh, 'pg_restore --list');
        $confirma = strpos($sh, '"RESTAURAR"');
        $seguranca = strpos($sh, 'pre-restauracao-');
        $para = strpos($sh, 'compose stop app worker scheduler');
        $restaura = strpos($sh, 'pg_restore -U');

        foreach ([$valida, $confirma, $seguranca, $para, $restaura] as $posicao) {
            $this->assertNotFalse($posicao);
        }
        $this->assertTrue(
            $valida < $confirma && $confirma < $seguranca && $seguranca < $para && $para < $restaura,
            'Ordem: validar, confirmar, dump de segurança, parar serviços, restaurar.',
        );
    }

    public function test_restauracao_e_atomica_e_sobe_os_servicos_de_volta(): void
    {
        $sh = $this->script('restaurar.sh');

        $this->assertStringContainsString('--single-transaction', $sh);
        $this->assertStringContainsString('--clean --if-exists', $sh);
        $this->assertStringContainsString('compose up -d app worker scheduler', $sh);
        $this->assertStringContainsString('information_schema.tables', $sh);
    }
}
