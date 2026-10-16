# F8c Backup e restauração Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Backup diário validado do banco de produção, com retenção de 14 dias, e restauração com confirmação e dump de segurança, documentados num runbook.

**Architecture:** Dois scripts bash em `deploy/` que falam com o serviço `db` do Compose (`pg_dump -Fc`, `pg_restore`). Agendamento por cron do usuário (só documentado). O teste PHPUnit confere o conteúdo dos scripts, no estilo de `EnvDeProducaoTest`. Nada de código de aplicação muda.

**Tech Stack:** bash, Docker Compose, PostgreSQL 16 (`pg_dump`/`pg_restore` dentro do container `db`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-16-f8c-backup-restauracao-design.md`

## Global Constraints

- Destino do backup: só o disco local da VPS (decisão do usuário). Retenção: 14 dias.
- O backup guarda só o banco. A APP_KEY fica fora do backup; o runbook manda guardá-la fora da VPS.
- O app não grava arquivos em `storage`: o volume `storage` fica fora do backup.
- Scripts: `#!/usr/bin/env bash`, `set -euo pipefail`, fim de linha LF (`.gitattributes` já tem `*.sh text eol=lf`).
- Esta máquina não tem Docker nem PostgreSQL: o ciclo real de backup e restauração **não** pode ser executado aqui. O plano não pode afirmar que foi testado; a prova é o roteiro do runbook, a cargo do usuário no staging.
- Código PHP com `declare(strict_types=1)`. Escrever arquivos com `\` pela ferramenta Write, nunca por heredoc de shell.

## Review Focus

- Backup do dia falha no meio (dump vazio ou truncado): nada pode ser renomeado para `.dump` nem apagar backups antigos. Task 1 testa a ordem validar → renomear → apagar.
- Restauração a meio caminho: o banco não pode ficar meio restaurado. Task 2 testa `--single-transaction` e o dump de segurança antes do `pg_restore`.
- Operador digita errado ou aperta Enter: a restauração não pode seguir. Task 2 testa a confirmação `RESTAURAR`.
- Arquivo `.tmp` não pode sobrar nem ser tratado como backup válido. Task 1 testa o `trap` de limpeza e que a retenção só olha `*.dump`.

## File Structure

- Create `deploy/backup.sh`: dump, validação, renomeação atômica, retenção.
- Create `deploy/restaurar.sh`: validação, confirmação, dump de segurança, restauração.
- Create `.gitignore` (raiz): ignora `backups/`.
- Create `backend/tests/Unit/Deploy/BackupDoBancoTest.php`: testes de conteúdo dos scripts e do `.gitignore`.
- Create `docs/go-live/backup-e-restauracao.md`: runbook.
- Modify `docs/go-live/checklist-producao.md`: passo apontando para o runbook.
- Modify `deploy/README.md`: troca o `pg_dump` manual pela referência aos scripts.
- Modify `PROGRESSO.md`, `TAREFAS.md`.

---

### Task 1: Script de backup

**Files:**
- Create: `backend/tests/Unit/Deploy/BackupDoBancoTest.php`
- Create: `deploy/backup.sh`
- Create: `.gitignore`

**Interfaces:**
- Consumes: serviço `db` do `deploy/docker-compose.yml`; `DB_USERNAME` e `DB_DATABASE` de `deploy/.env`.
- Produces: arquivos `plataforma-AAAAMMDD-HHMMSS.dump` em `BACKUP_DIR` (padrão `<repo>/backups`); o prefixo `plataforma-` e a extensão `.dump` são o contrato lido pelo `restaurar.sh` e pelo runbook. Helper de teste `script(string $nome): string` reutilizado na Task 2.

- [ ] **Step 1: Escrever os testes que falham**

Criar `backend/tests/Unit/Deploy/BackupDoBancoTest.php`:

```php
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
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=BackupDoBancoTest`
Expected: 4 testes falham (`Failed asserting that file ".../deploy/backup.sh" exists` e `.gitignore`).

- [ ] **Step 3: Implementar**

Criar `.gitignore` na raiz:

```
# Dumps do banco gerados por deploy/backup.sh (contêm dados de clientes).
/backups/
```

Criar `deploy/backup.sh`:

```bash
#!/usr/bin/env bash
# Backup do banco (pg_dump formato custom), validado, com retenção de 14 dias. Só o banco: o app não grava arquivos
# em storage. Rode em qualquer pasta; agende por cron (docs/go-live/backup-e-restauracao.md).
set -euo pipefail
cd "$(dirname "$0")"

env_val() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | tr -d '\r'; }

[ -f .env ] || { echo "deploy/.env não encontrado." >&2; exit 1; }
DB_USERNAME="$(env_val DB_USERNAME)"
DB_DATABASE="$(env_val DB_DATABASE)"
[ -n "$DB_USERNAME" ] && [ -n "$DB_DATABASE" ] || { echo "DB_USERNAME ou DB_DATABASE vazio no deploy/.env." >&2; exit 1; }

BACKUP_DIR="${BACKUP_DIR:-$(cd .. && pwd)/backups}"
RETENCAO_DIAS=14

umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

final="$BACKUP_DIR/plataforma-$(date +%Y%m%d-%H%M%S).dump"
tmp="$final.tmp"
trap 'rm -f "$tmp"' EXIT

docker compose exec -T db pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > "$tmp"
[ -s "$tmp" ] || { echo "Dump vazio; backup abortado." >&2; exit 1; }
docker compose exec -T db pg_restore --list < "$tmp" > /dev/null || { echo "Dump ilegível; backup abortado." >&2; exit 1; }

mv "$tmp" "$final"
find "$BACKUP_DIR" -maxdepth 1 -name 'plataforma-*.dump' -mtime +"$RETENCAO_DIAS" -delete

echo "Backup ok: $final ($(du -h "$final" | cut -f1))"
```

- [ ] **Step 4: Rodar e ver passar, mais checagem de sintaxe**

Run: `cd backend && php artisan test --filter=BackupDoBancoTest && bash -n ../deploy/backup.sh && echo SINTAXE_OK`
Expected: 4 testes passam; `SINTAXE_OK`.

- [ ] **Step 5: Commit**

```bash
git add .gitignore deploy/backup.sh backend/tests/Unit/Deploy/BackupDoBancoTest.php
git commit -m "feat(deploy): backup diário validado do banco com retenção de 14 dias"
```

---

### Task 2: Script de restauração

**Files:**
- Modify: `backend/tests/Unit/Deploy/BackupDoBancoTest.php`
- Create: `deploy/restaurar.sh`

**Interfaces:**
- Consumes: arquivos `plataforma-*.dump` e o helper `script()` da Task 1; mesmas variáveis de `deploy/.env`.
- Produces: `deploy/restaurar.sh <arquivo.dump>`; gera `pre-restauracao-AAAAMMDD-HHMMSS.dump` em `BACKUP_DIR`.

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar ao final da classe em `BackupDoBancoTest.php` (antes do `}` final):

```php
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
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=BackupDoBancoTest`
Expected: os 3 testes novos falham (`restaurar.sh` não existe); os 4 anteriores passam.

- [ ] **Step 3: Implementar**

Criar `deploy/restaurar.sh`:

```bash
#!/usr/bin/env bash
# Restaura o banco a partir de um dump gerado por backup.sh. SOBRESCREVE o banco atual.
#   ./restaurar.sh ../backups/plataforma-20261016-030000.dump
# Antes de tocar no banco, valida o arquivo, pede confirmação e grava um dump de segurança do estado atual.
set -euo pipefail
cd "$(dirname "$0")"

arquivo="${1:-}"
[ -n "$arquivo" ] && [ -f "$arquivo" ] || { echo "Uso: ./restaurar.sh <arquivo.dump>" >&2; exit 1; }

env_val() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | tr -d '\r'; }

[ -f .env ] || { echo "deploy/.env não encontrado." >&2; exit 1; }
DB_USERNAME="$(env_val DB_USERNAME)"
DB_DATABASE="$(env_val DB_DATABASE)"
[ -n "$DB_USERNAME" ] && [ -n "$DB_DATABASE" ] || { echo "DB_USERNAME ou DB_DATABASE vazio no deploy/.env." >&2; exit 1; }

BACKUP_DIR="${BACKUP_DIR:-$(cd .. && pwd)/backups}"

docker compose exec -T db pg_restore --list < "$arquivo" > /dev/null || { echo "Dump ilegível; nada foi alterado." >&2; exit 1; }

echo "ATENÇÃO: isto SOBRESCREVE o banco '$DB_DATABASE' com o conteúdo de $arquivo."
echo "Os serviços app, worker e scheduler ficam parados durante a restauração."
read -r -p 'Digite RESTAURAR para continuar: ' resposta
[ "$resposta" = "RESTAURAR" ] || { echo "Cancelado; nada foi alterado." >&2; exit 1; }

umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
seguranca="$BACKUP_DIR/pre-restauracao-$(date +%Y%m%d-%H%M%S).dump"
docker compose exec -T db pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > "$seguranca"
[ -s "$seguranca" ] || { echo "Dump de segurança vazio; nada foi alterado." >&2; exit 1; }
echo "Dump de segurança do estado atual: $seguranca"

docker compose stop app worker scheduler
docker compose exec -T db pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" --clean --if-exists --no-owner --single-transaction < "$arquivo" || {
  echo "Restauração falhou e foi desfeita (transação única). Serviços continuam parados; dump de segurança: $seguranca" >&2
  exit 1
}
docker compose up -d app worker scheduler

tabelas="$(docker compose exec -T db psql -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc "select count(*) from information_schema.tables where table_schema = 'public'")"
echo "Restauração concluída. Tabelas no schema public: $tabelas"
```

- [ ] **Step 4: Rodar e ver passar, mais checagem de sintaxe**

Run: `cd backend && php artisan test --filter=BackupDoBancoTest && bash -n ../deploy/restaurar.sh && echo SINTAXE_OK`
Expected: 7 testes passam; `SINTAXE_OK`.

- [ ] **Step 5: Commit**

```bash
git add deploy/restaurar.sh backend/tests/Unit/Deploy/BackupDoBancoTest.php
git commit -m "feat(deploy): restauração do banco com confirmação, dump de segurança e transação única"
```

---

### Task 3: Runbook, docs e fechamento

**Files:**
- Create: `docs/go-live/backup-e-restauracao.md`
- Modify: `docs/go-live/checklist-producao.md`
- Modify: `deploy/README.md:41`
- Modify: `PROGRESSO.md`, `TAREFAS.md` (F8c)

**Interfaces:**
- Consumes: `deploy/backup.sh`, `deploy/restaurar.sh`, prefixos `plataforma-*.dump` e `pre-restauracao-*.dump`.
- Produces: nada que outra tarefa consuma.

- [ ] **Step 1: Escrever o runbook**

Criar `docs/go-live/backup-e-restauracao.md`:

````markdown
# Backup e restauração do banco

Escopo: backup diário do PostgreSQL e restauração. Só o banco: XMLs, certificados A1 (criptografados) e todos os dados ficam nele. O app não grava arquivos em `storage` (só logs e cache). **Se alguém passar a gravar arquivos lá, este backup precisa mudar.**

Scripts: `deploy/backup.sh` e `deploy/restaurar.sh`. Dumps em `backups/` na raiz do repositório na VPS (`~/crm-plataforma/src/backups`), com permissão 700; a pasta fica fora do git.

## Limites que você aceitou

- **O backup fica só no disco da VPS.** Se a VPS for perdida ou o disco corromper, os dumps vão junto. Copie o dump mais recente para outra máquina de vez em quando, por exemplo do seu computador:

```bash
scp usuario@VPS:~/crm-plataforma/src/backups/plataforma-AAAAMMDD-HHMMSS.dump .
```

- **O dump não é criptografado.** As colunas sensíveis já são criptografadas pelo app com a `APP_KEY`; o restante (notas, clientes) está legível no dump. Trate o arquivo como dado de cliente.
- **A `APP_KEY` não está no backup.** Sem ela, o dump restaurado não abre os certificados A1 nem os outros segredos. Guarde a `APP_KEY` (valor do `deploy/.env`) num cofre de senhas seu, fora da VPS. Restaurar exige a mesma `APP_KEY`.
- Retenção de 14 dias: o `backup.sh` apaga `plataforma-*.dump` mais antigos. Os `pre-restauracao-*.dump` nunca são apagados sozinhos; remova à mão quando não precisar mais.

## Agendar

Na VPS, com o usuário que roda o Docker:

```bash
chmod +x ~/crm-plataforma/src/deploy/backup.sh ~/crm-plataforma/src/deploy/restaurar.sh
crontab -e
```

Linha do cron (todo dia às 03:00):

```
0 3 * * * ~/crm-plataforma/src/deploy/backup.sh >> ~/crm-plataforma/src/backups/backup.log 2>&1
```

Teste uma vez à mão, antes de confiar no cron:

```bash
~/crm-plataforma/src/deploy/backup.sh
ls -lh ~/crm-plataforma/src/backups
```

Esperado: `Backup ok: .../plataforma-AAAAMMDD-HHMMSS.dump (tamanho)` e o arquivo na pasta.

## Conferir que o backup do dia existe

```bash
ls -lt ~/crm-plataforma/src/backups | head -5
tail -n 5 ~/crm-plataforma/src/backups/backup.log
```

O arquivo mais recente deve ter menos de 24 h. Se o log mostrar `Dump vazio` ou `Dump ilegível`, o backup do dia falhou e **nenhum backup antigo foi apagado**; investigue antes da próxima noite.

## Restaurar

1. Confira que tem a `APP_KEY` do banco original no `deploy/.env` da máquina de destino.
2. Escolha o dump: `ls -lt ~/crm-plataforma/src/backups`.
3. Rode:

```bash
cd ~/crm-plataforma/src/deploy
./restaurar.sh ../backups/plataforma-AAAAMMDD-HHMMSS.dump
```

4. O script valida o arquivo, pede que você digite `RESTAURAR`, grava `pre-restauracao-*.dump` com o estado atual, para `app`, `worker` e `scheduler`, restaura numa transação única e sobe os serviços. Se a restauração falhar, ela é desfeita inteira, os serviços continuam parados e o dump de segurança aponta o estado anterior.
5. Confira: login funciona, a lista de notas mostra as notas esperadas e `docker compose ps` mostra os serviços de pé.

Em outra VPS: suba o `db` (`docker compose up -d db`), rode `php artisan migrate --force` só se o dump for mais antigo que o código, coloque a mesma `APP_KEY` no `.env` e rode o `restaurar.sh`.

## Roteiro de verificação (você executa no staging)

Esta verificação **não foi executada pelo desenvolvimento**: a máquina de desenvolvimento não tem Docker nem PostgreSQL. Faça uma vez, no staging, antes de confiar no backup:

1. Rode `./backup.sh`; confirme `Backup ok` e o arquivo.
2. Anote uma contagem: `docker compose exec -T db psql -U plataforma -d plataforma -tAc "select count(*) from notas"`.
3. Crie um dado de teste (ex.: um cliente novo na tela) e confirme que a contagem ou a lista mudou.
4. Rode `./restaurar.sh ../backups/<o dump do passo 1>`, digite `RESTAURAR`.
5. Confirme que o dado criado no passo 3 sumiu (voltou ao estado do dump), que o login funciona e que a contagem do passo 2 voltou.
6. Rode `ls ../backups`: o `pre-restauracao-*.dump` existe.
7. Teste a recusa: `./restaurar.sh arquivo-inexistente.dump` deve imprimir o uso e sair com erro; um arquivo qualquer (`echo x > /tmp/falso.dump`) deve ser recusado com `Dump ilegível` sem alterar nada.
````

- [ ] **Step 2: Atualizar checklist, README e rastreio**

Em `docs/go-live/checklist-producao.md`, inserir entre as seções 4 e 5 uma nova subseção:

```markdown
### 4.1 Backup

1. Guarde a `APP_KEY` do `.env` num cofre de senhas fora da VPS.
2. Agende o backup diário e teste uma vez, seguindo `docs/go-live/backup-e-restauracao.md`.
3. Lembre: o backup fica só no disco da VPS; copie o dump para fora de vez em quando.
```

Em `deploy/README.md`, trocar a linha `Backup do banco: ...pg_dump...` por:

```markdown
Backup e restauração do banco: `./backup.sh` e `./restaurar.sh <dump>` (veja `docs/go-live/backup-e-restauracao.md`).
```

Em `TAREFAS.md` marcar `- [x] **F8c Backup e restauração:**` e acrescentar no fim da linha: `Scripts e runbook prontos; o ciclo real de backup e restauração ainda precisa ser provado pelo usuário no staging (roteiro no runbook).` Em `PROGRESSO.md` registrar o fechamento da F8c (1 a 3 linhas: scripts, teste de conteúdo, limite não executado), a próxima tarefa F8d e reescrever a "Contexto necessário" para F8d.

- [ ] **Step 3: Suíte completa e checagens**

Run: `cd backend && php artisan test 2>&1 | tail -6 && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G 2>&1 | tail -3`
Expected: toda a suíte passa (1551 testes: 1544 + 7); Pint e PHPStan sem erros. Comandos longos vão em background (timeout de 120 s).

- [ ] **Step 4: Commit**

```bash
git add docs/go-live deploy/README.md PROGRESSO.md TAREFAS.md
git commit -m "docs: F8c concluída (runbook de backup e restauração)"
```
