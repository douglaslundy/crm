# Deploy de acompanhamento (staging)

Docker Compose isolado (projeto `crm`) para ver o sistema funcionando numa VPS. Serviços: `db` (PostgreSQL 16, sem porta no host), `app` (API: nginx + PHP-FPM), `worker` (fila `database`), `scheduler` (agendador) e `web` (Next.js).

| Porta | O quê |
|---|---|
| `3200` | sistema (frontend) |
| `8200` | API |

Escolhidas para não colidir com outros projetos da mesma VPS. Mude `WEB_PORT`/`API_PORT` no `.env` se precisar (e `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `NEXT_PUBLIC_API_URL` junto).

## Atualizar / instalar

No computador de desenvolvimento, empacote o código commitado e envie para a VPS (não precisa de push):

```bash
git archive --format=tar.gz -o crm.tar.gz HEAD
scp crm.tar.gz usuario@VPS:~/crm-plataforma/
ssh usuario@VPS 'cd ~/crm-plataforma && mkdir -p src && tar -xzf crm.tar.gz -C src && bash src/deploy/deploy.sh --seed-demo'
```

O `deploy.sh` cria o `deploy/.env` na primeira vez (segredos aleatórios, `HOST` = IP da VPS ou o valor de `HOST=`), constrói, roda as migrations e sobe tudo. `--seed-demo` cria as contas de demonstração só se o banco estiver vazio.

## Contas de demonstração (senha `Senha123`)

- `admin@plataforma.local`: administrador da plataforma (`/admin`).
- `dono@empresa.local`: dono da empresa demonstração.

São contas públicas do projeto: use este ambiente só em rede privada e com dados fictícios. **Não coloque certificado A1 nem CNPJ real aqui** sem decidir isso conscientemente (a homologação real com a SEFAZ é outro passo).

## Operação

```bash
cd ~/crm-plataforma/src/deploy
docker compose ps
docker compose logs -f app worker        # logs da API e da fila
docker compose exec app php artisan tinker
docker compose down                      # para (os dados ficam nos volumes)
```

Backup do banco: `docker compose exec -T db pg_dump -U plataforma plataforma > backup.sql`.

## Limitações

HTTP puro e sem domínio (cookie `SESSION_SECURE_COOKIE=false`): serve para rede privada. Para a internet, ponha HTTPS na frente e ajuste `.env` (`SESSION_SECURE_COOKIE=true`, domínios e `NEXT_PUBLIC_API_URL`).
