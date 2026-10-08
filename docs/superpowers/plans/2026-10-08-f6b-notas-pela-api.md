# F6b: notas pela API pública, plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (Native, escolhido pelo usuário nas fases anteriores) ou superpowers:subagent-driven-development. Steps usam checkbox (`- [ ]`).

**Goal:** emitir NF-e, NFC-e e NFS-e, consultar, baixar PDF/XML, cancelar e inutilizar pela `/api/v1`, com `Idempotency-Key`, itens e cliente inline (cadastro pelo SKU/código/documento) e a chave `test_`/`live_` coincidindo com o ambiente fiscal do emitente.

**Architecture:** uma Action orquestradora `EmitirNotaPelaApi` (módulo Fiscal) compõe `ResolvedorDeCadastrosInline` (acha ou cadastra pelas Actions do app, recusa divergência), `CriarRascunhoDeNota`, `IniciarEmissao::verificar` e `IniciarEmissao::executar`. A idempotência é um middleware genérico (`GarantirIdempotencia`) com tabela `idempotencias`. Os demais endpoints são controllers V1 finos sobre as Actions e Resources do app. Erros: o `TratadorDeErrosDaApi` da F6a.

**Tech Stack:** Laravel 12, PHPUnit, SQLite nos testes/PostgreSQL em staging, Pint, PHPStan.

**Spec:** `docs/superpowers/specs/2026-10-08-f6b-notas-pela-api-design.md` (autoridade; conflito no plano resolve-se pelo spec). Base: spec e plano da F6a (`docs/superpowers/specs/2026-10-08-f6a-api-fundacao-design.md`).

## Global Constraints

- Nunca chutar valor fiscal: dado ausente bloqueia; zero é válido (`=== null`, nunca `empty()`/`?? 0`; `origem: 0` aceito).
- Frontend e API chamam as mesmas Actions; nenhuma regra fiscal no controller nem no orquestrador (ele só compõe).
- Todo Model de tenant tem Global Scope; todo endpoint novo ganha teste de isolamento (nota de outro tenant: 404 `problem+json`).
- Schema só por migration; `declare(strict_types=1)` em todo PHP; testes importam toda classe usada; `status()` é método final do PHPUnit.
- Armadilhas da máquina: o shell come barras invertidas (heredoc, `sed`, `node -e`): gravar com Write/Edit; arquivos têm CRLF (Edit com âncora de uma linha); Python só com `io.open(..., encoding='utf-8', newline='')`; conferir UTF-8 válido nos arquivos tocados antes da revisão final; SQLite perde `WHERE` de índice parcial ao recriar tabela (aqui só se adicionam colunas/índices).
- Testes de emissão: `Queue::fake()` e o cenário `Tests\Fixtures\CenarioDeNota` (emitente em HOMOLOGAÇÃO) + `CenarioDeApi`; o `EmissorFake` (`app->instance(EmissorDeNfe::class, ...)`) onde o job rodar.
- Chave `test_` ⇔ emitente `HOMOLOGACAO`; chave `live_` ⇔ `PRODUCAO` (decisão do usuário). Divergência: 422 `API_AMBIENTE_DIVERGENTE`, antes de criar qualquer coisa.
- Sem push. Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs`.

## Review Focus

- Bloqueio fiscal na emissão inline não deixa rascunho nem cadastro criados (rollback completo).
- Idempotência: corrida de duas requisições com a mesma chave (insert único), reserva órfã (processo morreu), 5xx libera a chave, 4xx grava, mesma chave em duas empresas não colide, hash do corpo independe da ordem das chaves e inclui o caminho.
- `CADASTRO_DIVERGENTE` nunca altera o cadastro; campo omitido usa o cadastro; documento do cliente comparado normalizado.
- Ambiente da chave contra o do emitente nos dois sentidos, inclusive emitente sem configuração.
- Rascunho invisível pela API (lista, `show`, `pdf`, `xml`, cancelar); nota de outro tenant dá 404 em todos os endpoints.
- Autor da nota/evento é o criador da chave; `PoliticaDeNotas` do app continua valendo.
- Inline respeita o limite do plano (`LIMITE_DO_PLANO`) e não burla a numeração (nada de número consumido quando o bloqueio vem antes).

## File Structure

- `backend/database/migrations/2026_10_13_000001_add_codigo_to_servicos.php`, `2026_10_13_000002_create_idempotencias.php`
- `Catalog/...` (Servico `codigo`: Model, Requests, Resource), `Shared/Domain/Models/Idempotencia.php`, `Shared/Http/Middleware/GarantirIdempotencia.php`, `Shared/Domain/Exceptions/{IdempotencyKeyAusenteException,...}.php`, `Shared/Console/LimparIdempotenciasCommand.php`
- `Fiscal/Application/{EmitirNotaPelaApi.php (Actions/), ResolvedorDeCadastrosInline.php}`, `Fiscal/Domain/Exceptions/{ApiAmbienteDivergenteException,CadastroDivergenteException}.php`
- `Fiscal/Http/Requests/EmitirNotaApiRequest.php`, `Fiscal/Http/Controllers/V1/{NotasController,InutilizacoesController}.php`, `Fiscal/Http/routes-v1.php`
- Testes em `backend/tests/Feature/Api/` e `backend/tests/Feature/Shared/`.

---

### Task 1: `servicos.codigo` (app e API)

**Files:**
- Create: `backend/database/migrations/2026_10_13_000001_add_codigo_to_servicos.php`
- Modify: `Catalog/Domain/Models/Servico.php`, `Catalog/Http/Requests/CriarServicoRequest.php`, `Catalog/Http/Requests/AtualizarServicoRequest.php`, `Catalog/Http/Resources/ServicoResource.php`, `database/factories/ServicoFactory.php` (se precisar), `Catalog/Application/Actions/AtualizarServico.php` (confira como grava)
- Test: `backend/tests/Feature/Catalog/ServicosTest.php` (acrescentar casos) e `backend/tests/Feature/Api/ServicosV1Test.php` (acrescentar um)

**Interfaces:**
- Produces: coluna `servicos.codigo` (string 60, nula, único por `(tenant_id, codigo)`; NULLs não colidem em SQLite nem em PostgreSQL, então basta o índice único composto, sem `WHERE`), `Servico::$codigo` (`@property ?string`, em `$fillable`), campo `codigo` aceito/devolvido por `/api/app/servicos` e `/api/v1/servicos`.

- [ ] **Step 1: Testes (RED)** (no `ServicosTest` do app): cria serviço com `codigo` e o `data.codigo` volta; mesmo `codigo` no mesmo tenant dá 422 (`errors.codigo`); mesmo `codigo` em outro tenant é aceito; dois serviços sem `codigo` coexistem; atualizar com o próprio `codigo` não colide; `codigo` com 61 caracteres dá 422. No `ServicosV1Test`: `codigo` aparece na criação e na lista da v1.
- [ ] **Step 2:** `php artisan test --filter="ServicosTest|ServicosV1Test"`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Migration só `Schema::table('servicos', ...)` com `$table->string('codigo', 60)->nullable();` e `$table->unique(['tenant_id', 'codigo']);`. `codigo` entra em `regras()` (`['nullable','string','max:60']`), em `dados()` (`$this->filled('codigo') ? trim(...) : null`) e na regra `Rule::unique('servicos','codigo')->where('tenant_id', $this->user()?->tenant_id)` (no Atualizar, `->ignore($this->route('id'))`; confira o nome do parâmetro de rota). `ServicoResource` devolve `codigo`.
- [ ] **Step 4:** `php artisan test --filter="Servico|Catalog"` e phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(catalog): código opcional e único por empresa no serviço`.

---

### Task 2: Idempotência (tabela, middleware, limpeza)

**Files:**
- Create: `backend/database/migrations/2026_10_13_000002_create_idempotencias.php`, `Shared/Domain/Models/Idempotencia.php`, `Shared/Http/Middleware/GarantirIdempotencia.php`, `Shared/Domain/Exceptions/ErroDeIdempotenciaException.php`, `Shared/Console/LimparIdempotenciasCommand.php`, `backend/database/factories/IdempotenciaFactory.php`
- Modify: `Platform/Providers/PlatformServiceProvider.php` (registrar o comando, junto de `ExpirarTestesCommand`), `backend/routes/console.php` (agenda diária 00:50), `backend/bootstrap/app.php` (alias `idempotencia`)
- Test: `backend/tests/Feature/Shared/IdempotenciaTest.php`

**Interfaces:**
- Produces:
  - Tabela `idempotencias`: `id` uuid, `tenant_id`, `chave` string(255), `rota` string(160), `hash_do_corpo` string(64), `status_http` smallint nulo, `corpo` longText nulo, `tipo_do_conteudo` string(100) nulo, `nota_id` uuid nulo, `expira_em` timestamp, timestamps; único `(tenant_id, rota, chave)`; índice em `expira_em`.
  - `Idempotencia` Model de tenant (`BelongsToTenant`, `HasUuids`, `$fillable = []`, `expira_em` datetime).
  - Middleware com alias `idempotencia` e parâmetro: `->middleware('idempotencia:obrigatoria')` ou `'idempotencia'` (opcional). A controller pode registrar a nota criada com `$request->attributes->set('idempotencia_nota_id', $id)`.
  - `ErroDeIdempotenciaException` (`ErroDeNegocio`) com construtores nomeados: `ausente()` 400 `IDEMPOTENCY_KEY_AUSENTE`, `invalida()` 400 `IDEMPOTENCY_KEY_INVALIDA`, `corpoDiferente()` 409 `IDEMPOTENCIA_CORPO_DIFERENTE`, `emAndamento()` 409 `IDEMPOTENCIA_EM_ANDAMENTO` (com `headers()` `Retry-After: 1`).
  - Comando `api:limpar-idempotencias` apaga `expira_em < now()`.

- [ ] **Step 1: Testes (RED)** em `IdempotenciaTest` usando `CenarioDeApi`, com rotas de prova registradas no teste (`Route::middleware(['api','api-v1','idempotencia:obrigatoria'])->post('api/v1/_idem', fn (Request $r) => response()->json(['n' => ++$contador], 201))` e uma opcional `api/v1/_idem_opc`, uma que devolve 422 via `ErroDeNegocio`, uma que lança `RuntimeException`):
  - sem header na obrigatória: 400 `IDEMPOTENCY_KEY_AUSENTE`; na opcional passa e executa a cada chamada;
  - chave inválida (vazia, com espaço, > 255): 400 `IDEMPOTENCY_KEY_INVALIDA`;
  - mesma chave e mesmo corpo: segundo POST devolve o mesmo status e corpo, com `Idempotent-Replayed: true`, e o handler **não** roda de novo (contador);
  - mesma chave e corpo igual em ordem de chaves diferente: replay (hash canônico);
  - mesma chave e corpo diferente: 409 `IDEMPOTENCIA_CORPO_DIFERENTE`; mesma chave e **caminho** diferente (rota com `{id}`): 409/não replay conforme a regra do hash (o hash inclui o caminho concreto);
  - 422 de negócio fica gravado e é repetido igual; 500 libera a reserva (a segunda tentativa executa de novo);
  - reserva em andamento (insira por factory com `status_http` nulo e `created_at` agora): 409 `IDEMPOTENCIA_EM_ANDAMENTO` com `Retry-After`; reserva órfã (`created_at` há mais de 120 s): é descartada e a requisição executa;
  - registro expirado (`expira_em` no passado) é descartado e a requisição executa;
  - isolamento: a mesma chave em duas empresas não colide, e uma empresa não vê o registro da outra;
  - `api:limpar-idempotencias` apaga só os expirados.
- [ ] **Step 2:** `php artisan test --filter=IdempotenciaTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Contrato do middleware (`handle(Request $request, Closure $next, string $modo = 'opcional')`):
  1. `$chave = $request->header('Idempotency-Key')`; ausente: `obrigatoria` lança `ausente()`, senão `return $next($request)`. Formato `/^[\x21-\x7E]{1,255}$/` senão `invalida()`.
  2. `$rota = $request->method().' '.$request->route()->uri()`; `$hash = hash('sha256', $request->path().'|'.json_encode(ordenar($request->json()->all()), JSON_UNESCAPED_UNICODE))` onde `ordenar` aplica `ksort` recursivo só em arrays associativos (listas mantêm a ordem).
  3. Reservar: `try { Idempotencia::create(...)` via `forceFill` com `expira_em = now()->addDay()` e `status_http` nulo `} catch (UniqueConstraintViolationException)`: ler o existente com `lockForUpdate` dentro de transação curta; expirado ou (sem `status_http` e `created_at` < now−120 s): apagar e reservar de novo (uma vez); hash diferente: `corpoDiferente()`; sem `status_http`: `emAndamento()`; senão devolver `response($corpo, $status, ['Content-Type' => $tipo, 'Idempotent-Replayed' => 'true'])`.
  4. Executar `$next`. Resposta `< 500`: gravar `status_http`, `corpo` (`getContent()`), `tipo_do_conteudo`, `nota_id` (do atributo da request). Resposta `>= 500` ou exceção: apagar a reserva (e relançar a exceção).
  5. A reserva roda **antes** de qualquer transação do handler; o middleware não pode ficar dentro de uma `DB::transaction` do chamador.
  Alias em `bootstrap/app.php`: `$middleware->alias(['idempotencia' => GarantirIdempotencia::class]);`.
- [ ] **Step 4:** `php artisan test --filter="IdempotenciaTest|Identity|Api"` e phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(api): idempotência por Idempotency-Key`.

---

### Task 3: Resolvedor de cadastros inline

**Files:**
- Create: `Fiscal/Application/ResolvedorDeCadastrosInline.php`, `Fiscal/Domain/Exceptions/CadastroDivergenteException.php`
- Test: `backend/tests/Feature/Api/ResolvedorDeCadastrosInlineTest.php`

**Interfaces:**
- Consumes: `CriarCliente::executar(Usuario, array)`, `CriarProduto::executar(Usuario, array)`, `CriarServico::executar(Usuario, array)`, `Cpf::tentar`/`Cnpj::tentar` (veja o namespace em `CriarClienteRequest`), `ValidadorCamposFiscais`.
- Produces:
  - `ResolvedorDeCadastrosInline::cliente(Usuario $autor, array $dados): Cliente`, `::produto(Usuario $autor, array $dados): Produto`, `::servico(Usuario $autor, array $dados): Servico`. Procuram por documento normalizado (`cpf_cnpj`, só dígitos), `sku` e `codigo` (escopo do tenant pelo Global Scope). Não achou: cadastram pela Action do app (a validação de formato já foi feita no Request da Task 4). Achou: compara os campos **enviados** com o cadastro; igual ou omitido: devolve o cadastro; diferente: `CadastroDivergenteException`.
  - Campos comparados: cliente `tipo`, `nome`, `inscricao_estadual`, `ie_isento`, endereço (`logradouro`, `numero`, `bairro`, `cidade`, `uf`, `cep`, `codigo_ibge`); produto `nome`, `unidade`, `preco_centavos`, `gtin`, `ncm`, `cest`, `origem`, `tributacao_icms`; serviço `nome`, `preco_centavos`, `codigo_lc116`, `c_trib_nac`, `codigo_municipal`, `aliquota_iss`, `nbs`. Comparação com os mesmos normalizadores do cadastro (`origem` por `=== `, `0` ≠ `null`; `aliquota_iss` como decimal de 2 casas; `cep` só dígitos; `uf` maiúscula; `unidade` maiúscula).
  - `CadastroDivergenteException` (`ErroDeNegocio`, 422, `CADASTRO_DIVERGENTE`), `extras()` = `['errors' => [['campo' => ..., 'enviado' => ..., 'cadastro' => ...]]]` e mensagem "O cadastro de <sku/código/documento> já existe com dados diferentes. Atualize o cadastro pelos endpoints próprios ou envie os mesmos dados."

- [ ] **Step 1: Testes (RED):** para cada tipo: não existe → cadastra (e o limite do plano barra com `LimiteDoPlanoAtingidoException`); existe igual → devolve o mesmo id e não cria; existe e o corpo omite o campo → usa o cadastro; existe e preço/ncm/origem/nome difere → `CadastroDivergenteException` com o campo na lista e **cadastro inalterado**; produto novo sem `origem` fica com `origem` nula (pendente) e `origem = 0` é gravada como 0; cliente por CPF com máscara acha o cliente gravado só com dígitos; `tributacao_icms`/`cest` divergentes; múltiplos campos divergentes aparecem todos; cadastro de outro tenant com o mesmo SKU **não** é achado (cria o do tenant).
- [ ] **Step 2:** `php artisan test --filter=ResolvedorDeCadastrosInlineTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** (classe `final`, sem estado, injeta as três Actions).
- [ ] **Step 4:** teste + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(fiscal): resolver cadastros inline da API pelo SKU, código e documento`.

---

### Task 4: `POST /api/v1/notas` (orquestrador, request, controller)

**Files:**
- Create: `Fiscal/Application/Actions/EmitirNotaPelaApi.php`, `Fiscal/Domain/Exceptions/ApiAmbienteDivergenteException.php`, `Fiscal/Http/Requests/EmitirNotaApiRequest.php`, `Fiscal/Http/Controllers/V1/NotasController.php`
- Modify: `Fiscal/Http/routes-v1.php` (rota `POST notas` com `->middleware('idempotencia:obrigatoria')`)
- Test: `backend/tests/Feature/Api/EmitirNotaV1Test.php`

**Interfaces:**
- Consumes: Tasks 1 a 3; `CriarRascunhoDeNota::executar(array $dados, Usuario $autor)`, `IniciarEmissao::verificar(Nota, Usuario)` e `::executar(string $notaId, Usuario)`, `ExcluirRascunhoDeNota::executar(Nota)`, `AmbienteDaChave`, atributo da request `ambiente_da_chave`.
- Produces:
  - `EmitirNotaPelaApi::executar(Usuario $autor, AmbienteDaChave $ambiente, array $dados): Nota`. Passos: (1) `ApiAmbienteDivergenteException` se `Emitente::query()->first()` não existe ou o ambiente do emitente não casa (`TEST`⇔`HOMOLOGACAO`, `LIVE`⇔`PRODUCAO`); (2) `DB::transaction`: resolve cliente/itens inline para ids, monta o array do app (`cliente_id`, `itens[]` com `produto_id`/`servico_id`, `pagamentos`, `desconto_centavos`, `informacoes_complementares`, `consumidor_final`, `modelo`), `CriarRascunhoDeNota`, `IniciarEmissao::verificar`; qualquer exceção desfaz tudo; (3) depois do commit, `IniciarEmissao::executar`; se falhar com `ErroDeNegocio` (corrida rara), apaga o rascunho (`ExcluirRascunhoDeNota`) e relança.
  - `ApiAmbienteDivergenteException` (422, `API_AMBIENTE_DIVERGENTE`): "A chave é de <teste|produção>, mas a empresa emite em <homologação|produção>. Use uma chave `<test_|live_>` ou mude o ambiente fiscal da empresa."
  - `EmitirNotaApiRequest`: reaproveita `CriarClienteRequest::regras()`, `CriarProdutoRequest::regras()`, `CriarServicoRequest::regras()` para os blocos inline (prefixando `cliente.`, `itens.*.produto.`, `itens.*.servico.`) e as regras de `SalvarNotaRequest` para quantidade, pagamentos e desconto (extrair para método estático `regras(?string $tenantId, string $modelo)` em `SalvarNotaRequest` se não existir, sem mudar o comportamento do app). Cada item exige **exatamente um** de `produto_id`, `produto`, `servico_id`, `servico` conforme o modelo (`DPS` só serviço; `NFE`/`NFCE` só produto); `cliente` é `{id}` ou inline (obrigatório exceto `NFCE`); CPF/CNPJ do cliente inline validado como no app (sem a regra `unique`, porque o cliente pode existir); `dados()` devolve o array normalizado como o `dados()` das requests do app.
  - `NotasController::store`: chama a Action com `$request->attributes->get('ambiente_da_chave')`, registra `idempotencia_nota_id` e responde `202` `{ data: { id, modelo, status } }`.

- [ ] **Step 1: Testes (RED)** (`CenarioDeNota` + `CenarioDeApi` no mesmo teste: tenant com `Modulo::Api`, `FiscalNfe`, `FiscalNfce`, `FiscalNfse`; admin e chave do `CenarioDeApi`; para usar o emitente de `CenarioDeNota` num tenant com módulo API, monte o plano com os módulos e use `montarApi([], Modulo::FiscalNfe, ...)` e depois crie emitente/série como no `CenarioDeNota`; `Queue::fake()`):
  - NF-e por referência (`produto_id`, `cliente: {id}`): 202, `status PROCESSANDO`, `EmitirNotaJob` despachado, nota `numero` 1, ambiente `HOMOLOGACAO`, evento "Emissão iniciada" com o `usuario_id` do **criador da chave**;
  - NF-e com produto e cliente inline novos e completos: cadastra os dois e emite; **mesmo SKU de novo** (outra `Idempotency-Key`): reaproveita o produto, sem duplicar;
  - NFC-e sem cliente; NFS-e (`DPS`) com `servico` inline (`codigo`);
  - **sem `Idempotency-Key`**: 400 `IDEMPOTENCY_KEY_AUSENTE`; **replay** (mesma chave e corpo): mesma resposta, `Idempotent-Replayed`, **uma** nota e **um** job; mesma chave com corpo diferente: 409;
  - produto inline **sem origem**: 422 com o código estável do bloqueio do app (confira o código em `BloqueiosDeEmissaoTest`), **nenhum** rascunho e **nenhum** cadastro criados (`Nota::count() === 0`, `Produto::count()` igual ao anterior), número da série não consumido; `origem: 0` emite;
  - inline divergente: 422 `CADASTRO_DIVERGENTE`, nada criado e cadastro intacto;
  - limite de produtos do plano no inline: 422 `LIMITE_DO_PLANO`;
  - **ambiente:** chave `test_` com emitente em produção e chave `live_` com emitente em homologação: 422 `API_AMBIENTE_DIVERGENTE`, nada criado; emitente inexistente: bloqueio com código estável;
  - módulo do plano ausente (NFC-e sem `FiscalNfce`): 403 `MODULO_NAO_CONTRATADO`; conta suspensa: 403 (o grupo `api-v1` já barra escrita);
  - validação: item com `produto_id` **e** `produto`, item sem nenhum, `DPS` com `produto_id`, `quantidade` mal formada, `itens` vazio: 422 `VALIDACAO` com `errors[].campo`;
  - **isolamento:** `produto_id`/`cliente.id` de outro tenant: 422 `VALIDACAO` (não achado), nada criado.
- [ ] **Step 2:** `php artisan test --filter=EmitirNotaV1Test`. Expected: FAIL.
- [ ] **Step 3: Implementar** conforme as interfaces. O controller não tem regra: chama a Action e monta a resposta.
- [ ] **Step 4:** `php artisan test --filter="EmitirNotaV1Test|SalvarNota|Notas|Fiscal"` (a extração de regras não pode quebrar o app) e phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(api): emitir nota pelo /api/v1 com itens inline`.

---

### Task 5: Consultas de nota (lista, show, status, pdf, xml)

**Files:**
- Modify: `Fiscal/Http/Controllers/V1/NotasController.php`, `Fiscal/Http/routes-v1.php`
- Test: `backend/tests/Feature/Api/NotasConsultaV1Test.php`

**Interfaces:**
- Consumes: `PaginadorPorCursor::paginar(Builder, Request, callable, array)`, `NotaResource`, `NotaStatusResource`, `GerarDanfe`, as respostas de xml do `NotaController` do app.
- Produces: `GET /notas` (cursor; filtros `status`, `modelo`, `de`, `ate` validados como no app; **exclui `RASCUNHO`**), `GET /notas/{id}` (`NotaResource` com `itens`, `pagamentos`, `eventos`; rascunho: 404), `GET /notas/{id}/status`, `GET /notas/{id}/pdf`, `GET /notas/{id}/xml`, `GET /notas/{id}/xml-cancelamento`. Todas as rotas com `{id}` usam `whereUuid('id')` e a busca `Nota::query()->where('status', '!=', StatusNota::Rascunho->value)->findOrFail($id)`.

- [ ] **Step 1: Testes (RED):** lista paginada por cursor com notas de vários status (rascunho oculto), filtros `status`/`modelo`/`de`/`ate`, `atualizado_desde`, `limite`; `show` traz `cstat`/`motivo` de uma nota rejeitada (`status=REJEITADA`); `status` leve; `pdf` de nota autorizada (`application/pdf`, mesmo conteúdo da rota do app; use o gerador de teste dos testes `DanfeApiTest`); `xml` de autorizada e 422/erro do app de não autorizada (`NotaSemXmlException`) em `problem+json`; `xml-cancelamento`; rascunho: 404 em **todos** os endpoints; **isolamento por endpoint** (nota de outro tenant: 404 `problem+json`).
- [ ] **Step 2:** `php artisan test --filter=NotasConsultaV1Test`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Reutilize `NotaResource`/`NotaStatusResource`; extraia dos métodos `xml`/`xmlCancelamento`/`pdf` do `NotaController` do app o que for comum (por exemplo, um método estático ou uma Action `BaixarXmlDaNota`) para os dois controllers ficarem finos. A listagem `index` usa `->with('cliente')` e `PaginadorPorCursor` com busca desabilitada (`[]`; o filtro textual não existe para notas).
- [ ] **Step 4:** `php artisan test --filter="NotasConsultaV1Test|DanfeApiTest|Fiscal"` e phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(api): consultar notas, PDF e XML no /api/v1`.

---

### Task 6: Cancelar e inutilizar pela API

**Files:**
- Create: `Fiscal/Http/Controllers/V1/InutilizacoesController.php`
- Modify: `Fiscal/Http/Controllers/V1/NotasController.php` (`cancelar`), `Fiscal/Http/routes-v1.php`
- Test: `backend/tests/Feature/Api/CancelarEInutilizarV1Test.php`

**Interfaces:**
- Consumes: `IniciarCancelamento::executar(string $notaId, string $justificativa, Usuario)`, `CancelarNotaRequest`, `InutilizarNumeracao::executar(array, Usuario)`, `InutilizarNumeracaoRequest`, `InutilizacaoResource`.
- Produces: `POST /notas/{id}/cancelar` (`202`, `NotaStatusResource`; `idempotencia` opcional), `POST /notas/inutilizacoes` (`201`; `idempotencia` opcional), `GET /notas/inutilizacoes` (cursor). As rotas `notas/inutilizacoes` são registradas **antes** das de `notas/{id}`.

- [ ] **Step 1: Testes (RED):** cancelar nota autorizada devolve 202 e a nota vai a `CANCELANDO` (job `CancelarNotaJob` despachado); justificativa curta (14 caracteres) e longa (256): 422 `VALIDACAO`; nota não autorizada: erro de negócio do app em `problem+json`; rascunho: 404; `Idempotency-Key` repetida no cancelar devolve a mesma resposta e **um** job; inutilizar faixa válida: 201 com `InutilizacaoResource`; faixa inválida/justificativa curta: 422; `GET /notas/inutilizacoes` lista só do tenant, por cursor; `/notas/inutilizacoes` não é capturada por `notas/{id}`; isolamento (cancelar nota de outro tenant: 404 `problem+json`); o evento registra o criador da chave.
- [ ] **Step 2:** `php artisan test --filter=CancelarEInutilizarV1Test`. Expected: FAIL.
- [ ] **Step 3: Implementar** (controllers só chamam as Actions; `idempotencia` como middleware opcional nas duas rotas `POST`).
- [ ] **Step 4:** `php artisan test --filter="CancelarEInutilizarV1Test|CancelamentoApiTest|InutilizacoesApiTest"` e phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(api): cancelar notas e inutilizar numeração no /api/v1`.

---

### Task 7: Documentação, revisão final e fechamento

**Files:**
- Modify: `docs/api-publica.md` (seções Notas, Idempotência, Ambiente e exemplos cURL), `TAREFAS.md` (F6b `[x]`, "Pendências da F6b"), `PROGRESSO.md` ("Fechamento da F6b"; próxima tarefa F6c com contexto), memória `project_estado_atual.md` e `MEMORY.md`

- [ ] **Step 1:** `docs/api-publica.md`: emissão (`POST /notas` com exemplo cURL de NF-e com item inline, `Idempotency-Key`, resposta 202), ambiente (`test_`⇔homologação, `live_`⇔produção, `API_AMBIENTE_DIVERGENTE`), idempotência (regras e códigos), polling (`GET /notas/{id}/status`), cancelar, inutilizar, erros novos e `servicos.codigo`.
- [ ] **Step 2:** Suíte completa (backend `php artisan test --compact`, phpstan, pint; frontend `npx vitest run`, `npx tsc --noEmit`, `npm run lint`, `npm run build`). Expected: verde.
- [ ] **Step 3:** Revisão final por subagente (modelo `fable`) com `review-package` (base = commit do plano; confira **UTF-8 válido** em todos os arquivos tocados antes); entregar o Review Focus acima; corrigir Critical/Important numa passada com teste RED→GREEN; Minors vão para "Pendências da F6b".
- [ ] **Step 4:** Commit `docs: F6b concluída (notas pela API)`; apagar o workspace `.superpowers/sdd/2026-10-08-f6b-notas-pela-api`; contexto da F6c no `PROGRESSO.md` (spec mestre seção 8: webhooks de saída com `X-Signature`, retentativas até 24 h, log de entregas, reenvio manual; OpenAPI 3.1 gerado do código; guia do integrador; eventos `nota.autorizada|rejeitada|cancelada|erro|contingencia`); recomendar `/compact` ou `/clear`. Sem push.
