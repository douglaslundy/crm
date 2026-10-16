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

1. Confira que o `deploy/.env` da máquina de destino tem a `APP_KEY` do banco original.
2. Escolha o dump: `ls -lt ~/crm-plataforma/src/backups`.
3. Rode:

```bash
cd ~/crm-plataforma/src/deploy
./restaurar.sh ../backups/plataforma-AAAAMMDD-HHMMSS.dump
```

4. O script valida o arquivo, pede que você digite `RESTAURAR`, grava `pre-restauracao-*.dump` com o estado atual, para `app`, `worker` e `scheduler`, restaura numa transação única e sobe os serviços. Se a restauração falhar, ela é desfeita inteira, os serviços continuam parados e o dump de segurança guarda o estado anterior.
5. Confira: o login funciona, a lista de notas mostra as notas esperadas e `docker compose ps` mostra os serviços de pé.

Em outra VPS: suba o `db` (`docker compose up -d db`), coloque a mesma `APP_KEY` no `.env`, rode o `restaurar.sh` e depois `docker compose run --rm app php artisan migrate --force` se o dump for mais antigo que o código.

## Roteiro de verificação (você executa no staging)

Esta verificação **não foi executada pelo desenvolvimento**: a máquina de desenvolvimento não tem Docker nem PostgreSQL. Os testes automáticos só conferem o conteúdo e a sintaxe dos scripts. Faça este roteiro uma vez, no staging, antes de confiar no backup:

1. Rode `./backup.sh`; confirme `Backup ok` e o arquivo.
2. Anote uma contagem: `docker compose exec -T db psql -U plataforma -d plataforma -tAc "select count(*) from clientes"` (use uma tabela que a tela permita alterar).
3. Crie um dado de teste (por exemplo, um cliente novo na tela) e confirme que a contagem mudou.
4. Rode `./restaurar.sh ../backups/<o dump do passo 1>` e digite `RESTAURAR`.
5. Confirme que o dado criado no passo 3 sumiu, que a contagem do passo 2 voltou e que o login funciona.
6. Rode `ls ../backups`: o `pre-restauracao-*.dump` existe.
7. Teste a recusa: `./restaurar.sh arquivo-inexistente.dump` imprime o uso e sai com erro; `echo x > /tmp/falso.dump` seguido de `./restaurar.sh /tmp/falso.dump` é recusado com `Dump ilegível`, sem alterar nada.
