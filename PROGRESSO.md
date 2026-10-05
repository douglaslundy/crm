# Progresso do Projeto

## Última atualização
2026-10-02

## Tarefa em andamento
**Nenhuma execução em andamento. F3b1 (cancelamento e inutilização da NF-e) concluída em 2026-10-02 (15 tarefas). F3b2 (PDF/DANFE da NF-e): spec escrita, commitada e aprovada pelo usuário (`docs/superpowers/specs/2026-10-02-f3b2-pdf-danfe-design.md`, motor `sped-da`); FALTA escrever o plano (skill writing-plans, ~7 tarefas) e executar em modo Native.** A F3b está dividida em F3b1 (feita), F3b2 (em andamento) e F3b3 (NFC-e).

Decisões da F3b1: cancelamento por fila com estado `CANCELANDO`; o prazo é decisão da SEFAZ (sem número fixo no código); cancelada continua contando na cota `DOCUMENTOS_MES`; `consultarPorChave` usa o ambiente da **nota** (corrige consulta no ambiente errado depois de "Ir para produção"); o XML do cancelamento é o `nfeProc` com `retEvento` (`Complements::cancelRegister`); aceitos 135/136/155; inutilização síncrona com guardas locais (faixa já alocada, sem nota autorizada/em processamento/cancelada na faixa), resultado sempre gravado.

## Decisões pendentes com o usuário
- **Push dos commits da F3a:** feitos na master; o último push foi `db2bc7d` (docs do backlog).
- **Roteiro de homologação real da F3a** (abaixo): só o usuário consegue, com certificado A1 e CNPJ de teste.

## Decisões já tomadas
- **Papel PROPRIETARIO irreversível (2026-10-01):** mantido como está — ninguém altera nem desativa um proprietário pela aplicação; reversão só no banco.
- **Execução da F3a (2026-10-02):** inline (native), direto na master, sem worktree (escolha do usuário).
- **Numeração (F3a):** o contador `proximo_numero` da série é único e compartilhado entre homologação e produção (spec).
- **`sped-nfe` v5.2.8** instalado; assinaturas `tag*()` conferidas no vendor (um `stdClass` cada).
- **Retry de ERRO:** `IniciarEmissao` preserva `chave`/`xml_enviado` quando o status anterior é ERRO e o número é reaproveitado, para o job consultar a SEFAZ antes de reenviar (evita duplicata). REJEITADA zera.
- `EmitirNotaJob` restaura o `TenantContext` anterior (com fila `sync` o job redespachado limparia o do chamador).
- `cNF` como `12345678` é recusado pela `Make` (sequência); nos testes usar `47382915`.
- Testes: helper `spaComo($usuario)` no `TestCase` troca de usuário sem o 401 espúrio.
- `NfePhpEmissor` não tem teste automatizado (exige SEFAZ); coberto pela fumaça abaixo e pelo roteiro de homologação real.

## Design system do CRM (2026-10-04)
- Documentação do layout do Mecânica Pro (somente leitura, `C:\Users\dougl\workspace\mecanicapro`) em `docs/design-system/` (índice em `00-INDICE.md`): análise, tokens dos temas escuro e claro com contraste WCAG, especificação de componentes, layout/responsividade e plano de aplicação.
- **Nada aplicado no CRM ainda.** Decisões tomadas (D1 a D6): escuro por padrão com alternância, mesmo visual COM correções de acessibilidade, manter o nome "Plataforma Fiscal", gaveta lateral no celular, ícones lucide, menu regular (14 px). Falta spec + plano das 5 fases (~28 tarefas, sem mudar regra de negócio) e a aprovação para executar.
- Defeitos do original que NÃO se copiam: sem foco visível nos campos, cores semânticas do tema claro com 2,0 a 3,7:1, borda de campo 1,2:1, texto branco em pílulas sólidas (3,3 a 4,2:1), ícones em emoji.

## Ambiente de acompanhamento (staging) na VPS (2026-10-04)
- **Endereço:** front `http://192.168.0.233:3200`, API `http://192.168.0.233:8200` (rede local, HTTP). VPS Ubuntu 26.04 **compartilhada** com outros projetos (monitoramento, xadrez, readcoach, market_system): o CRM roda num Compose isolado (projeto `crm`, pasta `~/crm-plataforma`, usuário `lundy`), sem mexer nos outros. `root` não loga por senha via SSH.
- **Contas de demonstração (senha `Senha123`):** `admin@plataforma.local` (admin da plataforma) e `dono@empresa.local` (dono da empresa demonstração). Só dados fictícios; nenhum certificado A1 nem CNPJ real.
- **Verificado:** build, migrations de todas as fases no PostgreSQL 16 (primeira vez fora do SQLite), login com sessão pela API, CORS, URL da API embutida no front, worker e agendador de pé, API ~35 ms. **Não verificado:** as telas no navegador.
- **Reimplantar:** `git -c core.autocrlf=false archive --format=tar.gz -o crm.tar.gz HEAD`, enviar para `~/crm-plataforma/`, extrair em `src/` (NÃO apagar `src/deploy/.env`: tem a senha do banco) e rodar `bash src/deploy/deploy.sh`. Detalhes em `deploy/README.md`.
- **Diferenças para produção real:** HTTP sem domínio, `INSTALL_DEV=1` (Faker para o seeder), `SESSION_SECURE_COOKIE=false`. Antes de expor à internet: HTTPS, domínio, trocar a senha do usuário SSH e as contas de demonstração.

## Fechamento da F3b1 (2026-10-02)
- Backend: 436 testes; frontend: 134; Pint, PHPStan 6, tsc e build verdes; eslint só com o warning antigo do `CadastroForm` (`form.watch`).
- **Fumaça com o motor real** (HTTP de teste, `NfePhpEmissor` real, `.pfx` autoassinado, sem EmissorFake), nota `AUTORIZADA` criada por `forceFill`:
  - `POST /cancelar` → 202; a nota ficou `CANCELANDO` com a justificativa gravada e o evento "Falha técnica ao cancelar: ...". A lib chegou a **montar o XML do evento** e a validação do schema recusou o autor como CPF com 14 dígitos: ela decide CNPJ ou CPF pelo OID ICP-Brasil do certificado, e o `.pfx` autoassinado não tem nenhum dos dois. Um A1 real tem o OID de CNPJ. A comunicação com a SEFAZ **não foi exercitada**.
  - `fiscal:reconciliar-processando` não mudou a nota (consulta também sem SEFAZ): segue `CANCELANDO`, nunca `CANCELADA` nem volta a `AUTORIZADA` sem confirmação.
  - Bloqueios: justificativa de 5 caracteres dá 422; nota em `RASCUNHO` dá 409 `CANCELAMENTO_NAO_PERMITIDO`; inutilização de faixa não alocada dá 422 `FISCAL_FAIXA_NAO_ALOCADA`.
- Conferência visual no navegador (temas e celular) **não feita**, como na F2 e na F3a.

## Roteiro de homologação real da F3b1 (para o usuário)
1. Ter uma NF-e de teste autorizada (roteiro da F3a).
2. Notas > abrir a nota autorizada > "Cancelar NF-e" com justificativa de 15+ caracteres; esperar `CANCELADA`; baixar o XML do cancelamento.
3. Emitir outra NF-e de teste, deixar passar do prazo da UF e tentar cancelar: a SEFAZ deve recusar (cStat 501 ou similar), a nota continua autorizada e a tela mostra o motivo. **Não** contornar.
4. Notas > Inutilizar numeração: inutilizar um número já alocado e não utilizado (ex.: emitir uma sequência de teste e inutilizar a faixa pulada).
5. Conferir códigos desconhecidos em `assets/cstat-table.md` da skill.
6. Worker (`php artisan queue:work`) e agendador (`php artisan schedule:work`) de pé; a reconciliação de 15 min agora também resolve `CANCELANDO`.

## Fechamento da F3a (2026-10-02)
- Backend: 376 testes; frontend: 117; Pint, PHPStan 6, tsc e build verdes; eslint só com o warning antigo do `CadastroForm` (`form.watch`).
- **Fumaça com o motor real** (HTTP de teste, `NfePhpEmissor` real, `.pfx` autoassinado gerado no openssl, sem EmissorFake):
  - Upload do certificado: 200, `VALIDO`. Rascunho: 201. `POST /emitir`: 202.
  - Resultado: `status=ERRO`, nunca `REJEITADA`; `chave` de 44 dígitos gravada, `xml_enviado` assinado (`<Signature`), número 1 preservado, trilha `RASCUNHO → PROCESSANDO → ERRO`.
  - O erro foi `Foi impossivel identificar o OID do CNPJ ou do CPF`: o certificado autoassinado não tem o OID ICP-Brasil do CNPJ que um A1 real tem. A comunicação com a SEFAZ **não foi exercitada** (precisa de A1 real).
  - Bloqueios: produto sem NCM dá 422 `FISCAL_PRODUTO_INCOMPLETO`; certificado vencido dá 422 `FISCAL_CERTIFICADO_INVALIDO`; a nota continua em RASCUNHO nos dois.
- Conferência visual no navegador (temas e celular) **não feita**, como na F2.

## Roteiro de homologação real da F3a (para o usuário)
1. Ter um certificado A1 do CNPJ de teste e a IE habilitada para NF-e em homologação na UF.
2. Em Configurações > Fiscal: endereço com IBGE, regime Simples, IE, CNAE, certificado, série NF-e (série 1, próximo número 1).
3. Cadastrar cliente PF com CPF, endereço e IBGE; produto com NCM real, origem, tributação ICMS.
4. Em Notas > Nova NF-e, emitir; esperar `AUTORIZADA`; baixar o XML.
5. Se vier `REJEITADA`, anotar `cStat` e `motivo` e conferir em `assets/cstat-table.md` da skill; **não** contornar a regra.
6. Só então "Ir para produção".
O worker precisa estar de pé: `php artisan queue:work` e o agendador `php artisan schedule:work` (a reconciliação roda a cada 15 min).

## Contexto necessário (F3b2: PDF da NF-e)
- Spec mestre §7 (PDF sempre a partir do XML autorizado) e `docs/superpowers/specs/2026-10-02-f3b1-cancelamento-inutilizacao-design.md` §1 (decomposição da F3b).
- Skill `br-fiscal-note-emission`: `assets/pdf-layouts/danfe` (renderer e templates testados) e `assets/pdf-layouts/README.md`; avaliar `nfephp-org/sped-da` contra eles.
- Dados disponíveis: `Nota::xml_autorizado` (nfeProc), `xml_cancelamento` (nfeProc com retEvento; o DANFE de nota cancelada deve sair com a marca "CANCELADA"), `NotaController::xml` como padrão de download.
- Padrões a seguir: `backend/app/Modules/Fiscal` (Actions, Resources) e `frontend/features/notas` (`DetalheDaNota`).
- Pendências conhecidas da F3a e da F3b1 (não bloqueiam a F3b2): em `TAREFAS.md`.

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

- [x] **F3a Núcleo da NF-e concluída (23 tarefas, 2026-10-02):** módulo `Fiscal` com `Nota`/`NotaItem`/`NotaPagamento`/`NotaEvento`, resolvers (CRT, CFOP, CSOSN, indIEDest), rateio de desconto, `IniciarEmissao`, `EmitirNotaJob` (fila `database`), `AplicarResultadoDaEmissao` (inclui cStat 539), reconciliação a cada 15 min, `NfePhpEmissor` (sped-nfe 5.2.8), contador `DOCUMENTOS_MES`, `/api/app/notas`, produção só após NF-e de teste autorizada; frontend `features/notas` (lista, formulário, detalhe com polling e XML) e `ConfirmarProducao`.
- [x] **F3b1 Cancelamento e inutilização da NF-e concluída (15 tarefas, 2026-10-02):** status `CANCELANDO`, `Justificativa` (15 a 255), `IniciarCancelamento`/`CancelarNotaJob`/`AplicarResultadoDoCancelamento`, reconciliação de `CANCELANDO`, `InutilizarNumeracao`, `consultarPorChave` com o ambiente da nota, cota que continua contando canceladas, endpoints `/notas/{nota}/cancelar`, `/xml-cancelamento` e `/inutilizacoes`; frontend com cancelar no detalhe da nota e `/notas/inutilizar`.

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
F3b2: escrever o plano de implementação a partir da spec aprovada (`docs/superpowers/specs/2026-10-02-f3b2-pdf-danfe-design.md`) e executar. Decisões já tomadas: motor `sped-da ^1.1` (v1.1.6, spike OK no PHP 8.2), interface `GeradorDeDanfe`, Action `GerarDanfe` (PDF sempre do XML: AUTORIZADA e CANCELANDO usam `xml_autorizado`; CANCELADA usa `xml_cancelamento` com `cancelada=true`), `GET /api/app/notas/{nota}/pdf` inline, link "Abrir DANFE (PDF)" no detalhe, sem logo e sem armazenar PDF. Antes ou em paralelo, o usuário pode fazer o push (39 commits locais) e rodar os roteiros de homologação real da F3a e da F3b1 (certificado A1). O teste `CadastroForm` estoura 5 s às vezes na suíte completa (passa isolado).
