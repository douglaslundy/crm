# Progresso do Projeto

## Última atualização
2026-09-27

## Tarefa em andamento
F1 concluída (branch `f1-plataforma`), aguardando revisão final e integração ao `master`. Próxima tarefa: escrever a spec da F2 (cadastros e configuração fiscal do emitente).

## Contexto necessário
- Spec mestre: `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`, §F2 (escopo da fase: clientes, produtos, serviços, categorias fiscais, pendências, importação CSV, dados fiscais do emitente, certificado, CSC, séries).
- Base fiscal: `docs/referencia-fiscal/00-INDICE.md` — abrir só o arquivo do assunto necessário.
- Skill fiscal: `br-fiscal-note-emission` — ler antes de qualquer código ou spec que toque regra fiscal (certificado, CSC, séries, dados do emitente).
- Pendências da revisão da F1 (não bloqueiam a F2): `TAREFAS.md`, seção "Pendências da revisão da F1".

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
- [x] **F1 Plataforma e planos** (18 tarefas, branch `f1-plataforma`, aguardando revisão final e integração ao `master`):
  - [x] Tarefas 1-11 (backend): tenancy fail-closed reforçado, `Plano`/`Tenant`/módulos/limites, `EntitlementService`, `MudarSituacaoDaEmpresa`, expiração de teste, cadastro público com CNPJ/BrasilAPI, admin de planos e empresas, convites e papéis de usuário.
  - [x] Tarefas 12-17 (frontend): tipos e API admin, telas de planos e empresas (admin), fluxo de cadastro público, área do app (dashboard, assinatura, usuários), convite e definição de senha.
  - [x] Tarefa 18 (fechamento): suíte completa verde (backend 142 testes, Pint, PHPStan nível 6, `composer audit`; frontend 74 testes, typecheck, lint, build, `npm audit`) e fumaça ponta a ponta via curl (plano com limite de 1 usuário → cadastro `PENDENTE` → ativação manual `ATIVA` → 4º passo barrado com `422 LIMITE_DO_PLANO`), exatamente o critério de pronto da spec mestre.

## Decisões não óbvias
- O `php.ini` global ganhou `pdo_sqlite`, `sqlite3`, `soap` e `intl`, com autorização do usuário e backup `php.ini.bak`.
- Versões do frontend: `zod ^4`, `@vitejs/plugin-react ^5` (a v6 conflita com o peer do Babel 8) e `@types/node ^24`.
- `mutationFn` sempre recebe `(dados) => api(dados)`, porque o TanStack 5 passa o contexto como 2º argumento.
- Formulários usam `useEnvioUnico` (trava por ref). `isSubmitting` sozinho não segura dois cliques no mesmo tick.
- O PHPStan analisa também `tests/Fixtures`, para a trait de tenant ser verificada.
- Idioma pt-BR via `laravel-lang` (arquivos em `backend/lang/pt_BR`). O 429 é traduzido no handler de exceções.
- Os arquivos voltam do checkout com CRLF (autocrlf do Windows). Edições por regex com `\n` falham nesses arquivos.

## Próxima tarefa
Escrever a spec da F2 (cadastros e configuração fiscal do emitente): clientes, produtos, serviços, categorias fiscais, pendências, importação CSV, dados fiscais, certificado, CSC e séries.
