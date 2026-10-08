# F6a API pública, fundação Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução Native, escolhida pelo usuário). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Chaves de API (`live_`/`test_`) criadas pela empresa, autenticação Bearer no `/api/v1`, limite de requisições por plano, erros RFC 9457, paginação por cursor e os recursos `clientes`, `produtos`, `servicos`, `empresa` e `consumo`, tudo pelas mesmas Actions do app.

**Architecture:** A chave pertence a um tenant e age como o usuário que a criou (papel e auditoria dele). Um middleware novo (`AutenticarChaveDeApi`) resolve chave → usuário → `TenantContext` e entra num grupo `api-v1` separado do `empresa`/Sanctum. Controllers `V1` finos chamam as Actions existentes. Um handler de exceções dedicado converte erros do `/api/v1` para `application/problem+json`.

**Tech Stack:** Laravel 12, PHPUnit, Pint, PHPStan; Next.js, React, TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-08-f6a-api-fundacao-design.md` (mestre: seção 8 de `2026-09-25-plataforma-fiscal-crm-design.md`).

## Global Constraints

- Nunca chutar valor: dado fiscal ausente vira pendência/bloqueio do app, nunca padrão; zero é válido (`=== null`, nunca `empty()`/`?? 0`).
- Frontend e API chamam as mesmas Actions; nenhuma regra nova no controller. Todo Model de tenant com Global Scope e todo endpoint novo com teste de isolamento. Schema só por migration. Segredos fora de log e de resposta (a chave inteira só aparece na criação). `declare(strict_types=1)`. TS strict, sem `any`.
- Comandos: backend `cd backend && php artisan test --filter=<Nome>`, suíte `php artisan test --compact`, `vendor/bin/pint`, `vendor/bin/phpstan analyse --no-progress`. Frontend `cd frontend && npx vitest run <arquivo>`, `npx tsc --noEmit`, `npm run lint`, `npm run build`.
- **Armadilhas desta máquina:** (1) o shell come barras invertidas em heredoc e em `node -e`: para gravar use **Write**, para editar use **Edit**; (2) **script Python só com `io.open(p, encoding='utf-8', newline='')`** (o padrão aqui é cp1252 e corrompe os acentos novos), e depois do lote confira UTF-8 válido em todos os arquivos tocados; (3) arquivos podem ter CRLF (use Edit, âncoras de uma linha); (4) em testes, importe toda classe usada; (5) `status()` é método final do PHPUnit; (6) `Tenant::plano_id` e `situacao` só mudam por `forceFill`; (7) uma assinatura não cancelada por tenant (índice único parcial); (8) no SQLite, alterar tabela perde o `WHERE` dos índices únicos parciais: a migration nova só **cria** tabela, então não mexe neles; (9) `app.timezone` é UTC, `fuso_negocio` é America/Sao_Paulo; (10) arrow function (`fn`) não propaga atribuição de variável; (11) o `/api/app` mantém o formato de erro atual (`{message, codigo}`); só o `/api/v1` muda.
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs`. Sem push.
- Erros de negócio: classe que estende `ErroDeNegocio` (`status()`, `codigo()`, `extras()`), como `BillingException`.

## Review Focus

- A chave inteira nunca vai para banco, log, `activity` ou listagem; só o hash (Task 1 e 2).
- Chave de usuário desativado, rebaixado, de módulo fora do plano ou de conta bloqueada não autentica (Task 4).
- Isolamento: um teste por recurso `/api/v1` e um para as chaves (Tasks 3 e 7); a sessão do app não vale no `/api/v1` e a chave não vale no `/api/app` (Task 4).
- Limite de requisições por chave (não por IP nem por empresa), fail-closed com limite 0 (Task 5).
- Erros RFC 9457 sem vazar exceção interna; `/api/app` intacto (Task 6).
- Paginação: cursor estável com `updated_at` repetido, limite máximo, cursor inválido (Task 7).
- `GET /empresa` sem certificado, senha, CSC ou token (Task 7).

## File Structure

- `backend/database/migrations/2026_10_12_000001_create_chaves_api.php`
- `backend/app/Modules/Identity/Domain/{Enums/AmbienteDaChave,Models/ChaveDeApi,ChaveGerada,GeradorDeChaveDeApi}.php`, `Domain/Exceptions/{ChaveDeApiInvalidaException,LimiteDeChavesAtingidoException}.php`
- `backend/app/Modules/Identity/Application/{PoliticaDeChavesDeApi}.php`, `Application/Actions/{CriarChaveDeApi,RevogarChaveDeApi,ListarChavesDeApi}.php`
- `backend/app/Modules/Identity/Http/{Controllers/ChavesDeApiController,Requests/CriarChaveDeApiRequest,Resources/ChaveDeApiResource,Middleware/AutenticarChaveDeApi,Middleware/LimitarRequisicoesDaApi,routes.php}`
- `backend/app/Modules/Shared/Http/{ErroApiV1,TratadorDeErrosDaApi,PaginadorPorCursor}.php`, `backend/config/api.php`, `backend/bootstrap/app.php`
- `backend/app/Modules/{Customers,Catalog}/Http/{Controllers/V1/*,routes-v1.php}`, `backend/app/Modules/{Fiscal,Platform}/Http/Controllers/V1/{EmpresaController,ConsumoController}.php`
- `frontend/features/chaves-api/{types.ts,api.ts,components/ChavesDeApi.tsx}`, `frontend/app/(app)/configuracoes/api/page.tsx`

---

### Task 1: Tabela, Model e gerador da chave

**Files:**
- Create: `backend/database/migrations/2026_10_12_000001_create_chaves_api.php`, `Identity/Domain/Enums/AmbienteDaChave.php`, `Identity/Domain/Models/ChaveDeApi.php`, `Identity/Domain/ChaveGerada.php`, `Identity/Domain/GeradorDeChaveDeApi.php`, `backend/database/factories/ChaveDeApiFactory.php`
- Test: `backend/tests/Unit/Identity/GeradorDeChaveDeApiTest.php`, `backend/tests/Feature/Identity/ChaveDeApiModeloTest.php`

**Interfaces:**
- Produces:
  - `AmbienteDaChave` enum string: `Live = 'LIVE'`, `Test = 'TEST'`; `prefixoDaChave(): string` (`live_`|`test_`).
  - `GeradorDeChaveDeApi::gerar(AmbienteDaChave): ChaveGerada` (`texto` = prefixo + 40 caracteres `[A-Za-z0-9]` de `random_bytes`; `hash` = `hash('sha256', texto)`; `prefixo` = 12 primeiros; `ultimos4`) e `GeradorDeChaveDeApi::hashDe(string $texto): string`.
  - `ChaveDeApi` Model (uuid, `BelongsToTenant`, `$fillable = []`): `tenant_id`, `usuario_id`, `nome`, `ambiente` (cast do enum), `prefixo`, `ultimos4`, `hash` (`$hidden`), `ultimo_uso_em`, `revogada_em` (datetime nulos); `estaRevogada(): bool`; relação `usuario(): BelongsTo`.
  - Tabela `chaves_api` com FK `tenant_id` e `usuario_id`, `hash` único.

- [ ] **Step 1: Testes (RED)**
  - `GeradorDeChaveDeApiTest` (Unit): `live` gera `live_` + 40 caracteres alfanuméricos; `test` gera `test_`; duas chaves diferem; `hash` é o SHA-256 do texto e `hashDe` o reproduz; `prefixo` tem 12 caracteres e `ultimos4` são os 4 últimos.
  - `ChaveDeApiModeloTest`: cria por factory com tenant e usuário; `hash` não aparece em `toArray()`/`toJson()`; isolamento por Global Scope (tenant B não vê a chave do A); `hash` único (segunda com o mesmo hash lança `QueryException`); `estaRevogada()`.
- [ ] **Step 2:** `php artisan test --filter="GeradorDeChaveDeApiTest|ChaveDeApiModeloTest"`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Migration só `Schema::create` (sem índices parciais). O Model segue `Assinatura`/`Cobranca` (traits `BelongsToTenant`, `HasUuids`, `HasFactory`). Factory gera a chave pelo `GeradorDeChaveDeApi` e guarda o `texto` numa propriedade do state para os testes (`ChaveDeApiFactory::com Texto` não é necessário: os testes de outras Tasks usam `GeradorDeChaveDeApi::gerar` e passam `hash`/`prefixo`/`ultimos4` ao `create`).
- [ ] **Step 4:** `php artisan test --filter="Identity"` e phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(identity): tabela e gerador de chaves de API`.

---

### Task 2: Criar, revogar e listar chaves (Actions)

**Files:**
- Create: `Identity/Application/PoliticaDeChavesDeApi.php`, `Identity/Application/Actions/{CriarChaveDeApi,RevogarChaveDeApi,ListarChavesDeApi}.php`, `Identity/Domain/Exceptions/LimiteDeChavesAtingidoException.php`
- Test: `backend/tests/Feature/Identity/ChavesDeApiActionsTest.php`

**Interfaces:**
- Consumes: Task 1, `EntitlementService::garantirModulo(Tenant, Modulo::Api)`, `ModuloNaoContratadoException`.
- Produces:
  - `CriarChaveDeApi::executar(Usuario $autor, string $nome, AmbienteDaChave $ambiente): ChaveGerada` com o `modelo` (`ChaveDeApi`) e o `texto`.
  - `RevogarChaveDeApi::executar(Usuario $autor, string $id): ChaveDeApi` (idempotente; outro tenant: `ModelNotFoundException`).
  - `ListarChavesDeApi::executar(Usuario $autor): Collection<ChaveDeApi>` (mais recentes primeiro, com `usuario`).
  - `PoliticaDeChavesDeApi::garantirPodeGerenciar(Usuario)`: só `PROPRIETARIO`/`ADMIN` (`AcessoNegadoException`, como `PoliticaDeUsuarios`).
  - Código `API_CHAVES_LIMITE_ATINGIDO` (422) com 20 chaves ativas (não revogadas) por empresa.

- [ ] **Step 1: Testes (RED)** (`CenarioDeCobranca` não serve: crie tenant com plano que tem `Modulo::Api` via `Plano::factory()->comModulos(Modulo::Api)`; admin por `Usuario::factory()`):
  - criar devolve o texto no formato certo, grava só o hash/prefixo/ultimos4, `usuario_id` = autor; `activity('api')` registrada **sem** o texto (consulte `Spatie\Activitylog\Models\Activity` e confira que nem `properties` nem `description` contêm o texto);
  - nome vazio ou > 80 recusado (a validação está no Request da Task 3, mas a Action também recusa `InvalidArgumentException`/`ValidationException`: use `ValidationException::withMessages`);
  - papel `FISCAL`/`VENDEDOR`/`LEITURA` recusado; plano sem o módulo `API` lança `ModuloNaoContratadoException`; 21ª chave ativa lança `API_CHAVES_LIMITE_ATINGIDO` (revogada não conta);
  - revogar define `revogada_em`; revogar duas vezes não muda o instante; papel sem permissão recusado; chave de outro tenant: `ModelNotFoundException`;
  - listar traz só as do tenant, nunca o `hash`.
- [ ] **Step 2:** `php artisan test --filter=ChavesDeApiActionsTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** no molde de `DesativarUsuario`/`ConvidarUsuario` (transação, `activity`). A listagem usa o escopo de tenant do Model.
- [ ] **Step 4:** testes acima + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(identity): criar, revogar e listar chaves de API`.

---

### Task 3: Rotas do app para as chaves

**Files:**
- Create: `Identity/Http/Controllers/ChavesDeApiController.php`, `Identity/Http/Requests/CriarChaveDeApiRequest.php`, `Identity/Http/Resources/ChaveDeApiResource.php`
- Modify: `Identity/Http/routes.php` (ou `routes-app.php` do módulo, conforme o padrão do `UsuariosController`)
- Test: `backend/tests/Feature/Identity/ChavesDeApiHttpTest.php`

**Interfaces:**
- Produces: `GET /api/app/chaves-api` → `data: [{ id, nome, ambiente, prefixo, ultimos4, usuario: {id, nome}, ultimo_uso_em, revogada_em, criada_em }]`; `POST /api/app/chaves-api` (`nome` obrigatório até 80, `ambiente` em `LIVE,TEST`) → 201 `data: {…o mesmo…, chave: "<texto inteiro>"}` (único lugar onde o texto aparece); `DELETE /api/app/chaves-api/{id}` → 200 com a chave revogada. Grupo `empresa`; rotas nomeadas `app.chaves-api.*`.

- [ ] **Step 1: Testes (RED)**: criar devolve 201 e o texto uma vez; a listagem seguinte não contém o texto nem o hash (procure o texto na resposta inteira); validação 422 de `nome`/`ambiente`; 403 para papel sem permissão em cada verbo; módulo fora do plano devolve o erro de módulo; revogar e listar mostram `revogada_em`; **isolamento:** admin de outro tenant lista 0 e recebe 404 ao revogar; a rota não é acessível com chave de API (Task 4 repete).
- [ ] **Step 2:** `php artisan test --filter=ChavesDeApiHttpTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** (controller só chama as Actions).
- [ ] **Step 4:** `php artisan test --filter=Identity` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(identity): API do app para chaves de API`.

---

### Task 4: Autenticação da chave (`api-v1`)

**Files:**
- Create: `Identity/Http/Middleware/AutenticarChaveDeApi.php`, `Identity/Domain/Exceptions/ChaveDeApiInvalidaException.php`, `Identity/Domain/Exceptions/ApiNaoContratadaException.php` (ou reaproveite `ModuloNaoContratadoException` mapeado para `API_NAO_CONTRATADA` no `/api/v1`)
- Modify: `backend/bootstrap/app.php` (grupo `api-v1`), `Customers/Http/routes-v1.php` e `Catalog/Http/routes-v1.php` (trocar `middleware('empresa')` por `middleware('api-v1')`)
- Test: `backend/tests/Feature/Identity/AutenticacaoPorChaveTest.php`

**Interfaces:**
- Produces: grupo de middleware `api-v1` = `[AutenticarChaveDeApi, VerificarSituacaoDoTenant, LimitarRequisicoesDaApi (Task 5)]`; o middleware faz `Auth::setUser($usuario)`, `TenantContext::set($chave->tenant_id)` e `$request->attributes->set('ambiente_da_chave', AmbienteDaChave)` e `'chave_de_api'`. Códigos: 401 `API_CHAVE_AUSENTE`, 401 `API_CHAVE_INVALIDA` (inexistente, revogada, autor inativo ou sem papel), 403 `API_NAO_CONTRATADA` (plano sem o módulo ou limite `API_REQUISICOES_MIN` = 0). Header `WWW-Authenticate: Bearer` nos 401.
- Reuso: `VerificarSituacaoDoTenant` já cobre escrita bloqueada e `PENDENTE`; `CANCELADA` e `PENDENTE` devem recusar também a leitura na API (acrescente esse caso dentro do `AutenticarChaveDeApi`, não no middleware do app).

- [ ] **Step 1: Testes (RED)** (rota de prova `GET /api/v1/empresa`-like: crie na Task 7; aqui use uma rota de teste registrada só no teste via `Route::middleware('api-v1')->get('api/v1/_ping', fn () => ['ok' => true])`, ou use `GET /api/v1/clientes`, que já existe): sem header → 401 `API_CHAVE_AUSENTE`; header sem `Bearer` ou chave sem `live_`/`test_` → 401 `API_CHAVE_INVALIDA`; chave inexistente, revogada, autor desativado e autor rebaixado para `VENDEDOR` → 401 `API_CHAVE_INVALIDA` com a mesma mensagem; chave `live_` e `test_` válidas passam e o ambiente fica no request; `ultimo_uso_em` atualizado na primeira chamada e **não** reescrito em chamadas dentro do mesmo minuto (`travelTo`); plano sem o módulo `API` → 403; conta `PENDENTE`/`CANCELADA` → 403 também no `GET`; `INADIMPLENTE`/`SUSPENSA` leem mas não escrevem (mesmo erro do app); **sessão do app (`spaComo`) em `/api/v1/clientes` → 401**; **chave de API em `/api/app/clientes` → 401**; o tenant do contexto é o da chave (listagem não vaza dados de outro tenant).
- [ ] **Step 2:** `php artisan test --filter=AutenticacaoPorChaveTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Busca por `ChaveDeApi::withoutTenantScope()->where('hash', GeradorDeChaveDeApi::hashDe($texto))->first()`; checagem de prefixo antes do banco; autor via `Usuario::withoutTenantScope()` se `Usuario` tiver escopo (confira); papéis aceitos `PROPRIETARIO`/`ADMIN`; `ultimo_uso_em` com `where(fn => ultimo_uso_em is null or < now()->subMinute())->update(...)` (uma query, sem race). O grupo registra os middlewares na ordem certa; remova `GarantirUsuarioAtivo` do caminho da chave (a checagem de ativo é do próprio middleware, sem logout/sessão).
- [ ] **Step 4:** `php artisan test --filter="AutenticacaoPorChaveTest|Customers|Catalog"` (as rotas v1 migram de grupo; ajuste os testes v1 existentes para autenticar por chave, mantendo as asserções) + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(identity): autenticação por chave de API no /api/v1`.

---

### Task 5: Limite de requisições

**Files:**
- Create: `Identity/Http/Middleware/LimitarRequisicoesDaApi.php`
- Modify: `bootstrap/app.php` (já no grupo `api-v1`)
- Test: `backend/tests/Feature/Identity/LimiteDaApiTest.php`

**Interfaces:**
- Produces: por chave, janela de 1 minuto, limite = `EntitlementService::limite($tenant, Recurso::ApiRequisicoesMin)`; headers `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (epoch do fim da janela) em todas as respostas do `/api/v1` autenticadas; 429 `API_LIMITE_EXCEDIDO` com `Retry-After` (segundos); `-1` não limita e não manda os headers de contagem (manda `X-RateLimit-Limit: ilimitado`? **não**: omita os três); `0` → 403 `API_NAO_CONTRATADA` (já tratado na Task 4: aqui só confira que o middleware não divide por zero).

- [ ] **Step 1: Testes (RED)** (plano com limite 3): três chamadas passam com `Remaining` 2, 1, 0; a quarta é 429 com `Retry-After` > 0 e `API_LIMITE_EXCEDIDO`; depois de `travelTo(+61 s)` volta a passar; **duas chaves do mesmo tenant não dividem o contador**; chave de outro tenant não é afetada; ilimitado não manda headers e não bloqueia 50 chamadas; limite 0 não passa; o app (`/api/app`) não é contado.
- [ ] **Step 2:** `php artisan test --filter=LimiteDaApiTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** com `Illuminate\Cache\RateLimiter` (`hit`/`tooManyAttempts`/`availableIn`), chave `api-v1:<chave_id>`. Use o cache `array` nos testes (já é o padrão do `phpunit.xml`).
- [ ] **Step 4:** `php artisan test --filter="LimiteDaApiTest|AutenticacaoPorChaveTest"` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(identity): limite de requisições da API por chave`.

---

### Task 6: Erros no formato RFC 9457

**Files:**
- Create: `Shared/Http/ErroApiV1.php` (monta o corpo), `Shared/Http/TratadorDeErrosDaApi.php`, `backend/config/api.php` (`erros_base_url`)
- Modify: `bootstrap/app.php` (`withExceptions`: antes dos handlers atuais, requisições `api/v1/*` vão para o tratador)
- Test: `backend/tests/Feature/Identity/ErrosDaApiTest.php`

**Interfaces:**
- Produces: para `$request->is('api/v1/*')`: `Content-Type: application/problem+json`, corpo `{ type, title, status, detail, codigo, errors?: [{campo, mensagem}], ...extras }`; `type = config('api.erros_base_url').'/'.strtolower(codigo)`; códigos: `ErroDeNegocio` (status/codigo/extras próprios, `title` pelo status), `ValidationException` → 422 `VALIDACAO`, `AuthenticationException` → 401 `API_CHAVE_INVALIDA`, `AuthorizationException`/`AcessoNegadoException` → 403 `ACESSO_NEGADO`, `ModelNotFoundException`/`NotFoundHttpException` → 404 `RECURSO_NAO_ENCONTRADO`, `MethodNotAllowedHttpException` → 405 `METODO_NAO_PERMITIDO`, `ThrottleRequestsException` → 429 `API_LIMITE_EXCEDIDO`, qualquer outro → 500 `ERRO_INTERNO` com `detail` fixo (sem mensagem da exceção; a exceção continua sendo reportada). O `/api/app` não muda.

- [ ] **Step 1: Testes (RED)**: um caso por tipo via `/api/v1/clientes` (validação: `POST` com corpo vazio → `errors[0].campo`; 404: `GET /api/v1/clientes/<uuid inexistente>`; 405: `DELETE /api/v1/clientes`; 401: sem chave; erro de negócio: algum já existente, por exemplo cliente com documento duplicado se houver, ou registre um `ErroDeNegocio` de teste na rota `_ping`); `Content-Type` correto; 500: rota de teste que lança `RuntimeException('segredo')` → resposta sem a palavra `segredo` e `ERRO_INTERNO`; rota `/api/app/...` com o mesmo erro continua `{message, codigo}` (regressão); URL desconhecida `/api/v1/nada` → 404 problem+json.
- [ ] **Step 2:** `php artisan test --filter=ErrosDaApiTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** (`$exceptions->render(fn (Throwable $e, Request $r) => $r->is('api/v1/*') ? $tratador->tratar($e, $r) : null)` registrado primeiro; devolve `null` para deixar o fluxo normal nas outras rotas). Cuide de que `shouldRenderJsonWhen` trate `api/v1/*` como JSON.
- [ ] **Step 4:** `php artisan test --filter="ErrosDaApiTest|Billing|Identity"` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(api): erros no formato RFC 9457 no /api/v1`.

---

### Task 7: Recursos v1 (cadastros, empresa, consumo) e paginação por cursor

**Files:**
- Create: `Shared/Http/PaginadorPorCursor.php`, `Customers/Http/Controllers/V1/ClientesController.php`, `Catalog/Http/Controllers/V1/{ProdutosController,ServicosController}.php`, `Fiscal/Http/Controllers/V1/EmpresaController.php`, `Platform/Http/Controllers/V1/ConsumoController.php`, Requests/Resources reaproveitados do app (não duplicar)
- Modify: `Customers/Http/routes-v1.php`, `Catalog/Http/routes-v1.php`, `Fiscal/Http/routes-v1.php` (novo, registrado no `FiscalServiceProvider` com `prefix('api/v1')`), `Platform/Http/routes-v1.php` (novo), providers
- Test: `backend/tests/Feature/Api/{ClientesV1Test,ProdutosV1Test,ServicosV1Test,EmpresaV1Test,ConsumoV1Test,PaginacaoV1Test}.php`

**Interfaces:**
- Produces:
  - `PaginadorPorCursor::paginar(Builder $query, Request $request, callable $mapa, ?array $buscaPorColunas): array{data, links, meta}`: ordena por `(updated_at, id)`, `limite` 1..100 (padrão 50; fora da faixa: 422 `VALIDACAO`), `cursor` opaco (`base64(json{u, i})`) e `proxima` = URL com o cursor do último item ou `null`; `?atualizado_desde=` (ISO-8601 inválido: 422); `?q=` aplica `LIKE` (escapando `%`/`_`) nas colunas dadas; cursor inválido: 422 `CURSOR_INVALIDO`. O `updated_at` repetido não pula nem repete itens (desempate por `id`).
  - Controllers V1: `index` (paginado), `show`, `store` (201), `update`; exatamente as chamadas do controller do app (mesmas Actions, `PoliticaDeCadastros`, Requests e Resources); sem `destroy` (o app não tem). `GET /empresa`: nome, razão social, CNPJ, regime, UF, ambiente fiscal e `ambiente_da_chave`, **sem** certificado, senha, CSC, token ou caminhos. `GET /consumo`: `data: [{ recurso, uso, limite }]` de `EntitlementService::consumo`.

- [ ] **Step 1: Testes (RED)** (autentique com uma chave real criada por `ChaveDeApi` + `GeradorDeChaveDeApi`; helper `comoApi(Usuario|Tenant)` num trait de teste `Tests\Fixtures\CenarioDeApi`, que monta tenant com plano com módulo `API`, limite ilimitado, admin e a chave):
  - para cada recurso: lista, mostra, cria (201) e atualiza com os mesmos campos do app; **cria cliente/produto com dado fiscal incompleto → o bloqueio/pendência do app vale igual** (por exemplo produto sem origem fica pendente, não ganha padrão; zero em `origem` é aceito); papel do autor sem permissão de escrita (usuário criador `LEITURA` não pode ter chave, então teste a `PoliticaDeCadastros` via criador `FISCAL`/`VENDEDOR` conforme as regras do app: espelhe o teste do app);
  - **isolamento (um por recurso):** dado do tenant B não aparece na lista, `show` e `update` dão 404 em `problem+json`;
  - `PaginacaoV1Test`: três páginas com `limite=2` cobrem todos os registros sem repetir (inclua dois registros com o mesmo `updated_at`); `limite=101` e `limite=0` → 422; cursor adulterado → 422 `CURSOR_INVALIDO`; `q` encontra por nome e escapa `%`; `atualizado_desde` filtra; `links.proxima` é `null` na última página;
  - `EmpresaV1Test`: campos esperados; **nenhuma chave** com `senha`, `pfx`, `csc`, `token` e nenhuma string do certificado na resposta serializada; `ambiente_da_chave` reflete `live`/`test`;
  - `ConsumoV1Test`: uso e limite batem com o `EntitlementService`.
- [ ] **Step 2:** `php artisan test --filter="V1Test|PaginacaoV1Test"`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Reutilize, não copie: se o controller do app já tem a lógica de `store`/`update`, extraia o comum para um método/Action e deixe os dois controllers finos (sem regra duplicada). Só o `index` difere (paginação).
- [ ] **Step 4:** `php artisan test --compact` (suíte inteira), phpstan, pint. Expected: verde.
- [ ] **Step 5:** commit `feat(api): clientes, produtos, serviços, empresa e consumo no /api/v1`.

---

### Task 8: Frontend (chaves de API)

**Files:**
- Create: `frontend/features/chaves-api/{types.ts,api.ts}`, `components/ChavesDeApi.tsx`, `components/ChavesDeApi.test.tsx`, `frontend/app/(app)/configuracoes/api/page.tsx`
- Modify: o menu/atalho de Configurações onde `configuracoes/usuarios` e `configuracoes/fiscal` são listados (Grep por `configuracoes/usuarios`)

**Interfaces:**
- Produces: `ChaveDeApi { id, nome, ambiente: 'LIVE'|'TEST', prefixo, ultimos4, usuario: {id, nome}, ultimo_uso_em, revogada_em, criada_em }`; `chavesApi.listar()`, `.criar({nome, ambiente})` → `{ ...ChaveDeApi, chave: string }`, `.revogar(id)`; `<ChavesDeApi />`.

- [ ] **Step 1: Testes (RED)** (moldes de `ListaDeFaturas`/`Cobranca.test.tsx`; mocks de `../api` e `useUsuario`): lista mostra nome, ambiente, `prefixo…ultimos4`, último uso e "Revogada"; criar chama a API com nome e ambiente, mostra a chave inteira **uma vez** com aviso "Copie agora: ela não será mostrada de novo" e botão **Copiar chave** (`navigator.clipboard.writeText`); ao fechar o aviso (ou recarregar a lista) a chave inteira não está mais no DOM; revogar pede confirmação e chama a API; erro da API em `role="alert"` (inclusive o de limite de 20 chaves e o de módulo fora do plano); papel sem permissão vê só o aviso e nenhuma chamada é feita; o nome da chave é obrigatório (mensagem de validação).
- [ ] **Step 2:** `npx vitest run features/chaves-api`. Expected: FAIL.
- [ ] **Step 3: Implementar** com `react-hook-form` + `zod` (nome 1 a 80), TanStack Query e os componentes de `components/ui`; **não** armazene a chave em `localStorage` nem em cache de query (estado local do componente, descartado ao fechar).
- [ ] **Step 4:** `npx vitest run`, `npx tsc --noEmit`, `npm run lint`, `npm run build`. Expected: verde (lint só com o warning antigo).
- [ ] **Step 5:** commit `feat(chaves-api): tela de chaves de API`.

---

### Task 9: Documentação e fechamento

**Files:**
- Modify: `TAREFAS.md` (F6a `[x]`, F6b/F6c pendentes, "Pendências da F6a"), `PROGRESSO.md` ("Fechamento da F6a", próxima tarefa F6b e contexto), memória `project_estado_atual.md` e `MEMORY.md`; criar `docs/api-publica.md` (rascunho do guia: autenticação, ambientes, erros, paginação, limites; o guia completo e o OpenAPI são da F6c).

- [ ] **Step 1:** Contexto da F6b no `PROGRESSO.md`: spec mestre seção 8 e 7, specs da F3a/F4a/F4b (emissão), `Fiscal/Application/Actions/{CriarRascunhoDeNota,IniciarEmissao,IniciarCancelamento,InutilizarNumeracao,GerarDanfe}.php`, `Fiscal/Http/Controllers` de notas, este plano e o spec da F6a; fazer brainstorming (arquitetural, atenção a `Idempotency-Key`, itens inline e `test_` = homologação).
- [ ] **Step 2:** `docs/api-publica.md` (autenticação por chave, `live_`/`test_`, formato de erro, paginação, limites e exemplo cURL de listar clientes).
- [ ] **Step 3:** Suíte completa (backend `php artisan test --compact`, phpstan, pint; frontend vitest, tsc, lint, build). Expected: verde.
- [ ] **Step 4:** Revisão final por subagente (modelo `fable`) com `review-package` (base = commit do plano); **antes, confira UTF-8 válido em todos os arquivos tocados**; corrigir Critical/Important em uma passada com teste RED→GREEN; Minors vão para "Pendências da F6a". Peça ao revisor atenção especial a: vazamento da chave, autenticação (autor rebaixado/desativado), isolamento, limite por chave, RFC 9457 e paginação.
- [ ] **Step 5:** Commit `docs: F6a concluída (API pública, fundação)`; apagar o workspace `.superpowers/sdd/2026-10-08-f6a-api-fundacao`; recomendar `/compact` ou `/clear`. Sem push.
