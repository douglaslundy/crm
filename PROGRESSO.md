# Progresso do Projeto

## Última atualização
2026-10-01

## Tarefa em andamento
Spec da F2 escrita e aprovada no brainstorming: `docs/superpowers/specs/2026-10-01-f2-cadastros-e-emitente-design.md`. Aguardando a revisão do usuário sobre o arquivo antes de passar para o plano (`writing-plans`).

## Decisões pendentes com o usuário
- **Remoto no GitHub:** o repositório não tem remoto; o CI (incluindo o job em PostgreSQL 16) só roda depois de configurar um.

## Decisões já tomadas
- **Papel PROPRIETARIO irreversível (2026-10-01):** mantido como está — ninguém altera nem desativa um proprietário pela aplicação; reversão só no banco.

## Contexto necessário
- Spec mestre: `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`, linha F2 do §15 (clientes, produtos, serviços, categorias fiscais, pendências, importação CSV, dados fiscais do emitente, certificado, CSC, séries).
- Base fiscal: `docs/referencia-fiscal/00-INDICE.md` — abrir só o arquivo do assunto necessário.
- Skill fiscal: `br-fiscal-note-emission` — ler antes de qualquer código ou spec que toque regra fiscal.
- Padrões já estabelecidos na F1 (seguir): `docs/adr/0004-planos-situacao-e-limites.md` (EntitlementService, contadores por módulo, erros `{message, codigo}`), grupo de middleware `empresa`, `Campo`/`useEnvioUnico` no frontend.
- Pendências da revisão da F1 (não bloqueiam a F2): `TAREFAS.md`, seção "Pendências da revisão da F1".

## Concluído
- [x] Requisitos, decisões, base fiscal (`docs/referencia-fiscal/`) e pesquisa de CRM.
- [x] Projeto mestre (spec) aprovado.
- [x] **F0 Fundação** (integrada ao master em 2026-09-26).
- [x] **F1 Plataforma e planos** (integrada ao master em 2026-09-27 por fast-forward, commit `872e504`):
  - Spec `docs/superpowers/specs/2026-09-26-f1-plataforma-e-planos-design.md`; plano `docs/superpowers/plans/2026-09-27-f1-plataforma-e-planos.md` (18 tarefas, execução por subagentes com revisão por tarefa e revisão final).
  - Backend: planos com módulos e limites, situação da assinatura (máquina de estados + expiração diária do teste), `EntitlementService`, áreas `empresa`/`plataforma`, admin de planos, empresas e métricas, cadastro público com BrasilAPI e CNPJ alfanumérico, usuários da empresa com convite de 72 h, auditoria (activitylog).
  - Frontend: faixas de situação e "Aguardando ativação", consumo no dashboard, `/planos` e `/cadastro`, área `/admin` (painel, planos, empresas), `Configurações › Usuários`, `/definir-senha`.
  - Revisão final: corrigidos cadastro/login sem sessão (400 `SESSAO_INDISPONIVEL` antes de gravar), teste de isolamento da assinatura, locks contra corrida (situação × rotina noturna, troca de plano × convite), transação na auditoria de usuários, `strict_types` faltantes.
  - Suítes no master: backend 148 testes, frontend 74; Pint, PHPStan 6, typecheck, lint e build verdes. Fumaça ponta a ponta do critério de pronto: OK.
- [x] **Spec da F2** escrita e aprovada no brainstorming (2026-10-01): `docs/superpowers/specs/2026-10-01-f2-cadastros-e-emitente-design.md`.
  - Decisões: módulo `Fiscal` novo (só emitente/certificado/séries nesta fase); wizard de onboarding pós-ativação; certificado validado com `openssl_pkcs12_read()` nativo (sem antecipar `sped-nfe`); papéis (`PROPRIETARIO/ADMIN/FISCAL` no emitente, +`VENDEDOR` nos cadastros); CSV cria e atualiza por chave; F2 já constrói a transição `LEAD → CLIENTE`.
  - Ainda falta: plano de implementação (`writing-plans`), depois de o usuário revisar o arquivo da spec.

## Decisões não óbvias
- O `php.ini` global ganhou `pdo_sqlite`, `sqlite3`, `soap` e `intl`, com autorização do usuário e backup `php.ini.bak`.
- Versões do frontend: `zod ^4`, `@vitejs/plugin-react ^5` (a v6 conflita com o peer do Babel 8) e `@types/node ^24`.
- `mutationFn` sempre recebe `(dados) => api(dados)`, porque o TanStack 5 passa o contexto como 2º argumento.
- Formulários usam `useEnvioUnico` (trava por ref). `isSubmitting` sozinho não segura dois cliques no mesmo tick.
- O PHPStan analisa também `tests/Fixtures`, para a trait de tenant ser verificada.
- Idioma pt-BR via `laravel-lang` (arquivos em `backend/lang/pt_BR`). O 429 é traduzido no handler de exceções.
- Os arquivos voltam do checkout com CRLF (autocrlf do Windows). Edições por regex com `\n` falham nesses arquivos.
- Testes: trocar de usuário com `actingAs()` no mesmo teste dá 401 espúrio (AuthenticateSession do Sanctum) — usar `$this->app['auth']->forgetGuards(); $this->flushSession();` entre as trocas.
- `GarantirUsuarioAtivo` e `DefinirTenantDoUsuario` estão na priority list de middleware (antes de `SubstituteBindings`); há teste de ordem.
- Next 16: `AppShell` e `app/(admin)/layout.tsx` são `'use client'` (ícones Lucide não serializam de Server para Client).
- React Compiler lint: usar `useWatch` em vez de `form.watch()`.
- Convite expirado: a mensagem orienta "Esqueci minha senha" (não há reenvio de convite na F1).
- Fumaça com curl: usar banco SQLite descartável, host `localhost` (não `127.0.0.1`, por causa do `SESSION_DOMAIN`) e login em `/api/app/auth/login`.

## Próxima tarefa
Usuário revisa a spec da F2 (`docs/superpowers/specs/2026-10-01-f2-cadastros-e-emitente-design.md`). Aprovada → invocar `writing-plans` para gerar o plano de implementação (seguindo o padrão da F1: tarefas por subagente, revisão por tarefa e revisão final).
