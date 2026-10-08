# F6b: notas pela API pública

Data: 2026-10-08. Continua a F6a (chaves, autenticação `api-v1`, limite, erros RFC 9457, cursor). Decisões do usuário: a chave `test_`/`live_` **tem que coincidir** com o ambiente fiscal do emitente; itens inline **cadastram pelo SKU** (e o equivalente para serviço e cliente).

## Objetivo

Um ERP emite NF-e, NFC-e e NFS-e, consulta, baixa PDF e XML, cancela e inutiliza pela API, sem sincronizar catálogo antes. As regras são as do app: a API chama as mesmas Actions e Policies, e todo bloqueio fiscal continua valendo. Nada é chutado: dado ausente bloqueia com mensagem acionável.

**Fora da F6b:** webhooks de saída, OpenAPI e guia (F6c); venda mista (NF-e + NFS-e); retransmissão de contingência e EPEC manual pela API; rascunhos pela API.

## Emissão: `POST /api/v1/notas`

Header **`Idempotency-Key`** obrigatório (ver Idempotência). Resposta **`202`** `{ data: { id, modelo, status: "PROCESSANDO" } }` (o mesmo `NotaStatusResource` do app).

Corpo:

- `modelo`: `NFE` (padrão), `NFCE` ou `DPS` (NFS-e).
- `cliente`: `{ "id": "<uuid>" }` **ou** dados inline (`tipo`, `nome`, `cpf_cnpj`, `inscricao_estadual`, `ie_isento`, `email`, `telefone`, endereço…). Opcional na NFC-e (venda sem identificar o consumidor); obrigatório nos demais.
- `itens[]`, cada um com `quantidade` (string decimal, até 3 casas) e **um** destes:
  - `produto_id` / `servico_id` (referência), ou
  - `produto` / `servico` inline: produto `{ sku, nome, unidade, preco_centavos, gtin, ncm, cest, origem, tributacao_icms }`; serviço `{ codigo, nome, preco_centavos, codigo_lc116, c_trib_nac, codigo_municipal, aliquota_iss, nbs }`.
  - `valor_unitario_centavos` opcional (sobrescreve o preço do cadastro), como no app.
- `pagamentos[]` (`tpag`, `xpag`, `valor_centavos`), proibido na NFS-e; `desconto_centavos` (obrigatório, zero é válido); `informacoes_complementares`.

Fluxo na Action `EmitirNotaPelaApi(Usuario $autor, AmbienteDaChave $ambiente, array $dados): Nota` (módulo `Fiscal`, sem lógica nova de imposto):

1. `PoliticaDeNotas` (papel do autor, o mesmo do app) e módulo do plano para o modelo.
2. **Ambiente:** `test_` exige emitente em `HOMOLOGACAO`; `live_` exige `PRODUCAO`. Divergência: **422 `API_AMBIENTE_DIVERGENTE`** (detalhe diz qual é o ambiente do emitente e o da chave), antes de criar qualquer coisa. Emitente inexistente ou sem configuração: o mesmo bloqueio do app.
3. **Resolução do inline** (dentro da transação): cliente por `cpf_cnpj` (normalizado), produto por `sku`, serviço por `codigo` (ver abaixo). Achou: usa o cadastro. Não achou: cadastra pelas Actions do app (`CriarCliente`, `CriarProduto`, `CriarServico`), com o limite do plano (`LIMITE_DO_PLANO`) e as validações normais; dado fiscal ausente cria o cadastro **pendente de revisão** e a emissão bloqueia no passo 5.
4. `CriarRascunhoDeNota::executar` com os ids resolvidos.
5. `IniciarEmissao::executar` (conta, plano, certificado, CSC, série, produto sem origem etc.). Qualquer bloqueio desfaz tudo (rascunho e cadastros criados nesta chamada) e vira **422** com o código estável da exceção existente (por exemplo `FISCAL_PRODUTO_SEM_ORIGEM`).

**Divergência de cadastro:** se o SKU/código/documento já existe e algum campo **fiscal ou de preço** enviado difere do cadastro, a API recusa com **422 `CADASTRO_DIVERGENTE`** (lista `errors[]` com campo, valor enviado e valor do cadastro), em vez de escolher um lado. Campo omitido no inline não conta como divergência (usa o cadastro). Nada do cadastro é alterado pela emissão.

**Serviço sem código:** o cadastro de serviço não tem hoje um identificador natural além do `id`. A F6b acrescenta a coluna `servicos.codigo` (texto até 60, único por tenant quando não nulo, opcional no app e na API de serviços); inline por serviço exige `codigo`.

## Idempotência

Middleware `GarantirIdempotencia` nas rotas `POST` do `api-v1` que emitem ou alteram estado (`notas`, `notas/{id}/cancelar`, `notas/inutilizacoes`).

- Tabela `idempotencias` (Model de tenant, Global Scope, teste de isolamento): `id`, `tenant_id`, `chave` (até 255), `rota`, `hash_do_corpo` (SHA-256 do JSON com chaves ordenadas), `status_http` e `corpo` da resposta (nulos enquanto em andamento), `nota_id` (nulo), `expira_em` (24 h), timestamps; único `(tenant_id, rota, chave)`.
- `Idempotency-Key` é **obrigatória** em `POST /notas` (**400 `IDEMPOTENCY_KEY_AUSENTE`**); nas demais é opcional (sem ela, sem proteção). Formato: 1 a 255 caracteres visíveis (**400 `IDEMPOTENCY_KEY_INVALIDA`**).
- Mesma chave, mesma rota e mesmo corpo: devolve a resposta original (status e corpo) com `Idempotent-Replayed: true`. Corpo diferente: **409 `IDEMPOTENCIA_CORPO_DIFERENTE`**. Chave ainda em processamento: **409 `IDEMPOTENCIA_EM_ANDAMENTO`** com `Retry-After: 1`.
- A reserva (insert único) acontece antes de executar; respostas `2xx` e `4xx` ficam gravadas (um `422` repetido devolve o mesmo `422`); `5xx` e exceção inesperada liberam a reserva para nova tentativa. Registros expirados são apagados por comando agendado (`api:limpar-idempotencias`, diário).
- A chave é por empresa (não por chave de API): trocar de chave de API não duplica uma emissão.

## Demais endpoints (todos nas rotas `api-v1`, mesmas Actions do app)

- `GET /notas`: cursor da F6a (`PaginadorPorCursor`), `limite`, `cursor`, `atualizado_desde` e filtros `status`, `modelo`, `de`, `ate` (datas `AAAA-MM-DD`, validação igual à do app). **Rascunhos não aparecem** (a API só cria notas já emitindo).
- `GET /notas/{id}`: `NotaResource` do app (itens, pagamentos, eventos, `chave_de_acesso`, `cstat`, `motivo`, `numero`, `serie`, `ambiente`). A rejeição da autoridade fica no recurso (`status = REJEITADA`). Rascunho: 404.
- `GET /notas/{id}/status`: `NotaStatusResource` (para polling leve).
- `GET /notas/{id}/pdf` e `/xml`, `GET /notas/{id}/xml-cancelamento`: as mesmas respostas do app (`GerarDanfe`, XML autorizado), com os erros no formato RFC 9457.
- `POST /notas/{id}/cancelar`: `{ justificativa }` (15 a 255 caracteres, mesmo `CancelarNotaRequest`), `202`, `IniciarCancelamento`.
- `POST /notas/inutilizacoes`: mesmo corpo e Action (`InutilizarNumeracao`) do app, `201`; `GET /notas/inutilizacoes`: lista paginada por cursor.
- Sem `PUT`/`DELETE` de nota. Rotas `notas/inutilizacoes` são registradas **antes** de `notas/{id}` (e `{id}` com `whereUuid`).

## Erros novos

`API_AMBIENTE_DIVERGENTE` (422), `CADASTRO_DIVERGENTE` (422), `IDEMPOTENCY_KEY_AUSENTE` e `IDEMPOTENCY_KEY_INVALIDA` (400), `IDEMPOTENCIA_CORPO_DIFERENTE` e `IDEMPOTENCIA_EM_ANDAMENTO` (409). Os bloqueios fiscais e de plano reaproveitam os códigos das exceções existentes.

## Dados

Migration nova: `idempotencias`; `servicos.codigo` (nulo, único parcial por tenant: o SQLite perde o `WHERE` de índice parcial ao recriar tabela, então a migration só adiciona a coluna e o índice, sem alterar a tabela por outros meios). Nada muda em `notas`.

## Testes

- Emissão `NFE`, `NFCE` e `DPS` com o emissor fake; referência e inline (novo, existente igual, divergente, omitido); cliente inline por CPF/CNPJ; produto novo sem origem bloqueia a emissão e **não deixa rascunho nem cadastro**; `origem: 0` aceito; limite do plano em cadastro inline; módulo do plano; conta suspensa/inadimplente.
- Ambiente: `test_` com emitente em produção e `live_` com emitente em homologação dão 422 e não criam nada.
- Idempotência: replay (mesma resposta, header), corpo diferente 409, em andamento 409, 422 repetido devolve o mesmo 422, `5xx` libera a chave, expiração, chave ausente 400, isolamento entre tenants (mesma chave em duas empresas não colide), comando de limpeza.
- Demais endpoints: lista (filtros, cursor, rascunho oculto), `show`, `status`, `pdf`, `xml`, cancelar (justificativa curta 422), inutilização; **isolamento por endpoint** (nota de outro tenant: 404 `problem+json`).
- O autor da nota/evento é o criador da chave (auditoria); papel `FISCAL`/`ADMIN`/`PROPRIETARIO` conforme a `PoliticaDeNotas`.

## Pendências a registrar

F6c (webhooks, OpenAPI, guia); venda mista e retransmissão/EPEC pela API; escopos por chave; polling com `ETag`; devolução do XML dentro do JSON (hoje só por download); `servicos.codigo` sem backfill para serviços já existentes.
