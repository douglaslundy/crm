# F6a: API pública, fundação (chaves, autenticação, limite, erros e cadastros)

Data: 2026-10-08. A F6 (spec mestre, seção 8) é cortada em **F6a (este documento)**, **F6b (notas: emissão com `Idempotency-Key`, `GET`, `pdf`, `xml`, `cancelar`, inutilizações)** e **F6c (webhooks de saída, OpenAPI 3.1 e guia do integrador)**. Decisões do usuário: o corte acima; a chave age como **o usuário que a criou** (papel e auditoria dele).

## Objetivo

Um integrador autenticado por chave de API lê e grava clientes, produtos e serviços, consulta a empresa e o consumo do plano, com erros no formato RFC 9457, paginação por cursor e limite de requisições por plano. As regras são as mesmas do app: a API chama as mesmas Actions e Policies.

**Fora da F6a:** notas (F6b); webhooks de saída, OpenAPI e guia (F6c); papel próprio por chave; escopos por recurso; rotação automática; IP allowlist; importação CSV pela API.

## Dados

Migration nova, tabela `chaves_api` (Model de tenant com Global Scope, teste de isolamento):

- `id` (uuid), `tenant_id`, `usuario_id` (autor: quem criou), `nome` (até 80), `ambiente` (`LIVE`|`TEST`), `prefixo` (primeiros 12 caracteres da chave, para identificar), `ultimos4`, `hash` (SHA-256 da chave inteira, único, indexado), `ultimo_uso_em` (nulo), `revogada_em` (nula), timestamps.
- A chave inteira (`live_` ou `test_` + 40 caracteres aleatórios, alfanuméricos) só existe na resposta da criação. Nada dela vai para log, `activity` nem listagem. O hash nunca é exposto.

## Chaves (Actions, no módulo `Identity`)

- `CriarChaveDeApi(Usuario $autor, string $nome, AmbienteDaChave $ambiente): ResultadoDaChave` (modelo + texto da chave, uma vez). Só `PROPRIETARIO`/`ADMIN`. Limite de 20 chaves ativas por empresa (`API_CHAVES_LIMITE_ATINGIDO`). Recusa quando o plano não tem o módulo `API` (`ModuloNaoContratadoException`).
- `RevogarChaveDeApi(Usuario $autor, string $id)`: define `revogada_em` (idempotente); outro tenant dá 404.
- `ListarChavesDeApi`: nome, ambiente, prefixo, últimos 4, criador, último uso, revogada.
- Auditoria (`activity('api')`) de criar e revogar, sem a chave.
- Rotas do app: `GET/POST /api/app/chaves-api`, `DELETE /api/app/chaves-api/{id}` (grupo `empresa`; nunca acessíveis com chave de API). Tela em Configurações: lista, criar (mostra a chave uma vez, com botão copiar e aviso) e revogar com confirmação.

## Autenticação (`api-v1`)

Middleware `AutenticarChaveDeApi`, grupo `api-v1`:

1. Lê `Authorization: Bearer <chave>`; ausente ou malformada: 401 `API_CHAVE_AUSENTE`/`API_CHAVE_INVALIDA`, com `WWW-Authenticate: Bearer`.
2. Procura pelo hash (`hash_equals` não é necessário: a busca é por índice do hash). Chave inexistente ou revogada: 401 `API_CHAVE_INVALIDA`. A mensagem não distingue os casos.
3. O autor (`usuario_id`) precisa estar ativo e com papel `PROPRIETARIO`/`ADMIN`; senão 401 `API_CHAVE_INVALIDA` (a chave "morre" com o usuário).
4. Define o usuário autenticado da requisição (`Auth::setUser`) e o `TenantContext` pelo tenant da chave; guarda o ambiente da chave na requisição (`$request->attributes`).
5. Módulo `API` ausente no plano: 403 `API_NAO_CONTRATADA`. Situação da conta pela mesma regra do `VerificarSituacaoDoTenant` (escrita bloqueada para inadimplente/suspenso; `PENDENTE` e `CANCELADA` recusam tudo com 403).
6. Atualiza `ultimo_uso_em` no máximo uma vez por minuto por chave (evita escrita a cada requisição).

Os grupos `empresa`/Sanctum não aceitam chave de API, e o `api-v1` não aceita sessão/cookie.

## Limite de requisições

Middleware `LimitarRequisicoesDaApi` depois da autenticação: `RateLimiter` por chave, janela de 1 minuto, limite = `limite(API_REQUISICOES_MIN)` do plano. `-1` (ilimitado) não limita; `0` ou ausente (fail-closed) significa módulo efetivamente fechado e responde 403 `API_NAO_CONTRATADA`, como o módulo ausente. Headers em toda resposta `/api/v1`: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (epoch). Estouro: 429 `API_LIMITE_EXCEDIDO` com `Retry-After`.

## Erros (RFC 9457)

Handler dedicado para requisições `api/v1/*` (o `/api/app` não muda): `Content-Type: application/problem+json`, corpo `{ type, title, status, detail, codigo, errors? , instance? }`.

- `type`: `https://docs.<dominio>/erros/<codigo-em-minusculas>` (URL base em `config('api.erros_base_url')`; sem página até a F6c).
- `ErroDeNegocio` → o `status()` e `codigo()` já existentes; `title` genérico por status; `detail` = a mensagem; extras (por exemplo `excessos`) saem como campos.
- Validação → 422 `VALIDACAO` com `errors[]` = `[{ campo, mensagem }]`.
- 401, 403, 404 (`RECURSO_NAO_ENCONTRADO`), 405, 429 e 500 (`ERRO_INTERNO`, sem detalhe interno).

## Recursos

Todos chamam as Actions e Policies do app; nenhuma regra nova no controller. Papel do autor = papel do criador da chave.

- `clientes`, `produtos`, `servicos`: `GET` (lista paginada), `GET /{id}`, `POST` (201), `PUT /{id}`, `DELETE /{id}` quando o app permite (mesma regra de pendências/uso do app). Os campos são os do app (mesmos Requests/Resources). O que o app já bloqueia (dado fiscal incompleto marca pendência, nunca é chutado) vale igual.
- `GET /empresa`: dados cadastrais e fiscais do emitente em leitura, sem certificado, senha, CSC ou token; `ambiente_da_chave`.
- `GET /consumo`: `[{ recurso, uso, limite }]` do `EntitlementService::consumo`.
- Rotas fora desse conjunto respondem 404 no formato RFC 9457.

## Paginação

Cursor opaco (base64 do último `(ordenacao, id)`), `?limite=` (1 a 100, padrão 50), `?cursor=`, filtros `?q=` (busca por nome/documento/sku) e `?atualizado_desde=` (ISO-8601). Resposta: `{ data: [...], links: { proxima: <url|null> }, meta: { limite } }`. Ordenação estável por `(updated_at, id)`. Cursor inválido: 422 `CURSOR_INVALIDO`.

## Testes

- Chaves: criação (texto só uma vez, hash gravado, nunca em log), limite de 20, papel sem permissão, módulo fora do plano, revogação idempotente, isolamento (outro tenant não lista nem revoga), auditoria sem a chave.
- Autenticação: sem header, malformada, inexistente, revogada, `live_` e `test_`, criador desativado/rebaixado, módulo ausente, conta pendente/cancelada/inadimplente/suspensa, sessão do app não vale no `api-v1` e chave não vale no `/api/app`, `ultimo_uso_em` com no máximo uma escrita por minuto.
- Limite: contagem por chave (duas chaves do mesmo tenant não dividem), headers, 429 com `Retry-After`, ilimitado, limite 0.
- Erros: um caso por tipo, `Content-Type` correto, sem vazar exceção interna.
- Recursos: CRUD de cada um, isolamento entre tenants (um teste por recurso), bloqueios do app preservados (por exemplo produto sem origem vira pendência, não valor padrão), paginação (cursor, limite máximo, filtros, cursor inválido), `empresa` sem segredos, `consumo`.
- Frontend: tela de chaves (lista, criar mostra a chave uma vez e some ao fechar, revogar com confirmação, papel sem permissão, erro em alerta).

## Pendências a registrar

F6b e F6c; escopos por chave e papel próprio; IP allowlist; rotação; página pública de erros (`type`); `X-RateLimit-*` agregado por empresa; importação CSV pela API.
