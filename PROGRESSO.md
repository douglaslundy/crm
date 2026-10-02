# Progresso do Projeto

## Última atualização
2026-10-02

## Tarefa em andamento
**F3a (emissão de NF-e), execução inline na master (ledger em `.superpowers/sdd/2026-10-02-f3a-nfe-emissao/progress.md`, git-ignorado).** Backend concluído (Tarefas 1 a 16): backend 376 testes, Pint e PHPStan verdes. Faltam o frontend (17 a 22) e o fechamento (23). Plano `docs/superpowers/plans/2026-10-02-f3a-nfe-emissao.md`; spec `docs/superpowers/specs/2026-10-01-f3a-nfe-emissao-design.md`.

## Decisões pendentes com o usuário
- Nenhuma. O push de 2026-10-02 levou a F2 e os docs da F3a ao `origin`; os commits da F3a ainda não foram enviados.

## Decisões já tomadas
- **Papel PROPRIETARIO irreversível (2026-10-01):** mantido como está — ninguém altera nem desativa um proprietário pela aplicação; reversão só no banco.
- **Execução da F3a (2026-10-02):** inline (native), direto na master, sem worktree (escolha do usuário).
- **Numeração (F3a):** o contador `proximo_numero` da série é único e compartilhado entre homologação e produção (spec).
- **`sped-nfe` v5.2.8** instalado; assinaturas `tag*()` conferidas no vendor (um `stdClass` cada).
- **Retry de ERRO:** `IniciarEmissao` preserva `chave`/`xml_enviado` quando o status anterior é ERRO e o número é reaproveitado, para o job consultar a SEFAZ antes de reenviar (evita duplicata). REJEITADA zera.
- `EmitirNotaJob` restaura o `TenantContext` anterior (com fila `sync` o job redespachado limparia o do chamador).
- `cNF` como `12345678` é recusado pela `Make` (sequência); nos testes usar `47382915`.
- Testes: helper `spaComo($usuario)` no `TestCase` troca de usuário sem o 401 espúrio.
- `NfePhpEmissor` não tem teste automatizado (exige SEFAZ); coberto pela fumaça de homologação (Tarefa 23, precisa de certificado A1 e CNPJ de homologação).

## Contexto necessário
- Frontend da F3a (Tarefas 17 a 22): ler só as tarefas no plano (`task-start` gera o brief) e os padrões de `frontend/features/produtos` ou `clientes` (tipos, schemas, api, `Campo`, `useEnvioUnico`, TanStack Query, zod 4).
- Contrato da API de notas: `backend/app/Modules/Fiscal/Http/Resources/NotaResource.php` e `routes-app.php`.
- Skill fiscal: `br-fiscal-note-emission` — ler antes de qualquer código que toque regra fiscal.
- Pendências antigas: `TAREFAS.md`.

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
Tarefa 17 da F3a (frontend: tipos, schemas e API de notas). Contexto: só o plano e os padrões de `frontend/features/clientes`. O teste `CadastroForm` estoura 5 s às vezes na suíte completa (passa isolado).
