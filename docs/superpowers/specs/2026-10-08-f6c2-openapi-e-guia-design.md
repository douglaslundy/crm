# F6c2: OpenAPI 3.1 e guia do integrador (design)

Fase: F6 (API pública), sub-projeto c2. Spec mestre: `2026-09-25-plataforma-fiscal-crm-design.md`, seção 8.

## Objetivo

Um integrador externo emite uma nota em homologação **só pela documentação**. Entregáveis: contrato OpenAPI 3.1 das 33 rotas de `/api/v1`, guia do integrador (cURL, PHP, JS) e página pública `/docs/api` no frontend.

## Decisões (do usuário)

- OpenAPI **escrito à mão** em YAML, com teste de cobertura de rotas. Sem Scramble/Scribe: a validação mora nas Actions e em `ValidadorDeWebhook`, não em FormRequests, então um gerador inferiria schemas vazios.
- Publicação: arquivos em `docs/api/` mais página `/docs/api` no frontend com visualizador.

## Arquivos

- `docs/api/openapi.yaml`: OpenAPI 3.1.
  - `paths`: as 33 rotas de `api/v1` (clientes, produtos, serviços, empresa, consumo, notas, inutilizações, webhooks, entregas).
  - `components.schemas`: `Cliente`, `Produto`, `Servico`, `Empresa`, `Consumo`, `Nota`, `Inutilizacao`, `Webhook`, `WebhookComSegredo`, `Entrega`, `Problem` (RFC 9457, com `errors[]` e `code`), `PaginaDeCursor`.
  - `components.securitySchemes`: `bearerAuth` (chave `live_` ou `test_`).
  - `components.parameters/headers`: `Idempotency-Key`, `X-RateLimit-*`, `Retry-After`.
  - Respostas de erro padrão (`application/problem+json`) com os códigos enumerados.
  - `webhooks` (campo do 3.1): um por evento `nota.*` e `webhook.teste`, com `X-Signature`, `X-Webhook-Id`, `X-Webhook-Event`.
  - Exemplos anônimos (sem dado real) em requisições e respostas.
- `docs/api/guia-do-integrador.md`: nasce do `docs/api-publica.md` (movido com `git mv`, referências atualizadas) e ganha o passo a passo: criar a chave `test_`, cadastrar cliente e produto, emitir com `Idempotency-Key`, consultar até o estado final, baixar PDF/XML, receber o webhook e verificar a assinatura, tratar erros e limites. Exemplos em cURL, PHP e JS.
- Frontend: rota pública `app/docs/api/page.tsx` (fora de `(app)`, sem login). Renderiza o Redoc (pacote npm, carregado sob demanda) com o YAML copiado de `docs/api/openapi.yaml` para `public/` por script de build (`prebuild`/`predev`). Links para baixar o YAML e para o guia.

## Testes

1. **Cobertura** (PHPUnit, `tests/Feature/Api/OpenApiTest.php`): lê o YAML com `symfony/yaml`; cada rota `api/v1` de `Route::getRoutes()` (método + caminho, `{id}` normalizado) está em `paths` e vice-versa; cada `{param}` do caminho está declarado.
2. **Formato**: `openapi` começa com `3.1`; todo `$ref` interno resolve; `operationId` único e presente; toda operação tem `security` (ou `security: []` explícito, só se pública) e resposta 401; toda operação com corpo declara o `requestBody`.
3. **Conformidade**: respostas reais dos testes da v1 (clientes, produtos, serviços, nota, webhook, erro 422/404) são validadas contra os schemas do YAML com `justinrainbow/json-schema` (já presente no vendor; declarar em `require-dev`). Os schemas dos componentes são escritos num subconjunto compatível entre o 3.1 e o validador (sem recursos exclusivos do 2020-12). Divergência entre contrato e código se corrige no YAML; só vira mudança de código se for bug, com ledger.
4. **Guia**: blocos ```php e ```js do guia passam em `php -l` e `node --check` (teste que extrai os blocos; cURL fica sem checagem).
5. **Frontend** (Vitest): a página renderiza o título e o contêiner do visualizador, com o Redoc mockado.

## Erros e bordas

- Rota nova sem documentação quebra o teste de cobertura. É o mecanismo que impede a deriva.
- Exemplos nunca trazem CPF/CNPJ reais; usar números inválidos de forma explícita ou fictícios sinalizados.
- A documentação não promete o que o código não faz: cada resposta documentada tem origem num teste existente ou num novo.

## Fora do escopo

SDKs gerados, "try it" com chaves reais, versionamento da documentação, mudanças de comportamento da API. Publicação em domínio próprio fica para a F8.

## Riscos

- O validador pode não cobrir toda a sintaxe do 2020-12: mitigado pelo subconjunto acima.
- O Redoc é pesado: carregado com `dynamic import`, sem peso nas telas do app.
- O YAML é grande: dividir `components` em seções claras e manter os exemplos curtos.
