# F6c1: webhooks de saída, plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (Native, escolhido pelo usuário nas fases anteriores) ou superpowers:subagent-driven-development. Steps usam checkbox (`- [ ]`).

**Goal:** avisar o ERP, por webhook assinado com retentativas, log e reenvio manual, quando uma nota fica autorizada, rejeitada, cancelada, com erro ou em contingência; configurável pela API v1 e por uma tela do app.

**Architecture:** um observer em `NotaEvento` cria `webhook_entregas` (payload congelado) e despacha `EnviarWebhookJob` depois do commit. O envio (`EntregadorDeWebhook`) assina, valida a URL contra SSRF a cada tentativa, grava `webhook_tentativas` e reagenda na própria tabela; o comando `webhooks:reenviar-pendentes` (a cada minuto) retoma o que venceu. A gestão é feita por Actions chamadas por um controller usado pelas rotas `/api/v1/webhooks` e `/api/app/webhooks`.

**Tech Stack:** Laravel 12 (Http client/Guzzle, filas `database`, scheduler), PHPUnit, Next.js + React Query + react-hook-form + zod + Vitest.

**Spec:** `docs/superpowers/specs/2026-10-08-f6c1-webhooks-de-saida-design.md` (autoridade; conflito no plano resolve-se pelo spec).

## Global Constraints

- Segredos: o `segredo` do endpoint é criptografado (cast `encrypted`), aparece só na resposta da criação e **nunca** em listagem, log, `activity`, erro, payload de entrega ou cache do frontend.
- Frontend e API chamam as mesmas Actions; nenhuma regra no controller. Todo Model de tenant tem Global Scope e teste de isolamento; todo endpoint novo ganha teste de isolamento nas duas superfícies.
- Schema só por migration; `declare(strict_types=1)`; TypeScript strict sem `any`; testes importam toda classe usada; `status()` é método final do PHPUnit.
- SSRF: a URL é validada ao cadastrar e a cada envio (todos os IPs resolvidos), o envio conecta no IP validado e **não segue redirecionamentos**.
- Intervalos de retentativa depois de cada falha: `1 min, 5 min, 30 min, 2 h, 6 h, 12 h`; a 7ª falha encerra a entrega (`FALHOU`). 10 entregas esgotadas seguidas desativam o endpoint. Timeout de 10 s. Limite de 5 endpoints por empresa. Retenção de 30 dias.
- Armadilhas da máquina: o shell come barras invertidas (heredoc, `sed`, `node -e`, Python com `\` em string): gravar com Write/Edit, ou scripts Python em arquivo com strings `r"""..."""`; arquivos têm CRLF (Edit com âncora de uma linha); Python só com `io.open(..., encoding='utf-8', newline='')`; conferir UTF-8 válido nos arquivos tocados antes da revisão final; `$request->user()->tenant` precisa do usuário criado `->for($tenant)` nos testes.
- Testes: `QUEUE_CONNECTION=sync` no phpunit; use `Http::fake()` para o destino, `Bus::fake()`/`Queue::fake()` quando só interessa o despacho, `CenarioDeApi` (chave) e `CenarioDeNota` (nota). O resolvedor de DNS é injetável (`ResolvedorDeDns`) para os testes de SSRF.
- Sem push. Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs`.

## Review Focus

- O segredo e a assinatura: segredo nunca vaza; HMAC sobre `"<t>.<corpo>"` com o corpo **exato** enviado; `t` e assinatura recalculados a cada tentativa, `id`/corpo estáveis.
- SSRF: IPv6 (loopback `::1`, mapeado `::ffff:127.0.0.1`, ULA `fc00::/7`, link-local `fe80::/10`), `169.254.169.254`, `0.0.0.0`, IP em decimal/octal/hex, hostname que resolve para IP interno, URL com credenciais, porta incomum, DNS rebinding entre cadastro e envio, redirecionamento 30x.
- Idempotência do gatilho (mesmo `nota_evento_id` nunca duplica) e consistência transacional (rollback não deixa entrega; job só depois do commit).
- Concorrência do reenvio: o comando e o job não enviam a mesma entrega duas vezes; reserva por `UPDATE` condicional; entrega travada em "em envio" se o worker morre.
- Desativação automática, reativação, e o que acontece com entregas pendentes de endpoint desativado/excluído.
- Isolamento entre empresas nas duas superfícies, módulo API e papéis; `payload` sem XML e sem segredos.
- Retenção/limpeza não apaga entregas ainda pendentes.

## File Structure

- Migrations `2026_10_14_000001_create_webhooks.php` (3 tabelas).
- `Fiscal/Domain/Enums/{EstadoDaEntrega,EventoDeWebhook}.php`, `Fiscal/Domain/Models/{WebhookEndpoint,WebhookEntrega,WebhookTentativa}.php`, factories.
- `Fiscal/Application/Webhooks/{UrlDeWebhookSegura,ResolvedorDeDns,ResolvedorDeDnsDoSistema,AssinadorDeWebhook,MontadorDoPayload,RegistrarEntregasDeWebhook,EntregadorDeWebhook,PoliticaDeWebhooks}.php` e `Webhooks/Actions/{CriarWebhook,AtualizarWebhook,ExcluirWebhook,ListarWebhooks,ListarEntregasDeWebhook,ReenviarEntrega,EnviarTesteDeWebhook}.php`.
- `Fiscal/Application/Jobs/EnviarWebhookJob.php`, `Fiscal/Console/{ReenviarWebhooksPendentesCommand,LimparWebhooksCommand}.php`, `Fiscal/Infrastructure/ObservadorDeNotaEvento.php` (registro no `FiscalServiceProvider`).
- `Fiscal/Http/{Controllers/WebhooksController.php,Requests/SalvarWebhookRequest.php,Resources/{WebhookResource,WebhookEntregaResource}.php}`, rotas em `routes-app.php` e `routes-v1.php`.
- Frontend `features/webhooks/*`, `app/(app)/configuracoes/webhooks/page.tsx`, `nav-items.ts`.

---

### Task 1: Tabelas, enums e Models

**Files:**
- Create: `backend/database/migrations/2026_10_14_000001_create_webhooks.php`, `Fiscal/Domain/Enums/EstadoDaEntrega.php`, `Fiscal/Domain/Enums/EventoDeWebhook.php`, `Fiscal/Domain/Models/{WebhookEndpoint,WebhookEntrega,WebhookTentativa}.php`, `database/factories/{WebhookEndpointFactory,WebhookEntregaFactory}.php`
- Test: `backend/tests/Feature/Fiscal/WebhookModelosTest.php`

**Interfaces:**
- Produces:
  - `EventoDeWebhook` enum string (`NotaAutorizada='nota.autorizada'`, `NotaRejeitada='nota.rejeitada'`, `NotaCancelada='nota.cancelada'`, `NotaErro='nota.erro'`, `NotaContingencia='nota.contingencia'`, `Teste='webhook.teste'`) com `static function daNota(StatusNota $status): ?self` (AUTORIZADA, REJEITADA, CANCELADA, ERRO, CONTINGENCIA; os demais `null`) e `static function assinaveis(): list<string>` (os 5 de nota).
  - `EstadoDaEntrega` enum (`Pendente='PENDENTE'`, `Entregue='ENTREGUE'`, `Falhou='FALHOU'`).
  - `WebhookEndpoint` (uuid, `BelongsToTenant`, `$fillable=[]`, `$hidden=['segredo']`): `tenant_id`, `usuario_id`, `url`, `descricao`, `eventos` (cast array), `ambiente` (cast `AmbienteFiscal`), `segredo` (cast `encrypted`), `ativo` (bool), `desativado_em`, `motivo_desativacao`, `falhas_seguidas` (int); `assina(EventoDeWebhook): bool` (lista vazia = os 5 de nota; `Teste` sempre); relação `entregas()`.
  - `WebhookEntrega`: `webhook_endpoint_id`, `nota_evento_id` (nulo), `nota_id` (nulo), `evento` (string), `evento_id` (uuid), `payload` (cast array), `estado` (cast), `tentativas`, `proxima_tentativa_em`, `entregue_em`; relações `endpoint()`, `tentativasLog()` (`WebhookTentativa`, ordem por `numero`).
  - `WebhookTentativa`: `webhook_entrega_id`, `numero`, `status_http`, `duracao_ms`, `erro`, `resposta`, `created_at` (sem `updated_at`).
  - Tabelas conforme o spec, com FKs, único `(webhook_endpoint_id, nota_evento_id)` e índice `(estado, proxima_tentativa_em)`. `webhook_entregas.endpoint` com `cascadeOnDelete` (excluir o endpoint apaga o log).

- [ ] **Step 1: Testes (RED)**: `EventoDeWebhook::daNota` para os 8 status; `assina` (lista vazia, lista parcial, `Teste`); `segredo` criptografado no banco (o valor cru da coluna difere do texto) e fora de `toArray()`/`toJson()`; casts; único `(endpoint, nota_evento_id)` lança `QueryException`; **isolamento** por Global Scope dos três Models (tenant B não vê); excluir o endpoint apaga entregas e tentativas.
- [ ] **Step 2:** `php artisan test --filter=WebhookModelosTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** (molde: `ChaveDeApi`/`Idempotencia`; factories geram `segredo` `whsec_`+40 e `url` `https://exemplo.test/hook`).
- [ ] **Step 4:** teste + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(webhooks): tabelas e models de webhooks de saída`.

---

### Task 2: URL segura (SSRF)

**Files:**
- Create: `Fiscal/Application/Webhooks/{ResolvedorDeDns,ResolvedorDeDnsDoSistema,UrlDeWebhookSegura}.php`, `Fiscal/Domain/Exceptions/WebhookUrlInvalidaException.php`
- Test: `backend/tests/Unit/Fiscal/UrlDeWebhookSeguraTest.php`

**Interfaces:**
- Produces:
  - `interface ResolvedorDeDns { /** @return list<string> */ public function resolver(string $host): array; }`; `ResolvedorDeDnsDoSistema` usa `dns_get_record` A/AAAA e `gethostbynamel` (retorna lista de IPs; vazio = não resolve). Binding em `FiscalServiceProvider`.
  - `UrlDeWebhookSegura::validar(string $url): ValidacaoDeUrl` (readonly: `url`, `host`, `porta`, `ips`) — lança `WebhookUrlInvalidaException` (`ErroDeNegocio` 422 `WEBHOOK_URL_INVALIDA`, `extras()['errors'] = [['campo' => 'url', 'mensagem' => ...]]`).
  - Regras: esquema `https` (também `http` se `app()->environment(['local','testing'])`), sem usuário/senha, host presente, até 500 caracteres, porta 1 a 65535; host IP literal (inclusive decimal/octal/hex, `[::1]`) ou resolvido: **todos** os IPs têm de ser públicos — recusa `filter_var(..., FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` falhando, loopback, `0.0.0.0/8`, `100.64.0.0/10` (CGNAT), `169.254.0.0/16` (inclui metadados), IPv6 `::1`, `::`, `fc00::/7`, `fe80::/10`, `::ffff:0:0/96` mapeado (valida o IPv4 embutido), `64:ff9b::/96`; não resolve: recusa. Em `local`/`testing` o loopback `localhost`/`127.0.0.1` é aceito **somente** quando `http` for permitido.

- [ ] **Step 1: Testes (RED)** (resolvedor fake): `https://exemplo.test/x` com IP público passa e devolve os IPs; recusa: `http` em produção (`app()->detectEnvironment`), credenciais, IPs `10.0.0.1`, `172.16.0.1`, `192.168.1.1`, `127.0.0.1`, `169.254.169.254`, `100.64.0.1`, `0.0.0.0`, `::1`, `[fd00::1]`, `[fe80::1]`, `::ffff:127.0.0.1`, `2130706433` (decimal), `0x7f000001`, `017700000001`, host que resolve para IP interno, host que resolve para vários IPs com **um** interno, host que não resolve, URL > 500, porta 0 e 70000, texto que não é URL; em `testing`, `http://localhost` aceito.
- [ ] **Step 2:** `php artisan test --filter=UrlDeWebhookSeguraTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Normalize IP literal com `inet_pton`/`ip2long` e conversões de decimal/hex/octal antes do `filter_var`. Faixas extras por comparação de bytes (`inet_pton`).
- [ ] **Step 4:** teste + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(webhooks): validação de URL contra SSRF`.

---

### Task 3: Gestão dos endpoints (Actions e política)

**Files:**
- Create: `Fiscal/Application/Webhooks/PoliticaDeWebhooks.php`, `Fiscal/Application/Webhooks/Actions/{CriarWebhook,AtualizarWebhook,ExcluirWebhook,ListarWebhooks}.php`, `Fiscal/Domain/Exceptions/{WebhookLimiteAtingidoException,WebhookInativoException}.php`, `Fiscal/Application/Webhooks/ResultadoDoWebhook.php`
- Test: `backend/tests/Feature/Fiscal/GestaoDeWebhooksTest.php`

**Interfaces:**
- Consumes: Task 1 e 2, `EntitlementService::garantirModulo(Tenant, Modulo::Api)`.
- Produces:
  - `PoliticaDeWebhooks::garantirPodeGerenciar(Usuario)` (só `PROPRIETARIO`/`ADMIN`, `AcessoNegadoException`).
  - `CriarWebhook::executar(Usuario $autor, array $dados): ResultadoDoWebhook` (`modelo`, `segredo`); `dados` = `url`, `descricao?`, `eventos?` (lista), `ambiente`. Valida a URL (`UrlDeWebhookSegura`), eventos dentro de `EventoDeWebhook::assinaveis()` (`ValidationException` em `eventos`), módulo API, limite de 5 (`WebhookLimiteAtingidoException`, 422 `WEBHOOK_LIMITE_ATINGIDO`, com `lockForUpdate` no Tenant), segredo `whsec_`+40 alfanuméricos; `activity('webhooks')` sem segredo.
  - `AtualizarWebhook::executar(Usuario, string $id, array $dados): WebhookEndpoint` (url, descricao, eventos, ambiente, ativo; reativar zera `falhas_seguidas` e limpa `desativado_em`/`motivo_desativacao`; nunca altera o segredo); `ExcluirWebhook::executar(Usuario, string $id): void`; `ListarWebhooks::executar(Usuario): Collection`.
  - `WebhookInativoException` (409 `WEBHOOK_INATIVO`).

- [ ] **Step 1: Testes (RED)**: criar devolve modelo e segredo `whsec_[A-Za-z0-9]{40}` e o banco guarda criptografado; URL insegura recusada; eventos inválidos recusados; limite de 5 (o sexto falha; excluir libera); papéis `FISCAL`/`VENDEDOR`/`LEITURA` recusados; plano sem módulo API lança `ModuloNaoContratadoException`; atualizar muda os campos mas **não** o segredo; reativar zera `falhas_seguidas`; atualizar URL revalida; excluir apaga; listar traz só os da empresa, sem segredo; `activity('webhooks')` sem o segredo (procure o texto e o valor criptografado no `properties`); **isolamento** (endpoint de outro tenant: `ModelNotFoundException`).
- [ ] **Step 2:** `php artisan test --filter=GestaoDeWebhooksTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** no molde de `CriarChaveDeApi`/`RevogarChaveDeApi`.
- [ ] **Step 4:** teste + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(webhooks): criar, atualizar, excluir e listar endpoints`.

---

### Task 4: Envio, retentativas, limpeza e agenda

**Files:**
- Create: `Fiscal/Application/Webhooks/{AssinadorDeWebhook,EntregadorDeWebhook,DespachanteDeEntrega,DespachanteDeEntregaPorJob}.php`, `Fiscal/Application/Jobs/EnviarWebhookJob.php`, `Fiscal/Console/{ReenviarWebhooksPendentesCommand,LimparWebhooksCommand}.php`
- Modify: `Fiscal/Providers/FiscalServiceProvider.php` (comandos e binding do `DespachanteDeEntrega`), `backend/routes/console.php` (`webhooks:reenviar-pendentes` a cada minuto com `withoutOverlapping()->onOneServer()`, `webhooks:limpar` diário 00:55)
- Test: `backend/tests/Feature/Fiscal/EnvioDeWebhookTest.php`, `backend/tests/Unit/Fiscal/AssinadorDeWebhookTest.php`

**Interfaces:**
- Consumes: Tasks 1 e 2; `Illuminate\Support\Facades\Http`.
- Produces:
  - `AssinadorDeWebhook::cabecalho(string $segredo, int $t, string $corpo): string` → `t=<t>,v1=<hash_hmac('sha256', "{$t}.{$corpo}", $segredo)>`; `verificar(string $segredo, string $cabecalho, string $corpo, int $toleranciaSegundos = 300): bool` (tempo constante, usado nos testes e na documentação).
  - `EntregadorDeWebhook::enviar(string $entregaId): void`: carrega a entrega (sem Global Scope se o contexto estiver vazio: o job restaura o `TenantContext`); ignora se não é `PENDENTE`; descarta (estado `FALHOU`, `erro` explicativo, sem retentativa) se o endpoint está inativo/excluído, o plano não tem o módulo API ou o ambiente do endpoint ≠ `payload.ambiente`; valida a URL (`UrlDeWebhookSegura`) e conecta no IP validado (`withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$porta}:{$ip}"]], 'allow_redirects' => false])`); `corpo = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)` **uma vez** e enviado exatamente; cabeçalhos do spec; `timeout(10)`; grava `WebhookTentativa` (status, duração, erro, resposta truncada a 1024 bytes); `2xx` → `ENTREGUE`, `entregue_em`, `falhas_seguidas = 0`; senão `tentativas++` e `proxima_tentativa_em = now() + [60, 300, 1800, 7200, 21600, 43200][tentativas-1]` s, e na 7ª falha `FALHOU` (`proxima_tentativa_em = null`) com `falhas_seguidas++` e, em 10, desativa o endpoint (`motivo_desativacao = "10 entregas seguidas sem sucesso"`). A entrega é reservada antes do envio com `UPDATE ... WHERE estado='PENDENTE' AND proxima_tentativa_em <= now()` que muda `proxima_tentativa_em` para `now()+5 min` (trava curta: se o worker morrer, a retomada vem pelo comando).
  - `EnviarWebhookJob(string $entregaId, string $tenantId)` (`tries = 1`, `timeout = 30`, restaura o `TenantContext` como o `CancelarNotaJob`).
  - `ReenviarWebhooksPendentesCommand` (`webhooks:reenviar-pendentes`): para cada entrega `PENDENTE` com `proxima_tentativa_em <= now()` (sem escopo de tenant), despacha o job. `LimparWebhooksCommand` (`webhooks:limpar`): apaga entregas `ENTREGUE`/`FALHOU` com mais de 30 dias (e, por cascata, as tentativas); **nunca** apaga `PENDENTE`.
  - `DespachanteDeEntrega` (interface) e `DespachanteDeEntregaPorJob` (despacha o `EnviarWebhookJob`); o provider liga um ao outro.

- [ ] **Step 1: Testes (RED)** (`Http::fake`, `travelTo`): assinatura recalculável (`AssinadorDeWebhookTest`: formato, `verificar` aceita a correta, recusa corpo alterado, segredo errado e `t` fora da tolerância); entrega `2xx`: `ENTREGUE`, cabeçalhos (`X-Webhook-Id` = `evento_id`, `X-Webhook-Event`, `X-Signature` válida para o corpo **exato** enviado, `User-Agent`), uma tentativa no log; falhas `500`, `404`, timeout (`ConnectionException`) e erro de conexão agendam a próxima nos intervalos do spec (confira cada degrau com `travelTo`) e a 7ª falha encerra `FALHOU`; `X-Webhook-Id` e corpo idênticos em todas as tentativas, `t` e assinatura mudam; 10 entregas `FALHOU` seguidas desativam o endpoint e uma `ENTREGUE` zera `falhas_seguidas`; resposta truncada a 1024 bytes; **redirecionamento 301 não é seguido** (conta como falha e a segunda URL não é chamada); URL que passou a resolver para IP interno entre o cadastro e o envio (rebinding) é bloqueada sem chamar o destino; endpoint inativo, ambiente diferente e plano sem módulo descartam sem retentativa; o comando despacha só vencidas e não despacha duas vezes a mesma (reserva), retoma entrega travada; `webhooks:limpar` respeita os 30 dias e preserva `PENDENTE`; `Http::assertSentCount`.
- [ ] **Step 2:** `php artisan test --filter="EnvioDeWebhookTest|AssinadorDeWebhookTest"`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Reuso do `Http` com `Http::fake` funciona com `withOptions`; o `CURLOPT_RESOLVE` só vale com o handler curl (em teste o fake ignora).
- [ ] **Step 4:** testes + `Gatilho` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(webhooks): envio assinado com retentativas, limpeza e agenda`.

---

### Task 5: Gatilho: eventos de nota viram entregas

**Files:**
- Create: `Fiscal/Application/Webhooks/{MontadorDoPayload,RegistrarEntregasDeWebhook}.php`, `Fiscal/Infrastructure/ObservadorDeNotaEvento.php`
- Modify: `Fiscal/Providers/FiscalServiceProvider.php` (`NotaEvento::observe(ObservadorDeNotaEvento::class)`)
- Test: `backend/tests/Feature/Fiscal/GatilhoDeWebhookTest.php`

**Interfaces:**
- Consumes: Tasks 1, 3 e 4 (`DespachanteDeEntrega`, `EnviarWebhookJob`), `NotaResource` (`itens`, `pagamentos`, `eventos`, `cliente` carregados).
- Produces:
  - `MontadorDoPayload::paraNota(Nota $nota, EventoDeWebhook $evento, string $eventoId): array` — `{ id, tipo, criado_em, ambiente, data: { nota: <NotaResource resolvido> } }`, **sem** `xml_*`; `paraTeste(string $eventoId): array`.
  - `RegistrarEntregasDeWebhook::executar(NotaEvento $evento): int` — para cada `WebhookEndpoint` ativo, do ambiente da nota, que assina o evento (e com o módulo API no plano), cria `WebhookEntrega` (`estado PENDENTE`, `proxima_tentativa_em = now()`, `evento_id` novo `Str::uuid7()`, `nota_evento_id`, `payload`) e chama `DespachanteDeEntrega` com `DB::afterCommit`. Idempotente pelo único `(endpoint, nota_evento_id)` (`insertOrIgnore` ou captura de `UniqueConstraintViolationException`).
  - `ObservadorDeNotaEvento::created(NotaEvento)` chama a Action; uma falha ao registrar **não** pode derrubar a mudança de status da nota (captura `Throwable`, `report($e)`).

- [ ] **Step 1: Testes (RED)** (`CenarioDeNota`; `Bus::fake()`): cada status (`AUTORIZADA`, `REJEITADA`, `CANCELADA`, `ERRO`, `CONTINGENCIA`) gera a entrega com o `tipo` certo; `PROCESSANDO`, `CANCELANDO`, `RASCUNHO` não geram; endpoint inativo, de outro ambiente (`HOMOLOGACAO` vs nota `PRODUCAO`), que não assina o evento, ou de empresa sem módulo API não recebe; dois endpoints elegíveis recebem uma entrega cada; o mesmo `NotaEvento` processado duas vezes não duplica; o payload tem `data.nota` com itens e **sem** `xml_autorizado`/`xml_enviado`/`xml_cancelamento` e sem o texto do segredo; o `criado_em` e o `ambiente` batem; `DB::transaction` com rollback não deixa entrega; o job só é despachado depois do commit (use `DB::transaction` no teste e confira `Bus::assertNothingDispatched()` dentro dela); falha ao registrar (simule uma exceção no montador) não impede `NotaEvento::registrar`; **isolamento** (evento de nota da empresa A não gera entrega para endpoint da B).
- [ ] **Step 2:** `php artisan test --filter=GatilhoDeWebhookTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.** O ambiente da nota vem de `$nota->ambiente`; nulo (ainda sem ambiente) não notifica. Carregue `Nota::query()->with(['cliente','itens','pagamentos','eventos'])->find(...)` no montador.
- [ ] **Step 4:** `php artisan test --filter="GatilhoDeWebhookTest|Fiscal"` (o observer não pode quebrar os fluxos de nota) e phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(webhooks): gerar entregas a partir dos eventos de nota`.

---

### Task 6: Entregas: listar, reenviar e teste

**Files:**
- Create: `Fiscal/Application/Webhooks/Actions/{ListarEntregasDeWebhook,ReenviarEntrega,EnviarTesteDeWebhook}.php`
- Test: `backend/tests/Feature/Fiscal/EntregasDeWebhookTest.php`

**Interfaces:**
- Consumes: Tasks 1, 3, 4 e 5.
- Produces:
  - `ListarEntregasDeWebhook::executar(Usuario, string $webhookId, Request $request, PaginadorPorCursor): array` — usa o `PaginadorPorCursor` da F6a (filtro `estado` opcional, validado), ordenado como o paginador (por `updated_at`, `id`), e `with('tentativasLog')`; devolve `{data, links, meta}` (a Action recebe o paginador; o controller só repassa).
  - `ReenviarEntrega::executar(Usuario, string $entregaId): WebhookEntrega`: endpoint inativo → `WebhookInativoException`; volta `estado=PENDENTE`, `proxima_tentativa_em=now()`, **mantém `evento_id` e payload**, não zera `tentativas` (continua o histórico, mas dá mais uma rodada de até 7 tentativas: grave `tentativas_da_rodada` ou reinicie o contador de degraus em `rodada_inicio`); despacha o job; `activity('webhooks')`.
  - `EnviarTesteDeWebhook::executar(Usuario, string $webhookId): WebhookEntrega`: cria entrega com `evento = webhook.teste`, `nota_id` nulo, payload de `paraTeste`, despacha e (com fila `sync`) o resultado já aparece; endpoint inativo → `WebhookInativoException`.

- [ ] **Step 1: Testes (RED)**: lista paginada por cursor, filtro `estado`, traz o log de tentativas e não traz o payload; reenviar uma `FALHOU` volta a `PENDENTE`, mantém o `evento_id` e envia com o mesmo `X-Webhook-Id`; reenviar `ENTREGUE` é permitido (reentrega manual); endpoint inativo → `WebhookInativoException`; teste envia `webhook.teste` com corpo de teste e assinatura válida; papéis (`VENDEDOR` recusado); **isolamento** (entrega e endpoint de outro tenant: `ModelNotFoundException`); `activity` sem segredo.
- [ ] **Step 2:** `php artisan test --filter=EntregasDeWebhookTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Para a "nova rodada" do reenvio manual, acrescente a coluna `rodada` (int, padrão 0) a `webhook_entregas` **na migration da Task 1** se ainda não estiver (ou nova migration `2026_10_14_000002`); os intervalos usam `tentativas - rodada_inicio` onde `rodada_inicio` é o número de tentativas no começo da rodada (coluna `tentativas_na_rodada`); escolha uma coluna e registre o ruling.
- [ ] **Step 4:** teste + `EnvioDeWebhookTest` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(webhooks): listar entregas, reenviar e enviar teste`.

---

### Task 7: HTTP (API v1 e app)

**Files:**
- Create: `Fiscal/Http/Controllers/WebhooksController.php`, `Fiscal/Http/Requests/SalvarWebhookRequest.php`, `Fiscal/Http/Resources/{WebhookResource,WebhookEntregaResource}.php`
- Modify: `Fiscal/Http/routes-app.php`, `Fiscal/Http/routes-v1.php`
- Test: `backend/tests/Feature/Fiscal/WebhooksApiTest.php` (app) e `backend/tests/Feature/Api/WebhooksV1Test.php`

**Interfaces:**
- Consumes: Tasks 3 e 6.
- Produces: `GET/POST /webhooks`, `GET/PUT/DELETE /webhooks/{id}`, `GET /webhooks/{id}/entregas`, `GET /webhooks/entregas/{id}` (com payload), `POST /webhooks/{id}/testar`, `POST /webhooks/entregas/{id}/reenviar` em `/api/app` (nomes `app.webhooks.*`, grupo `empresa`) e em `/api/v1` (grupo `api-v1`; `POST` com `->middleware('idempotencia')`); `webhooks/entregas/*` registradas **antes** de `webhooks/{id}` e `{id}` com `whereUuid`. `WebhookResource`: `id`, `url`, `descricao`, `eventos`, `ambiente`, `ativo`, `desativado_em`, `motivo_desativacao`, `segredo_final` (últimos 4) e, só na criação, `segredo`. `SalvarWebhookRequest`: `url` (required string max 500), `descricao` (nullable max 80), `eventos` (array de `EventoDeWebhook::assinaveis()`), `ambiente` (`HOMOLOGACAO`|`PRODUCAO`), `ativo` (boolean, só no PUT). Mesmas Actions nas duas superfícies; o autor é `$request->user()` (na v1, o criador da chave).

- [ ] **Step 1: Testes (RED)** nas duas superfícies: criar `201` com o segredo uma vez (a listagem seguinte e o `GET` não o trazem; procure o texto e o valor cru da coluna na resposta inteira), validação `422` (URL insegura devolve `WEBHOOK_URL_INVALIDA`/`errors[]`, evento inválido, ambiente inválido), atualizar, excluir `204`, limite de 5, `403` por papel em cada verbo (app), módulo fora do plano, listar entregas por cursor, `GET` de entrega com payload, testar, reenviar, endpoint inativo `409 WEBHOOK_INATIVO`; **isolamento em cada rota** (endpoint e entrega de outro tenant: `404`; na v1 em `problem+json`); a v1 recusa sessão do app e o app recusa chave; idempotência opcional no `POST`.
- [ ] **Step 2:** `php artisan test --filter="WebhooksApiTest|WebhooksV1Test"`. Expected: FAIL.
- [ ] **Step 3: Implementar** (controller fino; um só controller para as duas superfícies).
- [ ] **Step 4:** testes + `Fiscal|Api` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(webhooks): API v1 e API do app de webhooks`.

---

### Task 8: Frontend (tela de webhooks)

**Files:**
- Create: `frontend/features/webhooks/{types.ts,schemas.ts,api.ts}`, `components/{Webhooks.tsx,EntregasDoWebhook.tsx,Webhooks.test.tsx}`, `frontend/app/(app)/configuracoes/webhooks/page.tsx`
- Modify: `frontend/components/layout/nav-items.ts` (item "Webhooks", ícone `Webhook` ou `Bell`, `PROPRIETARIO`/`ADMIN`), `frontend/components/layout/AppShell.test.tsx` se listar itens

**Interfaces:**
- Produces: tipos `Webhook`, `WebhookCriado` (`segredo`), `Entrega` (+ `TentativaDeEntrega`), `webhooksApi.{listar,criar,atualizar,excluir,entregas,testar,reenviar}` sobre `/api/app/webhooks`; `<Webhooks />` guardado por papel (como `ChavesDeApi`: sem permissão, só o aviso e nenhuma chamada).

- [ ] **Step 1: Testes (RED)** (moldes de `ChavesDeApi.test.tsx`): lista mostra URL, ambiente, eventos, estado e o motivo se desativado; criar chama a API e mostra o segredo **uma vez** com aviso e **Copiar** (`navigator.clipboard.writeText`), que some ao fechar e não vai a `localStorage`/`sessionStorage`; URL obrigatória (mensagem), ambiente e eventos; ativar/desativar chama `atualizar`; excluir pede confirmação; **Enviar teste** chama `testar` e mostra o resultado; abrir as entregas lista evento, estado, tentativas e próxima tentativa, expande as tentativas (status, erro) e **Reenviar** chama a API; erro da API (`WEBHOOK_URL_INVALIDA`, `WEBHOOK_LIMITE_ATINGIDO`, módulo fora do plano) em `role="alert"`; papel sem permissão não chama a API.
- [ ] **Step 2:** `npx vitest run features/webhooks`. Expected: FAIL.
- [ ] **Step 3: Implementar** (react-hook-form + zod, TanStack Query, `components/ui`; a resposta da criação fica em estado local e `criar.reset()`, como em `ChavesDeApi`).
- [ ] **Step 4:** `npx vitest run`, `npx tsc --noEmit`, `npm run lint`, `npm run build`. Expected: verde (lint só com o warning antigo).
- [ ] **Step 5:** commit `feat(webhooks): tela de webhooks`.

---

### Task 9: Documentação, revisão final e fechamento

**Files:**
- Modify: `docs/api-publica.md` (seção Webhooks: eventos, payload, verificação da assinatura em PHP/JS/cURL, retentativas, deduplicação, IPs, endpoints), `TAREFAS.md` (F6c1 `[x]`, F6c2 pendente, "Pendências da F6c1"), `PROGRESSO.md`, memória `project_estado_atual.md` e `MEMORY.md`

- [ ] **Step 1:** `docs/api-publica.md`: exemplo de verificação da assinatura (tolerância de 5 min, tempo constante), lista de eventos, tabela de retentativas, `at-least-once`, `X-Webhook-Id` para deduplicar, ambiente por endpoint, SSRF/`https`, desativação automática, endpoints da API.
- [ ] **Step 2:** Suíte completa (backend `php artisan test --compact`, phpstan, pint; frontend vitest, tsc, lint, build). Expected: verde.
- [ ] **Step 3:** Revisão final por subagente (modelo `fable`) com `review-package` (base = commit do plano; conferir **UTF-8 válido** antes); entregar o Review Focus; corrigir Critical/Important numa passada com teste RED→GREEN; Minors vão para "Pendências da F6c1".
- [ ] **Step 4:** Commit `docs: F6c1 concluída (webhooks de saída)`; apagar o workspace `.superpowers/sdd/2026-10-08-f6c1-webhooks-de-saida`; contexto da F6c2 no `PROGRESSO.md` (OpenAPI 3.1 e guia: spec mestre seção 8, `docs/api-publica.md`, rotas `api/v1`, decisão Scramble vs OpenAPI escrito à mão); recomendar `/compact` ou `/clear`. Sem push.
