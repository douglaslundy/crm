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
