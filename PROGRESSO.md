# Progresso do Projeto

## Última atualização
2026-09-26

## Tarefa em andamento
Revisão, pelo usuário, do plano da F1 e escolha do modo de execução (subagentes ou nativo).

## Contexto necessário
- Plano: `docs/superpowers/plans/2026-09-27-f1-plataforma-e-planos.md` (18 tarefas: backend de 1 a 11, frontend de 12 a 18). Ledger em `.superpowers/sdd/`.
- Spec da F1 (aprovada): `docs/superpowers/specs/2026-09-26-f1-plataforma-e-planos-design.md`.
- A execução começa numa branch nova (`f1-plataforma`), nunca direto no `master`.

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
  - [x] Correções da revisão: `tenant_id` imutável, e-mail de senha enviado depois da resposta, `TRUSTED_PROXIES`, pt-BR, retry no 419, envio único nos formulários.
- [x] Spec da F1 escrita (commit `1c92bb8`) e aprovada.
- [x] Plano da F1 escrito.

## Decisões não óbvias
- O `php.ini` global ganhou `pdo_sqlite`, `sqlite3`, `soap` e `intl`, com autorização do usuário e backup `php.ini.bak`.
- Versões do frontend: `zod ^4`, `@vitejs/plugin-react ^5` (a v6 conflita com o peer do Babel 8) e `@types/node ^24`.
- `mutationFn` sempre recebe `(dados) => api(dados)`, porque o TanStack 5 passa o contexto como 2º argumento.
- Formulários usam `useEnvioUnico` (trava por ref). `isSubmitting` sozinho não segura dois cliques no mesmo tick.
- O PHPStan analisa também `tests/Fixtures`, para a trait de tenant ser verificada.
- Idioma pt-BR via `laravel-lang` (arquivos em `backend/lang/pt_BR`). O 429 é traduzido no handler de exceções.
- Os arquivos voltam do checkout com CRLF (autocrlf do Windows). Edições por regex com `\n` falham nesses arquivos.

## Próxima tarefa
Com o plano aprovado: executar a F1 tarefa por tarefa, a partir da Tarefa 1.
