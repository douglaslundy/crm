# API pública (rascunho da F6a)

Guia curto do que a API oferece hoje. O guia completo e a especificação OpenAPI 3.1 chegam na F6c. A emissão de notas pela API é da F6b.

Base: `https://<seu-dominio>/api/v1`. Corpo e resposta em JSON.

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

## Exemplo

```bash
curl -s "https://<seu-dominio>/api/v1/clientes?limite=2&q=maria" \
  -H "Authorization: Bearer $CHAVE_DE_API" \
  -H "Accept: application/json"
```

## Pendências

Notas pela API (F6b); webhooks de saída, OpenAPI e guia completo (F6c); escopos por chave e papel próprio; allowlist de IP; rotação de chaves; página pública de cada `type` de erro; importação CSV pela API.
