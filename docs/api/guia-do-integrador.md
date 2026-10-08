# Guia do integrador da API pública

Este guia leva você da primeira chave até a primeira nota em homologação e mostra como receber os avisos por webhook.
A referência completa de cada endpoint, com campos e exemplos, está na especificação OpenAPI 3.1:
[`docs/api/openapi.yaml`](openapi.yaml), que também é exibida na página `/docs/api` do aplicativo.

Base: `https://<seu-dominio>/api/v1`. Corpo e resposta em JSON.

## Primeiros passos (do zero à primeira nota em homologação)

1. **Crie uma chave `test_`** em **Configurações → Chaves de API**. Guarde o texto inteiro: ele aparece uma única vez.
2. **Confira a empresa**: `GET /empresa` deve mostrar `ambiente_fiscal: HOMOLOGACAO`, certificado válido e `ambiente_da_chave: test`.
3. **Emita** com `POST /notas` e um `Idempotency-Key` novo (use um UUID por nota).
4. **Acompanhe** com `GET /notas/{id}/status` até sair de `PROCESSANDO` ou, melhor, receba o webhook `nota.autorizada`.
5. **Baixe** o PDF e o XML em `GET /notas/{id}/pdf` e `/xml`.

```bash
export BASE="https://<seu-dominio>/api/v1"
export CHAVE_DE_API="test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"

# 2. A empresa e o ambiente
curl -s "$BASE/empresa" -H "Authorization: Bearer $CHAVE_DE_API"

# 3. Emitir (NF-e com cliente e produto inline)
curl -s -X POST "$BASE/notas" \
  -H "Authorization: Bearer $CHAVE_DE_API" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"modelo":"NFE","cliente":{"tipo":"PF","nome":"Maria Souza","cpf_cnpj":"529.982.247-25","logradouro":"Rua A","numero":"10","bairro":"Centro","cidade":"São Paulo","uf":"SP","cep":"01001000","codigo_ibge":"3550308"},"itens":[{"produto":{"sku":"PAR-8","nome":"Parafuso M8","unidade":"UN","preco_centavos":500,"ncm":"73181500","origem":0,"tributacao_icms":"NORMAL"},"quantidade":"2"}],"pagamentos":[{"tpag":"01","valor_centavos":1000}],"desconto_centavos":0}'

# 4. Status (troque NOTA_ID pelo id devolvido)
curl -s "$BASE/notas/NOTA_ID/status" -H "Authorization: Bearer $CHAVE_DE_API"
```

Em PHP (sem bibliotecas, só a extensão cURL):

```php
$base = 'https://<seu-dominio>/api/v1';
$chave = getenv('CHAVE_DE_API');

function chamar(string $metodo, string $url, string $chave, ?array $corpo = null, ?string $idempotencia = null): array
{
    $cabecalhos = ['Authorization: Bearer '.$chave, 'Accept: application/json', 'Content-Type: application/json'];
    if ($idempotencia !== null) {
        $cabecalhos[] = 'Idempotency-Key: '.$idempotencia;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $cabecalhos,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $corpo === null ? null : json_encode($corpo, JSON_UNESCAPED_UNICODE),
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$status, json_decode((string) $resposta, true)];
}

[$status, $emissao] = chamar('POST', $base.'/notas', $chave, [
    'modelo' => 'NFE',
    'cliente' => ['id' => '<uuid do cliente>'],
    'itens' => [['produto_id' => '<uuid do produto>', 'quantidade' => '1']],
    'pagamentos' => [['tpag' => '01', 'valor_centavos' => 500]],
    'desconto_centavos' => 0,
], bin2hex(random_bytes(16)));

// 202: a nota nasce PROCESSANDO. Qualquer 4xx traz "codigo" e, na validação, "errors".
$notaId = $emissao['data']['id'] ?? null;
```

Em JavaScript (Node 18+ ou navegador de servidor, com `fetch`):

```js
const base = 'https://<seu-dominio>/api/v1';
const chave = process.env.CHAVE_DE_API;

async function chamar(metodo, caminho, corpo, idempotencia) {
  const resposta = await fetch(`${base}${caminho}`, {
    method: metodo,
    headers: {
      Authorization: `Bearer ${chave}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(idempotencia ? { 'Idempotency-Key': idempotencia } : {}),
    },
    body: corpo ? JSON.stringify(corpo) : undefined,
  });
  return { status: resposta.status, corpo: await resposta.json() };
}

const emissao = await chamar('POST', '/notas', {
  modelo: 'NFE',
  cliente: { id: '<uuid do cliente>' },
  itens: [{ produto_id: '<uuid do produto>', quantidade: '1' }],
  pagamentos: [{ tpag: '01', valor_centavos: 500 }],
  desconto_centavos: 0,
}, crypto.randomUUID());

// 202: a nota nasce PROCESSANDO. Consulte o status até o fim do processamento.
const notaId = emissao.corpo.data?.id;
let status = 'PROCESSANDO';
while (status === 'PROCESSANDO' && notaId) {
  await new Promise((r) => setTimeout(r, 2000));
  status = (await chamar('GET', `/notas/${notaId}/status`)).corpo.data.status;
}
```

Para ir à produção, troque a chave por uma `live_…` e configure a empresa em **produção** (Configurações → Fiscal): as notas só saem se o ambiente da chave e o da empresa coincidirem.

## Autenticação

Cada chave é criada em **Configurações → Chaves de API** (somente proprietário e administrador). O texto inteiro aparece **uma única vez**, na criação; depois só o prefixo e os 4 últimos caracteres. Guarde a chave num cofre de segredos.

```
Authorization: Bearer live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

- `live_…`: ambiente de produção. `test_…`: ambiente de teste (as notas, na F6b, vão para homologação).
- A chave **age como o usuário que a criou**, com o papel e a auditoria dele. Se esse usuário for desativado ou perder o papel de proprietário/administrador, a chave deixa de valer (401).
- A chave `test_` hoje lê e grava os mesmos clientes, produtos e serviços da `live_` (não é um sandbox de cadastros); a diferença aparece na emissão de notas (F6b).
- Revogue uma chave comprometida na mesma tela. Cada empresa pode ter até 20 chaves ativas.
- A API exige o módulo **API** no plano (403 `API_NAO_CONTRATADA` sem ele). Conta pendente ou cancelada não usa a API; conta suspensa só lê; a inadimplente ainda escreve, como no aplicativo (precisa poder regularizar). Revogar uma chave é permitido em qualquer situação.
- A sessão do aplicativo não vale no `/api/v1`, e a chave não vale no `/api/app`.

## Limite de requisições

O plano define as requisições por minuto, **por chave**, em janela de 1 minuto. Toda resposta que passa pela autenticação traz (401 e 403 anteriores ao limite não trazem):

| Cabeçalho | Significado |
|---|---|
| `X-RateLimit-Limit` | requisições permitidas por minuto |
| `X-RateLimit-Remaining` | quantas ainda cabem na janela |
| `X-RateLimit-Reset` | instante (epoch, segundos) em que a janela reinicia |

Planos com requisições ilimitadas não enviam esses cabeçalhos. Ao exceder: **429** `API_LIMITE_EXCEDIDO` com `Retry-After` (segundos).

## Erros (RFC 9457)

Todo erro do `/api/v1` é `application/problem+json`:

```json
{
  "type": "https://docs.example.com/erros/validacao",
  "title": "Dados inválidos",
  "status": 422,
  "detail": "Os dados enviados são inválidos.",
  "codigo": "VALIDACAO",
  "instance": "/api/v1/clientes",
  "errors": [{ "campo": "tipo", "mensagem": "O campo tipo é obrigatório." }]
}
```

Códigos principais: `API_CHAVE_AUSENTE`, `API_CHAVE_INVALIDA` (401, com `WWW-Authenticate: Bearer`), `API_NAO_CONTRATADA`, `ASSINATURA_*` (403), `RECURSO_NAO_ENCONTRADO` (404), `METODO_NAO_PERMITIDO` (405), `VALIDACAO` e `CURSOR_INVALIDO` (422), `API_LIMITE_EXCEDIDO` (429), `ERRO_INTERNO` (500, sem detalhe interno). Erros de regra de negócio trazem o `codigo` próprio e campos extras (por exemplo `recurso` e `limite`).

## Paginação

Listas usam **cursor**, ordenadas por `(updated_at, id)` crescente:

| Parâmetro | Descrição |
|---|---|
| `limite` | 1 a 100 (padrão 50) |
| `cursor` | opaco; use o `links.proxima` devolvido, sem alterar |
| `q` | busca por texto (nome, documento, SKU…) |
| `atualizado_desde` | ISO-8601; só registros alterados desde então (inclusive) |

Resposta: `{ "data": [...], "links": { "proxima": "<url>|null" }, "meta": { "limite": 50 } }`. Siga `links.proxima` até ser `null`. Para sincronizar, guarde o maior `updated_at` visto e use `atualizado_desde`.

## Recursos

| Recurso | Operações |
|---|---|
| `/clientes` | `GET` (lista), `GET /{id}`, `POST` (201), `PUT /{id}` |
| `/produtos` | idem |
| `/servicos` | idem |
| `/empresa` | `GET`: dados cadastrais e fiscais do emitente (sem certificado, senha, CSC ou tokens) e `ambiente_da_chave` |
| `/consumo` | `GET`: `[{ recurso, uso, limite }]` do plano |

Não há `DELETE`: o aplicativo também não exclui cadastros. As regras são as mesmas do aplicativo (mesmas Actions): dado fiscal ausente num produto o deixa **pendente de revisão**, nunca recebe valor padrão; `origem: 0` é um valor válido.

## Notas fiscais

Todas as chamadas usam as mesmas regras do aplicativo: dado fiscal ausente **bloqueia** com código estável, nunca recebe valor padrão.

### Ambiente da chave

A chave `test_` só emite se a empresa está em **homologação**; a `live_` só em **produção**. Se não coincidirem: `422 API_AMBIENTE_DIVERGENTE`, sem criar nada. O ambiente fiscal da empresa é mudado no aplicativo (Configurações → Fiscal).

### Idempotência

`POST /notas` exige o cabeçalho `Idempotency-Key` (1 a 255 caracteres visíveis, sem espaços; use um UUID por nota). Repetir a chamada com a **mesma chave e o mesmo corpo** devolve a resposta original (cabeçalho `Idempotent-Replayed: true`) e não emite outra nota. Mesma chave com outro corpo: `409 IDEMPOTENCIA_CORPO_DIFERENTE`. Chamada original ainda em andamento: `409 IDEMPOTENCIA_EM_ANDAMENTO` (repita após `Retry-After`). Respostas `2xx` e `4xx` ficam guardadas por 24 h; erros `5xx` liberam a chave para nova tentativa. O cancelamento e a inutilização aceitam o mesmo cabeçalho, opcional.

### Emitir

`POST /notas` responde `202` com `{ "data": { "id", "modelo", "status": "PROCESSANDO" } }`; a autorização da SEFAZ/ADN é assíncrona. Acompanhe com `GET /notas/{id}/status` (`AUTORIZADA`, `REJEITADA` com `cstat` e `motivo`, `ERRO`, `CONTINGENCIA`…).

```bash
curl -s -X POST "https://<seu-dominio>/api/v1/notas" \
  -H "Authorization: Bearer $CHAVE_DE_API" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "modelo": "NFE",
    "cliente": { "tipo": "PF", "nome": "Maria Souza", "cpf_cnpj": "529.982.247-25",
                 "logradouro": "Rua A", "numero": "10", "bairro": "Centro", "cidade": "São Paulo",
                 "uf": "SP", "cep": "01001000", "codigo_ibge": "3550308" },
    "itens": [{ "produto": { "sku": "PAR-8", "nome": "Parafuso M8", "unidade": "UN", "preco_centavos": 500,
                             "ncm": "73181500", "origem": 0, "tributacao_icms": "NORMAL" },
                "quantidade": "2" }],
    "pagamentos": [{ "tpag": "01", "valor_centavos": 1000 }],
    "desconto_centavos": 0
  }'
```

- `modelo`: `NFE` (padrão), `NFCE` (cliente opcional) ou `DPS` (NFS-e; itens de serviço, sem `pagamentos`).
- **Cliente:** `{ "id": "<uuid>" }` de um cliente existente **ou** os dados completos, identificados pelo `cpf_cnpj`.
- **Item:** `produto_id`/`servico_id` (cadastro existente) **ou** `produto`/`servico` inline, identificados por `sku` (produto), `codigo` (serviço) ou `cpf_cnpj` (cliente). Se o cadastro não existe, ele é criado (respeitando o limite do plano); campos fiscais ausentes deixam o cadastro **pendente de revisão** e a emissão é bloqueada. Se existe com **dados diferentes** dos enviados (preço, NCM, origem…): `422 CADASTRO_DIVERGENTE` com a lista `errors[]` (campo, valor enviado, valor do cadastro); a API não escolhe um lado nem altera o cadastro. Campo omitido usa o cadastro. Serviços ganham o campo opcional `codigo` (único por empresa) em `/servicos`.
- Um bloqueio fiscal (por exemplo `FISCAL_PRODUTO_INCOMPLETO`) desfaz tudo: nenhuma nota, cadastro ou número de série fica para trás.

### Consultar, baixar, cancelar e inutilizar

| Endpoint | Descrição |
|---|---|
| `GET /notas` | lista por cursor; filtros `status`, `modelo`, `de`, `ate` (`AAAA-MM-DD`) e `atualizado_desde` |
| `GET /notas/{id}` | nota completa (itens, pagamentos, eventos, `cstat`, `motivo`) |
| `GET /notas/{id}/status` | estado resumido, para consultar até o fim do processamento |
| `GET /notas/{id}/pdf` | DANFE/DANFSe/cupom em PDF |
| `GET /notas/{id}/xml` · `/xml-cancelamento` | XML autorizado e XML do cancelamento |
| `POST /notas/{id}/cancelar` | `{ "justificativa": "15 a 255 caracteres" }`, `202` |
| `POST /notas/inutilizacoes` · `GET /notas/inutilizacoes` | inutiliza uma faixa de numeração (`serie`, `numero_inicial`, `numero_final`, `justificativa`) · lista |

Rascunhos do aplicativo não aparecem pela API. Não há `PUT` nem `DELETE` de nota.

### Erros novos

`API_AMBIENTE_DIVERGENTE`, `CADASTRO_DIVERGENTE` (422); `IDEMPOTENCY_KEY_AUSENTE`, `IDEMPOTENCY_KEY_INVALIDA` (400); `IDEMPOTENCIA_CORPO_DIFERENTE`, `IDEMPOTENCIA_EM_ANDAMENTO` (409). Bloqueios fiscais e de plano mantêm os códigos do aplicativo (`FISCAL_*`, `LIMITE_DO_PLANO`, `MODULO_NAO_CONTRATADO`, `ASSINATURA_SEM_ESCRITA`).

## Webhooks

O sistema avisa o seu ERP quando uma nota muda de situação, sem você precisar consultar. Cada webhook é configurado por empresa na tela **Configurações → Webhooks** ou pela API (`/api/v1/webhooks`). Até **5** por empresa.

### Eventos

| Evento | Quando |
| --- | --- |
| `nota.autorizada` | a nota foi autorizada. Também é enviado quando um **cancelamento é recusado ou não confirmado** e a nota volta a autorizada: veja `cancelamento_motivo` no recurso |
| `nota.rejeitada` | a SEFAZ/prefeitura rejeitou a nota |
| `nota.cancelada` | o cancelamento foi confirmado |
| `nota.erro` | erro na emissão (a nota pode ser corrigida e reemitida) |
| `nota.contingencia` | a nota entrou em contingência |
| `webhook.teste` | enviado pelo botão/endpoint de teste |

Cada webhook tem um **ambiente** (`HOMOLOGACAO` ou `PRODUCAO`): só notas desse ambiente geram avisos, então use endpoints diferentes para testar e para produzir. `eventos` vazio assina os cinco eventos de nota.

### Corpo

```json
{
  "id": "0199b1c2-7d3e-7a10-9f55-3f1f6a2b9c10",
  "tipo": "nota.autorizada",
  "criado_em": "2026-10-08T10:15:00-03:00",
  "ambiente": "HOMOLOGACAO",
  "data": { "nota": { "id": "…", "status": "AUTORIZADA", "chave": "…", "itens": [], "pagamentos": [], "eventos": [] } }
}
```

`data.nota` é o mesmo recurso de `GET /notas/{id}`. O XML **não** vai no aviso: baixe-o por `GET /notas/{id}/xml`.

### Cabeçalhos

| Cabeçalho | Valor |
| --- | --- |
| `X-Webhook-Id` | igual ao `id` do corpo; **não muda nas retentativas** (use para ignorar repetições) |
| `X-Webhook-Event` | o `tipo` |
| `X-Signature` | `t=<unix>,v1=<hex>` (veja abaixo) |
| `User-Agent` | `PlataformaFiscal-Webhook/1` |

### Verificar a assinatura

O `v1` é o HMAC-SHA256, em hexadecimal, de `"<t>.<corpo exato recebido>"` usando o **segredo** do webhook (`whsec_…`, mostrado uma única vez ao criar). Confira com o **corpo bruto**, antes de decodificar o JSON. Recuse se `t` tiver mais de **5 minutos** de diferença (replay) e compare em **tempo constante**.

```php
function assinaturaValida(string $segredo, string $cabecalho, string $corpoBruto, int $tolerancia = 300): bool
{
    if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $cabecalho, $m)) {
        return false;
    }
    if (abs(time() - (int) $m[1]) > $tolerancia) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $m[1].'.'.$corpoBruto, $segredo), $m[2]);
}
```

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

export function assinaturaValida(segredo, cabecalho, corpoBruto, tolerancia = 300) {
  const m = /^t=(\d+),v1=([0-9a-f]{64})$/.exec(cabecalho ?? '');
  if (!m || Math.abs(Date.now() / 1000 - Number(m[1])) > tolerancia) return false;
  const esperado = createHmac('sha256', segredo).update(`${m[1]}.${corpoBruto}`).digest();
  return timingSafeEqual(esperado, Buffer.from(m[2], 'hex'));
}
```

```bash
# Para conferir à mão (T e CORPO vêm da requisição recebida):
printf '%s' "${T}.${CORPO}" | openssl dgst -sha256 -hmac "$SEGREDO"
```

### Entrega e retentativas

- Responda com qualquer **2xx** em até **10 segundos**. Redirecionamentos **não** são seguidos (um `3xx` conta como falha).
- Em falha (não-2xx, tempo esgotado ou erro de conexão) tentamos de novo depois de **1 min, 5 min, 30 min, 2 h, 6 h e 12 h**; a 7ª falha encerra a entrega (`FALHOU`, cerca de 20 h no total).
- A entrega é **pelo menos uma vez** e **sem ordem garantida**: o mesmo aviso pode chegar repetido ou fora de ordem. Deduplique por `X-Webhook-Id` e, se precisar do estado atual, consulte `GET /notas/{id}`.
- **10 entregas seguidas** que chegam a `FALHOU` desativam o webhook (o motivo aparece no recurso). Reative-o depois de corrigir o destino.
- O histórico de entregas e de tentativas (status, tempo, erro e os primeiros 1024 bytes da resposta) fica **30 dias**. Você pode **reenviar** uma entrega: ela sai com o mesmo `id` e o mesmo corpo, numa nova rodada de até 7 tentativas.

### Segurança da URL

A URL precisa ser **`https`**, sem usuário e senha, e apontar para um endereço público: IPs privados, de loopback, de link-local, de metadados de nuvem e nomes que resolvem para eles são recusados, ao cadastrar e a cada envio. Os avisos saem de IPs do servidor da plataforma; se o seu firewall exige lista de liberação, peça ao suporte os endereços de saída.

### Endpoints

| Rota | O que faz |
| --- | --- |
| `GET /webhooks` · `POST /webhooks` | lista · cria (`url`, `ambiente`, `eventos?`, `descricao?`); a resposta traz `segredo` **uma única vez** |
| `GET /webhooks/{id}` · `PUT /webhooks/{id}` · `DELETE /webhooks/{id}` | detalhe · altera `url`, `descricao`, `eventos`, `ambiente`, `ativo` (reativar zera as falhas) · exclui junto com o histórico |
| `GET /webhooks/{id}/entregas` | entregas por cursor; filtro `estado` (`PENDENTE`, `ENTREGUE`, `FALHOU`) |
| `GET /webhooks/entregas/{id}` | uma entrega com o `payload` enviado e o log de tentativas |
| `POST /webhooks/{id}/testar` | envia um `webhook.teste` (`202`) |
| `POST /webhooks/entregas/{id}/reenviar` | reenvia uma entrega já concluída (`202`) |

Só chaves de **proprietário ou administrador** acessam esses endpoints, e o plano precisa incluir o módulo API. `testar` e `reenviar` aceitam `Idempotency-Key`; a criação não (a resposta leva o segredo e não é guardada). O segredo não pode ser lido de novo nem rotacionado: para trocá-lo, crie outro webhook e exclua o antigo.

### Erros novos

`WEBHOOK_URL_INVALIDA` (422, com `errors[]` apontando `url`), `WEBHOOK_LIMITE_ATINGIDO` (422), `WEBHOOK_INATIVO` (409).

## Exemplo

```bash
curl -s "https://<seu-dominio>/api/v1/clientes?limite=2&q=maria" \
  -H "Authorization: Bearer $CHAVE_DE_API" \
  -H "Accept: application/json"
```

## Limites conhecidos

Ainda não existem: rotação do segredo do webhook, webhooks de outros recursos (cliente, produto, cobrança), ordenação garantida entre eventos, lista de IPs de saída do envio, venda mista, retransmissão e EPEC pela API, escopos por chave, allowlist de IP, rotação de chaves e importação em lote.

A referência de cada endpoint está no [OpenAPI](openapi.yaml); a página `/docs/api` do aplicativo mostra a mesma especificação.
