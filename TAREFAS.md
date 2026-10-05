# Backlog por fase

Detalhes em `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`, §15. Cada fase tem uma spec e um plano próprios antes de ter código.

- [x] **F0 Fundação:** repositório, Docker, CI, Laravel + Next, monólito modular, autenticação, tenancy com isolamento testado, layout responsivo com temas dark e light.
- [x] **F1 Plataforma e planos:** admin do SaaS, planos (módulos e limites), onboarding de empresa, usuários e papéis, `EntitlementService`.
- [x] **F2 Cadastros e emitente** (28 de 28, fechada em 2026-10-01): clientes, produtos, serviços, categorias fiscais, pendências, CSV, dados fiscais, certificado, CSC, séries.
- [ ] **F3 NF-e e NFC-e** (NFePHP), dividida em três specs, cada uma com plano próprio:
  - [x] **F3a Núcleo da NF-e** (23 de 23, fechada em 2026-10-02): modelo da nota, resolvers tributários, XML, emissão pela fila, numeração, polling, reconciliação, consulta, XML, confirmação de produção. Plano: `docs/superpowers/plans/2026-10-02-f3a-nfe-emissao.md`. Falta só a emissão real em homologação (precisa de certificado A1), roteiro no `PROGRESSO.md`.
  - [ ] **F3b**, dividida em três specs (2026-10-02), cada uma com plano próprio, nesta ordem:
    - [x] **F3b1 Cancelamento e inutilização da NF-e** (15 de 15, fechada em 2026-10-02): cancelamento por fila com `CANCELANDO`, reconciliação, inutilização síncrona, API e telas. Plano: `docs/superpowers/plans/2026-10-02-f3b1-cancelamento-inutilizacao.md`. Falta só o cancelamento e a inutilização reais em homologação (certificado A1), roteiro no `PROGRESSO.md`.
    - [x] **F3b2 PDF (DANFE) da NF-e** (concluída em 2026-10-05): `GET /api/app/notas/{id}/pdf` inline, gerado do XML com `sped-da`, sem armazenar; link "Abrir DANFE (PDF)" no detalhe. Pendências: logo do emitente, DANFE da NFC-e (F3b3), e-mail com PDF e XML, ZIP em lote, cache se o volume pedir, DANFE simplificado, conferência visual do PDF contra o DANFE oficial (depende do usuário, ver PROGRESSO).
    - [x] **F3b3a Emissão da NFC-e** (concluída em 2026-10-05): `notas.modelo`, destinatário opcional, CSC, QR Code, série por modelo, inutilização por modelo, seletor na tela. Spec `docs/superpowers/specs/2026-10-05-f3b3a-nfce-emissao-design.md`.
    - [x] **F3b3b Cupom 80 mm (sped-da DANFCE) e cancelamento da NFC-e** (concluída em 2026-10-05, caminho curto sem spec em arquivo): `GeradorDeDanfe::gerar(xml, cancelada, modelo)`, cupom `CUPOM-<n>.pdf` pelo mesmo `GET /notas/{id}/pdf`, link "Abrir cupom (PDF)", rótulos de cancelamento/emissão por modelo, modelo na lista de inutilizações. Pendências: conferência visual do cupom (usuário), contingência offline (F3c).
  - [ ] **F3c** (sem spec nem plano): contingência (EPEC e offline), retransmissão, alertas de prazo, dashboard fiscal.
  - Critério de pronto da F3: nota autorizada em homologação para NF-e e NFC-e, com o XML validado contra o XSD.
- [ ] **F4 NFS-e Nacional** (`nfse-php`): DANFSe, cancelamento, venda mista.
- [ ] **F5 Cobrança** (Mercado Pago): checkout transparente, assinatura, webhooks, inadimplência, excedente.
- [ ] **F6 API pública:** `/api/v1`, chaves live/test, idempotência, webhooks de saída, OpenAPI e guia.
- [ ] **F7 CRM:** funis, negócios, atividades, timeline, propostas → nota, relatórios, metas.
- [ ] **F8 Go-live:** auditoria fiscal, carga, backup e restauração, piloto em produção.

## Pendências herdadas da revisão da F0 (resolver no início da F1)
- [x] Tirar `tenant_id` e `papel` de `Usuario::$fillable` (escalada de tenant ou privilégio por mass assignment).
- [x] Pôr `DefinirTenantDoUsuario` na prioridade de middleware, antes do `SubstituteBindings` (route model binding de Models de tenant).
- [x] Revalidar `ativo` do usuário a cada requisição (usuário desativado hoje mantém a sessão).
- [x] Frontend: `MutationCache.onError` para 401, e revalidar `/me` com o app aberto.
- [x] Acessibilidade: `aria-invalid` e `aria-describedby` em todos os campos. Form de redefinição deve mostrar erro de e-mail/token vindo do link.
- [x] Testes de token de redefinição expirado e reutilizado. Job de CI com PostgreSQL.
- [x] `.env.example`: `QUEUE_CONNECTION=sync` no local. Documentar `SESSION_DOMAIN`/`SESSION_SECURE_COOKIE` de produção. Origem não stateful devolve 500.
- [x] Avaliar limite adicional por IP no login (spray de e-mails).

## Pendências da revisão da F1

Achados menores adiados e uma decisão de produto parcada durante a revisão das 18 tarefas da F1 (ledger em `.superpowers/sdd/2026-09-27-f1-plataforma-e-planos/progress.md`). Nenhum bloqueia a F1; ficam como candidatos a limpeza ou decisão futura.

**Decisão de produto parcada:**
- [x] Papel PROPRIETARIO irreversível: decidido manter (2026-10-01).

**Cosméticos e dívidas técnicas menores:**
- [x] `backend/bootstrap/app.php` sem `declare(strict_types=1)` (pré-existente).
- [ ] `Cnpj`: arrays `PESOS_DV1`/`PESOS_DV2` quase idênticos.
- [ ] Sem teste HTTP específico de `CnpjInvalidoException` (422 `CNPJ_INVALIDO`).
- [x] Migrations publicadas do pacote `activitylog` sem `declare(strict_types=1)`.
- [ ] `ExpirarTestesCommand` conta `$total` por referência (cosmético).
- [x] Sem teste cross-tenant para `ContadorDeUsuariosAtivos`.
- [ ] `limite()` e `consumo()` do `EntitlementService` com expressões quase idênticas.
- [ ] `AssinaturaController`: `firstOrFail` com tenant órfão devolve 404 com mensagem em inglês.
- [ ] Bloco `/** @var Usuario $autor */` repetido nos controllers de admin.
- [ ] Busca de empresas sem dígitos ainda adiciona `OR cnpj LIKE` (inofensivo; CNPJ alfanumérico pode casar letras).
- [ ] `CadastrarEmpresa`: `firstOrFail` em corrida de plano desativado devolve 404 genérico em inglês.
- [ ] BrasilAPI: resposta 200 com corpo vazio é cacheada como "encontrado".
- [ ] `GET /api/publico/planos` sem throttle.
- [x] Reativar usuário já ativo não é barrado (422 enganoso ou auditoria espúria).
- [ ] `unique` de e-mail fora do lock: corrida de convites para o mesmo e-mail pode dar 500.
- [ ] Lacunas de teste em convites: POST de PROPRIETARIO por ADMIN, reuso de token de convite, token do broker `users` no aceitar-convite, papéis FISCAL/LEITURA.
- [ ] Workaround `forgetGuards`/`flushSession` repetido nos testes — criar helper no `TestCase`.
- [ ] Listagem de usuários da empresa sem paginação (plano ilimitado).
- [x] `EditarUsuario`/`DesativarUsuario` gravam e auditam fora de transação.
- [ ] Commit de correção `83bf928` com mensagem em inglês.
- [ ] `Campo` sem teste do caso sem dica e sem erro.
- [ ] Faixas de cor do `ConsumoDoPlano` (80%/100%) e ramos INADIMPLENTE/CANCELADA da `FaixaSituacao` sem teste.
- [ ] Warning de lint `react-hooks/incompatible-library` por `form.watch` no `CadastroForm` (trocar por `useWatch`).
- [ ] Checkbox de aceite de termos fora do componente `Campo`.
- [ ] `ListaDePlanosAdmin` sem estado vazio.
- [ ] `TrocarPlanoForm` sem tratamento de erro ao carregar planos.
- [ ] `EditarUsuarioForm` sem teste.
- [ ] Mensagem de convite inválido duplicada entre frontend e backend, sem teste de contrato entre os dois.

## Pendências de infraestrutura e fechamento (2026-10-02)
- [x] Publicar no remoto: feito em 2026-10-02 (`origin/master` em `db2bc7d`). Falta enviar os commits da F3a e conferir o CI (job em PostgreSQL 16) na primeira execução.
- [ ] Conferência visual no navegador da F2 (passo 12 do fechamento, não feita): wizard `/onboarding`, `/configuracoes/fiscal`, produtos, serviços, pendências, clientes, CSV.
- [ ] Teste `CadastroForm` estoura 5 s às vezes na suíte completa (passa isolado): aumentar timeout ou aliviar o teste.
- [x] Plano da F3a revisado e executado inline (2026-10-02).
- [ ] Fora do código, ainda sem data: certificado A1 e CNPJ de homologação para a fumaça da F3a (Tarefa 23) e para a F8.

## Verificado em 2026-10-02 (pendências da F1 que seguem abertas)
- `CadastroForm` ainda usa `form.watch` (trocar por `useWatch`).
- O workaround `forgetGuards`/`flushSession` segue repetido nos testes, sem helper no `TestCase`.
- Os demais itens abertos da seção da F1 não foram reverificados nesta análise.

## Pendências da F3a (2026-10-02)
- [ ] **Emissão real em homologação** (roteiro no `PROGRESSO.md`): a comunicação com a SEFAZ nunca foi exercitada; a fumaça com `.pfx` autoassinado parou no OID do CNPJ do certificado.
- [ ] Conferência visual de `/notas`, `/notas/nova`, `/notas/{id}` e `/configuracoes/fiscal` (temas e celular), não feita.
- [ ] Regime Normal (CST, PIS/COFINS reais), DIFAL, IBS/CBS (PL_010+) e outras naturezas de operação (devolução, remessa, bonificação).
- [ ] IE `ISENTO` do emitente (hoje bloqueia como IE ausente).
- [ ] cStat 150 (autorizado fora de prazo) não é tratado como autorizada pelo `InterpretadorDeResposta`.
- [ ] Contador de numeração único entre homologação e produção (decisão do spec; revisar antes do go-live na F8).
- [ ] Listagem de notas sem paginação no frontend (a API pagina de 25 em 25; a tela mostra só a primeira página).
- [ ] `NfePhpEmissor` sem teste automatizado (precisa de SEFAZ).

## Pendências da F3b1 (2026-10-02)
- [ ] **Cancelamento e inutilização reais em homologação** (roteiro no `PROGRESSO.md`): a comunicação com a SEFAZ nunca foi exercitada; a fumaça com `.pfx` autoassinado chegou a montar o evento, mas a lib decide CNPJ/CPF pelo OID ICP-Brasil do certificado.
- [ ] Cancelamento por substituição, cancelamento extemporâneo e carta de correção (fora de escopo por decisão).
- [ ] O corpo inválido enviado por LEITURA ou VENDEDOR recebe 422 antes do 403 (o FormRequest valida antes da política dentro da Action).
- [ ] Lista de inutilizações sem filtros e sem paginação no frontend (a API pagina de 25 em 25).
- [ ] `NfePhpEmissor::cancelar` e `inutilizar` sem teste automatizado (precisam de SEFAZ).
- [ ] Conferência visual de `/notas/{id}` (cancelar) e `/notas/inutilizar` (temas e celular), não feita.
