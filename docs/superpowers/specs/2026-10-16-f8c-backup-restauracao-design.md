# F8c: backup e restauração (design)

Data: 2026-10-16. Caminho: arquitetural (spec, plano, execução). Origem: `TAREFAS.md` F8c.

## Objetivo

Ter um backup diário do banco de produção e um procedimento de restauração escrito, com scripts que recusam backup inválido e pedem confirmação antes de sobrescrever dados.

## Decisões do usuário (2026-10-16)

1. Destino: **só o disco local da VPS**. Não protege contra perda da própria VPS; o runbook avisa e recomenda copiar o dump para fora de vez em quando.
2. APP_KEY: o backup guarda só o banco. O runbook manda guardar a APP_KEY fora da VPS (cofre de senhas do usuário). Restaurar exige a mesma APP_KEY.
3. Retenção: **14 dias**.

## Fatos que sustentam o escopo

- O app não grava arquivos no storage: XMLs (`xml_enviado`, `xml_autorizado`, `xml_cancelamento`, `xml_epec`, `xml_resposta`), certificados A1 (criptografados) e demais dados ficam no PostgreSQL. O volume `storage` do Compose guarda só logs e cache do framework.
- O banco roda no serviço `db` (`postgres:16-alpine`, volume `pgdata`), sem porta no host. O backup existente documentado é um `pg_dump` manual em `deploy/README.md`.

## Componentes

**`deploy/backup.sh`** (bash, `set -euo pipefail`, executado dentro de `deploy/`):
- Lê `DB_USERNAME` e `DB_DATABASE` de `deploy/.env`.
- Roda `docker compose exec -T db pg_dump -Fc` (formato custom, comprimido) para `backups/plataforma-AAAAMMDD-HHMMSS.dump.tmp`.
- Valida o `.tmp` com `pg_restore --list` (via `docker compose exec -T db`); arquivo vazio ou ilegível falha e remove o `.tmp`.
- Só então renomeia (`mv`) para `.dump`, de forma atômica.
- Depois da validação, apaga os `.dump` com mais de 14 dias (`find -mtime +14`). Se o backup do dia falhar, nada é apagado.
- Qualquer falha sai com código diferente de zero.
- Diretório de destino: `BACKUP_DIR` (padrão `deploy/../backups`), criado com `chmod 700`; os arquivos são `chmod 600`.

**`deploy/restaurar.sh <arquivo.dump>`**:
- Exige o arquivo, valida com `pg_restore --list` antes de qualquer alteração.
- Pede confirmação digitada (`RESTAURAR`), porque sobrescreve o banco atual.
- Faz um dump de segurança do estado atual (`pre-restauracao-AAAAMMDD-HHMMSS.dump`) antes de tocar no banco.
- Para `app`, `worker` e `scheduler`; roda `pg_restore --clean --if-exists --no-owner` dentro do `db`; sobe os serviços de novo.
- Mostra a contagem de tabelas do schema `public` ao final.

**Agendamento:** linha de cron do usuário da VPS, diária às 03:00, com saída para `backups/backup.log`. Fica no runbook; não instalo cron na máquina do usuário.

**Runbook `docs/go-live/backup-e-restauracao.md`:**
- Como agendar, onde ficam os arquivos e como conferir que o backup do dia existe.
- Aviso de que disco local não protege contra perda da VPS; recomendação de copiar o dump para fora (comando `scp` de exemplo).
- Guardar a APP_KEY fora da VPS e restaurar com a mesma APP_KEY.
- Restauração passo a passo, inclusive em outra VPS.
- Roteiro de verificação (ciclo completo no staging), para o usuário executar.
- Regra: se o app passar a gravar arquivos em `storage`, o backup precisa mudar.

`docs/go-live/checklist-producao.md` ganha um passo apontando para o runbook. `deploy/README.md` troca o `pg_dump` manual pela referência aos scripts. `backups/` entra no `.gitignore`.

## Testes

Teste PHPUnit `backend/tests/Unit/Deploy/BackupDoBancoTest.php`, no estilo de `EnvDeProducaoTest`, sobre o conteúdo dos scripts:
- Os dois começam com `set -euo pipefail`.
- `backup.sh` usa `pg_dump -Fc`, valida com `pg_restore --list`, renomeia depois de validar e usa `-mtime +14`.
- `restaurar.sh` valida o arquivo, exige a confirmação `RESTAURAR`, faz o dump de segurança antes do `pg_restore` e usa `--clean --if-exists`.
- `.gitignore` ignora `backups/`.

Se houver `bash` no ambiente, o teste também roda `bash -n` em cada script para pegar erro de sintaxe.

## Limites

- Esta máquina não tem Docker nem PostgreSQL, então **o ciclo real de backup e restauração não foi executado aqui**. Os testes provam o conteúdo e a sintaxe dos scripts, não o comportamento contra um banco. A prova é o roteiro de verificação do runbook, a cargo do usuário no staging.
- Backup só local: perda ou corrupção da VPS leva os dumps junto.
- O dump não é criptografado; as colunas sensíveis já estão criptografadas pelo app com a APP_KEY, e o diretório tem permissão 700.
- `pg_restore --clean` substitui os objetos do banco inteiro; restauração parcial por tenant está fora do escopo.

## Fora do escopo

Envio para fora da VPS, criptografia do dump, alerta de falha de backup, restauração por tenant, backup do volume `storage`, testes de carga (F8d).

## Critério de pronto

Scripts e runbook commitados, teste de conteúdo verde, `.gitignore` atualizado, `checklist-producao.md` e `deploy/README.md` atualizados, `PROGRESSO.md` e `TAREFAS.md` atualizados com o limite acima dito às claras.

## Ajustes na implementação

- O `bash -n` não roda dentro do PHPUnit: no Windows o `bash` do PATH pode ser o do WSL e quebrar o teste por caminho. O teste confere só o conteúdo dos scripts; a sintaxe foi checada à mão com `bash -n` em cada script.
- Os scripts também foram exercitados sem Docker nas guardas de entrada (sem argumento, arquivo inexistente, `.env` ausente: saem com erro e mensagem). O caminho com banco continua não executado.
