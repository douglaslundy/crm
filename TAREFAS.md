# Backlog por fase

Detalhes em `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`, §15. Cada fase tem uma spec e um plano próprios antes de ter código.

- [x] **F0 Fundação:** repositório, Docker, CI, Laravel + Next, monólito modular, autenticação, tenancy com isolamento testado, layout responsivo com temas dark e light.
- [x] **F1 Plataforma e planos:** admin do SaaS, planos (módulos e limites), onboarding de empresa, usuários e papéis, `EntitlementService`.
- [x] **F2 Cadastros e emitente** (28 de 28, fechada em 2026-10-01): clientes, produtos, serviços, categorias fiscais, pendências, CSV, dados fiscais, certificado, CSC, séries.
- [x] **F3 NF-e e NFC-e** (NFePHP, concluída em 2026-10-06), dividida em três specs, cada uma com plano próprio:
  - [x] **F3a Núcleo da NF-e** (23 de 23, fechada em 2026-10-02): modelo da nota, resolvers tributários, XML, emissão pela fila, numeração, polling, reconciliação, consulta, XML, confirmação de produção. Plano: `docs/superpowers/plans/2026-10-02-f3a-nfe-emissao.md`. Falta só a emissão real em homologação (precisa de certificado A1), roteiro no `PROGRESSO.md`.
  - [x] **F3b**, dividida em três specs (2026-10-02), cada uma com plano próprio, nesta ordem:
    - [x] **F3b1 Cancelamento e inutilização da NF-e** (15 de 15, fechada em 2026-10-02): cancelamento por fila com `CANCELANDO`, reconciliação, inutilização síncrona, API e telas. Plano: `docs/superpowers/plans/2026-10-02-f3b1-cancelamento-inutilizacao.md`. Falta só o cancelamento e a inutilização reais em homologação (certificado A1), roteiro no `PROGRESSO.md`.
    - [x] **F3b2 PDF (DANFE) da NF-e** (concluída em 2026-10-05): `GET /api/app/notas/{id}/pdf` inline, gerado do XML com `sped-da`, sem armazenar; link "Abrir DANFE (PDF)" no detalhe. Pendências: logo do emitente, DANFE da NFC-e (F3b3), e-mail com PDF e XML, ZIP em lote, cache se o volume pedir, DANFE simplificado, conferência visual do PDF contra o DANFE oficial (depende do usuário, ver PROGRESSO).
    - [x] **F3b3a Emissão da NFC-e** (concluída em 2026-10-05): `notas.modelo`, destinatário opcional, CSC, QR Code, série por modelo, inutilização por modelo, seletor na tela. Spec `docs/superpowers/specs/2026-10-05-f3b3a-nfce-emissao-design.md`.
    - [x] **F3b3b Cupom 80 mm (sped-da DANFCE) e cancelamento da NFC-e** (concluída em 2026-10-05, caminho curto sem spec em arquivo): `GeradorDeDanfe::gerar(xml, cancelada, modelo)`, cupom `CUPOM-<n>.pdf` pelo mesmo `GET /notas/{id}/pdf`, link "Abrir cupom (PDF)", rótulos de cancelamento/emissão por modelo, modelo na lista de inutilizações. Pendências: conferência visual do cupom (usuário), contingência offline (F3c).
    - [x] **F3c1 Dashboard fiscal** (concluída em 2026-10-05): `GET /api/app/dashboard/fiscal` (Action `ResumoFiscal`), bloco "Fiscal" no painel com cartões, gráfico `recharts` e tabela acessível. Spec `docs/superpowers/specs/2026-10-05-f3c1-dashboard-fiscal-design.md`. Pendências: contingências com prazo no painel (F3c2), índice em `notas(tenant_id, emitido_em)` se o volume pedir, filtros de período, cache do resumo.
    - [x] **F3c2 Contingência offline da NFC-e, retransmissão e prazo** (concluída em 2026-10-05): status `CONTINGENCIA`, `tpEmis=9` remontado pelo `MontadorDeXml`, entrada só em `FalhaDeComunicacaoException` (com consulta prévia), `RetransmitirNota` (comando horário + botão), cupom de duas vias, prazo configurável (padrão 24 h) e alerta no painel. Spec `docs/superpowers/specs/2026-10-05-f3c2-contingencia-nfce-design.md`. Pendências: prazo legal por UF a confirmar, prazo por empresa, fuso por UF, notificações (F6), rejeição após contingência pode exigir inutilização, a conversão `SoapException` → `FalhaDeComunicacaoException` só é exercitada contra a SEFAZ real.
    - [x] **F3c3 EPEC da NF-e** (10 de 10, concluída em 2026-10-06; SVC ficou no backlog por decisão do usuário): a NF-e com `FalhaDeComunicacaoException` consulta a chave, remonta com `tpEmis=4`, registra o evento EPEC (110140) no Ambiente Nacional e fica em `CONTINGENCIA` (`contingencia_tipo=EPEC`, prazo 168 h), com retransmissão, DANFE com o registro EPEC e alerta de 48 h no painel. Spec `docs/superpowers/specs/2026-10-06-f3c3-epec-nfe-design.md`; plano `docs/superpowers/plans/2026-10-06-f3c3-epec-nfe.md`.
  - [x] **F3c** (concluída em 2026-10-06; SVC no backlog): contingência (EPEC e offline), retransmissão, alertas de prazo, dashboard fiscal.
  - Critério de pronto da F3: nota autorizada em homologação para NF-e e NFC-e, com o XML validado contra o XSD.
- [ ] **F4 NFS-e Nacional** (`nfse-php`), cortada em duas fatias (2026-10-07), cada uma com spec e plano próprios:
  - [x] **F4a NFS-e** (13 de 13, concluída em 2026-10-07): a NFS-e é o modelo `DPS` da tabela `notas`; emitir pela fila com consulta da DPS antes de enviar (incerteza nunca reenvia), reconciliação, consulta, cancelamento por evento 101101, DANFSe v2.0, formulário de serviços e módulo `FISCAL_NFSE`. Vários itens só se tiverem o mesmo código e a mesma alíquota. Spec `docs/superpowers/specs/2026-10-07-f4a-nfse-nacional-design.md`; plano `docs/superpowers/plans/2026-10-07-f4a-nfse-nacional.md`.
  - [x] **F4b Venda mista** (10 de 10, concluída em 2026-10-07): venda de produtos e serviços gera uma NF-e e uma NFS-e independentes ligadas por `notas.venda_id`, com o desconto geral rateado em proporção aos subtotais; as duas notas são conferidas sem emitir (`IniciarEmissao::verificar`) e, com uma pendente, só se emite a válida com confirmação; API `/api/app/vendas` e telas `/vendas/nova`, `/vendas/[id]`, `/vendas/[id]/editar`. Spec `docs/superpowers/specs/2026-10-07-f4b-venda-mista-design.md`; plano `docs/superpowers/plans/2026-10-07-f4b-venda-mista.md`.
- [x] **F5 Cobrança** (Mercado Pago), cortada em duas fatias (2026-10-07), cada uma com spec e plano próprios:
  - [x] **F5a Núcleo** (11 de 11, concluída em 2026-10-07): módulo `Billing` (assinaturas, cobranças, webhooks recebidos), `GatewayDeCobranca` com falso nos testes e `MercadoPagoGateway` (nunca exercitado), checkout de cartão (`preapproval`) e boleto (agendado por comando), webhook com HMAC e conciliação idempotente, inadimplência (1 dia) e suspensão (10 dias), regularização por boleto, API `/api/app/assinatura/*` e telas `/assinatura/planos` e `/assinatura/faturas`. Spec `docs/superpowers/specs/2026-10-07-f5a-cobranca-design.md`; plano `docs/superpowers/plans/2026-10-07-f5a-cobranca.md`; guia e roteiro de sandbox em `docs/cobranca.md`.
  - [x] **F5b1 Pagamento avulso e excedente** (9 de 9, concluída em 2026-10-08): gateway com cartão avulso e PIX, documento excedente fechado no dia 1 em uma cobrança `EXCEDENTE` paga por PIX ou cartão, mesma inadimplência da mensalidade.
  - [x] **F5b2 Troca de plano** (8 de 8, concluída em 2026-10-08): upgrade pago por cartão ou PIX (o plano só muda ao pagar; proporcional pelos dias restantes) e downgrade agendado para o fim do período, bloqueado se o uso passar dos limites do novo plano.
- [ ] **F6 API pública:** `/api/v1`, chaves live/test, idempotência, webhooks de saída, OpenAPI e guia.
  - [x] **F6a Fundação** (9 de 9, concluída em 2026-10-08): chaves, autenticação `api-v1`, limite por chave, erros RFC 9457, paginação por cursor, clientes/produtos/serviços/empresa/consumo e tela de chaves.
  - [x] **F6b Notas pela API** (7 de 7, concluída em 2026-10-08): emissão com `Idempotency-Key` e itens inline, consultas, pdf/xml, cancelar e inutilizar; chave `test_`⇔homologação.
  - [x] **F6c1 Webhooks de saída** (9 de 9, concluída em 2026-10-08): eventos de nota assinados, retentativas, log, reenvio, teste, API v1 e tela.
  - [ ] **F6c2 OpenAPI 3.1 e guia do integrador.**
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

## Pendências da F4a (2026-10-07)
- [ ] **Segurança (go-live, F8):** a `nfse-php` desliga a verificação TLS nos clientes HTTP (`CURLOPT_SSL_VERIFYPEER=0` e `VERIFYHOST=0`), sem opção de configurar. Avaliar fork/patch, proxy com pinning ou aceitar o risco documentado.
- [ ] **Emissão real da NFS-e em homologação** (roteiro no `PROGRESSO.md`): a comunicação com a ADN, a conversão de falha de rede em ERRO e o formato da resposta 200 de nota cancelada nunca foram exercitados. `NfseNacionalEmissor` não tem teste automatizado.
- [ ] Município não aderido ao padrão nacional: hoje a recusa da ADN vira mensagem; `consultarParametrosConvenio(codigoIbge)` da lib pode checar a adesão antes de emitir.
- [ ] Alíquota do ISS é exigida no cadastro do serviço mas não é enviada no Simples (E0625): revisar quando houver Regime Normal.
- [ ] `cNBS` obrigatório no XSD da lib, mas a ADN aceitou notas sem ele em 09/2026: confirmar na homologação.
- [ ] Substituição de NFS-e (cStat 101): a `nfse-php` 1.23 não tem mais o caso no enum `CodigoStatus`; uma resposta 101 viraria ERRO.
- [ ] ISS retido, Regime Normal, município legado (provider REST), e-mail ao tomador (F6/F7).
- [ ] Conferência visual do formulário de serviços, do detalhe da NFS-e e do DANFSe (temas e celular), não feita; DANFSe com Helvetica no lugar da Microsoft Sans Serif (diferença conhecida do clone).
- [ ] Cota `DOCUMENTOS_MES` conta só produção (regra da F3): a NFS-e de homologação não conta, como a NF-e.
- [ ] **DPS adotada divergente (revisão final):** se a ADN já tem uma NFS-e com o mesmo nº de DPS e o mesmo tomador mas outro valor, a nota vira ERRO preservando o número e pede conferência no portal. Falta oferecer, na tela, "adotar a nota da ADN" (regravando itens e total a partir do XML) ou "cancelar a anterior".
- [ ] Menores da revisão final da F4a: DPS achada já cancelada grava CANCELADA sem `numero_nfse` e sem XML (o DANFSe falha); a lista de notas mostra o nDPS e o detalhe mostra o número oficial, e falta filtro por modelo na lista; `PUT` sem `modelo` num rascunho DPS valida como NF-e; CPF/CNPJ do tomador sem validação de 11/14 dígitos; comentário "PFX só em memória" inexato (a lib grava um temporário e o apaga no destrutor: avaliar junto do TLS); QR do DANFSe extrai a chave por regex; tenant só com `FISCAL_NFSE` abre "Nova nota" em produtos; `NfseNacionalEmissor` sem teste com a lib simulada.

## Pendências da F5a (2026-10-07)
- [ ] **Sandbox do Mercado Pago** (roteiro em `docs/cobranca.md`): o `MercadoPagoGateway` nunca falou com o Mercado Pago de verdade; a conferir: campos do boleto (`external_resource_url`, `digitable_line`), `metadata.preapproval_id` e `external_reference` nos pagamentos recorrentes do cartão, se o `preapproval` aceita boleto, o manifesto e o `ts` do HMAC, e se a renovação do cartão chega só como `subscription_authorized_payment` (hoje só `type=payment` é tratado).
- [ ] **F5b2:** troca de plano. Também fora: estorno, NFS-e da própria plataforma para o assinante, e-mails de aviso de cobrança e de inadimplência (não há módulo de e-mail), trocar o cartão de quem teve a renovação recusada (hoje só se regulariza por boleto).
- [ ] **Cartão: pagamento a caminho ao trocar a assinatura.** Quem refaz o checkout com cartão tem a assinatura pendente anterior cancelada no gateway na hora; se o primeiro pagamento dela estava sendo aprovado naquele instante, ele cai numa assinatura cancelada.
- [ ] Menores da revisão final da F5a: (a) duas aprovações do mesmo pagamento em cobranças já pagas (pagamento em duplicidade) não geram alerta, só retornam; (b) a cobrança nova do ciclo seguinte não confere de novo se já existe uma com o mesmo id de pagamento depois do bloqueio (eventos `created` e `updated` simultâneos dão 503 e se resolvem no reenvio); (c) o gerador de boletos continua emitindo para tenant cancelado ou suspenso pela plataforma (só olha a assinatura); (d) `GET /api/app/assinatura` mostra método, plano e `paga_ate` para todos os papéis, embora a cobrança seja só de proprietário e admin; (e) boletos pendentes cancelados localmente continuam pagáveis no Mercado Pago (falta `PUT /v1/payments/{id}` com `cancelled`); (f) `AreaDaEmpresa` usa `startsWith('/assinatura')` (casaria `/assinaturas-x`); (g) a tolerância de 5 dias para compensação de boleto segura uma nova assinatura de quem nunca pagou.
- [ ] `GeradorDeDanfeTest::test_cancelada_imprime_a_marca...` falhou uma vez numa rodada completa da suíte e passou em seguida e isolado (extração de texto do PDF): acompanhar se repete.
- [ ] Conferência visual das telas de planos, checkout e faturas (temas e celular), e do Card Payment Brick com credenciais reais, não feitas.
## Pendências da F6c1 (2026-10-08)
- [ ] Rodar webhooks no PostgreSQL (staging/CI) e fazer um envio real de ponta a ponta (curl com `CURLOPT_RESOLVE`, TLS) para um destino externo.
- [ ] Menores da revisão final: (M1) deadlock no observer dentro de transação aninhada deixa a transação da nota abortada no PostgreSQL (fazer `ROLLBACK TO SAVEPOINT` explícito); (M3) faixas IPv6 `64:ff9b:1::/48` e `::ffff:0:0/96` fora do bloqueio; (M4) qualquer porta é aceita (permite varredura de portas pelo erro da tentativa); (M5) rotas do app listar/obter/excluir/testar/reenviar não checam o módulo API; (M8) entregas pendentes de endpoint desativado viram FALHOU uma a uma e não há reenvio em lote; DNS sem timeout próprio no `ResolvedorDeDnsDoSistema`; entrega esgotada por worker morto não conta em `falhas_seguidas`; `LimparWebhooksCommand` apaga em um único DELETE.
- [ ] Fora do escopo: rotação de segredo, webhooks de outros recursos, ordenação garantida, lista de IPs de saída, `ETag`.

## Pendências da F6b (2026-10-08)
- [ ] Rodar as rotas de notas e a idempotência no PostgreSQL (staging/CI); **nenhuma emissão real** foi feita pela API (homologação com certificado do usuário).
- [ ] Menores da revisão final: replay não reenvia os cabeçalhos originais além do `Content-Type`; corrida na criação inline do mesmo SKU/código/documento por duas emissões paralelas com chaves diferentes (o retry resolve; refazer o lookup depois do lock do tenant); respostas 4xx transitórias (limite do plano, conta sem escrita) ficam gravadas por 24 h e exigem chave nova.
- [ ] Fora do escopo: venda mista, retransmitir contingência e EPEC pela API; reemitir nota rejeitada pela API (hoje se cria outra nota); `ETag` no polling; XML dentro do JSON; `servicos.codigo` sem preenchimento dos serviços existentes; `uuid, exists` sem `bail` em cerca de 10 regras do app (500 no PostgreSQL com id mal formado).

## Pendências da F6a (2026-10-08)
- [ ] Rodar as rotas `/api/v1` e as chaves no PostgreSQL (staging/CI): a revisão apontou diferenças com SQLite (uuid, timestamps) já tratadas, mas não exercitadas.
- [ ] Escopos por chave e papel próprio; allowlist de IP; rotação; página pública de cada `type` de erro; limite agregado por empresa; throttle leve por IP para requisições sem chave válida; importação CSV pela API.
- [ ] Menores da revisão final: 503 de manutenção vira 500 `ERRO_INTERNO` (perde `Retry-After`); verificação de papel PROPRIETARIO/ADMIN duplicada entre `AutenticarChaveDeApi` e `PoliticaDeChavesDeApi`; mudança de contato não atualiza o `updated_at` do cliente (`atualizado_desde` não a vê); `q` não normaliza CPF/CNPJ formatado; chave `test_` ainda grava os mesmos cadastros da `live_`.
- [ ] Conferência visual da tela de chaves (temas e celular) não feita.

## Pendências da F5b2 (2026-10-08)
- [ ] **Sandbox do Mercado Pago** (roteiro em `docs/cobranca.md`): `PUT /preapproval` com o novo valor vale já na próxima cobrança?
- [ ] Troca de ciclo (mensal/anual), crédito ou estorno pela diferença e e-mail de aviso: fora do escopo.
- [ ] Menores da revisão final: o gateway é chamado dentro da transação em `TrocarDePlano` e `CancelarDowngrade` (segura o lock do tenant durante a chamada HTTP; falha depois da chamada deixa o Mercado Pago à frente do banco, o que a renovação tolera); falha de `atualizarAssinatura` depois do upgrade pago só vai para o log (sem fila de reprocessamento); ordem dos locks difere (`TrocarDePlano` trava tenant e depois assinatura; `ConciliarPagamento` e `AplicarDowngrade`, o inverso); `ValorDoUpgrade` usa `subMonthsNoOverflow` a partir de `paga_ate` (pode diferir em centavos do início real do período em fins de mês); `GerarBoletosDoCiclo` ainda usa o preço do plano e não `valor_ciclo_centavos` quando não há downgrade; downgrade é agendado num clique sem confirmação; o upgrade pendente não reaparece em "Trocar de plano" ao recarregar (só em Faturas); cada clique em outro plano gera uma fatura cancelada na lista; o rótulo da fatura é "Troca de plano: diferença de R$ x" (a spec dizia "Troca para <plano>").
- [ ] Conferência visual da troca de plano (temas e celular) não feita.

## Pendências da F5b1 (2026-10-08)
- [ ] **Sandbox do Mercado Pago para PIX e cartão avulso** (roteiro em `docs/cobranca.md`): campos `point_of_interaction.transaction_data.*`, expiração, formato da imagem do QR, webhook do PIX, status `in_process` do cartão.
- [ ] Cartão que fica `in_process` e depois é recusado: a cobrança fica com `metodo = CARTAO` e só volta a aceitar PIX depois de 30 minutos (usa `updated_at`); falta tratar o pagamento recusado vindo do webhook.
- [ ] Sem cancelamento do PIX no gateway: trocar de PIX para cartão exige esperar a expiração; pagamento em duplicidade só gera aviso em log (estorno fora do escopo).
- [ ] Excedente de empresa sem assinatura ativa fica só no log; e-mail de aviso do excedente fechado não existe (não há módulo de e-mail).
- [ ] Menores da revisão final: o gateway é chamado dentro da transação com a cobrança travada (até 20 s); PIX a segundos de expirar ainda é reaproveitado (sugestão: margem de 2 min); o texto "Código copiado" sem `aria-live`, botão Pagar sem `aria-expanded` e falha do `clipboard` sem tratamento; `ResumoFiscal` usa `whereBetween` sem conversão de fuso (mesmo problema do contador de documentos antes da correção).
- [ ] Conferência visual do painel de pagamento (temas e celular) não feita.

## Pendências da F4b (2026-10-07)
- [ ] Cancelar a venda inteira de uma vez (hoje cada nota se cancela na sua tela), NFC-e na venda mista (a NFS-e exige tomador identificado) e várias notas do mesmo tipo por venda: fora do escopo por decisão.
- [ ] **Nota da venda rejeitada com a outra já autorizada (revisão final):** a venda só se edita enquanto nenhuma nota saiu; se uma sai e a outra é rejeitada por dado da própria nota (quantidade, preço, pagamento), ela não se corrige pela venda nem sozinha. O salvamento já bloqueia pagamento divergente, serviços de códigos diferentes e rateio que zera uma nota, o que cobre os casos comuns; falta permitir corrigir a nota que falhou mantendo o desconto já rateado.
- [ ] Menores da revisão final: (a) a cota `DOCUMENTOS_MES` é conferida por nota; com 1 documento sobrando e fila rápida, a segunda nota pode estourar depois da primeira ter saído (conferir uma vez com a quantidade de notas a emitir); (b) exceção inesperada na conferência (produto ou cliente apagado) vira 404 da venda inteira em vez de bloqueio da nota; (c) venda apagada no meio da emissão gera `FISCAL_VENDA_FALHA_AO_EMITIR` e é reportada como inesperada; (d) a prévia do rateio na tela usa ponto flutuante (diverge do servidor só acima de uns R$ 1 milhão por lado); (e) o botão diz "Emitir as duas" mesmo quando só uma ainda pode sair; (f) `VendaForm` redefine `DINHEIRO`/`QUANTIDADE` e não reaproveita os componentes de item do `NotaForm`.
- [ ] Conferência visual das telas da venda mista (temas e celular) e homologação real da venda (depende da homologação das duas notas, F3a e F4a), não feitas.

## Pendências da F3c3 (2026-10-06)
- [ ] **EPEC real em homologação** (roteiro no `PROGRESSO.md`): a comunicação com o Ambiente Nacional e a conversão de falha real de rede em `FalhaDeComunicacaoException` nunca foram exercitadas; o evento `envEvento` do EPEC não é validado contra XSD nos testes.
- [ ] **EPEC registrado e não gravado (revisão final):** se a transação final falhar (banco fora, timeout) depois do registro no Ambiente Nacional, a chave, o XML e o protocolo do EPEC se perdem. Solução: persistir a chave/XML do EPEC antes do envio em colunas próprias, sem trocar a chave original.
- [ ] **cStat 539 após o EPEC** (original autorizada com resposta perdida): hoje vira REJEITADA. Guardar `chave_original` e conciliar por 539 na retransmissão (existe também na NFC-e offline).
- [ ] Menores da revisão: `nProt` vazio na resposta do EPEC; bloqueio por dado ausente com prefixo "Falha técnica" no job; `xml_epec` só com o `retEnvEvento` (falta o `procEventoNFe`); `idEstrangeiro` vazio bloqueia o EPEC.
- [ ] **SVC-AN/SVC-RS** (`tpEmis` 6 e 7): fora do escopo por decisão do usuário.
- [ ] Cancelamento e inutilização de nota em contingência; fuso por UF no `dhEmi`/`dhCont`.
- [ ] Rejeição após EPEC (dados diferentes do evento): conferir os cStat no `assets/cstat-table.md` da skill na homologação.
- [ ] Conferência visual do bloco de EPEC no detalhe da nota e do cartão "Notas em contingência" (temas e celular), não feita.
- [ ] `NfePhpEmissor::prepararEpec` e `registrarEpec` sem teste automatizado (precisam de certificado e SEFAZ); o XML `tpEmis=4` assinado e o corpo do evento são cobertos offline.
## Pendências da F3b1 (2026-10-02)
- [ ] **Cancelamento e inutilização reais em homologação** (roteiro no `PROGRESSO.md`): a comunicação com a SEFAZ nunca foi exercitada; a fumaça com `.pfx` autoassinado chegou a montar o evento, mas a lib decide CNPJ/CPF pelo OID ICP-Brasil do certificado.
- [ ] Cancelamento por substituição, cancelamento extemporâneo e carta de correção (fora de escopo por decisão).
- [ ] O corpo inválido enviado por LEITURA ou VENDEDOR recebe 422 antes do 403 (o FormRequest valida antes da política dentro da Action).
- [ ] Lista de inutilizações sem filtros e sem paginação no frontend (a API pagina de 25 em 25).
- [ ] `NfePhpEmissor::cancelar` e `inutilizar` sem teste automatizado (precisam de SEFAZ).
- [ ] Conferência visual de `/notas/{id}` (cancelar) e `/notas/inutilizar` (temas e celular), não feita.
