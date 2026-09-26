# Progresso do Projeto

## Última atualização
2026-09-26

## Tarefa em andamento
Revisão final do branch `f0-fundacao` e integração ao `master`.

## Contexto necessário
- Spec mestre: `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`, §4 (planos e limites) e §5 (área do CNPJ).
- `docs/adr/0002-isolamento-de-tenant.md`: como tornar um Model de tenant.
- Código base: `backend/app/Modules/{Tenancy,Identity}/` e `frontend/components/layout/nav-items.ts` (onde entram os menus novos).

## Concluído
- [x] Requisitos, decisões, base fiscal (`docs/referencia-fiscal/`) e pesquisa de CRM.
- [x] Projeto mestre (spec) aprovado.
- [x] **F0 Fundação** (integrada ao master em 2026-09-26, depois da revisão final e da passada de correções):
  - [x] Task 1: backend Laravel 12 com `/api/health`.
  - [x] Task 2: tenancy fail-closed (`TenantContext`, `TenantScope`, `BelongsToTenant`).
  - [x] Task 3: identidade (login SPA, logout, me, recuperação de senha, bloqueio após 5 tentativas, contexto de tenant por usuário).
  - [x] Task 4: frontend Next.js 16 + shadcn + temas dark e light + Vitest.
  - [x] Task 5: cliente de API Sanctum e telas de login e recuperação.
  - [x] Task 6: layout responsivo (sidebar e barra inferior) com proteção de rota.
  - [x] Task 7: CI, PHPStan nível 6, Pint, docker-compose, ADRs 0001 a 0003 e README.

## Decisões não óbvias
- O `php.ini` global ganhou `pdo_sqlite`, `sqlite3`, `soap` e `intl`, com autorização do usuário e backup `php.ini.bak`.
- Versões do frontend: `zod ^4`, `@vitejs/plugin-react ^5` (a v6 conflita com o peer do Babel 8) e `@types/node ^24`.
- `mutationFn` sempre recebe `(dados) => api(dados)`, porque o TanStack 5 passa o contexto como 2º argumento.
- O PHPStan analisa também `tests/Fixtures`, para a trait de tenant ser verificada.

## Próxima tarefa
Escrever a spec da **F1 (Plataforma e planos)**: admin do SaaS, CRUD de planos (módulos e limites), onboarding de empresa, usuários e papéis, `EntitlementService`.
