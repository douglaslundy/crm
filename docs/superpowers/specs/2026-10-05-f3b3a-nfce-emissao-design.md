# F3b3a: emissão da NFC-e (design)

Data: 2026-10-05. Fase do roteiro: F3 (NF-e e NFC-e), quinta fatia. Base: F3a, F3b1 e F3b2 concluídas; spec mestre §7; referência `docs/referencia-fiscal/04-nfephp-nfce.md`.

## 1. Contexto e decomposição

A F3b3 foi dividida em duas: **F3b3a (este documento: emissão da NFC-e)** e **F3b3b (cupom de 80 mm com `sped-da` DANFCE e cancelamento da NFC-e)**. Contingência offline (`tpEmis=9`) e retransmissão ficam na F3c.

**Fora da F3b3a:** PDF/cupom, cancelamento específico da NFC-e (o evento é o mesmo; a validação de prazo e o cupom cancelado são da F3b3b), contingência offline, e-mail ao consumidor, NFC-e interestadual (vedada), Regime Normal.

## 2. Entendimento e decisões

Objetivo: o usuário de um tenant com o módulo `FISCAL_NFCE` emite uma NFC-e (modelo 65) pelo mesmo fluxo da NF-e (rascunho, emitir, fila, polling, reconciliação), com destinatário opcional, CSC do ambiente e QR Code no XML.

Decisões do usuário (2026-10-05):
1. Dividir a F3b3 em F3b3a e F3b3b.
2. Sem trava de UF no código: qualquer UF do emitente; a validação real só ocorre nas UFs homologadas.
3. Cupom com `sped-da` DANFCE (F3b3b).
4. Mesma tela de nota, com seletor de modelo.
5. Abordagem: **`modelo` na própria nota**, não uma entidade nova. Aprovada no chat, junto com as regras da §4.

## 3. Dados (uma migration, só por migration)

- `notas.modelo` string(4), padrão `NFE`, valores `NFE` e `NFCE` (enum `ModeloDocumento`, já existente, restringido a esses dois na nota).
- `notas.cliente_id` passa a aceitar nulo (NFC-e sem cliente). NF-e continua exigindo cliente, por validação na Action.
- Índice único da numeração passa de `(tenant_id, ambiente, serie, numero)` para `(tenant_id, modelo, ambiente, serie, numero)`.
- `inutilizacoes.modelo` (padrão `NFE`), para a inutilização usar o modelo e a série corretos.
- Séries: `emitente_series` já tem `modelo`; a NFC-e usa a série e o contador do seu modelo. `IniciarEmissao` e `InutilizarNumeracao` deixam de fixar `ModeloDocumento::Nfe`.

## 4. Regras (Domain e Application; o motor só traduz formato)

- **Módulo do plano:** NFC-e exige `FISCAL_NFCE`; NF-e continua exigindo `FISCAL_NFE`. Sem o módulo: erro de entitlement já existente.
- **CSC:** sem `csc_id` e token do ambiente da emissão, bloqueia com `EmissaoBloqueadaException` acionável ("Cadastre o CSC de <ambiente> em Configurações › Fiscal"), **antes** de alocar número e de consumir cota. O token é decifrado só no momento de montar a configuração do `Tools`.
- **Destinatário opcional:** sem `cliente_id`, o snapshot guarda `destinatario = null` e o XML omite o grupo `dest`. Com cliente: vai `CPF` ou `CNPJ` e `xNome`, **sem endereço**; cliente sem CPF/CNPJ vira venda sem identificação (o grupo `dest` é omitido). A NFC-e não exige o endereço do cliente.
- **Campos fixos:** `idDest = 1`, `indFinal = 1`, `indIEDest = 9`, `tpImp = 4`, `mod = 65`.
- **UF:** a NFC-e é sempre operação interna. Se o cliente informado tiver UF diferente da do emitente, a emissão **bloqueia** com `FISCAL_NFCE_INTERESTADUAL` ("A NFC-e não pode ser emitida para outra UF. Use NF-e."). Não há CFOP 6108 por chute.
- **CFOP:** novo `CfopConsumidorResolver` (Domain/Resolvers): mesma UF e tributação normal → `5102`; ST → `5405`; qualquer outra combinação lança exceção (zero default).
- **Consumidor final PJ:** a pergunta `consumidor_final` não se aplica à NFC-e (sempre 1); a validação atual só roda para NF-e.
- **Cota:** NFC-e conta em `DOCUMENTOS_MES` como a NF-e (autorizada, cancelando e cancelada, em produção).
- **Denegada:** cStat de denegação (110, 301, 302) vira `ERRO` com o motivo da SEFAZ e a mensagem "NFC-e denegada" (não existe status novo nesta fatia). Contingência offline não é acionada.

## 5. Motor

- `NotaParaEmissao` ganha `modelo` e `destinatario` opcional (`?DestinatarioParaEmissao`); `idDest` vem fixo 1 para NFC-e.
- `MontadorDeXml::montar` lê o modelo: `mod`, `tpImp`, `indFinal`, `dest`/`enderDest` condicionais, CFOP já resolvido. Sem regra tributária no montador.
- `NfePhpEmissor::tools` recebe o modelo e, no 65, acrescenta `CSC` e `CSCid` ao JSON do `Tools` e chama `$tools->model(65)`. A biblioteca insere o QR Code (`infNFeSupl`) ao assinar. Consulta por chave, cancelamento e inutilização usam o modelo da nota (a `chave` já o carrega nas posições 21–22; o contrato `EmissorDeNfe` recebe `string $modelo` onde hoje assume 55).
- `InterpretadorDeResposta`: sem mudança de regra; o `denegado` já cai em `ERRO` pelos cStat mapeados.
- `EmissorFake` passa a aceitar o modelo e registra qual foi pedido.

## 6. API e frontend

- `POST/PUT /api/app/notas` aceitam `modelo` (`NFE` padrão) e, para `NFCE`, `cliente_id` opcional. `NotaResource` devolve `modelo`. Erros de negócio no formato `{message, codigo}`. Todo endpoint novo ganha teste de isolamento por tenant (os existentes passam a cobrir os dois modelos).
- `NotaForm`: seletor "NF-e / NFC-e" visível só se o tenant tem `FISCAL_NFCE`; na NFC-e o cliente é opcional ("Consumidor não identificado") e some "consumidor final". `ListaDeNotas` e `DetalheDaNota` mostram o modelo; o detalhe da NFC-e autorizada mostra chave, protocolo e o XML (sem DANFE nesta fatia).
- Texto de apoio na tela fiscal: o CSC já existe (F2); o aviso "exige CSC" passa a bloquear a emissão de NFC-e.

## 7. Testes

- Resolvers: `CfopConsumidorResolver` (5102, 5405, combinações inválidas lançam).
- Preparador: NFC-e sem cliente, com cliente PF só com CPF, com cliente de outra UF (bloqueia), sem CSC (bloqueia antes de alocar número), sem módulo.
- Numeração: série e contador separados por modelo; NF-e e NFC-e com o mesmo número não colidem; inutilização usa o modelo.
- XML real assinado (certificado de teste, CSC de teste): `mod=65`, `tpImp=4`, `idDest=1`, `indFinal=1`, `infNFeSupl/qrCode` presente, sem `dest`/`enderDest` quando sem cliente, `dest` só com CPF e nome quando identificado.
- Fluxo com `EmissorFake` (rascunho → emitir → autorizada), reconciliação de NFC-e `PROCESSANDO`, `denegada` virando `ERRO` com motivo.
- API: criar rascunho de NFC-e sem cliente, cota, isolamento entre tenants, NF-e inalterada (a suíte atual continua verde).
- Frontend: seletor aparece só com o módulo; NFC-e permite salvar sem cliente; envia `modelo`.

## 8. Critério de pronto

Em testes: emitir uma NFC-e pelo `EmissorFake`, com cliente e sem cliente, e montar/assinar o XML real com os campos acima. Em homologação real (usuário, depende de A1 e CSC de teste): autorizar uma NFC-e na UF do emitente e conferir o QR Code. Roteiro entra no `PROGRESSO.md`.

## 9. Pendências conhecidas (registrar em `TAREFAS.md`)

F3b3b (cupom e cancelamento), F3c (contingência offline, retransmissão, dashboard fiscal), NFC-e em Regime Normal, mapeamento fino de denegação como status próprio, validação das regras específicas de cada UF (CSC, URL do QR) em homologação.
