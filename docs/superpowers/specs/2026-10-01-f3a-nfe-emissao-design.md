# F3a: emissão de NF-e (modelo 55) com NFePHP

Data: 2026-10-01. Fase do projeto mestre: `2026-09-25-plataforma-fiscal-crm-design.md` (§7 e linha F3 do §15).

## 1. Contexto e decomposição

A F3 do spec mestre foi dividida em três specs, cada um com plano próprio:

| Fase | Conteúdo |
|---|---|
| **F3a (este)** | Núcleo da NF-e: modelo de dados da nota, resolvers, XML, emissão pela fila, numeração, polling, reconciliação, consulta, download do XML, confirmação de produção. |
| F3b | NFC-e (CSC, QR Code), cancelamento, inutilização, PDF (DANFE e cupom). |
| F3c | Contingência (EPEC e offline), retransmissão, alertas de prazo e dashboard fiscal. |

**Fora da F3a:** NFC-e, cancelamento, inutilização, PDF, contingência, dashboard, NFS-e, API pública de notas, webhooks, e-mail ao destinatário, ZIP em lote, CRM, venda mista, IBS/CBS, DIFAL, Regime Normal, outras naturezas de operação (devolução, remessa, bonificação).

## 2. Entendimento acordado

- **Resultado:** o tenant emite NF-e de venda de mercadoria em homologação pelo NFePHP (`sped-nfe`), direto na SEFAZ. A nota autorizada guarda o `nfeProc` oficial.
- **Origem da nota:** tela "Nova NF-e" avulsa, com rascunho. Cliente e itens vêm do cadastro. A Action `CriarRascunhoDeNota` é a mesma que o CRM e a API pública usarão depois.
- **Escopo fiscal:** Simples Nacional; venda de mercadoria interna e interestadual (CFOP 5102, 5405, 6102, 6404; CSOSN 102 e 500). Regime Normal bloqueia com mensagem clara.
- **Pagamentos múltiplos** (soma igual ao total) e desconto em valor sobre o total.
- **Fila:** driver `database` com worker (`queue:work`); a troca por Redis é só configuração.
- **XML** guardado em coluna `text` no banco (enviado e autorizado).
- **Limite do plano:** só notas AUTORIZADAS em PRODUÇÃO contam em `DOCUMENTOS_MES`.
- **Falha de rede com a SEFAZ:** vira `ERRO`, preserva o número, e a reconciliação consulta a chave.
- **Produção:** exige ao menos uma NF-e `AUTORIZADA` em homologação e uma tela de confirmação auditada.

## 3. Arquitetura

### 3.1 Módulo `Fiscal` (backend)

- **Models** (todos com Global Scope por `tenant_id`):
  - `Nota`: uuid, `emitente_id`, `cliente_id`, `status`, `ambiente` (gravado ao iniciar), `serie`, `numero`, `chave`, `protocolo`, `cstat`, `motivo`, `mensagem_erro`, `natureza_operacao`, `consumidor_final`, `subtotal_centavos`, `desconto_centavos`, `total_centavos`, `informacoes_complementares`, `xml_enviado`, `xml_autorizado`, `emitido_em`.
  - `NotaItem`: snapshot fiscal (`sku`, `descricao`, `unidade`, `gtin`, `ncm`, `cest`, `cfop`, `origem`, `tributacao_icms`, `cst_csosn`), `quantidade`, `valor_unitario_centavos`, `desconto_centavos`, `total_centavos`.
  - `NotaPagamento`: `tpag`, `xpag` (obrigatório para 99), `valor_centavos`.
  - `NotaEvento`: histórico de transições (`status_de`, `status_para`, `detalhe`, `usuario_id` nulável).
- **Enum `StatusNota`:** `RASCUNHO | PROCESSANDO | AUTORIZADA | REJEITADA | ERRO | CANCELADA`. Na F3a `CANCELADA` só é atingida pela reconciliação (nota cancelada fora do sistema); a ação de cancelar é F3b. `REJEITADA` é decisão da SEFAZ; `ERRO` é falha técnica ou incerteza. Nunca misturar.
- **Resolvers puros** (lançam exceção fora das saídas conhecidas): `CrtResolver`, `CfopSaidaResolver` (5102, 5405, 6102, 6404), `TributacaoIcmsSaidaResolver` (CSOSN 102 e 500), `IndicadorIeDestinatarioResolver`, `TabelaIbge` (UF para `cUF`), `RateioDeDesconto` (resíduo no último item; porta de `reference-calculations.php`).
- **Interface `EmissorDeNfe`:** `emitir(NotaParaEmissao): ResultadoDeEmissao` e `consultarPorChave(string, Emitente): ResultadoDeConsulta`. Implementação `NfePhpEmissor` (monta com `Make`, assina, envia, interpreta `cStat`). Testes usam `EmissorFake`. A regra tributária fica fora do emissor; ele só traduz formato.
- **Actions:** `CriarRascunhoDeNota`, `AtualizarRascunhoDeNota`, `ExcluirRascunhoDeNota`, `IniciarEmissao`, `AplicarResultadoDaEmissao`, `ConciliarNota`, e `ConfirmarProducao` (existente, ganha a pré-condição).
- **Job `EmitirNotaJob`:** fila `database`, `tries=1`; só age se a nota está `PROCESSANDO`; exceção inesperada vira `ERRO`.
- **Comando `fiscal:reconciliar-processando`:** a cada 15 minutos, `withoutOverlapping`.
- **Contador:** `ContadorDeDocumentosMes` (registrado para `Recurso::DocumentosMes`): notas `AUTORIZADA` em `PRODUCAO` no mês corrente.
- **Dependências novas:** `nfephp-org/sped-nfe ^5.2`.

### 3.2 Estados

`RASCUNHO → PROCESSANDO → AUTORIZADA | REJEITADA | ERRO`.
`REJEITADA` e `ERRO` voltam a ser editáveis e reaproveitam o número já alocado. `AUTORIZADA` só sai desse estado por cancelamento (F3b) ou por conciliação (`CANCELADA`).

### 3.3 Fluxo de emissão

1. `IniciarEmissao`: dentro de uma transação, trava a nota (`lockForUpdate`), valida todos os bloqueios (3.4), trava a `EmitenteSerie`, aloca o número (ou reaproveita o da nota), grava `ambiente`, série e número, marca `PROCESSANDO` e registra o evento. O dispatch do job acontece **fora** da transação.
2. `EmitirNotaJob`: decifra o certificado em memória, monta o `NotaParaEmissao`, chama o `EmissorDeNfe` e entrega o resultado a `AplicarResultadoDaEmissao`.
3. `AplicarResultadoDaEmissao`: trava a nota e grava `status`, `chave`, `protocolo`, `cstat`, `motivo`, `xml_enviado`, `xml_autorizado` e `emitido_em`. Só transita a partir de `PROCESSANDO`.
4. O frontend faz polling em `GET /notas/{id}/status` enquanto `PROCESSANDO`.

### 3.4 Bloqueios (`EmissaoBloqueadaException`, código `FISCAL_*` estável, mensagem acionável)

Nenhum valor tem default. Valor ausente bloqueia. Zero é válido: usar `=== null`, nunca `empty()` nem `?? 0`.

- **Plano e assinatura:** módulo `FISCAL_NFE` contratado; situação da assinatura que permita emitir (SUSPENSA bloqueia); limite `DOCUMENTOS_MES` (só produção; erro `{message, codigo}` do ADR-0004).
- **Emitente:** IE, regime, endereço com IBGE e CNAE preenchidos; certificado `VALIDO` e não vencido; série `NFE` cadastrada; regime diferente de Simples bloqueia com `FISCAL_REGIME_NAO_SUPORTADO`.
- **Destinatário:** UF, endereço completo com IBGE e CPF ou CNPJ. PJ sem IE e sem flag de isento bloqueia. `indIEDest` derivado (CPF=9, CNPJ com IE=1, isento=2).
- **`indFinal`:** PF é 1, derivado. PJ exige escolha explícita do usuário (`consumidor_final`), sem default.
- **Produto:** NCM, `origem` (`=== null` bloqueia; 0 é válido), tributação ICMS e CEST se ST. GTIN é opcional e vira `SEM GTIN`.
- **Pagamentos:** soma igual ao total da nota; `tPag=99` exige `xPag`.
- **Valores** em centavos inteiros até a montagem do XML.

### 3.5 Montagem do XML (`NfePhpEmissor`, camada `Make` sem I/O)

- `cUF` por tabela IBGE a partir da UF do emitente; a UF da chave deve bater com a do autor.
- CNPJ do emitente vazio lança exceção (a `Make` geraria `00000000000000` em silêncio).
- Simples: `tagICMSSN` com CSOSN (não `tagICMS`, que descarta o CSOSN). PIS e COFINS por item com CST 49 zerado.
- `getErrors()` da `Make` é sempre checado.
- Texto livre sanitizado ao conjunto de caracteres e ao limite do schema (`infCpl` até 5000).
- Schema `PL_009_V4`, versão 4.00. IBS/CBS fora do escopo (Simples dispensado até 04/01/2027).

### 3.6 Envio e resposta

- Assina (`signNFe`), envia com `sefazEnviaLote` (`indSinc=1`) e valida contra o XSD.
- `cStat=100` com `protNFe`: `AUTORIZADA`; `Complements::toAuthorize` monta o `nfeProc`, que é o `xml_autorizado`.
- `protNFe` com outro `cStat`: `REJEITADA`, mensagem `cStat=X: xMotivo`.
- Lote ainda em processamento: permanece `PROCESSANDO` (a reconciliação consulta a chave).
- Falha de comunicação, timeout ou exceção inesperada: `ERRO` com número preservado. Não entra em contingência (F3c).
- **`cStat=539`** (número já usado): extrai a `chNFe` da mensagem e consulta. Só avança para o próximo número se a nota que o ocupa estiver cancelada (101 ou 151), com no máximo 5 tentativas. Se estiver autorizada, a nota vai para `ERRO` com a chave na mensagem, para conciliação manual.

### 3.7 Reconciliação (a cada 15 minutos)

- Nota com `chave`: consulta a SEFAZ e concilia (100 vira `AUTORIZADA`; 101 e 151 viram `CANCELADA`).
- Nota `PROCESSANDO` há mais de 10 min, sem chave e sem `xml_enviado` ("nunca enviada"): vai para `ERRO`, preservando o número, para reenvio manual.

### 3.8 Produção

`ConfirmarProducao` passa a exigir ao menos uma NF-e `AUTORIZADA` em homologação (código `FISCAL_SEM_EMISSAO_DE_TESTE`). A tela de confirmação mostra o ambiente de forma inconfundível, pede confirmação explícita e a mudança é auditada.

## 4. API (`/api/app`, grupo `empresa`)

| Rota | Papéis | Observação |
|---|---|---|
| `GET /notas` | todos | filtros de status, período e busca; paginada |
| `GET /notas/{id}` | todos | detalhe com itens, pagamentos e eventos |
| `GET /notas/{id}/status` | todos | polling |
| `POST /notas` | PROPRIETARIO, ADMIN, FISCAL, VENDEDOR | cria rascunho |
| `PUT /notas/{id}` | idem | só em `RASCUNHO`, `REJEITADA`, `ERRO` |
| `DELETE /notas/{id}` | idem | só `RASCUNHO` |
| `POST /notas/{id}/emitir` | PROPRIETARIO, ADMIN, FISCAL | 202; já `AUTORIZADA` ou `PROCESSANDO` não faz nada |
| `GET /notas/{id}/xml` | todos | nome `NFe-<n>.xml`; só `AUTORIZADA` |
| `POST /emitente/producao` | PROPRIETARIO, ADMIN | pré-condição de 3.8 |

LEITURA só consulta. O controller não tem regra: só chama as Actions. Todo endpoint novo tem teste de isolamento por tenant.

## 5. Frontend

- `features/notas/{types,schemas,api}.ts`, componentes e páginas.
- **`/notas`:** lista com status coloridos (verde autorizada, âmbar processando, vermelho rejeitada ou erro) e o ambiente sempre visível.
- **`/notas/nova` e `/notas/[id]`:** cliente, itens do catálogo, desconto, pagamentos múltiplos, `consumidor_final` (PJ), emitir e polling. Bloqueios aparecem com a mensagem acionável. Baixar XML quando `AUTORIZADA`.
- **Menu:** item "Notas" em `nav-items.ts`, com os mesmos papéis do backend.
- **Configurações › Fiscal:** confirmação de produção com faixa de ambiente.
- Padrões já estabelecidos: `Campo`, `useEnvioUnico`, `useWatch`, tokens de tema e contraste AA.

## 6. Testes

- **Unitários:** resolvers, tabela IBGE, rateio de desconto.
- **Feature com `EmissorFake`:** fluxo completo (rascunho, emitir, autorizada, rejeitada, erro), todos os bloqueios, numeração com reuso, 539, reconciliação, limite do plano, papéis, isolamento por tenant.
- **XSD:** o XML gerado pela `Make` é validado contra o XSD do `sped-nfe`, sem SEFAZ, com dados anonimizados.
- **Frontend:** formulário, polling e bloqueios.
- Nenhum dado real em fixtures.

## 7. Critério de pronto

1. **Automática:** suítes verdes (backend e frontend), Pint, PHPStan, typecheck, lint e build; XML validado contra o XSD; fumaça com curl usando `EmissorFake`.
2. **Real (feita pelo usuário):** emitir uma NF-e na homologação da SEFAZ com o certificado A1 e a IE de homologação do próprio usuário. O roteiro fica no `PROGRESSO.md`. Só depois disso a "nota autorizada em homologação" do spec mestre é considerada cumprida.

## 8. Riscos e pendências conhecidas

- A validação real na SEFAZ depende de um certificado e de uma IE de homologação que o assistente não tem.
- Regime Normal, DIFAL, IBS/CBS e outras naturezas permanecem bloqueados ou fora de escopo, e registrados como pendência.
- O worker de fila precisa rodar em produção (processo supervisionado); sem ele, as notas ficam `PROCESSANDO` até a reconciliação marcar `ERRO`.
