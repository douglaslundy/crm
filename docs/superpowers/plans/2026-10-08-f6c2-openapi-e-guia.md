# F6c2: OpenAPI 3.1 e guia do integrador, plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar o contrato OpenAPI 3.1 das 33 rotas de `/api/v1`, o guia do integrador e a página pública `/docs/api`, com testes que impedem o contrato de divergir do código.

**Architecture:** `docs/api/openapi.yaml` escrito à mão. Um teste PHPUnit compara o YAML com `Route::getRoutes()` e valida o formato. Um helper de teste valida respostas reais da v1 contra os schemas do YAML (`justinrainbow/json-schema`, `$ref` resolvido pelo próprio documento). O frontend copia o YAML para `public/` no build e renderiza o Redoc sob demanda numa rota pública.

**Tech Stack:** Laravel 12 + PHPUnit, `symfony/yaml` e `justinrainbow/json-schema` (hoje transitivos; passam a `require-dev`/`require` explícitos), Next.js + Vitest, Redoc (npm).

**Spec:** `docs/superpowers/specs/2026-10-08-f6c2-openapi-e-guia-design.md`

## Global Constraints

- pt-BR em tudo; `declare(strict_types=1)` em todo PHP; TypeScript strict, sem `any`.
- Nenhum dado real em exemplos; CPF/CNPJ de exemplo inválidos de propósito ou claramente fictícios.
- Não mudar comportamento da API. Divergência contrato×código: corrigir o YAML; só mexer no código se for bug, com teste e registro.
- Schemas no subconjunto compatível com o validador: `type` (inclusive lista `["string","null"]`), `properties`, `required`, `items`, `enum`, `format`, `$ref` para `#/components/schemas/*`, `additionalProperties`. Nada exclusivo do 2020-12 (`prefixItems`, `unevaluatedProperties`, `$dynamicRef`).
- Todo schema de **resposta** usa `additionalProperties: false` e lista em `required` toda chave que o Resource sempre devolve. Isso é o que faz o teste de conformidade pegar divergências.
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs`. Sem push.
- Rodar testes do backend com `cd backend && php artisan test --filter=...`; do frontend com `cd frontend && npx vitest run ...`.

## Review Focus

- Rota v1 nova ou removida sem o YAML acompanhar (cobertura nos dois sentidos, método + caminho).
- Schema de resposta com chave a mais ou a menos que o Resource real (conformidade com `additionalProperties: false`).
- Erro real (422 `VALIDACAO` com `errors[]`, 404, 401, 429, 405, 409) diferente do que o YAML descreve.
- Segredo do webhook (`whsec_…`) aparecendo em schema que não seja o da criação.
- Exemplo do guia com sintaxe quebrada ou que não bate com o contrato (cabeçalhos, `Idempotency-Key`, assinatura).

---

## Mapa de arquivos

- Criar `docs/api/openapi.yaml`: contrato.
- Criar `docs/api/guia-do-integrador.md` (via `git mv docs/api-publica.md`).
- Criar `backend/tests/Support/ContratoOpenApi.php`: carrega o YAML, valida resposta contra schema.
- Criar `backend/tests/Feature/Api/OpenApiTest.php`: cobertura e formato.
- Criar `backend/tests/Feature/Api/ContratoV1Test.php`: conformidade de respostas reais (cadastros, notas, webhooks, erros).
- Criar `backend/tests/Feature/Api/GuiaDoIntegradorTest.php`: sintaxe dos blocos PHP/JS.
- Modificar `backend/composer.json` (`symfony/yaml` em `require-dev`, `justinrainbow/json-schema` em `require-dev`).
- Criar `frontend/app/(publico)/docs/api/page.tsx` e `frontend/features/docs-api/DocumentacaoDaApi.tsx` (+ `.test.tsx`).
- Criar `frontend/scripts/copiar-openapi.mjs`; modificar `frontend/package.json` (`predev`, `prebuild`, dependência `redoc`) e `frontend/.gitignore` (`public/openapi.yaml`).
- Modificar `PROGRESSO.md`, `TAREFAS.md`, memória.

---

### Task 1: Dependências, esqueleto do YAML, helper e testes de cobertura/formato

**Files:**
- Modify: `backend/composer.json`
- Create: `docs/api/openapi.yaml` (esqueleto)
- Create: `backend/tests/Support/ContratoOpenApi.php`
- Test: `backend/tests/Feature/Api/OpenApiTest.php`

**Interfaces:**
- Produces: `Tests\Support\ContratoOpenApi::carregar(): array` (documento como array, cacheado); `::operacoes(): array<string, array{metodo:string,caminho:string,op:array}>` (chave `"GET /api/v1/clientes/{id}"`); `::validar(string $schema, mixed $dados): list<string>` (lista de erros, vazia se válido; `$schema` = nome em `components.schemas`).

- [ ] **Step 1: Declarar dependências**

Run: `cd backend && composer require --dev symfony/yaml justinrainbow/json-schema --no-interaction`
Expected: ambas aparecem em `require-dev`; `composer.lock` atualizado sem remover pacotes.

- [ ] **Step 2: Escrever o esqueleto do YAML**

Criar `docs/api/openapi.yaml` com: `openapi: 3.1.0`; `info` (title "API da Plataforma Fiscal", version "1.0.0", description curta com link para o guia); `servers` (`https://api.exemplo.com.br` marcado como exemplo); `security: [{bearerAuth: []}]`; `tags` (Cadastros, Notas, Webhooks, Conta); `paths: {}`; `components` com `securitySchemes.bearerAuth` (http bearer, descrição "Chave `live_…` (produção) ou `test_…` (homologação)"), `parameters` (`IdempotencyKey` header obrigatório 1..255 chars, `Cursor` e `Limite` de query, `IdPath`), `headers` (`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After`), `schemas.Problem` (campos `type`, `title`, `status`, `detail`, `codigo`, `errors` (lista de `{campo, mensagem}`) com os nomes exatos lidos de `backend/app/Modules/Shared` / `TratadorDeErrosDaApi`) e `schemas.PaginaDeCursor` (`next_cursor`, `has_more` ou os nomes reais lidos de `PaginacaoV1Test`/`CursorPaginator`), e `responses` reutilizáveis: `NaoAutenticado` (401), `NaoEncontrado` (404), `Validacao` (422), `LimiteExcedido` (429), `MetodoNaoPermitido` (405), todas `application/problem+json` com `$ref: Problem` e a lista de `codigo` possíveis na descrição.

Confirmar cada nome de campo com `Grep` em `TratadorDeErrosDaApi` antes de escrever; o teste de conformidade da Task 2 falha se estiver errado.

- [ ] **Step 3: Escrever o helper**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

final class ContratoOpenApi
{
    private static ?array $documento = null;

    public static function carregar(): array
    {
        return self::$documento ??= Yaml::parseFile(base_path('../docs/api/openapi.yaml'));
    }

    /** @return array<string, array{metodo: string, caminho: string, op: array<string, mixed>}> */
    public static function operacoes(): array
    {
        $saida = [];
        foreach (self::carregar()['paths'] ?? [] as $caminho => $item) {
            foreach ($item as $metodo => $op) {
                if (in_array($metodo, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $saida[strtoupper($metodo).' /api/v1'.$caminho] = ['metodo' => strtoupper($metodo), 'caminho' => '/api/v1'.$caminho, 'op' => $op];
                }
            }
        }

        return $saida;
    }

    /** @return list<string> */
    public static function validar(string $schema, mixed $dados): array
    {
        $documento = json_decode((string) json_encode(self::carregar()));
        $armazem = new SchemaStorage;
        $armazem->addSchema('file://openapi', $documento);
        $validador = new Validator(new \JsonSchema\Constraints\Factory($armazem));
        $objeto = json_decode((string) json_encode($dados));
        $validador->validate($objeto, (object) ['$ref' => "file://openapi#/components/schemas/{$schema}"]);

        return array_map(fn (array $e): string => "{$e['property']}: {$e['message']}", $validador->getErrors());
    }
}
```

`servers`/`paths` ficam como estão. Se `base_path('../docs')` não resolver no ambiente de testes, usar `dirname(base_path())`.

- [ ] **Step 4: Escrever os testes de cobertura e formato (RED)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\Support\ContratoOpenApi;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    /** @return array<string, true> */
    private function rotasDaV1(): array
    {
        $rotas = [];
        foreach (Route::getRoutes() as $rota) {
            if (! str_starts_with($rota->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($rota->methods() as $metodo) {
                if ($metodo !== 'HEAD') {
                    $rotas[$metodo.' /'.$rota->uri()] = true;
                }
            }
        }

        return $rotas;
    }

    public function test_toda_rota_v1_esta_documentada_e_vice_versa(): void
    {
        $codigo = array_keys($this->rotasDaV1());
        $contrato = array_keys(ContratoOpenApi::operacoes());
        sort($codigo);
        sort($contrato);

        $this->assertSame([], array_values(array_diff($codigo, $contrato)), 'Rotas sem documentação');
        $this->assertSame([], array_values(array_diff($contrato, $codigo)), 'Documentadas que não existem');
    }

    public function test_formato_do_documento(): void
    {
        $doc = ContratoOpenApi::carregar();
        $this->assertStringStartsWith('3.1', (string) $doc['openapi']);

        $ids = [];
        foreach (ContratoOpenApi::operacoes() as $chave => $o) {
            $op = $o['op'];
            $this->assertNotEmpty($op['operationId'] ?? null, "{$chave} sem operationId");
            $this->assertArrayNotHasKey($op['operationId'], $ids, "{$chave}: operationId repetido");
            $ids[$op['operationId']] = true;
            $this->assertArrayHasKey('401', $op['responses'], "{$chave} sem resposta 401");
            $this->assertNotEmpty($op['summary'] ?? null, "{$chave} sem summary");
            preg_match_all('/\{(\w+)\}/', $o['caminho'], $m);
            $declarados = collect($op['parameters'] ?? [])->map(fn (array $p): string => $p['name'] ?? '')->all();
            foreach ($m[1] as $param) {
                $this->assertContains($param, $declarados, "{$chave}: parâmetro {$param} não declarado");
            }
        }
    }

    public function test_todo_ref_interno_resolve(): void
    {
        $doc = ContratoOpenApi::carregar();
        $texto = (string) json_encode($doc);
        preg_match_all('/"\$ref":"#\/([^"]+)"/', $texto, $m);
        foreach (array_unique($m[1]) as $caminho) {
            $no = $doc;
            foreach (explode('/', str_replace('\/', '/', $caminho)) as $parte) {
                $this->assertIsArray($no);
                $this->assertArrayHasKey($parte, $no, "\$ref quebrado: #/{$caminho}");
                $no = $no[$parte];
            }
        }
    }
}
```

Nota: parâmetros por `$ref` (ex.: `IdPath`) não têm `name` inline; o teste deve resolver `$ref` de `parameters` antes de comparar. Implementar essa resolução no teste (ler `components.parameters.<nome>.name`).

- [ ] **Step 5: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=OpenApiTest`
Expected: `test_toda_rota_v1_esta_documentada_e_vice_versa` FAIL (33 rotas sem documentação); os outros dois passam com `paths` vazio.

- [ ] **Step 6: Commit**

```bash
git add backend/composer.json backend/composer.lock docs/api/openapi.yaml backend/tests/Support/ContratoOpenApi.php backend/tests/Feature/Api/OpenApiTest.php
git commit -m "test(api): esqueleto do OpenAPI e testes de cobertura da v1"
```

(O RED é intencional; o commit fica verde ao fim da Task 4. Marcar no ledger que a suíte só fecha verde na Task 4.)

---

### Task 2: Paths de cadastros e conta (clientes, produtos, serviços, empresa, consumo)

**Files:**
- Modify: `docs/api/openapi.yaml`
- Test: `backend/tests/Feature/Api/ContratoV1Test.php`

**Interfaces:**
- Consumes: `ContratoOpenApi::validar`, `CenarioDeApi` (`montarApi`, `comoApi`, `emOutroTenant`).
- Produces: schemas `Cliente`, `Produto`, `Servico`, `Empresa`, `Consumo`, `ClienteEntrada`, `ProdutoEntrada`, `ServicoEntrada`; paths `/clientes`, `/clientes/{id}`, `/produtos`, `/produtos/{id}`, `/servicos`, `/servicos/{id}`, `/empresa`, `/consumo`.

- [ ] **Step 1: Escrever o teste de conformidade (RED)**

Em `ContratoV1Test`, com `use CenarioDeApi, RefreshDatabase` e `setUp` chamando `montarApi()` (padrão de `ClientesV1Test`):

```php
public function test_cliente_respeita_o_contrato(): void
{
    $criado = $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.444.777-35'])->assertCreated();
    $this->assertSame([], ContratoOpenApi::validar('Cliente', $criado->json('data')));

    $lista = $this->comoApi()->getJson('/api/v1/clientes')->assertOk();
    $this->assertSame([], ContratoOpenApi::validar('ListaDeClientes', $lista->json()));
}
```

Repetir o padrão para produto (criar via `ProdutoFactory`/POST como em `ProdutosV1Test`), serviço (como `ServicosV1Test`), `GET /empresa` (como `EmpresaV1Test`) e `GET /consumo` (como `ConsumoV1Test`), cada um validando o objeto e a lista contra `Produto`/`ListaDeProdutos` etc. Incluir um teste de erro: `POST /api/v1/clientes` inválido → `Problem`; 404 de cliente inexistente → `Problem`.

Run: `cd backend && php artisan test --filter=ContratoV1Test`
Expected: FAIL (schemas inexistentes).

- [ ] **Step 2: Escrever os schemas e paths**

Para cada schema de resposta, abrir o Resource correspondente (`ClienteResource`, `ProdutoResource`, `ServicoResource`, `EmpresaV1Resource`) e o controller de consumo, e listar **todas** as chaves com tipo (`["string","null"]` quando anulável), `required` com as sempre presentes e `additionalProperties: false`. Schemas `...Entrada` espelham as regras de validação das Actions (ler `rules()`/validador e o teste da rota): campos, tipos, `enum`, limites. Listas: `ListaDeX = {data: [X], <chaves de paginação reais>}`.

Cada operação: `operationId` em camelCase único (`listarClientes`, `criarCliente`, `obterCliente`, `atualizarCliente`, …), `tags`, `summary` em pt-BR, `parameters` (`$ref` Cursor/Limite nas listas, `IdPath` nas de item), `requestBody` com exemplo anônimo, respostas 200/201 com exemplo, mais `401`, `404`, `422`, `429` por `$ref`. `/clientes/{id}` não tem DELETE (a API responde 405; o YAML não declara o método).

- [ ] **Step 3: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=ContratoV1Test`
Expected: PASS. Falha de schema = corrigir o YAML conferindo o JSON real da resposta.

- [ ] **Step 4: Commit**

```bash
git add docs/api/openapi.yaml backend/tests/Feature/Api/ContratoV1Test.php
git commit -m "docs(api): OpenAPI dos cadastros, empresa e consumo"
```

---

### Task 3: Paths de notas

**Files:**
- Modify: `docs/api/openapi.yaml`
- Test: `backend/tests/Feature/Api/ContratoV1Test.php`

**Interfaces:**
- Consumes: helpers da Task 1; fixtures de nota de `EmitirNotaV1Test`/`NotasConsultaV1Test`.
- Produces: schemas `Nota`, `NotaStatus`, `NotaEntrada` (com itens inline, destinatário inline, `oneOf` NF-e/NFC-e/NFS-e conforme as Actions), `Cancelamento`, `Inutilizacao`, `InutilizacaoEntrada`; paths `/notas`, `/notas/{id}`, `/notas/{id}/status`, `/notas/{id}/pdf`, `/notas/{id}/xml`, `/notas/{id}/xml-cancelamento`, `/notas/{id}/cancelar`, `/notas/inutilizacoes`.

- [ ] **Step 1: Teste de conformidade (RED)**

Reaproveitar os cenários de `EmitirNotaV1Test` e `NotasConsultaV1Test` (copiar o setup mínimo: emitente, série, motor fake, `Idempotency-Key`). Validar: resposta da emissão contra `Nota`; `GET /notas/{id}` contra `Nota`; `GET /notas/{id}/status` contra `NotaStatus`; lista contra `ListaDeNotas`; cancelamento e inutilização contra seus schemas; erros `IDEMPOTENCY_KEY_AUSENTE` (400/422 conforme o código real) e `CANCELAMENTO_NAO_PERMITIDO` contra `Problem`. Para `pdf`/`xml`: o teste só confere que o YAML declara `application/pdf` e `application/xml` com `format: binary` (sem corpo JSON).

Run: `cd backend && php artisan test --filter=ContratoV1Test`
Expected: FAIL nos novos testes.

- [ ] **Step 2: Escrever schemas e paths**

Mesmo método da Task 2, a partir de `NotaResource`, `NotaStatusResource`, `InutilizacaoResource` e das Actions de emissão (campos e regras do corpo). `POST /notas` e `POST /notas/inutilizacoes` e `/cancelar` usam `$ref` do parâmetro `IdempotencyKey`; descrever 409 `IDEMPOTENCIA_EM_ANDAMENTO` e 422 `IDEMPOTENCIA_CORPO_DIFERENTE`. Documentar `test_`⇔homologação (`API_AMBIENTE_DIVERGENTE`) no `description` de `POST /notas`. Exemplo de emissão completo e anônimo (serviço NFS-e e produto NF-e).

- [ ] **Step 3: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=ContratoV1Test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add docs/api/openapi.yaml backend/tests/Feature/Api/ContratoV1Test.php
git commit -m "docs(api): OpenAPI das notas"
```

---

### Task 4: Paths de webhooks, eventos e fechamento da cobertura

**Files:**
- Modify: `docs/api/openapi.yaml`
- Test: `backend/tests/Feature/Api/ContratoV1Test.php`, `backend/tests/Feature/Api/OpenApiTest.php`

**Interfaces:**
- Consumes: `WebhookResource` (`comSegredo`), `WebhookEntregaResource` (`comPayload`), `MontadorDoPayload`.
- Produces: schemas `Webhook`, `WebhookComSegredo`, `WebhookEntrada`, `Entrega`, `EntregaComPayload`, `EventoDeNota` (payload de `nota.*`), `EventoDeTeste`; paths `/webhooks`, `/webhooks/{id}`, `/webhooks/{id}/entregas`, `/webhooks/{id}/testar`, `/webhooks/entregas/{id}`, `/webhooks/entregas/{id}/reenviar`; bloco `webhooks` do OpenAPI 3.1 (`notaAutorizada`, `notaRejeitada`, `notaCancelada`, `notaErro`, `notaContingencia`, `webhookTeste`).

- [ ] **Step 1: Testes de conformidade (RED)**

Seguir `WebhooksV1Test`: criar via `POST /api/v1/webhooks` (resposta valida `WebhookComSegredo`), `GET` (valida `Webhook` e checar que **não** há chave `segredo`), listar entregas (`ListaDeEntregas`), obter entrega com payload. Para os eventos: montar o payload com `MontadorDoPayload::paraNota(...)` e `paraTeste(...)` e validar contra `EventoDeNota`/`EventoDeTeste`. Erros: `WEBHOOK_URL_INVALIDA` (422 com `errors[]`), `WEBHOOK_LIMITE_ATINGIDO`, `WEBHOOK_INATIVO` (409) contra `Problem`.

Run: `cd backend && php artisan test --filter=ContratoV1Test`
Expected: FAIL nos novos testes.

- [ ] **Step 2: Escrever schemas, paths e o bloco `webhooks`**

`POST /webhooks` **sem** `IdempotencyKey` (descrição: a resposta traz o segredo, exibido uma única vez). `testar` e `reenviar` com `IdempotencyKey`. Em `webhooks:` descrever cada evento com `requestBody` do `EventoDeNota`/`EventoDeTeste`, os cabeçalhos `X-Signature` (formato `t=<unix>,v1=<hmac>`), `X-Webhook-Id`, `X-Webhook-Event`, e resposta esperada (2xx). A descrição do `nota.autorizada` mantém a nota sobre cancelamento recusado ou não confirmado (`cancelamento_motivo`), como no guia atual.

- [ ] **Step 3: Rodar a cobertura completa**

Run: `cd backend && php artisan test --filter="OpenApiTest|ContratoV1Test"`
Expected: PASS (33 operações documentadas, cobertura nos dois sentidos).

- [ ] **Step 4: Suíte e estilo**

Run: `cd backend && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G`
Expected: tudo verde.

- [ ] **Step 5: Commit**

```bash
git add docs/api/openapi.yaml backend/tests
git commit -m "docs(api): OpenAPI dos webhooks e eventos; cobertura completa da v1"
```

---

### Task 5: Guia do integrador

**Files:**
- Move: `docs/api-publica.md` → `docs/api/guia-do-integrador.md`
- Modify: referências a `docs/api-publica.md` (`PROGRESSO.md`, `TAREFAS.md`, memória; `grep` antes)
- Test: `backend/tests/Feature/Api/GuiaDoIntegradorTest.php`

**Interfaces:**
- Consumes: `docs/api/openapi.yaml` (links para operações e schemas).
- Produces: guia com seções Visão geral, Primeiros passos, Autenticação, Ambientes, Fluxo de emissão, Idempotência, Paginação, Erros, Limites, Webhooks, Referência (link para `/docs/api` e o YAML).

- [ ] **Step 1: Teste de sintaxe dos blocos (RED)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class GuiaDoIntegradorTest extends TestCase
{
    private function blocos(string $linguagem): array
    {
        $texto = (string) file_get_contents(base_path('../docs/api/guia-do-integrador.md'));
        preg_match_all('/```'.$linguagem.'\n(.*?)```/s', $texto, $m);

        return $m[1];
    }

    public function test_blocos_php_tem_sintaxe_valida(): void
    {
        $blocos = $this->blocos('php');
        $this->assertNotEmpty($blocos);
        foreach ($blocos as $i => $codigo) {
            $arq = tempnam(sys_get_temp_dir(), 'guia').'.php';
            file_put_contents($arq, str_starts_with(ltrim($codigo), '<?php') ? $codigo : "<?php\n".$codigo);
            $p = new Process([PHP_BINARY, '-l', $arq]);
            $p->run();
            $this->assertSame(0, $p->getExitCode(), "bloco php #{$i}: ".$p->getOutput().$p->getErrorOutput());
            @unlink($arq);
        }
    }

    public function test_blocos_js_tem_sintaxe_valida(): void
    {
        $blocos = $this->blocos('js');
        $this->assertNotEmpty($blocos);
        foreach ($blocos as $i => $codigo) {
            $arq = tempnam(sys_get_temp_dir(), 'guia').'.mjs';
            file_put_contents($arq, $codigo);
            $p = new Process(['node', '--check', $arq]);
            $p->run();
            $this->assertSame(0, $p->getExitCode(), "bloco js #{$i}: ".$p->getErrorOutput());
            @unlink($arq);
        }
    }

    public function test_guia_aponta_para_o_contrato(): void
    {
        $texto = (string) file_get_contents(base_path('../docs/api/guia-do-integrador.md'));
        $this->assertStringContainsString('openapi.yaml', $texto);
        $this->assertStringContainsString('/docs/api', $texto);
    }
}
```

Run: `cd backend && php artisan test --filter=GuiaDoIntegradorTest`
Expected: FAIL (arquivo ainda não existe).

- [ ] **Step 2: Mover e ampliar o guia**

`git mv docs/api-publica.md docs/api/guia-do-integrador.md`. Ajustar o título (tirar "rascunho"), manter as seções existentes e acrescentar "Primeiros passos" com o roteiro completo em homologação: (1) criar chave `test_` na tela de chaves, (2) `GET /empresa`, (3) `POST /clientes` e `POST /produtos`, (4) `POST /notas` com `Idempotency-Key`, (5) consultar `/status` até estado final, (6) baixar PDF/XML, (7) cadastrar webhook e verificar assinatura. Para cada passo: cURL, PHP (cURL nativo) e JS (`fetch`), usando só campos que existem no YAML. Remover a seção "Pendências" ou trocá-la por "Limites conhecidos". Os blocos de verificação de assinatura existentes já são PHP/JS e passam a ser checados. Cabeçalho do guia: links para `/docs/api` (referência interativa) e para `docs/api/openapi.yaml`.

- [ ] **Step 3: Corrigir referências**

Run: `grep -rn "api-publica" --include=*.md --include=*.php --include=*.ts --include=*.tsx . | grep -v node_modules | grep -v vendor | grep -v docs/superpowers`
Atualizar cada ocorrência (planos/specs antigos ficam como estão: são histórico).

- [ ] **Step 4: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=GuiaDoIntegradorTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add docs backend/tests/Feature/Api/GuiaDoIntegradorTest.php PROGRESSO.md TAREFAS.md
git commit -m "docs(api): guia do integrador com roteiro completo em homologação"
```

---

### Task 6: Página pública `/docs/api`

**Files:**
- Create: `frontend/scripts/copiar-openapi.mjs`
- Create: `frontend/features/docs-api/DocumentacaoDaApi.tsx`, `DocumentacaoDaApi.test.tsx`
- Create: `frontend/app/(publico)/docs/api/page.tsx`
- Modify: `frontend/package.json`, `frontend/.gitignore`, `frontend/app/(publico)/layout.tsx` só se o layout público impuser cabeçalho incompatível (ler antes)

**Interfaces:**
- Consumes: `docs/api/openapi.yaml` (copiado para `public/openapi.yaml`).
- Produces: rota pública `/docs/api`.

- [ ] **Step 1: Teste do componente (RED)**

```tsx
import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

vi.mock("redoc", () => ({
  RedocStandalone: ({ specUrl }: { specUrl: string }) => <div data-testid="redoc" data-spec={specUrl} />,
}));

import { DocumentacaoDaApi } from "./DocumentacaoDaApi";

describe("DocumentacaoDaApi", () => {
  it("mostra o título, os links e o visualizador apontando para o YAML", async () => {
    render(<DocumentacaoDaApi />);
    expect(screen.getByRole("heading", { name: /api da plataforma fiscal/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /baixar openapi/i })).toHaveAttribute("href", "/openapi.yaml");
    const visualizador = await screen.findByTestId("redoc");
    expect(visualizador).toHaveAttribute("data-spec", "/openapi.yaml");
  });
});
```

Run: `cd frontend && npx vitest run features/docs-api`
Expected: FAIL (componente inexistente).

- [ ] **Step 2: Implementar**

`npm install redoc styled-components` (peer do Redoc). Componente cliente (`"use client"`) que importa `RedocStandalone` com `next/dynamic` (`ssr: false`, `loading` com texto "Carregando documentação…"), cabeçalho com `h1` "API da Plataforma Fiscal", link "Baixar OpenAPI" (`/openapi.yaml`) e link para o guia. A página (`page.tsx`, componente de servidor) exporta `metadata` (title "API — Plataforma Fiscal") e renderiza o componente. `scripts/copiar-openapi.mjs` copia `../docs/api/openapi.yaml` para `public/openapi.yaml` (cria a pasta se preciso; falha com mensagem clara se o arquivo não existir). `package.json`: `"predev": "node scripts/copiar-openapi.mjs"`, `"prebuild": "node scripts/copiar-openapi.mjs"`. `.gitignore`: `public/openapi.yaml`. Se o `RedocStandalone` não aceitar `next/dynamic` em TS strict, tipar as props em uma interface local, nunca `any`.

- [ ] **Step 3: Verificar**

Run: `cd frontend && npx vitest run features/docs-api && npx tsc --noEmit && npm run lint && npm run build`
Expected: teste PASS, `tsc` e `build` limpos, lint só com o warning antigo. Conferir no `build` que `/docs/api` aparece nas rotas.

- [ ] **Step 4: Commit**

```bash
git add frontend
git commit -m "feat(docs): página pública /docs/api com a referência OpenAPI"
```

---

### Task 7: Fechamento

**Files:**
- Modify: `PROGRESSO.md`, `TAREFAS.md`, memória `project_estado_atual.md` e `MEMORY.md`

- [ ] **Step 1: Suítes completas**

Run: `cd backend && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G`; `cd frontend && npx vitest run && npx tsc --noEmit && npm run lint && npm run build`
Expected: tudo verde (backend ≥ 1278 + novos; frontend ≥ 359 + 1).

- [ ] **Step 2: Documentação de progresso**

`PROGRESSO.md`: seção "Fechamento da F6c2" (arquivos, decisões, como manter o contrato: rota nova exige entrada no YAML). `TAREFAS.md`: F6c2 `[x]`, F6 `[x]`, próxima = F7 (CRM, brainstorming). Memória: F6 inteira concluída, próxima F7.

- [ ] **Step 3: Commit**

```bash
git add PROGRESSO.md TAREFAS.md
git commit -m "docs: F6c2 concluída (OpenAPI e guia do integrador)"
```
