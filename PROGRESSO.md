# Progresso do Projeto

## Última atualização
2026-10-05

## Tarefa em andamento
**Nenhuma execução em andamento. F3b1, F3b2, F3b3a, F3b3b, F3c1 e F3c2 (contingência offline da NFC-e, 9 tarefas, 2026-10-05) feitas; a F3 só tem a F3c3 (EPEC e SVC da NF-e) pela frente.** A F3b está dividida em F3b1 (feita), F3b2 (em andamento) e F3b3 (NFC-e).

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
- **Em aplicação** (plano `docs/superpowers/plans/2026-10-04-design-system.md`, spec `…/specs/2026-10-04-design-system-design.md`; ledger em `.superpowers/sdd/2026-10-04-design-system/progress.md`). **Fases 1 a 4 concluídas (tarefas 1 a 20)**: tokens + teste de contraste (40 pares), fontes Barlow/Condensed/JetBrains, tema escuro padrão, Button, campos (37 px, borda forte, foco, seta no select), Campo (obrigatório), Card, Pilula/StatusDaNota, Banner, BarraDeProgresso, StatCard, DataTable, toast, Sidebar/MenuDeNavegacao/MenuMobile (gaveta; BottomNav removida), Topbar, CabecalhoDePagina, AuthCard em dois painéis. Staging atualizado em 2026-10-05 para conferência visual. Fase 4: todas as caixas de erro viraram Banner, títulos de página em fonte de título, lista de notas em DataTable; listas com edição inline ficam como lista em Card (DataTable mudaria o comportamento). **Falta a Fase 5: conferência visual no navegador (o Chrome automatizado não conectou nesta sessão; é com o usuário) nos dois temas e em 360/768/1280 px, correções que ela achar e fechamento (tarefas 21 e 22).** Staging atualizado.
- Defeitos do original que NÃO se copiam: sem foco visível nos campos, cores semânticas do tema claro com 2,0 a 3,7:1, borda de campo 1,2:1, texto branco em pílulas sólidas (3,3 a 4,2:1), ícones em emoji.

## Ambiente de acompanhamento (staging) na VPS (2026-10-04)
- **Endereço:** front `http://192.168.0.233:3200`, API `http://192.168.0.233:8200` (rede local, HTTP). VPS Ubuntu 26.04 **compartilhada** com outros projetos (monitoramento, xadrez, readcoach, market_system): o CRM roda num Compose isolado (projeto `crm`, pasta `~/crm-plataforma`, usuário `lundy`), sem mexer nos outros. `root` não loga por senha via SSH.
- **Contas de demonstração (senha `Senha123`):** `admin@plataforma.local` (admin da plataforma) e `dono@empresa.local` (dono da empresa demonstração). Só dados fictícios; nenhum certificado A1 nem CNPJ real.
- **Verificado:** build, migrations de todas as fases no PostgreSQL 16 (primeira vez fora do SQLite), login com sessão pela API, CORS, URL da API embutida no front, worker e agendador de pé, API ~35 ms. **Não verificado:** as telas no navegador.
- **Reimplantar:** `git -c core.autocrlf=false archive --format=tar.gz -o crm.tar.gz HEAD`, enviar para `~/crm-plataforma/`, extrair em `src/` (NÃO apagar `src/deploy/.env`: tem a senha do banco) e rodar `bash src/deploy/deploy.sh`. Detalhes em `deploy/README.md`.
- **Diferenças para produção real:** HTTP sem domínio, `INSTALL_DEV=1` (Faker para o seeder), `SESSION_SECURE_COOKIE=false`. Antes de expor à internet: HTTPS, domínio, trocar a senha do usuário SSH e as contas de demonstração.

## Fechamento da F3c2 (2026-10-05)
- **Feito:** status `CONTINGENCIA` + colunas `contingencia_desde/limite_em/motivo` e `ultima_retransmissao_em` + `config/fiscal.php` (`FISCAL_CONTINGENCIA_PRAZO_HORAS` padrão 24, `FISCAL_CONTINGENCIA_ALERTA_HORAS` padrão 6); `FalhaDeComunicacaoException` (o `NfePhpEmissor` converte `SoapException` em `transmitir` e `consultarPorChave`); `MontadorDeXml` remonta a NFC-e com `tpEmis=9`, `dhCont` e `xJust` (a biblioteca recusa o modelo 65 em contingência), `EmissorDeNfe::prepararContingencia`; `EntrarEmContingencia` (só NFC-e e só falha de comunicação; consulta a chave original antes; consulta fora da transação); `RetransmitirNota` (consulta antes, reenvia o MESMO XML, lock de cache por nota; autorizada/rejeitada/mantém), comando `fiscal:retransmitir-contingencia` (hourly, `withoutOverlapping`, `onOneServer`) e `POST /notas/{id}/retransmitir`; cupom de duas vias (o `Danfce` já imprime "Via Consumidor" e "Via Estabelecimento" para `tpEmis=9` sem protocolo); `ResumoFiscal.contingencias` (quantidade, em alerta, vencidas, limite mais próximo); frontend (status, bloco no detalhe com prazo e "Retransmitir agora", filtro, cartão e avisos no painel).
- **Verificado:** backend 562 testes e frontend 271, Pint/PHPStan/tsc/lint/build limpos; XML offline assinado com CSC valida no XSD e tem QR Code; PDF real do cupom offline conferido por texto. **NÃO verificado:** a conversão de falha real de rede em `FalhaDeComunicacaoException`, a entrada e a retransmissão contra a SEFAZ de verdade (só com `EmissorFake` e XML local).
- **Decisões:** prazo configurável com padrão conservador de 24 h (valor legal por UF a confirmar); contingência só automática; NF-e continua em ERRO (EPEC é a F3c3); não conta na cota até autorizar.
- **Roteiro de homologação real (usuário, com A1 e CSC):** (1) bloquear a rede para a SEFAZ (ou apontar um proxy morto), emitir uma NFC-e e conferir a entrada em contingência e o cupom com duas vias; (2) restaurar a rede, rodar `php artisan fiscal:retransmitir-contingencia` (ou o botão) e conferir a autorização; (3) confirmar o prazo legal da sua UF e ajustar `FISCAL_CONTINGENCIA_PRAZO_HORAS`; (4) conferir o painel (cartão e avisos).
- Auto-revisão (sem subagente).

## Fechamento da F3c1 (2026-10-05)
- **Feito:** `ResumoFiscal` (Action) + `GET /api/app/dashboard/fiscal` (qualquer papel da empresa; exige `FISCAL_NFE` ou `FISCAL_NFCE`; só o ambiente atual; meses em São Paulo; 12 meses sempre preenchidos; valor = AUTORIZADA + CANCELANDO; rejeições = REJEITADA/ERRO, 5 recentes); `features/dashboard-fiscal` (tipos, helpers, hook, `PainelFiscal`, `GraficoDeFaturamento` com `recharts` e tabela `sr-only`); bloco "Fiscal" no painel, só com módulo fiscal no plano. Nova dependência: `recharts ^3.10.1` (as vulnerabilidades do `npm audit` são pré-existentes, em `braces`/`fast-glob`).
- **Verificado:** backend 525 testes e frontend 259, Pint/PHPStan/tsc/lint/build limpos. **Não verificado:** o desenho do gráfico e do painel no navegador (temas e 360 px) e com dados reais do staging.
- Auto-revisão (sem subagente). Decisão: rejeições e notas do mês seguem o ambiente atual do emitente.

## Fechamento da F3b3b (2026-10-05)
- **Feito:** `GeradorDeDanfe::gerar(xml, cancelada, modelo = NFE)`; `SpedDaGeradorDeDanfe` usa `Danfce` com `setPaperWidth(80)` e `setAsCanceled()` para NFC-e; `GerarDanfe` passa o modelo e nomeia `CUPOM-<n>.pdf`; mesmo endpoint de PDF; link "Abrir cupom (PDF)"; botões e títulos de emissão e cancelamento seguem o modelo; lista de inutilizações mostra o modelo. O cancelamento da NFC-e já funcionava desde a F3b3a (módulo e modelo 65 pela chave); o prazo segue sendo decisão da SEFAZ.
- **Verificado:** backend 511 testes, frontend 233, Pint/PHPStan/tsc/build limpos; o cupom real é gerado nos testes (texto: emitente, "Nota Fiscal de Consumidor", consulta pública, marca de homologação só em tpAmb 2, CANCELADA com a flag). **Não verificado:** o desenho do cupom; conferir no leitor de PDF com uma NFC-e de teste autorizada.
- Caminho curto (design em chat, sem spec/plano em arquivo) por ser extensão de fluxos existentes; sem revisão separada (auto-revisão).

## Fechamento da F3b3a (2026-10-05)
- **Feito:** migration (`notas.modelo`, `cliente_id` nulável, índice único com modelo, `inutilizacoes.modelo`); `CfopConsumidorResolver` (5102/5405; outra UF bloqueia com `FISCAL_NFCE_INTERESTADUAL`); `NotaParaEmissao` com `modelo` e `ConsumidorParaEmissao` (só documento e nome); Preparador/Verificador/IniciarEmissao (módulo `FISCAL_NFCE`, série por modelo, `FISCAL_CSC_AUSENTE` antes de alocar número, destinatário opcional); rascunho de NFC-e pela API (`modelo`, `cliente_id` opcional, filtro `?modelo=`); motor modelo 65 (`mod=65`, `tpImp=4`, CSC no `Tools`, QR Code ao assinar, `ModeloDaChave` deriva o modelo da chave em consulta e cancelamento); inutilização por modelo; cancelamento exige o módulo do modelo da nota; frontend (seletor de modelo só com `FISCAL_NFCE`, cliente opcional, lista/detalhe/inutilização por modelo; DANFE só para NF-e).
- **Verificado:** backend 502 testes e frontend 226, Pint, PHPStan, `tsc` e build limpos. XML 65 assinado com certificado e CSC de teste tem `infNFeSupl/qrCode` e valida no XSD; sem cliente não há `dest`; identificada leva só CPF e nome.
- **Decisões não óbvias:** uso denegado (cStat 110) cai em REJEITADA com cStat/motivo (spec previa ERRO; fica assim até a F3c); em homologação a biblioteca troca o `xNome` do destinatário pelo texto legal da SEFAZ; consulta/cancelamento NÃO mudaram o contrato (modelo vem da chave), só `inutilizar` ganhou parâmetro; a revisão final foi auto-revisão (sem subagente) e achou uma lacuna real (cancelamento exigia `FISCAL_NFE`), já corrigida com teste.
- **Roteiro de homologação real (usuário, precisa de A1 e CSC de teste da UF):** cadastrar CSC de homologação e série NFC-e; emitir uma NFC-e sem cliente e uma com CPF; conferir autorização, o QR Code no XML (`infNFeSupl`) e a consulta pública; tentar uma NFC-e para cliente de outra UF (deve bloquear).

## Fechamento da F3b2 (2026-10-05)
- **Feito:** `GeradorDeDanfe` (interface) + `SpedDaGeradorDeDanfe` (sped-da 1.1.x; falha vira `PdfIndisponivelException` 422 `PDF_INDISPONIVEL`), Action `GerarDanfe` (AUTORIZADA/CANCELANDO usam `xml_autorizado`; CANCELADA usa o `nfeProc` de `xml_cancelamento` com a marca, ou o autorizado se o fallback for cru; outros status ou XML ausente: 409 `NOTA_SEM_PDF`), `GET /api/app/notas/{nota}/pdf` (inline, `DANFE-<numero>.pdf`), link "Abrir DANFE (PDF)". PDF nunca é gravado.
- **Verificado:** backend 455 testes, frontend 218, Pint e PHPStan limpos; o PDF real é gerado nos testes e o texto extraído confere (DANFE, emitente, produto, homologação só em tpAmb 2, CANCELADA por evento e por flag com cStat 136).
- **Não verificado:** o desenho do PDF (só o texto foi conferido) e a revisão final foi **auto-revisão** (sem subagente nesta execução).
- **Roteiro de homologação real (usuário):** emitir uma NF-e de teste autorizada, abrir o DANFE e comparar chave, código de barras, itens e totais com o XML; cancelar e conferir a marca CANCELADA; olhar o layout contra o DANFE oficial.

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

## Contexto necessário (F3c3: EPEC e SVC da NF-e)
- Spec mestre §7 (EPEC na NF-e, prazo e alerta, reconciliação horária) e `docs/referencia-fiscal/03-nfephp-nfe.md` (seção "Contingência EPEC": bug do vendor 5.2.8 em `sefazEPEC` e o contorno com `Contingency` type EPEC, `tpEmis=4`, `signNFe` com timezone UTC por causa do `dhCont`, evento `EVT_EPEC` com `tagAdic`, cStat 135/136 ⇒ contingência, 7 dias, `retransmitir` consulta a chave e reenvia o mesmo XML).
- Reuso da F3c2: `FalhaDeComunicacaoException`, status `CONTINGENCIA` e colunas `contingencia_*`, `EntrarEmContingencia` (hoje só NFC-e), `RetransmitirNota`, comando horário, `ResumoFiscal.contingencias`, `config('fiscal.contingencia_*')` (o prazo do EPEC é de 7 dias: provavelmente precisa de configuração própria por tipo).
- A F3c3 ainda **não tem spec**: começar por brainstorming (arquitetural). Skill `br-fiscal-note-emission`: `pitfalls.md` (558, `dhCont`) e `reliability-patterns.md`.
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
F3c3: brainstorming para a spec do EPEC (e SVC) da NF-e. Antes ou em paralelo, o usuário pode: fazer o push (muitos commits locais), reimplantar o staging (desatualizado: tem o design system até a Fase 4, mas não DANFE, NFC-e, cupom, painel fiscal nem contingência), rodar os roteiros de homologação real (inclusive o da contingência) e conferir o design system no navegador (Fase 5, tarefas 21 e 22). O teste `CadastroForm` estoura 5 s às vezes na suíte completa do frontend, e `GeradorDeDanfeTest` (marca CANCELADA) falhou 1 vez em ~6 execuções completas do backend (passa isolado).
