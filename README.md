# Plataforma Fiscal + CRM

SaaS de emissão de NF-e, NFC-e e NFS-e por assinatura, com API pública e CRM.

- **Projeto mestre:** `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`
- **Decisões de arquitetura:** `docs/adr/`
- **Base fiscal:** `docs/referencia-fiscal/00-INDICE.md`
- **Progresso e próxima tarefa:** `PROGRESSO.md` e `TAREFAS.md`

## Rodar localmente

Requisitos: PHP 8.2+ (com `pdo_sqlite`, `soap` e `intl`), Composer 2, Node 24.

```bash
# backend (http://localhost:8000)
cd backend
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve

# frontend (http://localhost:3000), em outro terminal
cd frontend
cp .env.example .env.local
npm install && npm run dev
```

Contas de demonstração (só no ambiente local, senha `Senha123`):
- `dono@empresa.local`: dono da empresa demonstração.
- `admin@plataforma.local`: admin da plataforma.

## Testes e qualidade

```bash
cd backend && php artisan test && composer analyse && vendor/bin/pint --test
cd frontend && npm test && npm run typecheck && npm run lint
```

## Produção

| Variável | Valor | Por quê |
|---|---|---|
| `SESSION_DOMAIN` | domínio pai comum ao front e à API (ex.: `.suaempresa.com.br`) | o cookie de sessão precisa valer nos dois |
| `SESSION_SECURE_COOKIE` | `true` | cookie só em HTTPS |
| `SANCTUM_STATEFUL_DOMAINS` | domínio do frontend (ex.: `app.suaempresa.com.br`) | libera a sessão do SPA |
| `FRONTEND_URL` | URL do frontend | CORS e links dos e-mails (senha e convite) |
| `TRUSTED_PROXIES` | IPs/CIDRs do balanceador, ou `*` só atrás de proxy controlado | IP real do cliente nos limites de tentativa |
| `QUEUE_CONNECTION` | `redis` (ou `database`) com um worker rodando | e-mails e jobs fora da requisição |
| `BRASILAPI_URL` | `https://brasilapi.com.br` | consulta de CNPJ no cadastro (opcional) |

O agendador precisa rodar a cada minuto (`* * * * * php artisan schedule:run`). É ele que suspende as contas com teste vencido às 00:10 (America/Sao_Paulo).

## Endpoints da F1

- **Público:** `GET /api/publico/planos`, `GET /api/publico/cnpj/{cnpj}`, `POST /api/publico/cadastro`.
- **Empresa** (sessão, grupo `empresa`):
  - `GET /api/app/assinatura`.
  - `GET|POST /api/app/usuarios`, `PUT /api/app/usuarios/{id}`, `POST /api/app/usuarios/{id}/desativar|reativar`.
- **Convite:** `POST /api/app/auth/aceitar-convite`.
- **Admin** (sessão, grupo `plataforma`: SUPERADMIN escreve, SUPORTE lê):
  - `/api/admin/planos` (+ `{id}`, `{id}/desativar`).
  - `/api/admin/empresas` (+ `{id}`, `{id}/situacao`, `{id}/plano`).
  - `/api/admin/metricas`.

Erros de negócio sempre vêm como `{"message": "...", "codigo": "..."}`:

| Código | Status |
|---|---|
| `ASSINATURA_SEM_ESCRITA` | 403 |
| `ASSINATURA_PENDENTE` | 403 |
| `ACESSO_NEGADO` | 403 |
| `MODULO_NAO_CONTRATADO` | 403 |
| `LIMITE_DO_PLANO` | 422 |
| `TRANSICAO_INVALIDA` | 422 |
| `PLANO_EXCEDIDO` | 422 |
| `PLANO_INDISPONIVEL` | 422 |
| `CONSULTA_INDISPONIVEL` | 503 |
