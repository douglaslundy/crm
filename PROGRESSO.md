# Progresso do Projeto

## Última atualização
2026-10-02

## Tarefa em andamento
**F3a (emissão de NF-e): spec aprovado e plano escrito, aguardando revisão do usuário e escolha do método de execução.** Spec `docs/superpowers/specs/2026-10-01-f3a-nfe-emissao-design.md`; plano `docs/superpowers/plans/2026-10-02-f3a-nfe-emissao.md` (23 tarefas: backend 1 a 16, frontend 17 a 22, fechamento 23).

## Decisões pendentes com o usuário
- **Remoto no GitHub:** `origin` existe, mas está em `a9dc666` (fim da F1); faltam 35 commits (F2 e docs da F3a). Fazer push para o CI rodar a F2, incluindo o job em PostgreSQL 16.

## Decisões já tomadas
- **Papel PROPRIETARIO irreversível (2026-10-01):** mantido como está — ninguém altera nem desativa um proprietário pela aplicação; reversão só no banco.

## Contexto necessário
- Spec mestre: `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`, linha F2 do §15 (clientes, produtos, serviços, categorias fiscais, pendências, importação CSV, dados fiscais do emitente, certificado, CSC, séries).
- Base fiscal: `docs/referencia-fiscal/00-INDICE.md` — abrir só o arquivo do assunto necessário.
- Skill fiscal: `br-fiscal-note-emission` — ler antes de qualquer código ou spec que toque regra fiscal.
- Padrões já estabelecidos na F1 (seguir): `docs/adr/0004-planos-situacao-e-limites.md` (EntitlementService, contadores por módulo, erros `{message, codigo}`), grupo de middleware `empresa`, `Campo`/`useEnvioUnico` no frontend.
- F3 (NF-e e NFC-e): ainda sem spec nem plano. Ler a linha F3 do §15 do spec mestre e a skill fiscal, depois brainstorming.
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
- [x] **Plano da F2** escrito (2026-10-01): `docs/superpowers/plans/2026-10-01-f2-cadastros-e-emitente.md`, 28 tarefas.
  - Ordem: `Shared` (Cpf, ConsultaCep) → `Catalog` (produtos/serviços/categorias/pendências) → `Customers` (clientes/contatos/conversão) → `Fiscal` (emitente/certificado/CSC/séries) → matriz de papéis → CSV → API pública `/api/v1` → frontend (produtos, serviços, pendências, clientes, wizard do emitente, CSV) → revisão final.
- [x] **F2 concluída (28 tarefas)** (2026-10-01): Shared (Cpf, ConsultaCep), upload FormData, Catalog (produto, serviço, categoria fiscal, validador, pendências), Customers (cliente, contato, LEAD→CLIENTE), Fiscal (emitente, certificado A1, CSC, séries), matriz de papéis, importador CSV, CSV de cadastros, API `/api/v1`, frontend de produtos, serviços, pendências, clientes e wizard do emitente (`/onboarding`, `/configuracoes/fiscal`).

- **Fechamento da F2 (2026-10-01):** backend 261 testes, frontend 92; Pint, PHPStan, typecheck, lint e build verdes. Fumaça com curl (SQLite descartável) passou: cadastro, emitente (empresa, fiscal, certificado .pfx VALIDO, série), lead→cliente, produto com `origem: 0` (gravado como 0), serviço e CSV (1 criado, 1 erro por linha). Sem `?? 0`/`empty()` em Catalog e Fiscal. Conferência visual no navegador (passo 12) não feita.
- Fumaça: JSON com acentos via curl no Git Bash chega corrompido; usar escapes `ç` no corpo.
- Produto com `origem: 0` ainda aparece em pendências por faltar NCM/tributação, não por causa da origem (comportamento esperado).

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
Usuário revisa o plano da F3a e escolhe a execução (subagent-driven ou native). Depois: Tarefa 1 (instalar `sped-nfe`, fila `database`, `config/fiscal.php`). Contexto: só o plano e o spec da F3a; abrir `docs/referencia-fiscal/03-nfephp-nfe.md` e `~/.claude/skills/br-fiscal-note-emission/references/pitfalls.md` nas Tarefas 12 e 13. Observação: o teste `CadastroForm` estoura 5 s às vezes na suíte completa (passa isolado).
