# F2: Cadastros e emitente — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao tenant tudo o que a emissão fiscal exige antes de existir motor de emissão: clientes, produtos, serviços, categorias fiscais padrão, pendências fiscais, importação CSV, e os dados fiscais do emitente (endereço, regime/IE/IM/CNAE, certificado A1, CSC, séries).

**Architecture:** Três módulos novos no monólito modular (`Customers`, `Catalog`, `Fiscal`), cada um em `backend/app/Modules/<Nome>/{Domain,Application,Http}`, seguindo exatamente os padrões da F1 (Actions chamadas por controller e por API pública, `BelongsToTenant` + Global Scope, `EntitlementService` para limites de plano, exceções `ErroDeNegocio` com `{message, codigo}`, Políticas como classes simples com métodos `garantirPode*`). O emitente é uma tabela 1:1 por tenant (`emitentes`), preenchida por um wizard pós-ativação no frontend.

**Tech Stack:** Laravel 12 / PHP 8.3+ (backend), Next.js App Router + TypeScript strict (frontend), PostgreSQL, Pest/PHPUnit, React Hook Form + Zod + TanStack Query.

**Spec:** `docs/superpowers/specs/2026-10-01-f2-cadastros-e-emitente-design.md` (e `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md` §3–§6, §8, §15 linha F2). Os executores devem ler a spec da fase antes de começar — este plano assume as decisões dela como corretas.

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo.
- TypeScript em modo strict, sem `any`, em todo arquivo novo do frontend. Antes de mexer no frontend, ler `frontend/AGENTS.md` (este Next.js tem mudanças de API em relação ao treinamento do modelo).
- Dado fiscal ausente bloqueia com mensagem acionável; nunca um default. Comparar sempre com `=== null` (nunca `empty()` ou `?? 0`) — origem `0` é um valor válido.
- Regra tributária (validação de NCM/CEST/origem etc.) fica em resolvers puros no `Domain`, nunca no provider ou no controller.
- Frontend e API pública chamam as mesmas Actions de `Application`; nenhuma regra fica duplicada em controller.
- Todo Model novo de tenant usa a trait `BelongsToTenant` (nunca `tenant_id` em `$fillable`) e tem teste de isolamento via HTTP: outro tenant recebe `404`.
- Mudança de schema só por migration.
- Segredo (senha do certificado, `.pfx`, tokens de CSC) sempre com `Crypt::encryptString`/`decryptString`, nunca em texto plano.
- Nenhum dado real em fixture/factory/seed.
- `ambiente_fiscal` do emitente nasce sempre `HOMOLOGACAO`; ir para `PRODUCAO` é uma ação própria, confirmada e auditada (`spatie/laravel-activitylog`).
- Erro de negócio sempre uma subclasse de `ErroDeNegocio` (`{message, codigo}` via `bootstrap/app.php`), nunca uma exception genérica estourando 500.

## Review Focus

- Produto/serviço com campo fiscal `0` (ex.: `origem = 0`) é tratado como preenchido em toda a cadeia — validação, pendências fiscais e serialização JSON — nunca como ausente. Teste na Tarefa 5 e na Tarefa 9.
- Upload de certificado com senha certa mas arquivo que não é um `.pfx` (ex.: um PDF) responde com um código estável, nunca um erro 500. Teste na Tarefa 16.
- CSV gerado pelo Excel em Windows-1252 (acentuação fora de UTF-8) é convertido antes do parse, não corrompe nomes/endereços silenciosamente. Teste na Tarefa 19.
- Índice único de CPF/CNPJ de cliente é composto com `tenant_id` — dois tenants podem cadastrar o mesmo CPF sem colidir. Teste na Tarefa 10.
- O wizard não bloqueia o tenant pedindo CSC/série de um modelo (NFC-e, NFS-e) que o plano não contratou — a API só informa `exige_csc`, nunca recusa salvar sem ele. Teste na Tarefa 17.

---

### Tarefa 1: `Shared` — value object `Cpf`

**Files:**
- Create: `backend/app/Modules/Shared/Domain/Cpf.php`
- Create: `backend/app/Modules/Shared/Domain/Exceptions/CpfInvalidoException.php`
- Create: `backend/app/Modules/Shared/Http/Rules/CpfValido.php`
- Test: `backend/tests/Unit/Shared/CpfTest.php`

**Interfaces:**
- Consumes: nada (só `ErroDeNegocio`, já existente).
- Produces: `App\Modules\Shared\Domain\Cpf::de(string): self`, `::tentar(string): ?self`, `->formatado(): string`, `(string) $cpf` (11 dígitos sem máscara). `CpfValido` (regra de validação de Request). Usado pela Tarefa 10 (`Cliente`).

- [ ] **Step 1: Escrever os testes (devem falhar: classe não existe)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\Cpf;
use App\Modules\Shared\Domain\Exceptions\CpfInvalidoException;
use PHPUnit\Framework\TestCase;

class CpfTest extends TestCase
{
    public function test_aceita_cpf_valido_com_mascara(): void
    {
        $cpf = Cpf::de('111.444.777-35');

        $this->assertSame('11144477735', (string) $cpf);
        $this->assertSame('111.444.777-35', $cpf->formatado());
    }

    public function test_aceita_cpf_valido_sem_mascara(): void
    {
        $this->assertSame('11144477735', (string) Cpf::de('11144477735'));
    }

    public function test_rejeita_digito_verificador_errado(): void
    {
        $this->expectException(CpfInvalidoException::class);

        Cpf::de('111.444.777-34');
    }

    public function test_rejeita_todos_os_digitos_iguais(): void
    {
        $this->expectException(CpfInvalidoException::class);

        Cpf::de('111.111.111-11');
    }

    public function test_rejeita_tamanho_errado(): void
    {
        $this->expectException(CpfInvalidoException::class);

        Cpf::de('123');
    }

    public function test_tentar_devolve_null_em_vez_de_lancar(): void
    {
        $this->assertNull(Cpf::tentar('123'));
        $this->assertSame('11144477735', (string) Cpf::tentar('111.444.777-35'));
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=CpfTest`
Expected: FAIL (`Class "App\Modules\Shared\Domain\Cpf" not found`).

- [ ] **Step 3: Implementar a exceção**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class CpfInvalidoException extends ErroDeNegocio
{
    public static function para(string $entrada): self
    {
        return new self("CPF inválido: {$entrada}.");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'CPF_INVALIDO';
    }
}
```

- [ ] **Step 4: Implementar o value object**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use App\Modules\Shared\Domain\Exceptions\CpfInvalidoException;
use Stringable;

/** CPF: 9 posições numéricas + 2 dígitos verificadores (módulo 11). */
final class Cpf implements Stringable
{
    private const PESOS_DV1 = [10, 9, 8, 7, 6, 5, 4, 3, 2];

    private const PESOS_DV2 = [11, 10, 9, 8, 7, 6, 5, 4, 3, 2];

    private function __construct(public readonly string $valor) {}

    public static function de(string $entrada): self
    {
        $valor = preg_replace('/\D/', '', $entrada) ?? '';

        if (! self::ehValido($valor)) {
            throw CpfInvalidoException::para($entrada);
        }

        return new self($valor);
    }

    public static function tentar(string $entrada): ?self
    {
        try {
            return self::de($entrada);
        } catch (CpfInvalidoException) {
            return null;
        }
    }

    public function formatado(): string
    {
        $v = $this->valor;

        return substr($v, 0, 3).'.'.substr($v, 3, 3).'.'.substr($v, 6, 3).'-'.substr($v, 9, 2);
    }

    public function __toString(): string
    {
        return $this->valor;
    }

    private static function ehValido(string $valor): bool
    {
        if (preg_match('/^\d{11}$/', $valor) !== 1 || preg_match('/^(\d)\1{10}$/', $valor) === 1) {
            return false;
        }

        $base = substr($valor, 0, 9);
        $dv1 = self::digito($base, self::PESOS_DV1);
        $dv2 = self::digito($base.$dv1, self::PESOS_DV2);

        return substr($valor, 9) === $dv1.$dv2;
    }

    /** @param list<int> $pesos */
    private static function digito(string $base, array $pesos): string
    {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += ((int) $base[$i]) * $peso;
        }
        $resto = $soma % 11;

        return (string) ($resto < 2 ? 0 : 11 - $resto);
    }
}
```

- [ ] **Step 5: Implementar a regra de validação**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Rules;

use App\Modules\Shared\Domain\Cpf;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class CpfValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Cpf::tentar($value) === null) {
            $fail('Informe um CPF válido.');
        }
    }
}
```

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=CpfTest`
Expected: PASS (6 testes).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/Shared/Domain/Cpf.php backend/app/Modules/Shared/Domain/Exceptions/CpfInvalidoException.php backend/app/Modules/Shared/Http/Rules/CpfValido.php backend/tests/Unit/Shared/CpfTest.php
git commit -m "feat(shared): value object Cpf com validação de dígito verificador"
```

---

### Tarefa 2: `Shared` — `ConsultaCep` (ViaCEP)

**Files:**
- Create: `backend/app/Modules/Shared/Infrastructure/ConsultaCep.php`
- Create: `backend/app/Modules/Shared/Domain/Exceptions/ConsultaCepIndisponivelException.php`
- Modify: `backend/config/services.php` (adicionar bloco `viacep`)
- Modify: `backend/.env.example` (adicionar `VIACEP_URL=https://viacep.com.br`, ao lado de `BRASILAPI_URL` se já existir)
- Test: `backend/tests/Feature/Shared/ConsultaCepTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `App\Modules\Shared\Infrastructure\ConsultaCep::buscar(string $cep): ?array{logradouro:string,bairro:string,cidade:string,uf:string,codigo_ibge:string}`. Lança `ConsultaCepIndisponivelException` (503, `codigo: CONSULTA_INDISPONIVEL`) se o serviço cair. Usado pela Tarefa 15 (dados da empresa do emitente) e pela Tarefa 11 (endereço do cliente, opcional).

- [ ] **Step 1: Escrever os testes (devem falhar: classe não existe)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Domain\Exceptions\ConsultaCepIndisponivelException;
use App\Modules\Shared\Infrastructure\ConsultaCep;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConsultaCepTest extends TestCase
{
    public function test_consulta_cep_valido(): void
    {
        Http::fake(['*/ws/01001000/json/' => Http::response([
            'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
        ])]);

        $dados = app(ConsultaCep::class)->buscar('01001-000');

        $this->assertSame([
            'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'cidade' => 'São Paulo', 'uf' => 'SP', 'codigo_ibge' => '3550308',
        ], $dados);
    }

    public function test_cep_inexistente_devolve_null(): void
    {
        Http::fake(['*' => Http::response(['erro' => true])]);

        $this->assertNull(app(ConsultaCep::class)->buscar('00000000'));
    }

    public function test_servico_fora_do_ar_lanca_excecao(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->expectException(ConsultaCepIndisponivelException::class);

        app(ConsultaCep::class)->buscar('01001000');
    }

    public function test_resposta_e_cacheada_por_cep(): void
    {
        Http::fake(['*/ws/01001000/json/' => Http::response([
            'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
        ])]);

        app(ConsultaCep::class)->buscar('01001000');
        app(ConsultaCep::class)->buscar('01001000');

        Http::assertSentCount(1);
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ConsultaCepTest`
Expected: FAIL (`Class "App\Modules\Shared\Infrastructure\ConsultaCep" not found`).

- [ ] **Step 3: Adicionar a configuração**

Em `backend/config/services.php`, logo abaixo do bloco `'brasilapi'`:

```php
    'viacep' => [
        'url' => env('VIACEP_URL', 'https://viacep.com.br'),
    ],
```

Em `backend/.env.example`, na mesma região de `BRASILAPI_URL`:

```
VIACEP_URL=https://viacep.com.br
```

- [ ] **Step 4: Implementar a exceção**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class ConsultaCepIndisponivelException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Não foi possível consultar o CEP agora. Preencha o endereço manualmente.');
    }

    public function status(): int
    {
        return 503;
    }

    public function codigo(): string
    {
        return 'CONSULTA_INDISPONIVEL';
    }
}
```

- [ ] **Step 5: Implementar o serviço**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure;

use App\Modules\Shared\Domain\Exceptions\ConsultaCepIndisponivelException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Conveniência de preenchimento, nunca validação obrigatória (mesmo padrão de `ConsultaCnpj`). */
final class ConsultaCep
{
    private const TIMEOUT_SEGUNDOS = 5;

    private const CACHE_SEGUNDOS = 86400;

    /** @return array{logradouro: string, bairro: string, cidade: string, uf: string, codigo_ibge: string}|null */
    public function buscar(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep) ?? '';
        $chave = 'viacep:'.$cep;
        /** @var array{logradouro: string, bairro: string, cidade: string, uf: string, codigo_ibge: string}|null $emCache */
        $emCache = Cache::get($chave);
        if ($emCache !== null) {
            return $emCache;
        }

        try {
            $resposta = Http::baseUrl((string) config('services.viacep.url'))
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->acceptJson()
                ->get("/ws/{$cep}/json/");
        } catch (ConnectionException) {
            throw new ConsultaCepIndisponivelException;
        }

        if (! $resposta->successful()) {
            throw new ConsultaCepIndisponivelException;
        }

        if ($resposta->json('erro') === true) {
            return null;
        }

        $dados = [
            'logradouro' => trim((string) $resposta->json('logradouro')),
            'bairro' => trim((string) $resposta->json('bairro')),
            'cidade' => trim((string) $resposta->json('localidade')),
            'uf' => trim((string) $resposta->json('uf')),
            'codigo_ibge' => trim((string) $resposta->json('ibge')),
        ];
        Cache::put($chave, $dados, self::CACHE_SEGUNDOS);

        return $dados;
    }
}
```

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ConsultaCepTest`
Expected: PASS (4 testes).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/Shared/Infrastructure/ConsultaCep.php backend/app/Modules/Shared/Domain/Exceptions/ConsultaCepIndisponivelException.php backend/config/services.php backend/.env.example backend/tests/Feature/Shared/ConsultaCepTest.php
git commit -m "feat(shared): ConsultaCep (ViaCEP) com cache e falha graciosa"
```

---

### Tarefa 3: Frontend — suporte a upload de arquivo (`FormData`) no cliente de API

**Files:**
- Modify: `frontend/lib/api.ts`
- Modify: `frontend/lib/api.test.ts` (já existe — acrescentar um `it` dentro do `describe('api', ...)` existente)

**Interfaces:**
- Consumes: nada.
- Produces: `api<T>(path, init)` continua igual para JSON, mas agora não força `Content-Type: application/json` quando `init.body instanceof FormData` (deixa o browser definir o `multipart/form-data; boundary=...`). Usado pela Tarefa 16 (upload de certificado) e pela Tarefa 25 (importação CSV).

- [ ] **Step 1: Ler `frontend/lib/api.ts` e `frontend/lib/api.test.ts`, depois acrescentar o teste que falha**

Dentro do `describe('api', ...)` já existente em `frontend/lib/api.test.ts`, seguindo o padrão `vi.spyOn(globalThis, 'fetch')` já usado nos outros testes do arquivo, acrescentar:

```ts
  it('não define Content-Type quando o corpo é FormData, deixando o boundary automático', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D';
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(resposta(200, { data: 'ok' }));

    const corpo = new FormData();
    corpo.append('arquivo', new Blob(['conteudo']), 'arquivo.csv');

    await api('/api/app/clientes/importar', { method: 'POST', body: corpo });

    const [, init] = fetchMock.mock.calls[0];
    expect(new Headers(init?.headers).has('Content-Type')).toBe(false);
  });
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd frontend && npx vitest run lib/api.test.ts`
Expected: FAIL (o `Content-Type: application/json` é definido mesmo com `FormData`).

- [ ] **Step 3: Corrigir `enviar()` em `frontend/lib/api.ts`**

Trocar:

```ts
  if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
```

por:

```ts
  if (init.body && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `cd frontend && npx vitest run lib/api.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/lib/api.ts frontend/lib/api.test.ts
git commit -m "fix(frontend): não forçar Content-Type JSON em upload multipart"
```

---

### Tarefa 4: `Catalog` — modelo de dados (Produto, Serviço, Categoria fiscal padrão)

**Files:**
- Create: `backend/database/migrations/2026_10_01_000001_create_catalog_tables.php`
- Create: `backend/app/Modules/Catalog/Domain/Enums/TributacaoIcms.php`
- Create: `backend/app/Modules/Catalog/Domain/Enums/FonteFiscal.php`
- Create: `backend/app/Modules/Catalog/Domain/Models/Produto.php`
- Create: `backend/app/Modules/Catalog/Domain/Models/Servico.php`
- Create: `backend/app/Modules/Catalog/Domain/Models/CategoriaFiscalPadrao.php`
- Create: `backend/database/factories/ProdutoFactory.php`
- Create: `backend/database/factories/ServicoFactory.php`
- Create: `backend/database/factories/CategoriaFiscalPadraoFactory.php`
- Test: `backend/tests/Feature/Catalog/CatalogIsolamentoTest.php`

**Interfaces:**
- Consumes: `App\Modules\Tenancy\Domain\Concerns\BelongsToTenant` (trait existente).
- Produces: Models `Produto`, `Servico`, `CategoriaFiscalPadrao` (todos com `BelongsToTenant`, `HasFactory`, `HasUuids`). `Produto::pendenteDeRevisaoFiscal(): bool` e `Servico::pendenteDeRevisaoFiscal(): bool`, usados pela Tarefa 9. Enums `TributacaoIcms::{Normal,St}` e `FonteFiscal::{Manual,Padrao}`. Usado pelas Tarefas 5–9 e 19.

- [ ] **Step 1: Escrever o teste de isolamento (deve falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_produto_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Produto::factory()->create());
    }

    public function test_servico_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Servico::factory()->create());
    }

    public function test_categoria_fiscal_padrao_e_isolada_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => CategoriaFiscalPadrao::factory()->create());
    }

    private function verificaIsolamento(Closure $criar): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        /** @var Model $registro */
        $registro = $criar();
        $this->assertSame($tenantA->id, $registro->getAttribute('tenant_id'));

        $contexto->set($tenantB->id);
        $this->assertSame(0, $registro::query()->count());
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=CatalogIsolamentoTest`
Expected: FAIL (`Class "App\Modules\Catalog\Domain\Models\Produto" not found`).

- [ ] **Step 3: Criar a migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('sku', 60);
            $table->string('nome', 150);
            $table->string('unidade', 10);
            $table->integer('preco_centavos');
            $table->string('gtin', 14)->nullable();
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable();
            $table->string('fiscal_fonte', 10)->default('MANUAL');
            $table->timestamp('fiscal_revisado_em')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });

        Schema::create('servicos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('nome', 150);
            $table->integer('preco_centavos');
            $table->string('codigo_lc116', 5)->nullable();
            $table->string('c_trib_nac', 6)->nullable();
            $table->string('codigo_municipal', 3)->nullable();
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->string('nbs', 9)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'nome']);
        });

        Schema::create('categorias_fiscais_padrao', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('categoria', 60);
            $table->string('ncm', 8)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_fiscais_padrao');
        Schema::dropIfExists('servicos');
        Schema::dropIfExists('produtos');
    }
};
```

- [ ] **Step 4: Criar os enums**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum TributacaoIcms: string
{
    case Normal = 'NORMAL';
    case St = 'ST';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

enum FonteFiscal: string
{
    case Manual = 'MANUAL';
    case Padrao = 'PADRAO';
}
```

- [ ] **Step 5: Criar os Models**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\ProdutoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $sku
 * @property string $nome
 * @property string $unidade
 * @property int $preco_centavos
 * @property ?string $gtin
 * @property ?string $ncm
 * @property ?string $cest
 * @property ?int $origem
 * @property ?TributacaoIcms $tributacao_icms
 * @property FonteFiscal $fiscal_fonte
 * @property ?\Carbon\CarbonInterface $fiscal_revisado_em
 */
class Produto extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<ProdutoFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'sku', 'nome', 'unidade', 'preco_centavos', 'gtin', 'ncm', 'cest',
        'origem', 'tributacao_icms', 'fiscal_fonte', 'fiscal_revisado_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'origem' => 'integer',
            'tributacao_icms' => TributacaoIcms::class,
            'fiscal_fonte' => FonteFiscal::class,
            'fiscal_revisado_em' => 'datetime',
        ];
    }

    /** Dado ausente nunca é chutado: `=== null`, nunca `empty()`. */
    public function pendenteDeRevisaoFiscal(): bool
    {
        return $this->ncm === null
            || $this->origem === null
            || $this->tributacao_icms === null
            || ($this->fiscal_fonte === FonteFiscal::Padrao && $this->fiscal_revisado_em === null);
    }

    protected static function newFactory(): ProdutoFactory
    {
        return ProdutoFactory::new();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\ServicoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $nome
 * @property int $preco_centavos
 * @property ?string $codigo_lc116
 * @property ?string $c_trib_nac
 * @property ?string $codigo_municipal
 * @property ?string $aliquota_iss
 * @property ?string $nbs
 */
class Servico extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<ServicoFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = ['nome', 'preco_centavos', 'codigo_lc116', 'c_trib_nac', 'codigo_municipal', 'aliquota_iss', 'nbs'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aliquota_iss' => 'decimal:2'];
    }

    public function pendenteDeRevisaoFiscal(): bool
    {
        return $this->codigo_lc116 === null || $this->c_trib_nac === null || $this->aliquota_iss === null;
    }

    protected static function newFactory(): ServicoFactory
    {
        return ServicoFactory::new();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\CategoriaFiscalPadraoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $categoria
 * @property ?string $ncm
 * @property ?int $origem
 * @property ?TributacaoIcms $tributacao_icms
 */
class CategoriaFiscalPadrao extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<CategoriaFiscalPadraoFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = ['categoria', 'ncm', 'origem', 'tributacao_icms'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['origem' => 'integer', 'tributacao_icms' => TributacaoIcms::class];
    }

    protected static function newFactory(): CategoriaFiscalPadraoFactory
    {
        return CategoriaFiscalPadraoFactory::new();
    }
}
```

- [ ] **Step 6: Criar as factories**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Catalog\Domain\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Produto> */
class ProdutoFactory extends Factory
{
    protected $model = Produto::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'nome' => fake()->words(3, true),
            'unidade' => 'UN',
            'preco_centavos' => fake()->numberBetween(1000, 100000),
            'gtin' => null,
            'ncm' => null,
            'cest' => null,
            'origem' => null,
            'tributacao_icms' => null,
            'fiscal_fonte' => FonteFiscal::Manual,
            'fiscal_revisado_em' => null,
        ];
    }

    public function completo(): static
    {
        return $this->state(fn (): array => [
            'ncm' => '12345678',
            'origem' => 0,
            'tributacao_icms' => TributacaoIcms::Normal,
        ]);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Models\Servico;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Servico> */
class ServicoFactory extends Factory
{
    protected $model = Servico::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nome' => fake()->words(3, true),
            'preco_centavos' => fake()->numberBetween(1000, 100000),
            'codigo_lc116' => null,
            'c_trib_nac' => null,
            'codigo_municipal' => null,
            'aliquota_iss' => null,
            'nbs' => null,
        ];
    }

    public function completo(): static
    {
        return $this->state(fn (): array => [
            'codigo_lc116' => '14.01',
            'c_trib_nac' => '140101',
            'aliquota_iss' => '5.00',
        ]);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CategoriaFiscalPadrao> */
class CategoriaFiscalPadraoFactory extends Factory
{
    protected $model = CategoriaFiscalPadrao::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'categoria' => 'Categoria '.fake()->unique()->numerify('##'),
            'ncm' => '12345678',
            'origem' => 0,
            'tributacao_icms' => TributacaoIcms::Normal,
        ];
    }
}
```

- [ ] **Step 7: Migrar e rodar os testes**

Run: `cd backend && php artisan migrate --env=testing --force && php artisan test --filter=CatalogIsolamentoTest`
Expected: PASS (3 testes).

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_10_01_000001_create_catalog_tables.php backend/app/Modules/Catalog backend/database/factories/ProdutoFactory.php backend/database/factories/ServicoFactory.php backend/database/factories/CategoriaFiscalPadraoFactory.php backend/tests/Feature/Catalog/CatalogIsolamentoTest.php
git commit -m "feat(catalog): modelo de dados de produto, serviço e categoria fiscal padrão"
```

---

### Tarefa 5: `Catalog` — resolver puro `ValidadorCamposFiscais`

**Files:**
- Create: `backend/app/Modules/Catalog/Domain/ValidadorCamposFiscais.php`
- Test: `backend/tests/Unit/Catalog/ValidadorCamposFiscaisTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `ValidadorCamposFiscais::ncm(?string): ?string`, `::cest(?string): ?string`, `::origem(int|string|null): ?int`. Usado pelas Tarefas 6, 7 e 19 (CSV) para nunca persistir um valor fiscal malformado.

- [ ] **Step 1: Escrever os testes (devem falhar: classe não existe)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Domain\ValidadorCamposFiscais;
use PHPUnit\Framework\TestCase;

class ValidadorCamposFiscaisTest extends TestCase
{
    public function test_ncm_valido_de_8_digitos(): void
    {
        $this->assertSame('12345678', ValidadorCamposFiscais::ncm('12345678'));
    }

    public function test_ncm_malformado_vira_null_nunca_o_valor_cru(): void
    {
        $this->assertNull(ValidadorCamposFiscais::ncm('123'));
        $this->assertNull(ValidadorCamposFiscais::ncm('abcdefgh'));
    }

    public function test_ncm_nulo_permanece_nulo(): void
    {
        $this->assertNull(ValidadorCamposFiscais::ncm(null));
    }

    public function test_cest_valido_de_7_digitos(): void
    {
        $this->assertSame('1234567', ValidadorCamposFiscais::cest('1234567'));
    }

    public function test_cest_malformado_vira_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::cest('123'));
    }

    public function test_origem_zero_e_tratada_como_preenchida_nunca_como_ausente(): void
    {
        $this->assertSame(0, ValidadorCamposFiscais::origem(0));
        $this->assertSame(0, ValidadorCamposFiscais::origem('0'));
    }

    public function test_origem_valida_de_0_a_8(): void
    {
        $this->assertSame(8, ValidadorCamposFiscais::origem(8));
    }

    public function test_origem_fora_da_faixa_vira_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::origem(9));
        $this->assertNull(ValidadorCamposFiscais::origem(-1));
    }

    public function test_origem_ausente_permanece_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::origem(null));
        $this->assertNull(ValidadorCamposFiscais::origem(''));
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ValidadorCamposFiscaisTest`
Expected: FAIL (`Class "App\Modules\Catalog\Domain\ValidadorCamposFiscais" not found`).

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * Resolver puro: valor malformado vira `null`, nunca o valor cru (lixo
 * nunca parece preenchido). `0` é um valor válido de origem.
 */
final class ValidadorCamposFiscais
{
    public static function ncm(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $limpo = preg_replace('/\D/', '', $valor) ?? '';

        return preg_match('/^\d{8}$/', $limpo) === 1 ? $limpo : null;
    }

    public static function cest(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $limpo = preg_replace('/\D/', '', $valor) ?? '';

        return preg_match('/^\d{7}$/', $limpo) === 1 ? $limpo : null;
    }

    public static function origem(int|string|null $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $v = (int) $valor;

        return $v >= 0 && $v <= 8 ? $v : null;
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ValidadorCamposFiscaisTest`
Expected: PASS (9 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Catalog/Domain/ValidadorCamposFiscais.php backend/tests/Unit/Catalog/ValidadorCamposFiscaisTest.php
git commit -m "feat(catalog): resolver puro ValidadorCamposFiscais (NCM/CEST/origem)"
```

---

### Tarefa 6: `Catalog` — CRUD de Produto (Actions, Request, Resource, Controller, rotas, limite de plano)

**Files:**
- Create: `backend/app/Modules/Shared/Application/PoliticaDeCadastros.php`
- Create: `backend/app/Modules/Catalog/Application/Actions/CriarProduto.php`
- Create: `backend/app/Modules/Catalog/Application/Actions/AtualizarProduto.php`
- Create: `backend/app/Modules/Catalog/Application/ContadorDeProdutos.php`
- Create: `backend/app/Modules/Catalog/Http/Requests/CriarProdutoRequest.php`
- Create: `backend/app/Modules/Catalog/Http/Requests/AtualizarProdutoRequest.php`
- Create: `backend/app/Modules/Catalog/Http/Resources/ProdutoResource.php`
- Create: `backend/app/Modules/Catalog/Http/Controllers/ProdutosController.php`
- Create: `backend/app/Modules/Catalog/Http/routes-app.php`
- Create: `backend/app/Modules/Catalog/Providers/CatalogServiceProvider.php`
- Modify: `backend/bootstrap/providers.php`
- Test: `backend/tests/Feature/Catalog/ProdutosTest.php`

**Interfaces:**
- Consumes: `Produto` (Tarefa 4), `ValidadorCamposFiscais` (Tarefa 5), `EntitlementService::garantirCapacidade()` e `Recurso::Produtos` (F1), `AcessoNegadoException` (F1).
- Produces: `PoliticaDeCadastros::garantirPodeEscrever(Usuario): void` (reaproveitada pelas Tarefas 7, 11 e 12). Rotas `GET|POST /api/app/produtos`, `GET|PUT /api/app/produtos/{id}`. Usado pela Tarefa 20 (API pública) e pela Tarefa 21 (frontend).

- [ ] **Step 1: Escrever os testes (devem falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProdutosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Produtos->value => 2]))->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
    }

    public function test_cria_produto(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'SKU-1')
            ->assertJsonPath('data.fiscal_fonte', 'MANUAL');
    }

    public function test_origem_zero_e_gravada_como_zero_nunca_como_null(): void
    {
        $resposta = $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500, 'origem' => 0,
        ])->assertCreated();

        $this->assertSame(0, $resposta->json('data.origem'));
        $this->assertDatabaseHas('produtos', ['sku' => 'SKU-1', 'origem' => 0]);
    }

    public function test_ncm_malformado_vira_null_nunca_o_valor_cru(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500, 'ncm' => '123',
        ])->assertCreated()->assertJsonPath('data.ncm', null);
    }

    public function test_sku_duplicado_no_mesmo_tenant_e_recusado(): void
    {
        Produto::factory()->for($this->tenant)->create(['sku' => 'SKU-1']);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Outro', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    public function test_sku_igual_em_outro_tenant_e_permitido(): void
    {
        Produto::factory()->for(Tenant::factory())->create(['sku' => 'SKU-1']);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertCreated();
    }

    public function test_respeita_o_limite_do_plano(): void
    {
        Produto::factory()->for($this->tenant)->count(2)->create();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/produtos', [
            'sku' => 'SKU-3', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_DO_PLANO');
    }

    public function test_atualizar_marca_fonte_manual_e_revisado_agora(): void
    {
        $produto = Produto::factory()->for($this->tenant)->create(['fiscal_fonte' => 'PADRAO', 'ncm' => '12345678']);

        $this->spa()->actingAs($this->admin)->putJson("/api/app/produtos/{$produto->id}", [
            'sku' => $produto->sku, 'nome' => 'Novo nome', 'unidade' => 'UN', 'preco_centavos' => 999, 'ncm' => '87654321',
        ])->assertOk()
            ->assertJsonPath('data.nome', 'Novo nome')
            ->assertJsonPath('data.fiscal_fonte', 'MANUAL');

        $this->assertNotNull($produto->refresh()->fiscal_revisado_em);
    }

    public function test_vendedor_cria_produto_mas_leitura_nao(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($vendedor)->postJson('/api/app/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->spa()->actingAs($leitura)->postJson('/api/app/produtos', [
            'sku' => 'SKU-2', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertForbidden()->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_produto_de_outro_tenant_responde_404(): void
    {
        $alheio = Produto::factory()->for(Tenant::factory())->create();

        $this->spa()->actingAs($this->admin)->getJson("/api/app/produtos/{$alheio->id}")->assertNotFound();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ProdutosTest`
Expected: FAIL (rota `produtos` inexistente, 404 de rota).

- [ ] **Step 3: Criar `PoliticaDeCadastros` (compartilhada por Catalog e Customers)**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem escreve em clientes, produtos e serviços (spec F2 §9). */
final class PoliticaDeCadastros
{
    public function garantirPodeEscrever(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin, Papel::Fiscal, Papel::Vendedor], true)) {
            throw new AcessoNegadoException('Você não tem permissão para alterar cadastros.');
        }
    }
}
```

- [ ] **Step 4: Criar as Actions**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\ValidadorCamposFiscais;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class CriarProduto
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param array{sku:string,nome:string,unidade:string,preco_centavos:int,gtin:?string,ncm:?string,cest:?string,origem:int|string|null,tributacao_icms:?string} $dados */
    public function executar(Usuario $autor, array $dados): Produto
    {
        return DB::transaction(function () use ($autor, $dados): Produto {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($autor->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Produtos);

            return Produto::create([
                'sku' => $dados['sku'],
                'nome' => $dados['nome'],
                'unidade' => $dados['unidade'],
                'preco_centavos' => $dados['preco_centavos'],
                'gtin' => $dados['gtin'],
                'ncm' => ValidadorCamposFiscais::ncm($dados['ncm']),
                'cest' => ValidadorCamposFiscais::cest($dados['cest']),
                'origem' => ValidadorCamposFiscais::origem($dados['origem']),
                'tributacao_icms' => $dados['tributacao_icms'],
                'fiscal_fonte' => FonteFiscal::Manual,
            ]);
        });
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\ValidadorCamposFiscais;

final class AtualizarProduto
{
    /** @param array{sku:string,nome:string,unidade:string,preco_centavos:int,gtin:?string,ncm:?string,cest:?string,origem:int|string|null,tributacao_icms:?string} $dados */
    public function executar(Produto $produto, array $dados): Produto
    {
        $produto->update([
            'sku' => $dados['sku'],
            'nome' => $dados['nome'],
            'unidade' => $dados['unidade'],
            'preco_centavos' => $dados['preco_centavos'],
            'gtin' => $dados['gtin'],
            'ncm' => ValidadorCamposFiscais::ncm($dados['ncm']),
            'cest' => ValidadorCamposFiscais::cest($dados['cest']),
            'origem' => ValidadorCamposFiscais::origem($dados['origem']),
            'tributacao_icms' => $dados['tributacao_icms'],
            // Edição manual conta como revisão: a pendência de "fonte padrão não revisada" acaba aqui.
            'fiscal_fonte' => FonteFiscal::Manual,
            'fiscal_revisado_em' => now(),
        ]);

        return $produto;
    }
}
```

- [ ] **Step 5: Criar o contador de uso**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class ContadorDeProdutos implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::Produtos;
    }

    public function contar(Tenant $tenant): int
    {
        return Produto::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count();
    }
}
```

- [ ] **Step 6: Criar os Requests**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarProdutoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public static function regras(): array
    {
        return [
            'sku' => ['required', 'string', 'max:60'],
            'nome' => ['required', 'string', 'max:150'],
            'unidade' => ['required', 'string', 'max:10'],
            'preco_centavos' => ['required', 'integer', 'min:0'],
            'gtin' => ['nullable', 'string', 'max:14'],
            'ncm' => ['nullable', 'string'],
            'cest' => ['nullable', 'string'],
            'origem' => ['nullable'],
            'tributacao_icms' => ['nullable', Rule::in(['NORMAL', 'ST'])],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = self::regras();
        $regras['sku'][] = Rule::unique('produtos', 'sku')->where('tenant_id', $this->user()?->tenant_id);

        return $regras;
    }

    /** @return array{sku:string,nome:string,unidade:string,preco_centavos:int,gtin:?string,ncm:?string,cest:?string,origem:int|string|null,tributacao_icms:?string} */
    public function dados(): array
    {
        return [
            'sku' => $this->string('sku')->toString(),
            'nome' => trim($this->string('nome')->toString()),
            'unidade' => strtoupper($this->string('unidade')->toString()),
            'preco_centavos' => (int) $this->input('preco_centavos'),
            'gtin' => $this->filled('gtin') ? $this->string('gtin')->toString() : null,
            'ncm' => $this->filled('ncm') ? $this->string('ncm')->toString() : null,
            'cest' => $this->filled('cest') ? $this->string('cest')->toString() : null,
            'origem' => $this->input('origem'),
            'tributacao_icms' => $this->filled('tributacao_icms') ? $this->string('tributacao_icms')->toString() : null,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AtualizarProdutoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = CriarProdutoRequest::regras();
        $regras['sku'][] = Rule::unique('produtos', 'sku')
            ->where('tenant_id', $this->user()?->tenant_id)
            ->ignore($this->route('id'));

        return $regras;
    }

    /** @return array{sku:string,nome:string,unidade:string,preco_centavos:int,gtin:?string,ncm:?string,cest:?string,origem:int|string|null,tributacao_icms:?string} */
    public function dados(): array
    {
        return [
            'sku' => $this->string('sku')->toString(),
            'nome' => trim($this->string('nome')->toString()),
            'unidade' => strtoupper($this->string('unidade')->toString()),
            'preco_centavos' => (int) $this->input('preco_centavos'),
            'gtin' => $this->filled('gtin') ? $this->string('gtin')->toString() : null,
            'ncm' => $this->filled('ncm') ? $this->string('ncm')->toString() : null,
            'cest' => $this->filled('cest') ? $this->string('cest')->toString() : null,
            'origem' => $this->input('origem'),
            'tributacao_icms' => $this->filled('tributacao_icms') ? $this->string('tributacao_icms')->toString() : null,
        ];
    }
}
```

- [ ] **Step 7: Criar o Resource**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Domain\Models\Produto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Produto */
final class ProdutoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'nome' => $this->nome,
            'unidade' => $this->unidade,
            'preco_centavos' => $this->preco_centavos,
            'gtin' => $this->gtin,
            'ncm' => $this->ncm,
            'cest' => $this->cest,
            'origem' => $this->origem,
            'tributacao_icms' => $this->tributacao_icms?->value,
            'fiscal_fonte' => $this->fiscal_fonte->value,
            'fiscal_revisado_em' => $this->fiscal_revisado_em?->toIso8601String(),
            'pendente_fiscal' => $this->pendenteDeRevisaoFiscal(),
        ];
    }
}
```

- [ ] **Step 8: Criar o Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Actions\AtualizarProduto;
use App\Modules\Catalog\Application\Actions\CriarProduto;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Http\Requests\AtualizarProdutoRequest;
use App\Modules\Catalog\Http\Requests\CriarProdutoRequest;
use App\Modules\Catalog\Http\Resources\ProdutoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProdutosController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): AnonymousResourceCollection
    {
        return ProdutoResource::collection(Produto::query()->orderBy('nome')->get());
    }

    public function show(string $id): ProdutoResource
    {
        return new ProdutoResource(Produto::query()->findOrFail($id));
    }

    public function store(CriarProdutoRequest $request, CriarProduto $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return (new ProdutoResource($criar->executar($this->autor($request), $request->dados())))
            ->response()->setStatusCode(201);
    }

    public function update(AtualizarProdutoRequest $request, string $id, AtualizarProduto $atualizar): ProdutoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $produto = Produto::query()->findOrFail($id);

        return new ProdutoResource($atualizar->executar($produto, $request->dados()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

- [ ] **Step 9: Criar as rotas e o provider do módulo**

```php
<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\ProdutosController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('produtos', [ProdutosController::class, 'index'])->name('app.produtos.index');
    Route::post('produtos', [ProdutosController::class, 'store'])->name('app.produtos.store');
    Route::get('produtos/{id}', [ProdutosController::class, 'show'])->name('app.produtos.show');
    Route::put('produtos/{id}', [ProdutosController::class, 'update'])->name('app.produtos.update');
});
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Application\ContadorDeProdutos;
use App\Modules\Platform\Application\RegistroDeContadores;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeProdutos);

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
    }
}
```

Em `backend/bootstrap/providers.php`, acrescentar `App\Modules\Catalog\Providers\CatalogServiceProvider::class` à lista.

- [ ] **Step 10: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ProdutosTest`
Expected: PASS (9 testes).

- [ ] **Step 11: Commit**

```bash
git add backend/app/Modules/Shared/Application/PoliticaDeCadastros.php backend/app/Modules/Catalog backend/bootstrap/providers.php backend/tests/Feature/Catalog/ProdutosTest.php
git commit -m "feat(catalog): CRUD de produto com limite de plano e política de cadastros"
```

---

### Tarefa 7: `Catalog` — CRUD de Serviço

**Files:**
- Create: `backend/app/Modules/Catalog/Application/Actions/CriarServico.php`
- Create: `backend/app/Modules/Catalog/Application/Actions/AtualizarServico.php`
- Create: `backend/app/Modules/Catalog/Application/ContadorDeServicos.php`
- Create: `backend/app/Modules/Catalog/Http/Requests/CriarServicoRequest.php`
- Create: `backend/app/Modules/Catalog/Http/Requests/AtualizarServicoRequest.php`
- Create: `backend/app/Modules/Catalog/Http/Resources/ServicoResource.php`
- Create: `backend/app/Modules/Catalog/Http/Controllers/ServicosController.php`
- Modify: `backend/app/Modules/Catalog/Http/routes-app.php` (acrescentar as rotas de `servicos`)
- Modify: `backend/app/Modules/Catalog/Providers/CatalogServiceProvider.php` (registrar `ContadorDeServicos`)
- Test: `backend/tests/Feature/Catalog/ServicosTest.php`

**Interfaces:**
- Consumes: `Servico` (Tarefa 4), `PoliticaDeCadastros` (Tarefa 6), `EntitlementService` + `Recurso::Servicos` (F1).
- Produces: Rotas `GET|POST /api/app/servicos`, `GET|PUT /api/app/servicos/{id}`. Usado pela Tarefa 20 (API pública) e pela Tarefa 22 (frontend).

- [ ] **Step 1: Escrever os testes (devem falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Servicos->value => 1]))->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
    }

    public function test_cria_servico(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000,
        ])->assertCreated()->assertJsonPath('data.nome', 'Consultoria');
    }

    public function test_codigo_lc116_fora_do_formato_e_recusado(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000, 'codigo_lc116' => '1401',
        ])->assertStatus(422)->assertJsonValidationErrors('codigo_lc116');
    }

    public function test_servico_completo_nao_fica_pendente(): void
    {
        $resposta = $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000,
            'codigo_lc116' => '14.01', 'c_trib_nac' => '140101', 'aliquota_iss' => '5.00',
        ])->assertCreated();

        $this->assertFalse($resposta->json('data.pendente_fiscal'));
    }

    public function test_respeita_o_limite_do_plano(): void
    {
        Servico::factory()->for($this->tenant)->create();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000,
        ])->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_DO_PLANO');
    }

    public function test_leitura_nao_cria_servico(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000,
        ])->assertForbidden()->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_servico_de_outro_tenant_responde_404(): void
    {
        $alheio = Servico::factory()->for(Tenant::factory())->create();

        $this->spa()->actingAs($this->admin)->getJson("/api/app/servicos/{$alheio->id}")->assertNotFound();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ServicosTest`
Expected: FAIL (rota `servicos` inexistente).

- [ ] **Step 3: Criar as Actions**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class CriarServico
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param array{nome:string,preco_centavos:int,codigo_lc116:?string,c_trib_nac:?string,codigo_municipal:?string,aliquota_iss:?string,nbs:?string} $dados */
    public function executar(Usuario $autor, array $dados): Servico
    {
        return DB::transaction(function () use ($autor, $dados): Servico {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($autor->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Servicos);

            return Servico::create($dados);
        });
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\Servico;

final class AtualizarServico
{
    /** @param array{nome:string,preco_centavos:int,codigo_lc116:?string,c_trib_nac:?string,codigo_municipal:?string,aliquota_iss:?string,nbs:?string} $dados */
    public function executar(Servico $servico, array $dados): Servico
    {
        $servico->update($dados);

        return $servico;
    }
}
```

- [ ] **Step 4: Criar o contador de uso**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class ContadorDeServicos implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::Servicos;
    }

    public function contar(Tenant $tenant): int
    {
        return Servico::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count();
    }
}
```

- [ ] **Step 5: Criar os Requests**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CriarServicoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public static function regras(): array
    {
        return [
            'nome' => ['required', 'string', 'max:150'],
            'preco_centavos' => ['required', 'integer', 'min:0'],
            'codigo_lc116' => ['nullable', 'string', 'regex:/^\d{2}\.\d{2}$/'],
            'c_trib_nac' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'codigo_municipal' => ['nullable', 'string', 'regex:/^\d{3}$/'],
            'aliquota_iss' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'nbs' => ['nullable', 'string', 'max:9'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return self::regras();
    }

    /** @return array{nome:string,preco_centavos:int,codigo_lc116:?string,c_trib_nac:?string,codigo_municipal:?string,aliquota_iss:?string,nbs:?string} */
    public function dados(): array
    {
        return [
            'nome' => trim($this->string('nome')->toString()),
            'preco_centavos' => (int) $this->input('preco_centavos'),
            'codigo_lc116' => $this->filled('codigo_lc116') ? $this->string('codigo_lc116')->toString() : null,
            'c_trib_nac' => $this->filled('c_trib_nac') ? $this->string('c_trib_nac')->toString() : null,
            'codigo_municipal' => $this->filled('codigo_municipal') ? $this->string('codigo_municipal')->toString() : null,
            'aliquota_iss' => $this->filled('aliquota_iss') ? (string) $this->input('aliquota_iss') : null,
            'nbs' => $this->filled('nbs') ? $this->string('nbs')->toString() : null,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarServicoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return CriarServicoRequest::regras();
    }

    /** @return array{nome:string,preco_centavos:int,codigo_lc116:?string,c_trib_nac:?string,codigo_municipal:?string,aliquota_iss:?string,nbs:?string} */
    public function dados(): array
    {
        return [
            'nome' => trim($this->string('nome')->toString()),
            'preco_centavos' => (int) $this->input('preco_centavos'),
            'codigo_lc116' => $this->filled('codigo_lc116') ? $this->string('codigo_lc116')->toString() : null,
            'c_trib_nac' => $this->filled('c_trib_nac') ? $this->string('c_trib_nac')->toString() : null,
            'codigo_municipal' => $this->filled('codigo_municipal') ? $this->string('codigo_municipal')->toString() : null,
            'aliquota_iss' => $this->filled('aliquota_iss') ? (string) $this->input('aliquota_iss') : null,
            'nbs' => $this->filled('nbs') ? $this->string('nbs')->toString() : null,
        ];
    }
}
```

- [ ] **Step 6: Criar o Resource**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Domain\Models\Servico;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Servico */
final class ServicoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'preco_centavos' => $this->preco_centavos,
            'codigo_lc116' => $this->codigo_lc116,
            'c_trib_nac' => $this->c_trib_nac,
            'codigo_municipal' => $this->codigo_municipal,
            'aliquota_iss' => $this->aliquota_iss,
            'nbs' => $this->nbs,
            'pendente_fiscal' => $this->pendenteDeRevisaoFiscal(),
        ];
    }
}
```

- [ ] **Step 7: Criar o Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Actions\AtualizarServico;
use App\Modules\Catalog\Application\Actions\CriarServico;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Catalog\Http\Requests\AtualizarServicoRequest;
use App\Modules\Catalog\Http\Requests\CriarServicoRequest;
use App\Modules\Catalog\Http\Resources\ServicoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ServicosController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): AnonymousResourceCollection
    {
        return ServicoResource::collection(Servico::query()->orderBy('nome')->get());
    }

    public function show(string $id): ServicoResource
    {
        return new ServicoResource(Servico::query()->findOrFail($id));
    }

    public function store(CriarServicoRequest $request, CriarServico $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return (new ServicoResource($criar->executar($this->autor($request), $request->dados())))
            ->response()->setStatusCode(201);
    }

    public function update(AtualizarServicoRequest $request, string $id, AtualizarServico $atualizar): ServicoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $servico = Servico::query()->findOrFail($id);

        return new ServicoResource($atualizar->executar($servico, $request->dados()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

- [ ] **Step 8: Acrescentar as rotas e registrar o contador**

Em `backend/app/Modules/Catalog/Http/routes-app.php`, dentro do mesmo `Route::middleware('empresa')->group(...)`:

```php
    Route::get('servicos', [ServicosController::class, 'index'])->name('app.servicos.index');
    Route::post('servicos', [ServicosController::class, 'store'])->name('app.servicos.store');
    Route::get('servicos/{id}', [ServicosController::class, 'show'])->name('app.servicos.show');
    Route::put('servicos/{id}', [ServicosController::class, 'update'])->name('app.servicos.update');
```

(E acrescentar `use App\Modules\Catalog\Http\Controllers\ServicosController;` no topo do arquivo.)

Em `backend/app/Modules/Catalog/Providers/CatalogServiceProvider.php`, no `boot()`:

```php
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeServicos);
```

(com o `use App\Modules\Catalog\Application\ContadorDeServicos;` correspondente.)

- [ ] **Step 9: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ServicosTest`
Expected: PASS (6 testes).

- [ ] **Step 10: Commit**

```bash
git add backend/app/Modules/Catalog backend/tests/Feature/Catalog/ServicosTest.php
git commit -m "feat(catalog): CRUD de serviço com limite de plano"
```

---

### Tarefa 8: `Catalog` — Categoria fiscal padrão e "aplicar categoria"

**Files:**
- Create: `backend/app/Modules/Catalog/Application/Actions/CriarCategoriaFiscal.php`
- Create: `backend/app/Modules/Catalog/Application/Actions/AtualizarCategoriaFiscal.php`
- Create: `backend/app/Modules/Catalog/Application/Actions/AplicarCategoriaFiscal.php`
- Create: `backend/app/Modules/Catalog/Http/Requests/CriarCategoriaFiscalRequest.php`
- Create: `backend/app/Modules/Catalog/Http/Requests/AtualizarCategoriaFiscalRequest.php`
- Create: `backend/app/Modules/Catalog/Http/Resources/CategoriaFiscalPadraoResource.php`
- Create: `backend/app/Modules/Catalog/Http/Controllers/CategoriasFiscaisController.php`
- Modify: `backend/app/Modules/Catalog/Http/routes-app.php`
- Test: `backend/tests/Feature/Catalog/CategoriasFiscaisTest.php`

**Interfaces:**
- Consumes: `CategoriaFiscalPadrao`, `Produto` (Tarefa 4), `PoliticaDeCadastros` (Tarefa 6).
- Produces: `AplicarCategoriaFiscal::executar(Produto, CategoriaFiscalPadrao): Produto` — preenche só os campos vazios do produto. Rotas `GET|POST /api/app/categorias-fiscais-padrao`, `PUT .../{id}`, `POST /api/app/produtos/{produtoId}/aplicar-categoria/{categoriaId}`.

- [ ] **Step 1: Escrever os testes (devem falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriasFiscaisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
    }

    public function test_cria_categoria_fiscal_padrao(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/categorias-fiscais-padrao', [
            'categoria' => 'Ferragens', 'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
        ])->assertCreated()->assertJsonPath('data.categoria', 'Ferragens');
    }

    public function test_aplicar_categoria_preenche_so_os_campos_vazios(): void
    {
        $categoria = CategoriaFiscalPadrao::factory()->for($this->tenant)->create([
            'ncm' => '73181500', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
        ]);
        $produto = Produto::factory()->for($this->tenant)->create([
            'ncm' => '12345678', 'origem' => null, 'tributacao_icms' => null, 'fiscal_fonte' => FonteFiscal::Manual,
        ]);

        $resposta = $this->spa()->actingAs($this->admin)
            ->postJson("/api/app/produtos/{$produto->id}/aplicar-categoria/{$categoria->id}")
            ->assertOk();

        // O NCM já preenchido não é sobrescrito.
        $this->assertSame('12345678', $resposta->json('data.ncm'));
        $this->assertSame(0, $resposta->json('data.origem'));
        $this->assertSame('NORMAL', $resposta->json('data.tributacao_icms'));
        $this->assertSame('PADRAO', $resposta->json('data.fiscal_fonte'));
        $this->assertNull($resposta->json('data.fiscal_revisado_em'));
    }

    public function test_aplicar_categoria_em_produto_ja_completo_nao_muda_a_fonte(): void
    {
        $categoria = CategoriaFiscalPadrao::factory()->for($this->tenant)->create();
        $produto = Produto::factory()->completo()->for($this->tenant)->create([
            'fiscal_fonte' => FonteFiscal::Manual, 'fiscal_revisado_em' => now(),
        ]);

        $resposta = $this->spa()->actingAs($this->admin)
            ->postJson("/api/app/produtos/{$produto->id}/aplicar-categoria/{$categoria->id}")
            ->assertOk();

        $this->assertSame('MANUAL', $resposta->json('data.fiscal_fonte'));
        $this->assertNotNull($resposta->json('data.fiscal_revisado_em'));
    }

    public function test_leitura_nao_cria_categoria(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson('/api/app/categorias-fiscais-padrao', [
            'categoria' => 'Ferragens',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=CategoriasFiscaisTest`
Expected: FAIL (rota inexistente).

- [ ] **Step 3: Criar as Actions**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\ValidadorCamposFiscais;

final class CriarCategoriaFiscal
{
    /** @param array{categoria:string,ncm:?string,origem:int|string|null,tributacao_icms:?string} $dados */
    public function executar(array $dados): CategoriaFiscalPadrao
    {
        return CategoriaFiscalPadrao::create([
            'categoria' => $dados['categoria'],
            'ncm' => ValidadorCamposFiscais::ncm($dados['ncm']),
            'origem' => ValidadorCamposFiscais::origem($dados['origem']),
            'tributacao_icms' => $dados['tributacao_icms'],
        ]);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\ValidadorCamposFiscais;

final class AtualizarCategoriaFiscal
{
    /** @param array{categoria:string,ncm:?string,origem:int|string|null,tributacao_icms:?string} $dados */
    public function executar(CategoriaFiscalPadrao $categoria, array $dados): CategoriaFiscalPadrao
    {
        $categoria->update([
            'categoria' => $dados['categoria'],
            'ncm' => ValidadorCamposFiscais::ncm($dados['ncm']),
            'origem' => ValidadorCamposFiscais::origem($dados['origem']),
            'tributacao_icms' => $dados['tributacao_icms'],
        ]);

        return $categoria;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\Models\Produto;

/** Preenche só os campos vazios do produto (nunca sobrescreve um valor já preenchido). */
final class AplicarCategoriaFiscal
{
    public function executar(Produto $produto, CategoriaFiscalPadrao $categoria): Produto
    {
        $preencheuAlgo = $produto->ncm === null || $produto->origem === null || $produto->tributacao_icms === null;

        $produto->update([
            'ncm' => $produto->ncm ?? $categoria->ncm,
            'origem' => $produto->origem ?? $categoria->origem,
            'tributacao_icms' => $produto->tributacao_icms ?? $categoria->tributacao_icms,
            'fiscal_fonte' => $preencheuAlgo ? FonteFiscal::Padrao : $produto->fiscal_fonte,
            'fiscal_revisado_em' => $preencheuAlgo ? null : $produto->fiscal_revisado_em,
        ]);

        return $produto;
    }
}
```

- [ ] **Step 4: Criar os Requests**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarCategoriaFiscalRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public static function regras(): array
    {
        return [
            'categoria' => ['required', 'string', 'max:60'],
            'ncm' => ['nullable', 'string'],
            'origem' => ['nullable'],
            'tributacao_icms' => ['nullable', Rule::in(['NORMAL', 'ST'])],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = self::regras();
        $regras['categoria'][] = Rule::unique('categorias_fiscais_padrao', 'categoria')->where('tenant_id', $this->user()?->tenant_id);

        return $regras;
    }

    /** @return array{categoria:string,ncm:?string,origem:int|string|null,tributacao_icms:?string} */
    public function dados(): array
    {
        return [
            'categoria' => trim($this->string('categoria')->toString()),
            'ncm' => $this->filled('ncm') ? $this->string('ncm')->toString() : null,
            'origem' => $this->input('origem'),
            'tributacao_icms' => $this->filled('tributacao_icms') ? $this->string('tributacao_icms')->toString() : null,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AtualizarCategoriaFiscalRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = CriarCategoriaFiscalRequest::regras();
        $regras['categoria'][] = Rule::unique('categorias_fiscais_padrao', 'categoria')
            ->where('tenant_id', $this->user()?->tenant_id)
            ->ignore($this->route('id'));

        return $regras;
    }

    /** @return array{categoria:string,ncm:?string,origem:int|string|null,tributacao_icms:?string} */
    public function dados(): array
    {
        return [
            'categoria' => trim($this->string('categoria')->toString()),
            'ncm' => $this->filled('ncm') ? $this->string('ncm')->toString() : null,
            'origem' => $this->input('origem'),
            'tributacao_icms' => $this->filled('tributacao_icms') ? $this->string('tributacao_icms')->toString() : null,
        ];
    }
}
```

- [ ] **Step 5: Criar o Resource**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CategoriaFiscalPadrao */
final class CategoriaFiscalPadraoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categoria' => $this->categoria,
            'ncm' => $this->ncm,
            'origem' => $this->origem,
            'tributacao_icms' => $this->tributacao_icms?->value,
        ];
    }
}
```

- [ ] **Step 6: Criar o Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Actions\AplicarCategoriaFiscal;
use App\Modules\Catalog\Application\Actions\AtualizarCategoriaFiscal;
use App\Modules\Catalog\Application\Actions\CriarCategoriaFiscal;
use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Http\Requests\AtualizarCategoriaFiscalRequest;
use App\Modules\Catalog\Http\Requests\CriarCategoriaFiscalRequest;
use App\Modules\Catalog\Http\Resources\CategoriaFiscalPadraoResource;
use App\Modules\Catalog\Http\Resources\ProdutoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CategoriasFiscaisController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): AnonymousResourceCollection
    {
        return CategoriaFiscalPadraoResource::collection(CategoriaFiscalPadrao::query()->orderBy('categoria')->get());
    }

    public function store(CriarCategoriaFiscalRequest $request, CriarCategoriaFiscal $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return (new CategoriaFiscalPadraoResource($criar->executar($request->dados())))->response()->setStatusCode(201);
    }

    public function update(AtualizarCategoriaFiscalRequest $request, string $id, AtualizarCategoriaFiscal $atualizar): CategoriaFiscalPadraoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $categoria = CategoriaFiscalPadrao::query()->findOrFail($id);

        return new CategoriaFiscalPadraoResource($atualizar->executar($categoria, $request->dados()));
    }

    public function aplicar(Request $request, string $produtoId, string $categoriaId, AplicarCategoriaFiscal $aplicar): ProdutoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $produto = Produto::query()->findOrFail($produtoId);
        $categoria = CategoriaFiscalPadrao::query()->findOrFail($categoriaId);

        return new ProdutoResource($aplicar->executar($produto, $categoria));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

- [ ] **Step 7: Acrescentar as rotas**

Em `backend/app/Modules/Catalog/Http/routes-app.php`:

```php
    Route::get('categorias-fiscais-padrao', [CategoriasFiscaisController::class, 'index'])->name('app.categorias-fiscais.index');
    Route::post('categorias-fiscais-padrao', [CategoriasFiscaisController::class, 'store'])->name('app.categorias-fiscais.store');
    Route::put('categorias-fiscais-padrao/{id}', [CategoriasFiscaisController::class, 'update'])->name('app.categorias-fiscais.update');
    Route::post('produtos/{produtoId}/aplicar-categoria/{categoriaId}', [CategoriasFiscaisController::class, 'aplicar'])->name('app.produtos.aplicar-categoria');
```

(com `use App\Modules\Catalog\Http\Controllers\CategoriasFiscaisController;` no topo.)

- [ ] **Step 8: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=CategoriasFiscaisTest`
Expected: PASS (4 testes).

- [ ] **Step 9: Commit**

```bash
git add backend/app/Modules/Catalog backend/tests/Feature/Catalog/CategoriasFiscaisTest.php
git commit -m "feat(catalog): categoria fiscal padrão e aplicação em produto"
```

---

### Tarefa 9: `Catalog` — Pendências fiscais (produtos e serviços)

**Files:**
- Create: `backend/app/Modules/Catalog/Http/Controllers/PendenciasFiscaisController.php`
- Modify: `backend/app/Modules/Catalog/Http/routes-app.php`
- Test: `backend/tests/Feature/Catalog/PendenciasFiscaisTest.php`

**Interfaces:**
- Consumes: `Produto::pendenteDeRevisaoFiscal()`, `Servico::pendenteDeRevisaoFiscal()` (Tarefa 4), `PoliticaDeCadastros` (Tarefa 6).
- Produces: `GET /api/app/produtos/pendencias-fiscais` → `{data: {produtos: [...], servicos: [...]}}`. `POST /api/app/produtos/{id}/marcar-revisado`. Usado pela Tarefa 21 (frontend).

- [ ] **Step 1: Escrever os testes (devem falhar: rota inexistente)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendenciasFiscaisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
    }

    public function test_lista_so_produtos_e_servicos_pendentes(): void
    {
        Produto::factory()->completo()->for($this->tenant)->create();
        $pendente = Produto::factory()->for($this->tenant)->create(['ncm' => null]);
        Servico::factory()->completo()->for($this->tenant)->create();
        $servicoPendente = Servico::factory()->for($this->tenant)->create(['codigo_lc116' => null]);

        $resposta = $this->spa()->actingAs($this->admin)->getJson('/api/app/produtos/pendencias-fiscais')->assertOk();

        $resposta->assertJsonCount(1, 'data.produtos')->assertJsonCount(1, 'data.servicos');
        $this->assertSame($pendente->id, $resposta->json('data.produtos.0.id'));
        $this->assertSame($servicoPendente->id, $resposta->json('data.servicos.0.id'));
    }

    public function test_produto_com_origem_zero_nao_aparece_como_pendente_por_causa_da_origem(): void
    {
        Produto::factory()->for($this->tenant)->create(['ncm' => '12345678', 'origem' => 0, 'tributacao_icms' => 'NORMAL']);

        $this->spa()->actingAs($this->admin)->getJson('/api/app/produtos/pendencias-fiscais')
            ->assertOk()->assertJsonCount(0, 'data.produtos');
    }

    public function test_produto_fonte_padrao_nao_revisado_aparece_como_pendente(): void
    {
        Produto::factory()->completo()->for($this->tenant)->create(['fiscal_fonte' => FonteFiscal::Padrao, 'fiscal_revisado_em' => null]);

        $this->spa()->actingAs($this->admin)->getJson('/api/app/produtos/pendencias-fiscais')
            ->assertOk()->assertJsonCount(1, 'data.produtos');
    }

    public function test_marcar_revisado_tira_da_lista(): void
    {
        $produto = Produto::factory()->completo()->for($this->tenant)->create(['fiscal_fonte' => FonteFiscal::Padrao, 'fiscal_revisado_em' => null]);

        $this->spa()->actingAs($this->admin)->postJson("/api/app/produtos/{$produto->id}/marcar-revisado")
            ->assertOk()->assertJsonPath('data.pendente_fiscal', false);

        $this->spa()->actingAs($this->admin)->getJson('/api/app/produtos/pendencias-fiscais')
            ->assertOk()->assertJsonCount(0, 'data.produtos');
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=PendenciasFiscaisTest`
Expected: FAIL (rota inexistente).

- [ ] **Step 3: Criar o Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Catalog\Http\Resources\ProdutoResource;
use App\Modules\Catalog\Http\Resources\ServicoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PendenciasFiscaisController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): JsonResponse
    {
        $produtos = Produto::query()->get()->filter(fn (Produto $p): bool => $p->pendenteDeRevisaoFiscal())->values();
        $servicos = Servico::query()->get()->filter(fn (Servico $s): bool => $s->pendenteDeRevisaoFiscal())->values();

        return response()->json([
            'data' => [
                'produtos' => ProdutoResource::collection($produtos),
                'servicos' => ServicoResource::collection($servicos),
            ],
        ]);
    }

    public function marcarRevisado(Request $request, string $id): ProdutoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $produto = Produto::query()->findOrFail($id);
        $produto->update(['fiscal_revisado_em' => now()]);

        return new ProdutoResource($produto);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

- [ ] **Step 4: Acrescentar as rotas**

Em `backend/app/Modules/Catalog/Http/routes-app.php`, **antes** da rota `produtos/{id}` (senão `pendencias-fiscais` seria capturado como um `{id}`):

```php
    Route::get('produtos/pendencias-fiscais', [PendenciasFiscaisController::class, 'index'])->name('app.produtos.pendencias-fiscais');
    Route::post('produtos/{id}/marcar-revisado', [PendenciasFiscaisController::class, 'marcarRevisado'])->name('app.produtos.marcar-revisado');
```

(com `use App\Modules\Catalog\Http\Controllers\PendenciasFiscaisController;` no topo.)

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=PendenciasFiscaisTest`
Expected: PASS (4 testes).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Catalog/Http/Controllers/PendenciasFiscaisController.php backend/app/Modules/Catalog/Http/routes-app.php backend/tests/Feature/Catalog/PendenciasFiscaisTest.php
git commit -m "feat(catalog): pendências fiscais de produtos e serviços"
```

---

### Tarefa 10: `Customers` — modelo de dados (Cliente, Contato)

**Files:**
- Create: `backend/database/migrations/2026_10_01_000002_create_customers_tables.php`
- Create: `backend/app/Modules/Customers/Domain/Enums/TipoCliente.php`
- Create: `backend/app/Modules/Customers/Domain/Enums/EstagioCliente.php`
- Create: `backend/app/Modules/Customers/Domain/Models/Cliente.php`
- Create: `backend/app/Modules/Customers/Domain/Models/Contato.php`
- Create: `backend/database/factories/ClienteFactory.php`
- Create: `backend/database/factories/ContatoFactory.php`
- Test: `backend/tests/Feature/Customers/CustomersIsolamentoTest.php`

**Interfaces:**
- Consumes: `BelongsToTenant` (trait existente).
- Produces: Models `Cliente` (com `contatos(): HasMany`) e `Contato` (com `cliente(): BelongsTo`). Enums `TipoCliente::{Pf,Pj}`, `EstagioCliente::{Lead,Cliente}`. Usado pelas Tarefas 11, 12, 13 e 19.

- [ ] **Step 1: Escrever o teste de isolamento (deve falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Domain\Models\Contato;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomersIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Cliente::factory()->create());
    }

    public function test_contato_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Contato::factory()->for(Cliente::factory())->create());
    }

    public function test_dois_tenants_podem_ter_o_mesmo_cpf(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        Cliente::factory()->comCpfValido()->create(['cpf_cnpj' => '11144477735']);

        $contexto->set($tenantB->id);
        $clienteB = Cliente::factory()->comCpfValido()->create(['cpf_cnpj' => '11144477735']);

        $this->assertSame('11144477735', $clienteB->cpf_cnpj);
    }

    private function verificaIsolamento(Closure $criar): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        /** @var Model $registro */
        $registro = $criar();
        $this->assertSame($tenantA->id, $registro->getAttribute('tenant_id'));

        $contexto->set($tenantB->id);
        $this->assertSame(0, $registro::query()->count());
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=CustomersIsolamentoTest`
Expected: FAIL (`Class "App\Modules\Customers\Domain\Models\Cliente" not found`).

- [ ] **Step 3: Criar a migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('tipo', 2);
            $table->string('nome', 150);
            $table->string('cpf_cnpj', 14)->nullable();
            $table->string('inscricao_estadual', 20)->nullable();
            $table->boolean('ie_isento')->default(false);
            $table->string('email', 150)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('logradouro', 150)->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('bairro', 100)->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('codigo_ibge', 7)->nullable();
            $table->json('tags')->nullable();
            $table->string('origem', 60)->nullable();
            $table->string('estagio', 10)->default('LEAD');
            $table->timestamps();
            // Postgres trata NULL como distinto em índice único: vários leads sem
            // CPF/CNPJ convivem, mas o mesmo CPF/CNPJ não duplica no mesmo tenant.
            $table->unique(['tenant_id', 'cpf_cnpj']);
            $table->index(['tenant_id', 'nome']);
        });

        Schema::create('contatos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('nome', 150);
            $table->string('cargo', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contatos');
        Schema::dropIfExists('clientes');
    }
};
```

- [ ] **Step 4: Criar os enums**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Enums;

enum TipoCliente: string
{
    case Pf = 'PF';
    case Pj = 'PJ';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Enums;

enum EstagioCliente: string
{
    case Lead = 'LEAD';
    case Cliente = 'CLIENTE';
}
```

- [ ] **Step 5: Criar os Models**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Models;

use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property TipoCliente $tipo
 * @property string $nome
 * @property ?string $cpf_cnpj
 * @property ?string $inscricao_estadual
 * @property bool $ie_isento
 * @property ?string $email
 * @property ?string $telefone
 * @property ?string $uf
 * @property array<int, string> $tags
 * @property ?string $origem
 * @property EstagioCliente $estagio
 */
class Cliente extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<ClienteFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tipo', 'nome', 'cpf_cnpj', 'inscricao_estadual', 'ie_isento', 'email', 'telefone',
        'logradouro', 'numero', 'bairro', 'cidade', 'uf', 'cep', 'codigo_ibge',
        'tags', 'origem', 'estagio',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoCliente::class,
            'ie_isento' => 'boolean',
            'tags' => 'array',
            'estagio' => EstagioCliente::class,
        ];
    }

    /** @return HasMany<Contato, $this> */
    public function contatos(): HasMany
    {
        return $this->hasMany(Contato::class);
    }

    protected static function newFactory(): ClienteFactory
    {
        return ClienteFactory::new();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\ContatoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $cliente_id
 * @property string $nome
 * @property ?string $cargo
 * @property ?string $email
 * @property ?string $telefone
 */
class Contato extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<ContatoFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = ['cliente_id', 'nome', 'cargo', 'email', 'telefone'];

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    protected static function newFactory(): ContatoFactory
    {
        return ContatoFactory::new();
    }
}
```

- [ ] **Step 6: Criar as factories**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cliente> */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tipo' => TipoCliente::Pf,
            'nome' => fake()->name(),
            'cpf_cnpj' => null,
            'inscricao_estadual' => null,
            'ie_isento' => false,
            'email' => fake()->unique()->safeEmail(),
            'telefone' => fake()->numerify('###########'),
            'logradouro' => null,
            'numero' => null,
            'bairro' => null,
            'cidade' => null,
            'uf' => null,
            'cep' => null,
            'codigo_ibge' => null,
            'tags' => [],
            'origem' => null,
            'estagio' => EstagioCliente::Lead,
        ];
    }

    public function cliente(): static
    {
        return $this->state(fn (): array => ['estagio' => EstagioCliente::Cliente, 'cpf_cnpj' => self::cpfValido()]);
    }

    public function comCpfValido(): static
    {
        return $this->state(fn (): array => ['tipo' => TipoCliente::Pf, 'cpf_cnpj' => self::cpfValido()]);
    }

    /** Gera um CPF numericamente válido (mesmo algoritmo de `App\Modules\Shared\Domain\Cpf`), só para dado de teste. */
    private static function cpfValido(): string
    {
        $n = [];
        for ($i = 0; $i < 9; $i++) {
            $n[] = random_int(0, 9);
        }
        $n[] = self::digito($n, [10, 9, 8, 7, 6, 5, 4, 3, 2]);
        $n[] = self::digito($n, [11, 10, 9, 8, 7, 6, 5, 4, 3, 2]);

        return implode('', $n);
    }

    /** @param list<int> $digitos @param list<int> $pesos */
    private static function digito(array $digitos, array $pesos): int
    {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += $digitos[$i] * $peso;
        }
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Domain\Models\Contato;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contato> */
class ContatoFactory extends Factory
{
    protected $model = Contato::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nome' => fake()->name(),
            'cargo' => null,
            'email' => fake()->unique()->safeEmail(),
            'telefone' => fake()->numerify('###########'),
        ];
    }
}
```

- [ ] **Step 7: Migrar e rodar os testes**

Run: `cd backend && php artisan migrate --env=testing --force && php artisan test --filter=CustomersIsolamentoTest`
Expected: PASS (3 testes).

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_10_01_000002_create_customers_tables.php backend/app/Modules/Customers backend/database/factories/ClienteFactory.php backend/database/factories/ContatoFactory.php backend/tests/Feature/Customers/CustomersIsolamentoTest.php
git commit -m "feat(customers): modelo de dados de cliente e contato"
```

---

### Tarefa 11: `Customers` — CRUD de Cliente

**Files:**
- Create: `backend/app/Modules/Customers/Application/Actions/CriarCliente.php`
- Create: `backend/app/Modules/Customers/Application/Actions/AtualizarCliente.php`
- Create: `backend/app/Modules/Customers/Application/ContadorDeClientes.php`
- Create: `backend/app/Modules/Customers/Http/Requests/CriarClienteRequest.php`
- Create: `backend/app/Modules/Customers/Http/Requests/AtualizarClienteRequest.php`
- Create: `backend/app/Modules/Customers/Http/Resources/ClienteResource.php`
- Create: `backend/app/Modules/Customers/Http/Controllers/ClientesController.php`
- Create: `backend/app/Modules/Customers/Http/routes-app.php`
- Create: `backend/app/Modules/Customers/Providers/CustomersServiceProvider.php`
- Modify: `backend/bootstrap/providers.php`
- Test: `backend/tests/Feature/Customers/ClientesTest.php`

**Interfaces:**
- Consumes: `Cliente` (Tarefa 10), `Cpf` (Tarefa 1), `Cnpj` (F1), `PoliticaDeCadastros` (Tarefa 6), `EntitlementService` + `Recurso::Clientes` (F1).
- Produces: Rotas `GET|POST /api/app/clientes`, `GET|PUT /api/app/clientes/{id}`. `ClienteResource` — a Tarefa 12 vai acrescentar a chave `contatos`. Usado pela Tarefa 13, pela Tarefa 20 (API pública) e pela Tarefa 23 (frontend).

- [ ] **Step 1: Escrever os testes (devem falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Clientes->value => 2]))->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
    }

    public function test_cria_lead_sem_nenhum_dado_fiscal(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria',
        ])->assertCreated()
            ->assertJsonPath('data.nome', 'Maria')
            ->assertJsonPath('data.estagio', 'LEAD')
            ->assertJsonPath('data.cpf_cnpj', null);
    }

    public function test_cria_cliente_pf_com_cpf_valido(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.444.777-35',
        ])->assertCreated()->assertJsonPath('data.cpf_cnpj', '11144477735');
    }

    public function test_cria_cliente_pj_com_cnpj_valido(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PJ', 'nome' => 'Padaria', 'cpf_cnpj' => '11.222.333/0001-81',
        ])->assertCreated()->assertJsonPath('data.cpf_cnpj', '11222333000181');
    }

    public function test_cpf_invalido_e_recusado(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.111.111-11',
        ])->assertStatus(422)->assertJsonValidationErrors('cpf_cnpj');
    }

    public function test_cpf_duplicado_no_mesmo_tenant_e_recusado(): void
    {
        Cliente::factory()->comCpfValido()->for($this->tenant)->create(['cpf_cnpj' => '11144477735']);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Outra Maria', 'cpf_cnpj' => '111.444.777-35',
        ])->assertStatus(422)->assertJsonValidationErrors('cpf_cnpj');
    }

    public function test_cpf_igual_em_outro_tenant_e_permitido(): void
    {
        Cliente::factory()->comCpfValido()->for(Tenant::factory())->create(['cpf_cnpj' => '11144477735']);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria', 'cpf_cnpj' => '111.444.777-35',
        ])->assertCreated();
    }

    public function test_respeita_o_limite_do_plano(): void
    {
        Cliente::factory()->for($this->tenant)->count(2)->create();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria',
        ])->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_DO_PLANO');
    }

    public function test_leitura_nao_cria_cliente(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Maria',
        ])->assertForbidden()->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_cliente_de_outro_tenant_responde_404(): void
    {
        $alheio = Cliente::factory()->for(Tenant::factory())->create();

        $this->spa()->actingAs($this->admin)->getJson("/api/app/clientes/{$alheio->id}")->assertNotFound();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ClientesTest`
Expected: FAIL (rota inexistente).

- [ ] **Step 3: Criar as Actions**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class CriarCliente
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param array<string, mixed> $dados */
    public function executar(Usuario $autor, array $dados): Cliente
    {
        return DB::transaction(function () use ($autor, $dados): Cliente {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($autor->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Clientes);

            return Cliente::create($dados);
        });
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Cliente;

final class AtualizarCliente
{
    /** @param array<string, mixed> $dados */
    public function executar(Cliente $cliente, array $dados): Cliente
    {
        $cliente->update($dados);

        return $cliente;
    }
}
```

- [ ] **Step 4: Criar o contador de uso**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** Conta leads e clientes: um registro é um registro, independente do estágio. */
final class ContadorDeClientes implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::Clientes;
    }

    public function contar(Tenant $tenant): int
    {
        return Cliente::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count();
    }
}
```

- [ ] **Step 5: Criar os Requests**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Domain\Cpf;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarClienteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'cpf_cnpj' => $this->filled('cpf_cnpj') ? preg_replace('/\D/', '', (string) $this->input('cpf_cnpj')) : null,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public static function regras(): array
    {
        return [
            'tipo' => ['required', Rule::in(['PF', 'PJ'])],
            'nome' => ['required', 'string', 'max:150'],
            'cpf_cnpj' => ['nullable', 'string'],
            'inscricao_estadual' => ['nullable', 'string', 'max:20'],
            'ie_isento' => ['boolean'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'logradouro' => ['nullable', 'string', 'max:150'],
            'numero' => ['nullable', 'string', 'max:20'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'uf' => ['nullable', 'string', 'size:2'],
            'cep' => ['nullable', 'string'],
            'codigo_ibge' => ['nullable', 'string'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:30'],
            'origem' => ['nullable', 'string', 'max:60'],
            'estagio' => ['nullable', Rule::in(['LEAD', 'CLIENTE'])],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = self::regras();
        $regras['cpf_cnpj'][] = function (string $attribute, mixed $value, Closure $fail): void {
            $valido = $this->input('tipo') === 'PJ' ? Cnpj::tentar((string) $value) !== null : Cpf::tentar((string) $value) !== null;
            if (! $valido) {
                $fail($this->input('tipo') === 'PJ' ? 'Informe um CNPJ válido.' : 'Informe um CPF válido.');
            }
        };
        $regras['cpf_cnpj'][] = Rule::unique('clientes', 'cpf_cnpj')->where('tenant_id', $this->user()?->tenant_id);

        return $regras;
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        return [
            'tipo' => $this->string('tipo')->toString(),
            'nome' => trim($this->string('nome')->toString()),
            'cpf_cnpj' => $this->input('cpf_cnpj'),
            'inscricao_estadual' => $this->filled('inscricao_estadual') ? $this->string('inscricao_estadual')->toString() : null,
            'ie_isento' => $this->boolean('ie_isento'),
            'email' => $this->filled('email') ? mb_strtolower($this->string('email')->toString()) : null,
            'telefone' => $this->filled('telefone') ? $this->string('telefone')->toString() : null,
            'logradouro' => $this->filled('logradouro') ? $this->string('logradouro')->toString() : null,
            'numero' => $this->filled('numero') ? $this->string('numero')->toString() : null,
            'bairro' => $this->filled('bairro') ? $this->string('bairro')->toString() : null,
            'cidade' => $this->filled('cidade') ? $this->string('cidade')->toString() : null,
            'uf' => $this->filled('uf') ? strtoupper($this->string('uf')->toString()) : null,
            'cep' => $this->filled('cep') ? preg_replace('/\D/', '', (string) $this->input('cep')) : null,
            'codigo_ibge' => $this->filled('codigo_ibge') ? $this->string('codigo_ibge')->toString() : null,
            'tags' => $this->input('tags', []),
            'origem' => $this->filled('origem') ? $this->string('origem')->toString() : null,
            'estagio' => $this->filled('estagio') ? $this->string('estagio')->toString() : 'LEAD',
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Domain\Cpf;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AtualizarClienteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'cpf_cnpj' => $this->filled('cpf_cnpj') ? preg_replace('/\D/', '', (string) $this->input('cpf_cnpj')) : null,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = CriarClienteRequest::regras();
        $regras['cpf_cnpj'][] = function (string $attribute, mixed $value, Closure $fail): void {
            $valido = $this->input('tipo') === 'PJ' ? Cnpj::tentar((string) $value) !== null : Cpf::tentar((string) $value) !== null;
            if (! $valido) {
                $fail($this->input('tipo') === 'PJ' ? 'Informe um CNPJ válido.' : 'Informe um CPF válido.');
            }
        };
        $regras['cpf_cnpj'][] = Rule::unique('clientes', 'cpf_cnpj')
            ->where('tenant_id', $this->user()?->tenant_id)
            ->ignore($this->route('id'));

        return $regras;
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        return [
            'tipo' => $this->string('tipo')->toString(),
            'nome' => trim($this->string('nome')->toString()),
            'cpf_cnpj' => $this->input('cpf_cnpj'),
            'inscricao_estadual' => $this->filled('inscricao_estadual') ? $this->string('inscricao_estadual')->toString() : null,
            'ie_isento' => $this->boolean('ie_isento'),
            'email' => $this->filled('email') ? mb_strtolower($this->string('email')->toString()) : null,
            'telefone' => $this->filled('telefone') ? $this->string('telefone')->toString() : null,
            'logradouro' => $this->filled('logradouro') ? $this->string('logradouro')->toString() : null,
            'numero' => $this->filled('numero') ? $this->string('numero')->toString() : null,
            'bairro' => $this->filled('bairro') ? $this->string('bairro')->toString() : null,
            'cidade' => $this->filled('cidade') ? $this->string('cidade')->toString() : null,
            'uf' => $this->filled('uf') ? strtoupper($this->string('uf')->toString()) : null,
            'cep' => $this->filled('cep') ? preg_replace('/\D/', '', (string) $this->input('cep')) : null,
            'codigo_ibge' => $this->filled('codigo_ibge') ? $this->string('codigo_ibge')->toString() : null,
            'tags' => $this->input('tags', []),
            'origem' => $this->filled('origem') ? $this->string('origem')->toString() : null,
            'estagio' => $this->filled('estagio') ? $this->string('estagio')->toString() : 'LEAD',
        ];
    }
}
```

- [ ] **Step 6: Criar o Resource**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Cliente */
final class ClienteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'nome' => $this->nome,
            'cpf_cnpj' => $this->cpf_cnpj,
            'inscricao_estadual' => $this->inscricao_estadual,
            'ie_isento' => $this->ie_isento,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'logradouro' => $this->logradouro,
            'numero' => $this->numero,
            'bairro' => $this->bairro,
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'cep' => $this->cep,
            'codigo_ibge' => $this->codigo_ibge,
            'tags' => $this->tags,
            'origem' => $this->origem,
            'estagio' => $this->estagio->value,
        ];
    }
}
```

- [ ] **Step 7: Criar o Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Application\Actions\AtualizarCliente;
use App\Modules\Customers\Application\Actions\CriarCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Http\Requests\AtualizarClienteRequest;
use App\Modules\Customers\Http\Requests\CriarClienteRequest;
use App\Modules\Customers\Http\Resources\ClienteResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ClientesController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function index(): AnonymousResourceCollection
    {
        return ClienteResource::collection(Cliente::query()->orderBy('nome')->get());
    }

    public function show(string $id): ClienteResource
    {
        return new ClienteResource(Cliente::query()->findOrFail($id));
    }

    public function store(CriarClienteRequest $request, CriarCliente $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return (new ClienteResource($criar->executar($this->autor($request), $request->dados())))
            ->response()->setStatusCode(201);
    }

    public function update(AtualizarClienteRequest $request, string $id, AtualizarCliente $atualizar): ClienteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $cliente = Cliente::query()->findOrFail($id);

        return new ClienteResource($atualizar->executar($cliente, $request->dados()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

- [ ] **Step 8: Criar as rotas e o provider do módulo**

```php
<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\ClientesController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('clientes', [ClientesController::class, 'index'])->name('app.clientes.index');
    Route::post('clientes', [ClientesController::class, 'store'])->name('app.clientes.store');
    Route::get('clientes/{id}', [ClientesController::class, 'show'])->name('app.clientes.show');
    Route::put('clientes/{id}', [ClientesController::class, 'update'])->name('app.clientes.update');
});
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Providers;

use App\Modules\Customers\Application\ContadorDeClientes;
use App\Modules\Platform\Application\RegistroDeContadores;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CustomersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeClientes);

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
    }
}
```

Em `backend/bootstrap/providers.php`, acrescentar `App\Modules\Customers\Providers\CustomersServiceProvider::class`.

- [ ] **Step 9: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ClientesTest`
Expected: PASS (9 testes).

- [ ] **Step 10: Commit**

```bash
git add backend/app/Modules/Customers backend/bootstrap/providers.php backend/tests/Feature/Customers/ClientesTest.php
git commit -m "feat(customers): CRUD de cliente com CPF/CNPJ condicional e limite de plano"
```

---

### Tarefa 12: `Customers` — CRUD de Contato (aninhado em cliente)

**Files:**
- Create: `backend/app/Modules/Customers/Application/Actions/CriarContato.php`
- Create: `backend/app/Modules/Customers/Application/Actions/AtualizarContato.php`
- Create: `backend/app/Modules/Customers/Http/Requests/CriarContatoRequest.php`
- Create: `backend/app/Modules/Customers/Http/Requests/AtualizarContatoRequest.php`
- Create: `backend/app/Modules/Customers/Http/Resources/ContatoResource.php`
- Create: `backend/app/Modules/Customers/Http/Controllers/ContatosController.php`
- Modify: `backend/app/Modules/Customers/Http/Resources/ClienteResource.php` (acrescentar a chave `contatos`)
- Modify: `backend/app/Modules/Customers/Http/Controllers/ClientesController.php` (`show` passa a carregar `contatos`)
- Modify: `backend/app/Modules/Customers/Http/routes-app.php`
- Test: `backend/tests/Feature/Customers/ContatosTest.php`

**Interfaces:**
- Consumes: `Cliente::contatos()` (Tarefa 10), `PoliticaDeCadastros` (Tarefa 6).
- Produces: Rotas `POST /api/app/clientes/{clienteId}/contatos`, `PUT /api/app/clientes/{clienteId}/contatos/{contatoId}`.

- [ ] **Step 1: Escrever os testes (devem falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Domain\Models\Contato;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContatosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        $this->cliente = Cliente::factory()->for($this->tenant)->create(['tipo' => 'PJ']);
    }

    public function test_cria_contato_do_cliente(): void
    {
        $this->spa()->actingAs($this->admin)->postJson("/api/app/clientes/{$this->cliente->id}/contatos", [
            'nome' => 'Ana', 'cargo' => 'Compras',
        ])->assertCreated()->assertJsonPath('data.nome', 'Ana');
    }

    public function test_cliente_show_traz_os_contatos(): void
    {
        Contato::factory()->for($this->cliente)->create(['nome' => 'Ana']);

        $this->spa()->actingAs($this->admin)->getJson("/api/app/clientes/{$this->cliente->id}")
            ->assertOk()->assertJsonPath('data.contatos.0.nome', 'Ana');
    }

    public function test_contato_de_outro_cliente_responde_404(): void
    {
        $outroCliente = Cliente::factory()->for($this->tenant)->create();
        $contato = Contato::factory()->for($outroCliente)->create();

        $this->spa()->actingAs($this->admin)
            ->putJson("/api/app/clientes/{$this->cliente->id}/contatos/{$contato->id}", ['nome' => 'X'])
            ->assertNotFound();
    }

    public function test_leitura_nao_cria_contato(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson("/api/app/clientes/{$this->cliente->id}/contatos", [
            'nome' => 'Ana',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ContatosTest`
Expected: FAIL (rota inexistente).

- [ ] **Step 3: Criar as Actions**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Domain\Models\Contato;

final class CriarContato
{
    /** @param array{nome:string,cargo:?string,email:?string,telefone:?string} $dados */
    public function executar(Cliente $cliente, array $dados): Contato
    {
        return $cliente->contatos()->create($dados);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Contato;

final class AtualizarContato
{
    /** @param array{nome:string,cargo:?string,email:?string,telefone:?string} $dados */
    public function executar(Contato $contato, array $dados): Contato
    {
        $contato->update($dados);

        return $contato;
    }
}
```

- [ ] **Step 4: Criar os Requests**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CriarContatoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:150'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array{nome:string,cargo:?string,email:?string,telefone:?string} */
    public function dados(): array
    {
        return [
            'nome' => trim($this->string('nome')->toString()),
            'cargo' => $this->filled('cargo') ? $this->string('cargo')->toString() : null,
            'email' => $this->filled('email') ? mb_strtolower($this->string('email')->toString()) : null,
            'telefone' => $this->filled('telefone') ? $this->string('telefone')->toString() : null,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarContatoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:150'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array{nome:string,cargo:?string,email:?string,telefone:?string} */
    public function dados(): array
    {
        return [
            'nome' => trim($this->string('nome')->toString()),
            'cargo' => $this->filled('cargo') ? $this->string('cargo')->toString() : null,
            'email' => $this->filled('email') ? mb_strtolower($this->string('email')->toString()) : null,
            'telefone' => $this->filled('telefone') ? $this->string('telefone')->toString() : null,
        ];
    }
}
```

- [ ] **Step 5: Criar o Resource**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Domain\Models\Contato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contato */
final class ContatoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'cargo' => $this->cargo,
            'email' => $this->email,
            'telefone' => $this->telefone,
        ];
    }
}
```

- [ ] **Step 6: Criar o Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Application\Actions\AtualizarContato;
use App\Modules\Customers\Application\Actions\CriarContato;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Domain\Models\Contato;
use App\Modules\Customers\Http\Requests\AtualizarContatoRequest;
use App\Modules\Customers\Http\Requests\CriarContatoRequest;
use App\Modules\Customers\Http\Resources\ContatoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Application\PoliticaDeCadastros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContatosController
{
    public function __construct(private readonly PoliticaDeCadastros $politica) {}

    public function store(CriarContatoRequest $request, string $clienteId, CriarContato $criar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $cliente = Cliente::query()->findOrFail($clienteId);

        return (new ContatoResource($criar->executar($cliente, $request->dados())))->response()->setStatusCode(201);
    }

    public function update(AtualizarContatoRequest $request, string $clienteId, string $contatoId, AtualizarContato $atualizar): ContatoResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $contato = Contato::query()->where('cliente_id', $clienteId)->findOrFail($contatoId);

        return new ContatoResource($atualizar->executar($contato, $request->dados()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

- [ ] **Step 7: Acrescentar `contatos` ao `ClienteResource` e carregar no `show`**

Em `ClienteResource::toArray()`, acrescentar:

```php
            'contatos' => ContatoResource::collection($this->whenLoaded('contatos')),
```

(com `use App\Modules\Customers\Http\Resources\ContatoResource;` — na verdade já está no mesmo namespace, então só a classe.)

Em `ClientesController::show()`, trocar `Cliente::query()->findOrFail($id)` por `Cliente::query()->with('contatos')->findOrFail($id)`.

- [ ] **Step 8: Acrescentar as rotas**

Em `backend/app/Modules/Customers/Http/routes-app.php`:

```php
    Route::post('clientes/{clienteId}/contatos', [ContatosController::class, 'store'])->name('app.clientes.contatos.store');
    Route::put('clientes/{clienteId}/contatos/{contatoId}', [ContatosController::class, 'update'])->name('app.clientes.contatos.update');
```

(com `use App\Modules\Customers\Http\Controllers\ContatosController;` no topo.)

- [ ] **Step 9: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ContatosTest`
Expected: PASS (4 testes).

- [ ] **Step 10: Commit**

```bash
git add backend/app/Modules/Customers backend/tests/Feature/Customers/ContatosTest.php
git commit -m "feat(customers): contatos aninhados em cliente"
```

---

### Tarefa 13: `Customers` — transição de estágio `LEAD → CLIENTE`

**Files:**
- Create: `backend/app/Modules/Customers/Application/Actions/ConverterEmCliente.php`
- Modify: `backend/app/Modules/Customers/Http/Controllers/ClientesController.php` (novo método `converterEmCliente`)
- Modify: `backend/app/Modules/Customers/Http/routes-app.php`
- Test: `backend/tests/Feature/Customers/ConverterEmClienteTest.php`

**Interfaces:**
- Consumes: `Cliente`, `EstagioCliente` (Tarefa 10), `PoliticaDeCadastros` (Tarefa 6).
- Produces: `POST /api/app/clientes/{id}/converter-em-cliente`.

- [ ] **Step 1: Escrever os testes (devem falhar: rota inexistente)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConverterEmClienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_converte_lead_sem_nenhum_dado_fiscal(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $lead = Cliente::factory()->for($tenant)->create(['estagio' => 'LEAD', 'cpf_cnpj' => null]);

        $this->spa()->actingAs($admin)->postJson("/api/app/clientes/{$lead->id}/converter-em-cliente")
            ->assertOk()->assertJsonPath('data.estagio', 'CLIENTE');
    }

    public function test_leitura_nao_converte(): void
    {
        $tenant = Tenant::factory()->create();
        $leitura = Usuario::factory()->for($tenant)->create(['papel' => Papel::Leitura]);
        $lead = Cliente::factory()->for($tenant)->create(['estagio' => 'LEAD']);

        $this->spa()->actingAs($leitura)->postJson("/api/app/clientes/{$lead->id}/converter-em-cliente")
            ->assertForbidden();
    }

    public function test_cliente_de_outro_tenant_responde_404(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $alheio = Cliente::factory()->for(Tenant::factory())->create(['estagio' => 'LEAD']);

        $this->spa()->actingAs($admin)->postJson("/api/app/clientes/{$alheio->id}/converter-em-cliente")
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ConverterEmClienteTest`
Expected: FAIL (rota inexistente).

- [ ] **Step 3: Criar a Action**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;

/** Sem pré-requisito de dado fiscal: a exigência só existe na emissão (F3). */
final class ConverterEmCliente
{
    public function executar(Cliente $cliente): Cliente
    {
        $cliente->update(['estagio' => EstagioCliente::Cliente]);

        return $cliente;
    }
}
```

- [ ] **Step 4: Acrescentar o método no Controller**

Em `ClientesController`, acrescentar:

```php
    public function converterEmCliente(Request $request, string $id, ConverterEmCliente $converter): ClienteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $cliente = Cliente::query()->findOrFail($id);

        return new ClienteResource($converter->executar($cliente));
    }
```

(com `use App\Modules\Customers\Application\Actions\ConverterEmCliente;` no topo.)

- [ ] **Step 5: Acrescentar a rota**

Em `backend/app/Modules/Customers/Http/routes-app.php`:

```php
    Route::post('clientes/{id}/converter-em-cliente', [ClientesController::class, 'converterEmCliente'])->name('app.clientes.converter-em-cliente');
```

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ConverterEmClienteTest`
Expected: PASS (3 testes).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/Customers/Application/Actions/ConverterEmCliente.php backend/app/Modules/Customers/Http/Controllers/ClientesController.php backend/app/Modules/Customers/Http/routes-app.php backend/tests/Feature/Customers/ConverterEmClienteTest.php
git commit -m "feat(customers): transição de estágio LEAD para CLIENTE"
```

---

### Tarefa 14: `Fiscal` — modelo de dados (Emitente, Série)

**Files:**
- Create: `backend/database/migrations/2026_10_01_000003_create_fiscal_tables.php`
- Create: `backend/app/Modules/Fiscal/Domain/Enums/AmbienteFiscal.php`
- Create: `backend/app/Modules/Fiscal/Domain/Enums/CertificadoStatus.php`
- Create: `backend/app/Modules/Fiscal/Domain/Enums/ModeloDocumento.php`
- Create: `backend/app/Modules/Fiscal/Domain/Models/Emitente.php`
- Create: `backend/app/Modules/Fiscal/Domain/Models/EmitenteSerie.php`
- Create: `backend/database/factories/EmitenteFactory.php`
- Create: `backend/database/factories/EmitenteSerieFactory.php`
- Test: `backend/tests/Feature/Fiscal/FiscalIsolamentoTest.php`

**Interfaces:**
- Consumes: `BelongsToTenant` (trait existente).
- Produces: Models `Emitente` (1:1 por tenant, com `series(): HasMany`) e `EmitenteSerie`. Enums `AmbienteFiscal::{Homologacao,Producao}`, `CertificadoStatus::{Pendente,Valido,Vencido,Invalido}`, `ModeloDocumento::{Nfe,Nfce,Dps}`. Usado pelas Tarefas 15, 16 e 17.

- [ ] **Step 1: Escrever o teste de isolamento (deve falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_emitente_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Emitente::factory()->create());
    }

    public function test_serie_e_isolada_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => EmitenteSerie::factory()->for(Emitente::factory())->create());
    }

    private function verificaIsolamento(Closure $criar): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        /** @var Model $registro */
        $registro = $criar();
        $this->assertSame($tenantA->id, $registro->getAttribute('tenant_id'));

        $contexto->set($tenantB->id);
        $this->assertSame(0, $registro::query()->count());
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=FiscalIsolamentoTest`
Expected: FAIL (`Class "App\Modules\Fiscal\Domain\Models\Emitente" not found`).

- [ ] **Step 3: Criar a migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emitentes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('logradouro', 150)->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('bairro', 100)->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('codigo_ibge', 7)->nullable();
            $table->string('regime_tributario', 30)->nullable();
            $table->string('inscricao_estadual', 20)->nullable();
            $table->string('inscricao_municipal', 20)->nullable();
            $table->string('cnae', 10)->nullable();
            $table->string('ambiente_fiscal', 12)->default('HOMOLOGACAO');
            $table->text('certificado_pfx_encrypted')->nullable();
            $table->text('certificado_senha_encrypted')->nullable();
            $table->date('certificado_validade')->nullable();
            $table->string('certificado_titular')->nullable();
            $table->string('certificado_status', 10)->default('PENDENTE');
            $table->string('csc_id_homologacao', 10)->nullable();
            $table->text('csc_token_homologacao_encrypted')->nullable();
            $table->string('csc_id_producao', 10)->nullable();
            $table->text('csc_token_producao_encrypted')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('emitente_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('emitente_id')->constrained('emitentes')->cascadeOnDelete();
            $table->string('modelo', 5);
            $table->string('serie', 3);
            $table->unsignedBigInteger('proximo_numero')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'modelo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emitente_series');
        Schema::dropIfExists('emitentes');
    }
};
```

- [ ] **Step 4: Criar os enums**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum AmbienteFiscal: string
{
    case Homologacao = 'HOMOLOGACAO';
    case Producao = 'PRODUCAO';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum CertificadoStatus: string
{
    case Pendente = 'PENDENTE';
    case Valido = 'VALIDO';
    case Vencido = 'VENCIDO';
    case Invalido = 'INVALIDO';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum ModeloDocumento: string
{
    case Nfe = 'NFE';
    case Nfce = 'NFCE';
    case Dps = 'DPS';
}
```

- [ ] **Step 5: Criar os Models**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\EmitenteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property ?string $uf
 * @property ?string $regime_tributario
 * @property AmbienteFiscal $ambiente_fiscal
 * @property ?\Carbon\CarbonInterface $certificado_validade
 * @property ?string $certificado_titular
 * @property CertificadoStatus $certificado_status
 * @property ?string $csc_id_homologacao
 * @property ?string $csc_id_producao
 */
class Emitente extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<EmitenteFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'logradouro', 'numero', 'bairro', 'cidade', 'uf', 'cep', 'codigo_ibge',
        'regime_tributario', 'inscricao_estadual', 'inscricao_municipal', 'cnae', 'ambiente_fiscal',
        'certificado_pfx_encrypted', 'certificado_senha_encrypted', 'certificado_validade',
        'certificado_titular', 'certificado_status',
        'csc_id_homologacao', 'csc_token_homologacao_encrypted', 'csc_id_producao', 'csc_token_producao_encrypted',
    ];

    protected $hidden = ['certificado_pfx_encrypted', 'certificado_senha_encrypted', 'csc_token_homologacao_encrypted', 'csc_token_producao_encrypted'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ambiente_fiscal' => AmbienteFiscal::class,
            'certificado_status' => CertificadoStatus::class,
            'certificado_validade' => 'date',
        ];
    }

    /** @return HasMany<EmitenteSerie, $this> */
    public function series(): HasMany
    {
        return $this->hasMany(EmitenteSerie::class);
    }

    protected static function newFactory(): EmitenteFactory
    {
        return EmitenteFactory::new();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\EmitenteSerieFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $emitente_id
 * @property ModeloDocumento $modelo
 * @property string $serie
 * @property int $proximo_numero
 */
class EmitenteSerie extends Model
{
    use BelongsToTenant;
    /** @use HasFactory<EmitenteSerieFactory> */
    use HasFactory;

    protected $fillable = ['emitente_id', 'modelo', 'serie', 'proximo_numero'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['modelo' => ModeloDocumento::class];
    }

    protected static function newFactory(): EmitenteSerieFactory
    {
        return EmitenteSerieFactory::new();
    }
}
```

**Nota:** `$hidden` esconde os segredos de **qualquer** serialização acidental do Model; o `EmitenteResource` (Tarefa 15) expõe só os campos de status, nunca lendo essas colunas.

- [ ] **Step 6: Criar as factories**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Emitente> */
class EmitenteFactory extends Factory
{
    protected $model = Emitente::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ambiente_fiscal' => AmbienteFiscal::Homologacao,
            'certificado_status' => CertificadoStatus::Pendente,
        ];
    }

    public function completo(): static
    {
        return $this->state(fn (): array => [
            'logradouro' => 'Rua Teste', 'numero' => '100', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
            'uf' => 'SP', 'cep' => '01001000', 'codigo_ibge' => '3550308',
            'regime_tributario' => 'SIMPLES', 'inscricao_estadual' => 'ISENTO', 'cnae' => '4520001',
        ]);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmitenteSerie> */
class EmitenteSerieFactory extends Factory
{
    protected $model = EmitenteSerie::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'modelo' => ModeloDocumento::Nfe,
            'serie' => '1',
            'proximo_numero' => 1,
        ];
    }
}
```

- [ ] **Step 7: Migrar e rodar os testes**

Run: `cd backend && php artisan migrate --env=testing --force && php artisan test --filter=FiscalIsolamentoTest`
Expected: PASS (2 testes).

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_10_01_000003_create_fiscal_tables.php backend/app/Modules/Fiscal backend/database/factories/EmitenteFactory.php backend/database/factories/EmitenteSerieFactory.php backend/tests/Feature/Fiscal/FiscalIsolamentoTest.php
git commit -m "feat(fiscal): modelo de dados do emitente e das séries de numeração"
```

---

### Tarefa 15: `Fiscal` — dados da empresa, dados fiscais e `GET emitente`

**Files:**
- Create: `backend/app/Modules/Fiscal/Application/PoliticaDoEmitente.php`
- Create: `backend/app/Modules/Fiscal/Application/Actions/AtualizarDadosDaEmpresa.php`
- Create: `backend/app/Modules/Fiscal/Application/Actions/AtualizarDadosFiscais.php`
- Create: `backend/app/Modules/Fiscal/Http/Requests/AtualizarDadosDaEmpresaRequest.php`
- Create: `backend/app/Modules/Fiscal/Http/Requests/AtualizarDadosFiscaisRequest.php`
- Create: `backend/app/Modules/Fiscal/Http/Resources/EmitenteResource.php`
- Create: `backend/app/Modules/Fiscal/Http/Resources/EmitenteSerieResource.php`
- Create: `backend/app/Modules/Fiscal/Http/Controllers/EmitenteController.php`
- Create: `backend/app/Modules/Fiscal/Http/Controllers/ConsultarCepController.php`
- Create: `backend/app/Modules/Fiscal/Http/routes-app.php`
- Create: `backend/app/Modules/Fiscal/Providers/FiscalServiceProvider.php`
- Modify: `backend/bootstrap/providers.php`
- Test: `backend/tests/Feature/Fiscal/EmitenteTest.php`

**Interfaces:**
- Consumes: `Emitente`, `EmitenteSerie` (Tarefa 14), `ConsultaCep` (Tarefa 2), `EntitlementService::temModulo()` + `Modulo::FiscalNfce` (F1).
- Produces: `PoliticaDoEmitente::garantirPodeEscrever(Usuario): void` (reaproveitada pela Tarefa 16 e pela Tarefa 17). Rotas `GET /api/app/emitente`, `PUT /api/app/emitente/empresa`, `PUT /api/app/emitente/fiscal`, `GET /api/app/emitente/cep/{cep}`. `EmitenteResource` com a chave `exige_csc`, reaproveitada pelas Tarefas 16 e 17.

- [ ] **Step 1: Escrever os testes (devem falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmitenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_emitente_cria_o_registro_vazio_na_primeira_vez(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->getJson('/api/app/emitente')
            ->assertOk()
            ->assertJsonPath('data.ambiente_fiscal', 'HOMOLOGACAO')
            ->assertJsonPath('data.certificado_status', 'PENDENTE')
            ->assertJsonPath('data.exige_csc', false);

        $this->assertDatabaseCount('emitentes', 1);
    }

    public function test_exige_csc_e_verdadeiro_quando_o_plano_tem_nfce(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::FiscalNfce))->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->getJson('/api/app/emitente')->assertJsonPath('data.exige_csc', true);
    }

    public function test_atualiza_dados_da_empresa(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->putJson('/api/app/emitente/empresa', [
            'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'numero' => '100',
            'bairro' => 'Sé', 'cidade' => 'São Paulo', 'uf' => 'sp', 'codigo_ibge' => '3550308',
        ])->assertOk()->assertJsonPath('data.uf', 'SP')->assertJsonPath('data.cep', '01001000');
    }

    public function test_atualiza_dados_fiscais(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->putJson('/api/app/emitente/fiscal', [
            'regime_tributario' => 'SIMPLES', 'inscricao_estadual' => 'ISENTO', 'cnae' => '4520001',
        ])->assertOk()->assertJsonPath('data.regime_tributario', 'SIMPLES');
    }

    public function test_consulta_cep_para_o_wizard(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        Http::fake(['*/ws/01001000/json/' => Http::response([
            'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
        ])]);

        $this->spa()->actingAs($admin)->getJson('/api/app/emitente/cep/01001000')
            ->assertOk()->assertJsonPath('data.cidade', 'São Paulo');
    }

    public function test_vendedor_nao_altera_o_emitente(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $vendedor = Usuario::factory()->for($tenant)->create(['papel' => Papel::Vendedor]);

        $this->spa()->actingAs($vendedor)->putJson('/api/app/emitente/fiscal', ['regime_tributario' => 'SIMPLES'])
            ->assertForbidden()->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_leitura_le_mas_nao_escreve(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $leitura = Usuario::factory()->for($tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->getJson('/api/app/emitente')->assertOk();
        $this->spa()->actingAs($leitura)->putJson('/api/app/emitente/fiscal', ['regime_tributario' => 'SIMPLES'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=EmitenteTest`
Expected: FAIL (rota inexistente).

- [ ] **Step 3: Criar a política**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem escreve nos dados fiscais do emitente (spec F2 §9). */
final class PoliticaDoEmitente
{
    public function garantirPodeEscrever(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin, Papel::Fiscal], true)) {
            throw new AcessoNegadoException('Você não tem permissão para alterar os dados fiscais do emitente.');
        }
    }
}
```

- [ ] **Step 4: Criar as Actions**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Models\Emitente;

final class AtualizarDadosDaEmpresa
{
    /** @param array{logradouro:?string,numero:?string,bairro:?string,cidade:?string,uf:?string,cep:?string,codigo_ibge:?string} $dados */
    public function executar(Emitente $emitente, array $dados): Emitente
    {
        $emitente->update($dados);

        return $emitente;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Models\Emitente;

final class AtualizarDadosFiscais
{
    /** @param array{regime_tributario:?string,inscricao_estadual:?string,inscricao_municipal:?string,cnae:?string} $dados */
    public function executar(Emitente $emitente, array $dados): Emitente
    {
        $emitente->update($dados);

        return $emitente;
    }
}
```

- [ ] **Step 5: Criar os Requests**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarDadosDaEmpresaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'logradouro' => ['nullable', 'string', 'max:150'],
            'numero' => ['nullable', 'string', 'max:20'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'uf' => ['nullable', 'string', 'size:2'],
            'cep' => ['nullable', 'string'],
            'codigo_ibge' => ['nullable', 'string'],
        ];
    }

    /** @return array{logradouro:?string,numero:?string,bairro:?string,cidade:?string,uf:?string,cep:?string,codigo_ibge:?string} */
    public function dados(): array
    {
        return [
            'logradouro' => $this->filled('logradouro') ? $this->string('logradouro')->toString() : null,
            'numero' => $this->filled('numero') ? $this->string('numero')->toString() : null,
            'bairro' => $this->filled('bairro') ? $this->string('bairro')->toString() : null,
            'cidade' => $this->filled('cidade') ? $this->string('cidade')->toString() : null,
            'uf' => $this->filled('uf') ? strtoupper($this->string('uf')->toString()) : null,
            'cep' => $this->filled('cep') ? preg_replace('/\D/', '', (string) $this->input('cep')) : null,
            'codigo_ibge' => $this->filled('codigo_ibge') ? $this->string('codigo_ibge')->toString() : null,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AtualizarDadosFiscaisRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'regime_tributario' => ['nullable', Rule::in(['SIMPLES', 'MEI', 'NORMAL'])],
            'inscricao_estadual' => ['nullable', 'string', 'max:20'],
            'inscricao_municipal' => ['nullable', 'string', 'max:20'],
            'cnae' => ['nullable', 'string', 'max:10'],
        ];
    }

    /** @return array{regime_tributario:?string,inscricao_estadual:?string,inscricao_municipal:?string,cnae:?string} */
    public function dados(): array
    {
        return [
            'regime_tributario' => $this->filled('regime_tributario') ? $this->string('regime_tributario')->toString() : null,
            'inscricao_estadual' => $this->filled('inscricao_estadual') ? $this->string('inscricao_estadual')->toString() : null,
            'inscricao_municipal' => $this->filled('inscricao_municipal') ? $this->string('inscricao_municipal')->toString() : null,
            'cnae' => $this->filled('cnae') ? preg_replace('/\D/', '', (string) $this->input('cnae')) : null,
        ];
    }
}
```

- [ ] **Step 6: Criar os Resources**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmitenteSerie */
final class EmitenteSerieResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'modelo' => $this->modelo->value,
            'serie' => $this->serie,
            'proximo_numero' => $this->proximo_numero,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Emitente */
final class EmitenteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'logradouro' => $this->logradouro,
            'numero' => $this->numero,
            'bairro' => $this->bairro,
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'cep' => $this->cep,
            'codigo_ibge' => $this->codigo_ibge,
            'regime_tributario' => $this->regime_tributario,
            'inscricao_estadual' => $this->inscricao_estadual,
            'inscricao_municipal' => $this->inscricao_municipal,
            'cnae' => $this->cnae,
            'ambiente_fiscal' => $this->ambiente_fiscal->value,
            'certificado_status' => $this->certificado_status->value,
            'certificado_validade' => $this->certificado_validade?->toDateString(),
            'certificado_titular' => $this->certificado_titular,
            'csc_homologacao_configurado' => $this->csc_id_homologacao !== null,
            'csc_producao_configurado' => $this->csc_id_producao !== null,
            'exige_csc' => app(EntitlementService::class)->temModulo($this->tenant, Modulo::FiscalNfce),
            'series' => EmitenteSerieResource::collection($this->whenLoaded('series')),
        ];
    }
}
```

- [ ] **Step 7: Criar os Controllers**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\AtualizarDadosDaEmpresa;
use App\Modules\Fiscal\Application\Actions\AtualizarDadosFiscais;
use App\Modules\Fiscal\Application\PoliticaDoEmitente;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Http\Requests\AtualizarDadosDaEmpresaRequest;
use App\Modules\Fiscal\Http\Requests\AtualizarDadosFiscaisRequest;
use App\Modules\Fiscal\Http\Resources\EmitenteResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\Request;

final class EmitenteController
{
    public function __construct(private readonly PoliticaDoEmitente $politica) {}

    public function show(): EmitenteResource
    {
        return new EmitenteResource($this->emitente()->load('series'));
    }

    public function atualizarEmpresa(AtualizarDadosDaEmpresaRequest $request, AtualizarDadosDaEmpresa $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return new EmitenteResource($acao->executar($this->emitente(), $request->dados())->load('series'));
    }

    public function atualizarFiscal(AtualizarDadosFiscaisRequest $request, AtualizarDadosFiscais $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return new EmitenteResource($acao->executar($this->emitente(), $request->dados())->load('series'));
    }

    private function emitente(): Emitente
    {
        return Emitente::query()->firstOrCreate([], []);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Shared\Infrastructure\ConsultaCep;
use Illuminate\Http\JsonResponse;

final class ConsultarCepController
{
    public function __invoke(string $cep, ConsultaCep $consulta): JsonResponse
    {
        $dados = $consulta->buscar($cep);
        if ($dados === null) {
            return response()->json(['message' => 'CEP não encontrado. Preencha o endereço manualmente.'], 404);
        }

        return response()->json(['data' => $dados]);
    }
}
```

- [ ] **Step 8: Criar as rotas e o provider do módulo**

```php
<?php

declare(strict_types=1);

use App\Modules\Fiscal\Http\Controllers\ConsultarCepController;
use App\Modules\Fiscal\Http\Controllers\EmitenteController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('emitente', [EmitenteController::class, 'show'])->name('app.emitente.show');
    Route::put('emitente/empresa', [EmitenteController::class, 'atualizarEmpresa'])->name('app.emitente.empresa');
    Route::put('emitente/fiscal', [EmitenteController::class, 'atualizarFiscal'])->name('app.emitente.fiscal');
    Route::get('emitente/cep/{cep}', ConsultarCepController::class)->name('app.emitente.cep');
});
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class FiscalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
    }
}
```

Em `backend/bootstrap/providers.php`, acrescentar `App\Modules\Fiscal\Providers\FiscalServiceProvider::class`.

- [ ] **Step 9: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=EmitenteTest`
Expected: PASS (7 testes).

- [ ] **Step 10: Commit**

```bash
git add backend/app/Modules/Fiscal backend/bootstrap/providers.php backend/tests/Feature/Fiscal/EmitenteTest.php
git commit -m "feat(fiscal): dados da empresa, dados fiscais e GET emitente"
```

---

### Tarefa 16: `Fiscal` — upload do certificado A1

**Files:**
- Create: `backend/app/Modules/Fiscal/Domain/DecisorDeStatusDoCertificado.php`
- Create: `backend/app/Modules/Fiscal/Domain/Exceptions/CertificadoSenhaInvalidaException.php`
- Create: `backend/app/Modules/Fiscal/Domain/Exceptions/CertificadoArquivoInvalidoException.php`
- Create: `backend/app/Modules/Fiscal/Application/Actions/ProcessarCertificado.php`
- Create: `backend/app/Modules/Fiscal/Http/Requests/UploadCertificadoRequest.php`
- Create: `backend/app/Modules/Fiscal/Http/Controllers/CertificadoController.php`
- Modify: `backend/app/Modules/Fiscal/Http/routes-app.php`
- Test: `backend/tests/Unit/Fiscal/DecisorDeStatusDoCertificadoTest.php`
- Test: `backend/tests/Feature/Fiscal/CertificadoTest.php`

**Interfaces:**
- Consumes: `Emitente` (Tarefa 14), `PoliticaDoEmitente`, `EmitenteResource` (Tarefa 15).
- Produces: `POST /api/app/emitente/certificado` (multipart `arquivo` + `senha`). `DecisorDeStatusDoCertificado::para(CarbonInterface): CertificadoStatus`.

- [ ] **Step 1: Escrever o teste unitário do decisor de status (deve falhar: classe não existe)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\DecisorDeStatusDoCertificado;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DecisorDeStatusDoCertificadoTest extends TestCase
{
    public function test_validade_futura_e_valido(): void
    {
        $this->assertSame(CertificadoStatus::Valido, DecisorDeStatusDoCertificado::para(CarbonImmutable::now()->addYear()));
    }

    public function test_validade_passada_e_vencido(): void
    {
        $this->assertSame(CertificadoStatus::Vencido, DecisorDeStatusDoCertificado::para(CarbonImmutable::now()->subDay()));
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=DecisorDeStatusDoCertificadoTest`
Expected: FAIL (classe não existe).

- [ ] **Step 3: Escrever o teste de upload (deve falhar: rota inexistente)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CertificadoTest extends TestCase
{
    use RefreshDatabase;

    /** Gera um .pfx autoassinado só para teste (nenhum dado real). */
    private function gerarPfx(string $senha): string
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($chave);
        $csr = openssl_csr_new(['commonName' => 'Empresa de Teste LTDA'], $chave);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $chave, 365);
        $this->assertNotFalse($cert);
        openssl_pkcs12_export($cert, $pfx, $chave, $senha);

        return $pfx;
    }

    public function test_upload_com_senha_certa_grava_validade_e_titular(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $pfx = $this->gerarPfx('senha-correta');
        $arquivo = UploadedFile::fake()->createWithContent('certificado.pfx', $pfx);

        $resposta = $this->spa()->actingAs($admin)->post('/api/app/emitente/certificado', [
            'arquivo' => $arquivo, 'senha' => 'senha-correta',
        ])->assertOk();

        $resposta->assertJsonPath('data.certificado_status', 'VALIDO');
        $this->assertSame('Empresa de Teste LTDA', $resposta->json('data.certificado_titular'));
        $this->assertDatabaseMissing('emitentes', ['certificado_senha_encrypted' => 'senha-correta']);
    }

    public function test_upload_com_senha_errada_e_recusado(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $pfx = $this->gerarPfx('senha-correta');
        $arquivo = UploadedFile::fake()->createWithContent('certificado.pfx', $pfx);

        $this->spa()->actingAs($admin)->post('/api/app/emitente/certificado', [
            'arquivo' => $arquivo, 'senha' => 'senha-errada',
        ])->assertStatus(422)->assertJsonPath('codigo', 'CERTIFICADO_SENHA_INVALIDA');
    }

    public function test_upload_de_arquivo_que_nao_e_pfx_e_recusado_sem_erro_500(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $arquivo = UploadedFile::fake()->createWithContent('documento.pdf', '%PDF-1.4 conteúdo qualquer, não é um certificado');

        $this->spa()->actingAs($admin)->post('/api/app/emitente/certificado', [
            'arquivo' => $arquivo, 'senha' => 'qualquer',
        ])->assertStatus(422)->assertJsonPath('codigo', 'CERTIFICADO_ARQUIVO_INVALIDO');
    }

    public function test_vendedor_nao_faz_upload_de_certificado(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $vendedor = Usuario::factory()->for($tenant)->create(['papel' => Papel::Vendedor]);
        $arquivo = UploadedFile::fake()->createWithContent('certificado.pfx', 'qualquer-coisa');

        $this->spa()->actingAs($vendedor)->post('/api/app/emitente/certificado', [
            'arquivo' => $arquivo, 'senha' => 'qualquer',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 4: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=CertificadoTest`
Expected: FAIL (rota inexistente).

- [ ] **Step 5: Criar o decisor de status e as exceções**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use Carbon\CarbonInterface;

final class DecisorDeStatusDoCertificado
{
    public static function para(CarbonInterface $validade): CertificadoStatus
    {
        return $validade->isPast() ? CertificadoStatus::Vencido : CertificadoStatus::Valido;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class CertificadoSenhaInvalidaException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('A senha do certificado está incorreta.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'CERTIFICADO_SENHA_INVALIDA';
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class CertificadoArquivoInvalidoException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Este arquivo não é um certificado A1 (.pfx) válido.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'CERTIFICADO_ARQUIVO_INVALIDO';
    }
}
```

- [ ] **Step 6: Criar a Action**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\DecisorDeStatusDoCertificado;
use App\Modules\Fiscal\Domain\Exceptions\CertificadoArquivoInvalidoException;
use App\Modules\Fiscal\Domain\Exceptions\CertificadoSenhaInvalidaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;

/**
 * Parse nativo com openssl, sem depender do `sped-nfe` (que só entra na F3).
 * O `.pfx` nunca toca o disco: é lido e descartado em memória.
 */
final class ProcessarCertificado
{
    public function executar(Emitente $emitente, string $conteudoBinario, string $senha): Emitente
    {
        $certs = [];
        if (! openssl_pkcs12_read($conteudoBinario, $certs, $senha)) {
            throw $this->interpretarFalha();
        }

        $info = openssl_x509_parse($certs['cert']);
        if ($info === false || ! isset($info['validTo_time_t'])) {
            throw new CertificadoArquivoInvalidoException;
        }

        $validade = CarbonImmutable::createFromTimestamp($info['validTo_time_t']);

        $emitente->update([
            'certificado_pfx_encrypted' => Crypt::encryptString(base64_encode($conteudoBinario)),
            'certificado_senha_encrypted' => Crypt::encryptString($senha),
            'certificado_validade' => $validade->toDateString(),
            'certificado_titular' => $info['subject']['CN'] ?? null,
            'certificado_status' => DecisorDeStatusDoCertificado::para($validade),
        ]);

        return $emitente;
    }

    private function interpretarFalha(): CertificadoSenhaInvalidaException|CertificadoArquivoInvalidoException
    {
        $mensagens = [];
        while (($erro = openssl_error_string()) !== false) {
            $mensagens[] = strtolower($erro);
        }
        $senhaErrada = array_filter($mensagens, static fn (string $m): bool => str_contains($m, 'mac verify failure'));

        return $senhaErrada !== [] ? new CertificadoSenhaInvalidaException : new CertificadoArquivoInvalidoException;
    }
}
```

- [ ] **Step 7: Criar o Request**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadCertificadoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'arquivo' => ['required', 'file', 'max:10240'],
            'senha' => ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 8: Criar o Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\ProcessarCertificado;
use App\Modules\Fiscal\Application\PoliticaDoEmitente;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Http\Requests\UploadCertificadoRequest;
use App\Modules\Fiscal\Http\Resources\EmitenteResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\Request;

final class CertificadoController
{
    public function __construct(private readonly PoliticaDoEmitente $politica) {}

    public function store(UploadCertificadoRequest $request, ProcessarCertificado $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $emitente = Emitente::query()->firstOrCreate([], []);
        $conteudo = (string) $request->file('arquivo')?->get();

        return new EmitenteResource(
            $acao->executar($emitente, $conteudo, (string) $request->input('senha'))->load('series'),
        );
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

- [ ] **Step 9: Acrescentar a rota**

Em `backend/app/Modules/Fiscal/Http/routes-app.php`:

```php
    Route::post('emitente/certificado', [CertificadoController::class, 'store'])->name('app.emitente.certificado');
```

(com `use App\Modules\Fiscal\Http\Controllers\CertificadoController;` no topo.)

- [ ] **Step 10: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=DecisorDeStatusDoCertificadoTest && php artisan test --filter=CertificadoTest`
Expected: PASS (2 + 4 testes).

- [ ] **Step 11: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Unit/Fiscal/DecisorDeStatusDoCertificadoTest.php backend/tests/Feature/Fiscal/CertificadoTest.php
git commit -m "feat(fiscal): upload e parse nativo do certificado A1"
```

---

### Tarefa 17: `Fiscal` — CSC, séries e confirmação de ambiente de produção

**Files:**
- Create: `backend/app/Modules/Fiscal/Application/Actions/AtualizarCsc.php`
- Create: `backend/app/Modules/Fiscal/Application/Actions/AtualizarSerie.php`
- Create: `backend/app/Modules/Fiscal/Application/Actions/ConfirmarProducao.php`
- Create: `backend/app/Modules/Fiscal/Http/Requests/AtualizarCscRequest.php`
- Create: `backend/app/Modules/Fiscal/Http/Requests/AtualizarSerieRequest.php`
- Create: `backend/app/Modules/Fiscal/Http/Requests/ConfirmarProducaoRequest.php`
- Create: `backend/app/Modules/Fiscal/Http/Controllers/ConfiguracaoFiscalController.php`
- Modify: `backend/app/Modules/Fiscal/Http/routes-app.php`
- Test: `backend/tests/Feature/Fiscal/ConfiguracaoFiscalTest.php`

**Interfaces:**
- Consumes: `Emitente`, `EmitenteSerie`, `ModeloDocumento`, `AmbienteFiscal` (Tarefa 14), `PoliticaDoEmitente`, `EmitenteResource` (Tarefa 15).
- Produces: `PUT /api/app/emitente/csc`, `PUT /api/app/emitente/series/{modelo}`, `POST /api/app/emitente/ambiente/producao`.

- [ ] **Step 1: Escrever os testes (devem falhar: nada existe ainda)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ConfiguracaoFiscalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Plano sem FISCAL_NFCE: o CSC não é exigido, mas continua aceito.
        $this->tenant = Tenant::factory()->for(Plano::factory())->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
    }

    public function test_salva_csc_mesmo_sem_o_plano_ter_nfce(): void
    {
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/csc', [
            'csc_id_homologacao' => '1', 'csc_token_homologacao' => 'token-homolog',
        ])->assertOk()->assertJsonPath('data.csc_homologacao_configurado', true);

        $this->assertDatabaseMissing('emitentes', ['csc_token_homologacao_encrypted' => 'token-homolog']);
    }

    public function test_salva_serie_de_nfe(): void
    {
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', [
            'serie' => '1', 'proximo_numero' => 1,
        ])->assertOk();

        $resposta = $this->spa()->actingAs($this->admin)->getJson('/api/app/emitente')->assertOk();
        $this->assertSame('NFE', $resposta->json('data.series.0.modelo'));
        $this->assertSame(1, $resposta->json('data.series.0.proximo_numero'));
    }

    public function test_atualizar_serie_existente_substitui_em_vez_de_duplicar(): void
    {
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => 1])->assertOk();
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '2', 'proximo_numero' => 50])->assertOk();

        $resposta = $this->spa()->actingAs($this->admin)->getJson('/api/app/emitente')->assertOk();
        $resposta->assertJsonCount(1, 'data.series');
        $this->assertSame(50, $resposta->json('data.series.0.proximo_numero'));
    }

    public function test_confirmar_producao_exige_a_flag_explicita(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', [])
            ->assertStatus(422)->assertJsonValidationErrors('confirmo');
    }

    public function test_confirmar_producao_muda_o_ambiente_e_audita(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])
            ->assertOk()->assertJsonPath('data.ambiente_fiscal', 'PRODUCAO');

        $this->assertSame(1, Activity::query()->where('event', 'ambiente_producao_confirmado')->count());
    }

    public function test_vendedor_nao_altera_csc_serie_ou_ambiente(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->spa()->actingAs($vendedor)->putJson('/api/app/emitente/csc', ['csc_id_homologacao' => '1'])->assertForbidden();
        $this->spa()->actingAs($vendedor)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => 1])->assertForbidden();
        $this->spa()->actingAs($vendedor)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])->assertForbidden();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ConfiguracaoFiscalTest`
Expected: FAIL (rota inexistente).

- [ ] **Step 3: Criar as Actions**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Models\Emitente;
use Illuminate\Support\Facades\Crypt;

final class AtualizarCsc
{
    /** @param array{csc_id_homologacao:?string,csc_token_homologacao:?string,csc_id_producao:?string,csc_token_producao:?string} $dados */
    public function executar(Emitente $emitente, array $dados): Emitente
    {
        $emitente->update([
            'csc_id_homologacao' => $dados['csc_id_homologacao'],
            'csc_token_homologacao_encrypted' => $dados['csc_token_homologacao'] !== null
                ? Crypt::encryptString($dados['csc_token_homologacao']) : null,
            'csc_id_producao' => $dados['csc_id_producao'],
            'csc_token_producao_encrypted' => $dados['csc_token_producao'] !== null
                ? Crypt::encryptString($dados['csc_token_producao']) : null,
        ]);

        return $emitente;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;

/** Editável livremente nesta fase: ainda não existe nota emitida (trava fica para a F3). */
final class AtualizarSerie
{
    public function executar(Emitente $emitente, ModeloDocumento $modelo, string $serie, int $proximoNumero): EmitenteSerie
    {
        return EmitenteSerie::query()->updateOrCreate(
            ['emitente_id' => $emitente->id, 'modelo' => $modelo],
            ['serie' => $serie, 'proximo_numero' => $proximoNumero],
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Identity\Domain\Models\Usuario;

final class ConfirmarProducao
{
    public function executar(Emitente $emitente, Usuario $autor): Emitente
    {
        $emitente->update(['ambiente_fiscal' => AmbienteFiscal::Producao]);

        activity('fiscal')->performedOn($emitente)->causedBy($autor)->event('ambiente_producao_confirmado')
            ->log('Ambiente fiscal alterado para produção');

        return $emitente;
    }
}
```

- [ ] **Step 4: Criar os Requests**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarCscRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'csc_id_homologacao' => ['nullable', 'string', 'max:10'],
            'csc_token_homologacao' => ['nullable', 'string'],
            'csc_id_producao' => ['nullable', 'string', 'max:10'],
            'csc_token_producao' => ['nullable', 'string'],
        ];
    }

    /** @return array{csc_id_homologacao:?string,csc_token_homologacao:?string,csc_id_producao:?string,csc_token_producao:?string} */
    public function dados(): array
    {
        return [
            'csc_id_homologacao' => $this->filled('csc_id_homologacao') ? $this->string('csc_id_homologacao')->toString() : null,
            'csc_token_homologacao' => $this->filled('csc_token_homologacao') ? $this->string('csc_token_homologacao')->toString() : null,
            'csc_id_producao' => $this->filled('csc_id_producao') ? $this->string('csc_id_producao')->toString() : null,
            'csc_token_producao' => $this->filled('csc_token_producao') ? $this->string('csc_token_producao')->toString() : null,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarSerieRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'serie' => ['required', 'string', 'max:3'],
            'proximo_numero' => ['required', 'integer', 'min:1'],
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmarProducaoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['confirmo' => ['required', 'accepted']];
    }
}
```

- [ ] **Step 5: Criar o Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\AtualizarCsc;
use App\Modules\Fiscal\Application\Actions\AtualizarSerie;
use App\Modules\Fiscal\Application\Actions\ConfirmarProducao;
use App\Modules\Fiscal\Application\PoliticaDoEmitente;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Http\Requests\AtualizarCscRequest;
use App\Modules\Fiscal\Http\Requests\AtualizarSerieRequest;
use App\Modules\Fiscal\Http\Requests\ConfirmarProducaoRequest;
use App\Modules\Fiscal\Http\Resources\EmitenteResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\Request;

final class ConfiguracaoFiscalController
{
    public function __construct(private readonly PoliticaDoEmitente $politica) {}

    public function atualizarCsc(AtualizarCscRequest $request, AtualizarCsc $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return new EmitenteResource($acao->executar($this->emitente(), $request->dados())->load('series'));
    }

    public function atualizarSerie(AtualizarSerieRequest $request, string $modelo, AtualizarSerie $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $acao->executar($this->emitente(), ModeloDocumento::from($modelo), (string) $request->input('serie'), (int) $request->input('proximo_numero'));

        return new EmitenteResource($this->emitente()->load('series'));
    }

    public function confirmarProducao(ConfirmarProducaoRequest $request, ConfirmarProducao $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return new EmitenteResource($acao->executar($this->emitente(), $this->autor($request))->load('series'));
    }

    private function emitente(): Emitente
    {
        return Emitente::query()->firstOrCreate([], []);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

- [ ] **Step 6: Acrescentar as rotas**

Em `backend/app/Modules/Fiscal/Http/routes-app.php`:

```php
    Route::put('emitente/csc', [ConfiguracaoFiscalController::class, 'atualizarCsc'])->name('app.emitente.csc');
    Route::put('emitente/series/{modelo}', [ConfiguracaoFiscalController::class, 'atualizarSerie'])->whereIn('modelo', ['NFE', 'NFCE', 'DPS'])->name('app.emitente.series');
    Route::post('emitente/ambiente/producao', [ConfiguracaoFiscalController::class, 'confirmarProducao'])->name('app.emitente.producao');
```

(com `use App\Modules\Fiscal\Http\Controllers\ConfiguracaoFiscalController;` no topo.)

- [ ] **Step 7: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ConfiguracaoFiscalTest`
Expected: PASS (6 testes).

- [ ] **Step 8: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/ConfiguracaoFiscalTest.php
git commit -m "feat(fiscal): CSC, séries de numeração e confirmação de ambiente de produção"
```

---

### Tarefa 18: Matriz cruzada de papéis (todos os controllers do F2)

**Files:**
- Test: `backend/tests/Feature/ControlePorPapelTest.php`

**Interfaces:**
- Consumes: todos os endpoints das Tarefas 6, 7, 11, 15 e 17. Nenhum código de produção novo — esta tarefa só preenche as lacunas de cobertura da tabela do §9 da spec que as tarefas individuais não cobriram explicitamente (`FISCAL` e `PROPRIETARIO` escrevendo no emitente e nos cadastros).

- [ ] **Step 1: Escrever o teste da matriz**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlePorPapelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory())->create();
    }

    private function usuario(Papel $papel): Usuario
    {
        return Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);
    }

    /** @return array<string, array{0: Papel, 1: bool}> */
    public static function papeisEEmitente(): array
    {
        return [
            'proprietario escreve' => [Papel::Proprietario, true],
            'admin escreve' => [Papel::Admin, true],
            'fiscal escreve' => [Papel::Fiscal, true],
            'vendedor nao escreve' => [Papel::Vendedor, false],
            'leitura nao escreve' => [Papel::Leitura, false],
        ];
    }

    /** @dataProvider papeisEEmitente */
    public function test_escrita_no_emitente_por_papel(Papel $papel, bool $podeEscrever): void
    {
        $resposta = $this->spa()->actingAs($this->usuario($papel))
            ->putJson('/api/app/emitente/fiscal', ['regime_tributario' => 'SIMPLES']);

        $podeEscrever ? $resposta->assertOk() : $resposta->assertForbidden();
    }

    /** @return array<string, array{0: Papel, 1: bool}> */
    public static function papeisECadastros(): array
    {
        return [
            'proprietario escreve' => [Papel::Proprietario, true],
            'admin escreve' => [Papel::Admin, true],
            'fiscal escreve' => [Papel::Fiscal, true],
            'vendedor escreve' => [Papel::Vendedor, true],
            'leitura nao escreve' => [Papel::Leitura, false],
        ];
    }

    /** @dataProvider papeisECadastros */
    public function test_escrita_em_produtos_por_papel(Papel $papel, bool $podeEscrever): void
    {
        $resposta = $this->spa()->actingAs($this->usuario($papel))->postJson('/api/app/produtos', [
            'sku' => 'SKU-'.$papel->value, 'nome' => 'Item', 'unidade' => 'UN', 'preco_centavos' => 100,
        ]);

        $podeEscrever ? $resposta->assertCreated() : $resposta->assertForbidden();
    }

    /** @dataProvider papeisECadastros */
    public function test_escrita_em_clientes_por_papel(Papel $papel, bool $podeEscrever): void
    {
        $resposta = $this->spa()->actingAs($this->usuario($papel))->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Cliente de '.$papel->value,
        ]);

        $podeEscrever ? $resposta->assertCreated() : $resposta->assertForbidden();
    }
}
```

- [ ] **Step 2: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ControlePorPapelTest`
Expected: PASS (15 testes: 5 + 5 + 5 combinações). Se alguma combinação falhar, é sinal de que a política de uma das Tarefas 6, 7, 11, 15 ou 17 ficou incompleta — corrigir a política antes de prosseguir.

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/ControlePorPapelTest.php
git commit -m "test: matriz cruzada de papéis sobre emitente, produtos e clientes"
```

---

### Tarefa 19: Importador CSV genérico

**Files:**
- Create: `backend/app/Modules/Shared/Infrastructure/ImportadorCsv.php`
- Create: `backend/app/Modules/Shared/Domain/Exceptions/ErroDeImportacao.php`
- Test: `backend/tests/Unit/Shared/ImportadorCsvTest.php`

**Interfaces:**
- Consumes: `ErroDeNegocio` (F1).
- Produces: `ImportadorCsv::importar(UploadedFile, callable(array<string,?string>, int): void): array{processados:int, erros: list<array{linha:int,motivo:string}>}`. Converte Windows-1252 para UTF-8, remove BOM, trata célula vazia como `null` (mesmo efeito do middleware `ConvertEmptyStringsToNull` que já roda nas requisições HTTP normais). Captura `ErroDeImportacao` **e** qualquer `ErroDeNegocio` (por exemplo `LimiteDoPlanoAtingidoException`) lançado dentro do callback, reportando como erro daquela linha sem interromper o lote. Usado pela Tarefa 20.

- [ ] **Step 1: Escrever os testes (devem falhar: classe não existe)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Infrastructure\ImportadorCsv;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportadorCsvTest extends TestCase
{
    public function test_converte_windows1252_para_utf8(): void
    {
        $conteudo = mb_convert_encoding("nome\nJosé da Silva\n", 'Windows-1252', 'UTF-8');
        $arquivo = UploadedFile::fake()->createWithContent('a.csv', $conteudo);
        $recebidas = [];

        app(ImportadorCsv::class)->importar($arquivo, function (array $linha) use (&$recebidas): void {
            $recebidas[] = $linha;
        });

        $this->assertSame('José da Silva', $recebidas[0]['nome']);
    }

    public function test_celula_vazia_vira_null(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('a.csv', "nome,email\nAna,\n");
        $recebidas = [];

        app(ImportadorCsv::class)->importar($arquivo, function (array $linha) use (&$recebidas): void {
            $recebidas[] = $linha;
        });

        $this->assertSame('Ana', $recebidas[0]['nome']);
        $this->assertNull($recebidas[0]['email']);
    }

    public function test_linha_invalida_vira_erro_sem_derrubar_o_lote(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('a.csv', "nome\nA\nB\nC\n");

        $resultado = app(ImportadorCsv::class)->importar($arquivo, function (array $linha): void {
            if ($linha['nome'] === 'B') {
                throw new ErroDeImportacao('nome inválido');
            }
        });

        $this->assertSame(2, $resultado['processados']);
        $this->assertSame([['linha' => 3, 'motivo' => 'nome inválido']], $resultado['erros']);
    }

    public function test_remove_bom_do_inicio_do_arquivo(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('a.csv', "\xEF\xBB\xBFnome\nAna\n");
        $recebidas = [];

        app(ImportadorCsv::class)->importar($arquivo, function (array $linha) use (&$recebidas): void {
            $recebidas[] = $linha;
        });

        $this->assertArrayHasKey('nome', $recebidas[0]);
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ImportadorCsvTest`
Expected: FAIL (`Class "App\Modules\Shared\Infrastructure\ImportadorCsv" not found`).

- [ ] **Step 3: Criar a exceção de linha**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use RuntimeException;

/** Erro de uma linha do CSV: o `ImportadorCsv` captura e nunca deixa escapar para o controller. */
final class ErroDeImportacao extends RuntimeException {}
```

- [ ] **Step 4: Implementar o importador**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure;

use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Illuminate\Http\UploadedFile;

/** Parser de CSV tolerante a Windows-1252 (Excel), BOM e linhas malformadas. */
final class ImportadorCsv
{
    /**
     * @param callable(array<string, ?string>, int): void $processarLinha
     * @return array{processados: int, erros: list<array{linha: int, motivo: string}>}
     */
    public function importar(UploadedFile $arquivo, callable $processarLinha): array
    {
        $conteudo = (string) $arquivo->get();
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo) ?? $conteudo;

        $linhas = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $conteudo) ?: [],
            fn (string $l): bool => trim($l) !== '',
        ));
        if ($linhas === []) {
            return ['processados' => 0, 'erros' => []];
        }

        $cabecalho = str_getcsv(array_shift($linhas));
        $processados = 0;
        $erros = [];

        foreach ($linhas as $i => $linhaCrua) {
            $numero = $i + 2; // +1 pelo cabeçalho, +1 porque a planilha começa em 1
            $valores = str_getcsv($linhaCrua);
            $valores = array_pad(array_slice($valores, 0, count($cabecalho)), count($cabecalho), '');
            /** @var array<string, ?string> $linha */
            $linha = array_map(static fn (?string $v): ?string => $v === '' ? null : $v, array_combine($cabecalho, $valores));

            try {
                $processarLinha($linha, $numero);
                $processados++;
            } catch (ErroDeImportacao|ErroDeNegocio $e) {
                $erros[] = ['linha' => $numero, 'motivo' => $e->getMessage()];
            }
        }

        return ['processados' => $processados, 'erros' => $erros];
    }
}
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ImportadorCsvTest`
Expected: PASS (4 testes).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Shared/Infrastructure/ImportadorCsv.php backend/app/Modules/Shared/Domain/Exceptions/ErroDeImportacao.php backend/tests/Unit/Shared/ImportadorCsvTest.php
git commit -m "feat(shared): importador CSV tolerante a Windows-1252, BOM e linha inválida"
```

---

### Tarefa 20: Importação CSV de clientes, produtos e serviços

**Files:**
- Create: `backend/app/Modules/Shared/Http/Requests/ImportarCsvRequest.php`
- Create: `backend/app/Modules/Customers/Application/Actions/ImportarClientesCsv.php`
- Create: `backend/app/Modules/Catalog/Application/Actions/ImportarProdutosCsv.php`
- Create: `backend/app/Modules/Catalog/Application/Actions/ImportarServicosCsv.php`
- Modify: `backend/app/Modules/Customers/Http/Controllers/ClientesController.php` (novo método `importar`)
- Modify: `backend/app/Modules/Catalog/Http/Controllers/ProdutosController.php` (novo método `importar`)
- Modify: `backend/app/Modules/Catalog/Http/Controllers/ServicosController.php` (novo método `importar`)
- Modify: `backend/app/Modules/Customers/Http/routes-app.php`
- Modify: `backend/app/Modules/Catalog/Http/routes-app.php`
- Test: `backend/tests/Feature/ImportacaoCsvTest.php`

**Interfaces:**
- Consumes: `ImportadorCsv` (Tarefa 19), `CriarCliente`/`AtualizarCliente` (Tarefa 11), `CriarProduto`/`AtualizarProduto` (Tarefa 6), `CriarServico`/`AtualizarServico` (Tarefa 7).
- Produces: `POST /api/app/clientes/importar`, `POST /api/app/produtos/importar`, `POST /api/app/servicos/importar`, todos devolvendo `{data: {criados, atualizados, erros}}`. Usado pela Tarefa 26 (frontend).

- [ ] **Step 1: Escrever os testes (devem falhar: rota inexistente)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacaoCsvTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory())->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
    }

    public function test_importa_clientes_criando_e_atualizando_por_cpf(): void
    {
        Cliente::factory()->comCpfValido()->for($this->tenant)->create(['cpf_cnpj' => '11144477735', 'nome' => 'Nome Antigo']);
        $csv = "tipo,nome,cpf_cnpj\nPF,Nome Novo,111.444.777-35\nPF,Outra Pessoa,\n";
        $arquivo = UploadedFile::fake()->createWithContent('clientes.csv', $csv);

        $resposta = $this->spa()->actingAs($this->admin)->post('/api/app/clientes/importar', ['arquivo' => $arquivo])->assertOk();

        $resposta->assertJsonPath('data.criados', 1)->assertJsonPath('data.atualizados', 1)->assertJsonPath('data.erros', []);
        $this->assertSame('Nome Novo', Cliente::query()->where('cpf_cnpj', '11144477735')->firstOrFail()->nome);
    }

    public function test_importa_produtos_reportando_linha_invalida_sem_derrubar_o_lote(): void
    {
        $csv = "sku,nome,unidade,preco_centavos\nSKU-1,Parafuso,UN,500\nSKU-2,,UN,500\n";
        $arquivo = UploadedFile::fake()->createWithContent('produtos.csv', $csv);

        $resposta = $this->spa()->actingAs($this->admin)->post('/api/app/produtos/importar', ['arquivo' => $arquivo])->assertOk();

        $resposta->assertJsonPath('data.criados', 1);
        $this->assertSame(3, $resposta->json('data.erros.0.linha'));
        $this->assertDatabaseHas('produtos', ['sku' => 'SKU-1']);
        $this->assertDatabaseMissing('produtos', ['sku' => 'SKU-2']);
    }

    public function test_importa_servicos_atualizando_por_nome(): void
    {
        Servico::factory()->for($this->tenant)->create(['nome' => 'Consultoria', 'preco_centavos' => 1000]);
        $csv = "nome,preco_centavos\nConsultoria,20000\n";
        $arquivo = UploadedFile::fake()->createWithContent('servicos.csv', $csv);

        $this->spa()->actingAs($this->admin)->post('/api/app/servicos/importar', ['arquivo' => $arquivo])
            ->assertOk()->assertJsonPath('data.atualizados', 1);

        $this->assertSame(20000, Servico::query()->where('nome', 'Consultoria')->firstOrFail()->preco_centavos);
    }

    public function test_importacao_respeita_o_limite_do_plano(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comLimites([\App\Modules\Platform\Domain\Enums\Recurso::Produtos->value => 1]))->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $csv = "sku,nome,unidade,preco_centavos\nSKU-1,A,UN,100\nSKU-2,B,UN,100\n";
        $arquivo = UploadedFile::fake()->createWithContent('produtos.csv', $csv);

        $resposta = $this->spa()->actingAs($admin)->post('/api/app/produtos/importar', ['arquivo' => $arquivo])->assertOk();

        $resposta->assertJsonPath('data.criados', 1);
        $this->assertStringContainsString('plano', $resposta->json('data.erros.0.motivo'));
    }

    public function test_leitura_nao_importa(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        $arquivo = UploadedFile::fake()->createWithContent('clientes.csv', "tipo,nome\nPF,Ana\n");

        $this->spa()->actingAs($leitura)->post('/api/app/clientes/importar', ['arquivo' => $arquivo])->assertForbidden();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ImportacaoCsvTest`
Expected: FAIL (rota inexistente).

- [ ] **Step 3: Criar o Request compartilhado**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportarCsvRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['arquivo' => ['required', 'file', 'max:5120']];
    }
}
```

- [ ] **Step 4: Criar `ImportarClientesCsv`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Http\Requests\CriarClienteRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Domain\Cpf;
use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Infrastructure\ImportadorCsv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

final class ImportarClientesCsv
{
    public function __construct(
        private readonly ImportadorCsv $importador,
        private readonly CriarCliente $criar,
        private readonly AtualizarCliente $atualizar,
    ) {}

    /** @return array{criados: int, atualizados: int, erros: list<array{linha: int, motivo: string}>} */
    public function executar(Usuario $autor, UploadedFile $arquivo): array
    {
        $criados = 0;
        $atualizados = 0;

        $resultado = $this->importador->importar($arquivo, function (array $linha) use ($autor, &$criados, &$atualizados): void {
            $dados = $this->validar($linha);
            $existente = $dados['cpf_cnpj'] !== null
                ? Cliente::query()->where('cpf_cnpj', $dados['cpf_cnpj'])->first()
                : null;

            if ($existente !== null) {
                $this->atualizar->executar($existente, $dados);
                $atualizados++;

                return;
            }

            $this->criar->executar($autor, $dados);
            $criados++;
        });

        return ['criados' => $criados, 'atualizados' => $atualizados, 'erros' => $resultado['erros']];
    }

    /**
     * @param array<string, ?string> $linha
     * @return array<string, mixed>
     */
    private function validar(array $linha): array
    {
        $validador = Validator::make($linha, CriarClienteRequest::regras());
        if ($validador->fails()) {
            throw new ErroDeImportacao($validador->errors()->first());
        }

        $tipo = (string) ($linha['tipo'] ?? '');
        $cpfCnpj = $linha['cpf_cnpj'] !== null ? preg_replace('/\D/', '', $linha['cpf_cnpj']) : null;
        if ($cpfCnpj !== null) {
            $valido = $tipo === 'PJ' ? Cnpj::tentar($cpfCnpj) !== null : Cpf::tentar($cpfCnpj) !== null;
            if (! $valido) {
                throw new ErroDeImportacao($tipo === 'PJ' ? 'CNPJ inválido.' : 'CPF inválido.');
            }
        }

        return [
            'tipo' => $tipo,
            'nome' => trim((string) $linha['nome']),
            'cpf_cnpj' => $cpfCnpj,
            'inscricao_estadual' => $linha['inscricao_estadual'] ?? null,
            'ie_isento' => filter_var($linha['ie_isento'] ?? false, FILTER_VALIDATE_BOOL),
            'email' => $linha['email'] ?? null,
            'telefone' => $linha['telefone'] ?? null,
            'logradouro' => $linha['logradouro'] ?? null,
            'numero' => $linha['numero'] ?? null,
            'bairro' => $linha['bairro'] ?? null,
            'cidade' => $linha['cidade'] ?? null,
            'uf' => $linha['uf'] ?? null,
            'cep' => $linha['cep'] ?? null,
            'codigo_ibge' => $linha['codigo_ibge'] ?? null,
            'tags' => [],
            'origem' => $linha['origem'] ?? null,
            'estagio' => $linha['estagio'] ?? 'LEAD',
        ];
    }
}
```

- [ ] **Step 5: Criar `ImportarProdutosCsv` e `ImportarServicosCsv`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Http\Requests\CriarProdutoRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Infrastructure\ImportadorCsv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

final class ImportarProdutosCsv
{
    public function __construct(
        private readonly ImportadorCsv $importador,
        private readonly CriarProduto $criar,
        private readonly AtualizarProduto $atualizar,
    ) {}

    /** @return array{criados: int, atualizados: int, erros: list<array{linha: int, motivo: string}>} */
    public function executar(Usuario $autor, UploadedFile $arquivo): array
    {
        $criados = 0;
        $atualizados = 0;

        $resultado = $this->importador->importar($arquivo, function (array $linha) use ($autor, &$criados, &$atualizados): void {
            $dados = $this->validar($linha);
            $existente = Produto::query()->where('sku', $dados['sku'])->first();

            if ($existente !== null) {
                $this->atualizar->executar($existente, $dados);
                $atualizados++;

                return;
            }

            $this->criar->executar($autor, $dados);
            $criados++;
        });

        return ['criados' => $criados, 'atualizados' => $atualizados, 'erros' => $resultado['erros']];
    }

    /**
     * @param array<string, ?string> $linha
     * @return array<string, mixed>
     */
    private function validar(array $linha): array
    {
        $validador = Validator::make($linha, CriarProdutoRequest::regras());
        if ($validador->fails()) {
            throw new ErroDeImportacao($validador->errors()->first());
        }

        return [
            'sku' => (string) $linha['sku'],
            'nome' => trim((string) $linha['nome']),
            'unidade' => strtoupper((string) $linha['unidade']),
            'preco_centavos' => (int) $linha['preco_centavos'],
            'gtin' => $linha['gtin'] ?? null,
            'ncm' => $linha['ncm'] ?? null,
            'cest' => $linha['cest'] ?? null,
            'origem' => $linha['origem'] ?? null,
            'tributacao_icms' => $linha['tributacao_icms'] ?? null,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Catalog\Http\Requests\CriarServicoRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Infrastructure\ImportadorCsv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

final class ImportarServicosCsv
{
    public function __construct(
        private readonly ImportadorCsv $importador,
        private readonly CriarServico $criar,
        private readonly AtualizarServico $atualizar,
    ) {}

    /** @return array{criados: int, atualizados: int, erros: list<array{linha: int, motivo: string}>} */
    public function executar(Usuario $autor, UploadedFile $arquivo): array
    {
        $criados = 0;
        $atualizados = 0;

        $resultado = $this->importador->importar($arquivo, function (array $linha) use ($autor, &$criados, &$atualizados): void {
            $dados = $this->validar($linha);
            $existente = Servico::query()->where('nome', $dados['nome'])->first();

            if ($existente !== null) {
                $this->atualizar->executar($existente, $dados);
                $atualizados++;

                return;
            }

            $this->criar->executar($autor, $dados);
            $criados++;
        });

        return ['criados' => $criados, 'atualizados' => $atualizados, 'erros' => $resultado['erros']];
    }

    /**
     * @param array<string, ?string> $linha
     * @return array<string, mixed>
     */
    private function validar(array $linha): array
    {
        $validador = Validator::make($linha, CriarServicoRequest::regras());
        if ($validador->fails()) {
            throw new ErroDeImportacao($validador->errors()->first());
        }

        return [
            'nome' => trim((string) $linha['nome']),
            'preco_centavos' => (int) $linha['preco_centavos'],
            'codigo_lc116' => $linha['codigo_lc116'] ?? null,
            'c_trib_nac' => $linha['c_trib_nac'] ?? null,
            'codigo_municipal' => $linha['codigo_municipal'] ?? null,
            'aliquota_iss' => $linha['aliquota_iss'] ?? null,
            'nbs' => $linha['nbs'] ?? null,
        ];
    }
}
```

- [ ] **Step 6: Acrescentar o método `importar` em cada Controller**

Em `ClientesController`:

```php
    public function importar(ImportarCsvRequest $request, ImportarClientesCsv $importar): JsonResponse
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return response()->json(['data' => $importar->executar($this->autor($request), $request->file('arquivo'))]);
    }
```

Em `ProdutosController` e `ServicosController`, o mesmo método (trocando `ImportarClientesCsv` por `ImportarProdutosCsv`/`ImportarServicosCsv`). Acrescentar `use App\Modules\Shared\Http\Requests\ImportarCsvRequest;` e `use Illuminate\Http\JsonResponse;` em cada um.

- [ ] **Step 7: Acrescentar as rotas**

Em `backend/app/Modules/Customers/Http/routes-app.php`:

```php
    Route::post('clientes/importar', [ClientesController::class, 'importar'])->name('app.clientes.importar');
```

Em `backend/app/Modules/Catalog/Http/routes-app.php`:

```php
    Route::post('produtos/importar', [ProdutosController::class, 'importar'])->name('app.produtos.importar');
    Route::post('servicos/importar', [ServicosController::class, 'importar'])->name('app.servicos.importar');
```

- [ ] **Step 8: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ImportacaoCsvTest`
Expected: PASS (5 testes).

- [ ] **Step 9: Commit**

```bash
git add backend/app/Modules/Shared/Http/Requests/ImportarCsvRequest.php backend/app/Modules/Customers backend/app/Modules/Catalog backend/tests/Feature/ImportacaoCsvTest.php
git commit -m "feat: importação CSV de clientes, produtos e serviços"
```

---

### Tarefa 21: API pública `/api/v1` — clientes, produtos e serviços

**Files:**
- Create: `backend/app/Modules/Catalog/Http/routes-v1.php`
- Create: `backend/app/Modules/Customers/Http/routes-v1.php`
- Modify: `backend/app/Modules/Catalog/Providers/CatalogServiceProvider.php`
- Modify: `backend/app/Modules/Customers/Providers/CustomersServiceProvider.php`
- Test: `backend/tests/Feature/ApiPublicaCadastrosTest.php`

**Interfaces:**
- Consumes: `ProdutosController`, `ServicosController` (Tarefas 6, 7), `ClientesController` (Tarefa 11) — reaproveitados tal como estão.
- Produces: `GET|POST /api/v1/clientes`, `GET|PUT /api/v1/clientes/{id}`, `GET|POST /api/v1/produtos`, `GET|PUT /api/v1/produtos/{id}`, `GET|POST /api/v1/servicos`, `GET|PUT /api/v1/servicos/{id}`.

**Nota:** nesta fase a API pública ainda não tem chave própria, idempotência ou limite de requisições (isso é a F6) — por enquanto ela só existe **atrás do mesmo guard de sessão** (`empresa`) do frontend, usando exatamente os mesmos Controllers e Actions, exatamente como a spec F2 §11 define. O objetivo aqui é só provar que nenhuma regra fica duplicada entre o frontend e a API.

- [ ] **Step 1: Escrever os testes (devem falhar: rota inexistente)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiPublicaCadastrosTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_produto_pela_v1(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->postJson('/api/v1/produtos', [
            'sku' => 'SKU-1', 'nome' => 'Parafuso', 'unidade' => 'UN', 'preco_centavos' => 500,
        ])->assertCreated();
    }

    public function test_produto_de_outro_tenant_responde_404_na_v1(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $alheio = Produto::factory()->for(Tenant::factory())->create();

        $this->spa()->actingAs($admin)->getJson("/api/v1/produtos/{$alheio->id}")->assertNotFound();
    }

    public function test_lista_clientes_pela_v1(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        Cliente::factory()->for($tenant)->create();

        $this->spa()->actingAs($admin)->getJson('/api/v1/clientes')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_leitura_nao_cria_pela_v1(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $leitura = Usuario::factory()->for($tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Ana'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd backend && php artisan test --filter=ApiPublicaCadastrosTest`
Expected: FAIL (rota `/api/v1/*` inexistente).

- [ ] **Step 3: Criar os arquivos de rotas da v1**

```php
<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\ProdutosController;
use App\Modules\Catalog\Http\Controllers\ServicosController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('produtos', [ProdutosController::class, 'index']);
    Route::post('produtos', [ProdutosController::class, 'store']);
    Route::get('produtos/{id}', [ProdutosController::class, 'show']);
    Route::put('produtos/{id}', [ProdutosController::class, 'update']);

    Route::get('servicos', [ServicosController::class, 'index']);
    Route::post('servicos', [ServicosController::class, 'store']);
    Route::get('servicos/{id}', [ServicosController::class, 'show']);
    Route::put('servicos/{id}', [ServicosController::class, 'update']);
});
```

```php
<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\ClientesController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('clientes', [ClientesController::class, 'index']);
    Route::post('clientes', [ClientesController::class, 'store']);
    Route::get('clientes/{id}', [ClientesController::class, 'show']);
    Route::put('clientes/{id}', [ClientesController::class, 'update']);
});
```

- [ ] **Step 4: Registrar as rotas nos providers**

Em `CatalogServiceProvider::boot()`, acrescentar:

```php
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Http/routes-v1.php');
```

Em `CustomersServiceProvider::boot()`, acrescentar:

```php
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Http/routes-v1.php');
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `cd backend && php artisan test --filter=ApiPublicaCadastrosTest`
Expected: PASS (4 testes).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Catalog/Http/routes-v1.php backend/app/Modules/Catalog/Providers/CatalogServiceProvider.php backend/app/Modules/Customers/Http/routes-v1.php backend/app/Modules/Customers/Providers/CustomersServiceProvider.php backend/tests/Feature/ApiPublicaCadastrosTest.php
git commit -m "feat: expõe clientes, produtos e serviços em /api/v1 reaproveitando as mesmas Actions"
```

---

### Tarefa 22: Frontend — Produtos e categorias fiscais padrão

**Files:**
- Create: `frontend/features/produtos/types.ts`
- Create: `frontend/features/produtos/schemas.ts`
- Create: `frontend/features/produtos/api.ts`
- Create: `frontend/features/produtos/components/ProdutoForm.tsx`
- Create: `frontend/features/produtos/components/ListaDeProdutos.tsx`
- Create: `frontend/features/produtos/components/CategoriaFiscalForm.tsx`
- Create: `frontend/features/produtos/components/Produtos.test.tsx`
- Create: `frontend/app/(app)/produtos/page.tsx`
- Modify: `frontend/components/layout/nav-items.ts`
- Test: `frontend/components/layout/nav-items.test.ts` (já existe — confirmar que o novo item aparece)

**Interfaces:**
- Consumes: `GET|POST /api/app/produtos`, `PUT /api/app/produtos/{id}`, `GET|POST /api/app/categorias-fiscais-padrao`, `POST /api/app/produtos/{id}/aplicar-categoria/{categoriaId}` (Tarefas 6, 8).
- Produces: página `/produtos`, reaproveitada como referência de padrão pela Tarefa 23 (serviços) e Tarefa 25 (clientes).

**Antes de começar:** ler `frontend/AGENTS.md` — este Next.js (16) pode divergir do treinamento do modelo.

- [ ] **Step 1: Ler os arquivos de referência**

Ler `frontend/features/usuarios/api.ts`, `schemas.ts`, `components/ConviteForm.tsx`, `components/ListaDeUsuarios.tsx` e `components/Usuarios.test.tsx` (padrão a seguir) e `frontend/components/layout/nav-items.ts`.

- [ ] **Step 2: Escrever o teste (deve falhar: nada existe ainda)**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { categoriasFiscaisApi, produtosApi } from '../api';
import { ListaDeProdutos } from './ListaDeProdutos';
import { ProdutoForm } from './ProdutoForm';

vi.mock('../api', () => ({
  QUERY_KEY_PRODUTOS: ['produtos'],
  QUERY_KEY_CATEGORIAS_FISCAIS: ['categorias-fiscais-padrao'],
  produtosApi: { listar: vi.fn(), criar: vi.fn(), editar: vi.fn(), aplicarCategoria: vi.fn() },
  categoriasFiscaisApi: { listar: vi.fn(), criar: vi.fn() },
}));

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('produtos', () => {
  beforeEach(() => {
    vi.mocked(categoriasFiscaisApi.listar).mockResolvedValue({ data: [] });
  });

  it('cria produto com origem zero preservada', async () => {
    vi.mocked(produtosApi.criar).mockResolvedValue({
      data: { id: '1', sku: 'SKU-1', nome: 'Parafuso', unidade: 'UN', preco_centavos: 500, gtin: null, ncm: null, cest: null, origem: 0, tributacao_icms: null, fiscal_fonte: 'MANUAL', fiscal_revisado_em: null, pendente_fiscal: false },
    });
    renderizar(<ProdutoForm />);

    await userEvent.type(screen.getByLabelText('SKU'), 'SKU-1');
    await userEvent.type(screen.getByLabelText('Nome'), 'Parafuso');
    await userEvent.type(screen.getByLabelText('Unidade'), 'UN');
    await userEvent.type(screen.getByLabelText('Preço (centavos)'), '500');
    await userEvent.type(screen.getByLabelText('Origem'), '0');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar produto' }));

    await waitFor(() => expect(produtosApi.criar).toHaveBeenCalledWith(expect.objectContaining({ origem: 0 })));
  });

  it('limite do plano aparece como alerta', async () => {
    vi.mocked(produtosApi.criar).mockRejectedValue(
      new ApiError(422, 'Seu plano permite até 2 produtos.', {}, { codigo: 'LIMITE_DO_PLANO' }),
    );
    renderizar(<ProdutoForm />);

    await userEvent.type(screen.getByLabelText('SKU'), 'SKU-1');
    await userEvent.type(screen.getByLabelText('Nome'), 'Parafuso');
    await userEvent.type(screen.getByLabelText('Unidade'), 'UN');
    await userEvent.type(screen.getByLabelText('Preço (centavos)'), '500');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar produto' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Seu plano permite até 2 produtos.');
  });

  it('lista produtos e mostra a pendência fiscal', async () => {
    vi.mocked(produtosApi.listar).mockResolvedValue({
      data: [{ id: '1', sku: 'SKU-1', nome: 'Parafuso', unidade: 'UN', preco_centavos: 500, gtin: null, ncm: null, cest: null, origem: null, tributacao_icms: null, fiscal_fonte: 'MANUAL', fiscal_revisado_em: null, pendente_fiscal: true }],
    });
    renderizar(<ListaDeProdutos />);

    expect(await screen.findByText('Parafuso')).toBeInTheDocument();
    expect(screen.getByText(/pendente/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Rodar e confirmar a falha**

Run: `cd frontend && npx vitest run features/produtos`
Expected: FAIL (módulos inexistentes).

- [ ] **Step 4: Criar `types.ts`**

```ts
export type TributacaoIcms = 'NORMAL' | 'ST';
export type FonteFiscal = 'MANUAL' | 'PADRAO';

export interface Produto {
  id: string;
  sku: string;
  nome: string;
  unidade: string;
  preco_centavos: number;
  gtin: string | null;
  ncm: string | null;
  cest: string | null;
  origem: number | null;
  tributacao_icms: TributacaoIcms | null;
  fiscal_fonte: FonteFiscal;
  fiscal_revisado_em: string | null;
  pendente_fiscal: boolean;
}

export interface ProdutoPayload {
  sku: string;
  nome: string;
  unidade: string;
  preco_centavos: number;
  gtin: string | null;
  ncm: string | null;
  cest: string | null;
  origem: number | null;
  tributacao_icms: TributacaoIcms | null;
}

export interface CategoriaFiscalPadrao {
  id: string;
  categoria: string;
  ncm: string | null;
  origem: number | null;
  tributacao_icms: TributacaoIcms | null;
}
```

- [ ] **Step 5: Criar `schemas.ts`**

```ts
import { z } from 'zod';

const vazioOuTexto = (max: number) => z.string().trim().max(max).optional().or(z.literal(''));
const origemTexto = z.string().trim().refine(
  (v) => v === '' || (/^\d+$/.test(v) && Number(v) >= 0 && Number(v) <= 8),
  'Origem deve ser um número de 0 a 8.',
);

export const produtoSchema = z.object({
  sku: z.string().trim().min(1, 'Informe o SKU.').max(60),
  nome: z.string().trim().min(1, 'Informe o nome.').max(150),
  unidade: z.string().trim().min(1, 'Informe a unidade.').max(10),
  preco_centavos: z.coerce.number().int().min(0, 'Informe um preço válido.'),
  gtin: vazioOuTexto(14),
  ncm: vazioOuTexto(20),
  cest: vazioOuTexto(20),
  origem: origemTexto,
  tributacao_icms: z.enum(['NORMAL', 'ST']).optional().or(z.literal('')),
});

export type ProdutoFormDados = z.infer<typeof produtoSchema>;

export const categoriaFiscalSchema = z.object({
  categoria: z.string().trim().min(1, 'Informe a categoria.').max(60),
  ncm: vazioOuTexto(20),
  origem: origemTexto,
  tributacao_icms: z.enum(['NORMAL', 'ST']).optional().or(z.literal('')),
});

export type CategoriaFiscalFormDados = z.infer<typeof categoriaFiscalSchema>;
```

- [ ] **Step 6: Criar `api.ts`**

```ts
import { api } from '@/lib/api';
import type { CategoriaFiscalFormDados, ProdutoFormDados } from './schemas';
import type { CategoriaFiscalPadrao, Produto, ProdutoPayload } from './types';

export const QUERY_KEY_PRODUTOS = ['produtos'] as const;
export const QUERY_KEY_CATEGORIAS_FISCAIS = ['categorias-fiscais-padrao'] as const;

/** Converte os campos opcionais do formulário (string) para o formato que a API espera (null quando vazio). */
export function paraPayload(d: ProdutoFormDados): ProdutoPayload {
  return {
    sku: d.sku,
    nome: d.nome,
    unidade: d.unidade.toUpperCase(),
    preco_centavos: d.preco_centavos,
    gtin: d.gtin || null,
    ncm: d.ncm || null,
    cest: d.cest || null,
    origem: d.origem === '' ? null : Number(d.origem),
    tributacao_icms: d.tributacao_icms || null,
  };
}

export const produtosApi = {
  listar: () => api<{ data: Produto[] }>('/api/app/produtos'),
  criar: (dados: ProdutoPayload) => api<{ data: Produto }>('/api/app/produtos', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: ProdutoPayload) => api<{ data: Produto }>(`/api/app/produtos/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  aplicarCategoria: (produtoId: string, categoriaId: string) =>
    api<{ data: Produto }>(`/api/app/produtos/${produtoId}/aplicar-categoria/${categoriaId}`, { method: 'POST' }),
};

export const categoriasFiscaisApi = {
  listar: () => api<{ data: CategoriaFiscalPadrao[] }>('/api/app/categorias-fiscais-padrao'),
  criar: (d: CategoriaFiscalFormDados) =>
    api<{ data: CategoriaFiscalPadrao }>('/api/app/categorias-fiscais-padrao', {
      method: 'POST',
      body: JSON.stringify({ categoria: d.categoria, ncm: d.ncm || null, origem: d.origem === '' ? null : Number(d.origem), tributacao_icms: d.tributacao_icms || null }),
    }),
};
```

- [ ] **Step 7: Criar `ProdutoForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { paraPayload, produtosApi, QUERY_KEY_PRODUTOS } from '../api';
import { produtoSchema, type ProdutoFormDados } from '../schemas';
import type { Produto } from '../types';

const VAZIO: ProdutoFormDados = { sku: '', nome: '', unidade: '', preco_centavos: 0, gtin: '', ncm: '', cest: '', origem: '', tributacao_icms: '' };

function paraFormulario(p: Produto): ProdutoFormDados {
  return {
    sku: p.sku, nome: p.nome, unidade: p.unidade, preco_centavos: p.preco_centavos,
    gtin: p.gtin ?? '', ncm: p.ncm ?? '', cest: p.cest ?? '',
    origem: p.origem === null ? '' : String(p.origem),
    tributacao_icms: p.tributacao_icms ?? '',
  };
}

export function ProdutoForm({ produto, onSalvar }: { produto?: Produto; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<ProdutoFormDados>({ resolver: zodResolver(produtoSchema), defaultValues: produto ? paraFormulario(produto) : VAZIO });
  const salvar = useMutation({
    mutationFn: (d: ProdutoFormDados) => (produto ? produtosApi.editar(produto.id, paraPayload(d)) : produtosApi.criar(paraPayload(d))),
    onSuccess: () => {
      toast.success(produto ? 'Produto atualizado.' : 'Produto criado.');
      if (!produto) form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_PRODUTOS });
      onSalvar?.();
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        form.setError('root', { message: 'Não foi possível salvar. Tente novamente.' });
        return;
      }
      form.setError('root', { message: erro.primeiraMensagem() });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="produto_sku" label="SKU" erro={erros.sku?.message}>
        {(a11y) => <Input {...a11y} {...form.register('sku')} />}
      </Campo>
      <Campo id="produto_nome" label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id="produto_unidade" label="Unidade" erro={erros.unidade?.message}>
        {(a11y) => <Input {...a11y} {...form.register('unidade')} />}
      </Campo>
      <Campo id="produto_preco" label="Preço (centavos)" erro={erros.preco_centavos?.message}>
        {(a11y) => <Input {...a11y} type="number" min={0} {...form.register('preco_centavos')} />}
      </Campo>
      <Campo id="produto_ncm" label="NCM" dica="8 dígitos. Deixe em branco se não souber ainda." erro={erros.ncm?.message}>
        {(a11y) => <Input {...a11y} {...form.register('ncm')} />}
      </Campo>
      <Campo id="produto_cest" label="CEST" erro={erros.cest?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cest')} />}
      </Campo>
      <Campo id="produto_origem" label="Origem" dica="0 a 8. Zero (nacional) é um valor válido." erro={erros.origem?.message}>
        {(a11y) => <Input {...a11y} {...form.register('origem')} />}
      </Campo>
      <Campo id="produto_tributacao" label="Tributação de ICMS" erro={erros.tributacao_icms?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('tributacao_icms')}>
            <option value="">Não informado</option>
            <option value="NORMAL">Normal</option>
            <option value="ST">Substituição tributária</option>
          </SelectNativo>
        )}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar produto</Button>
    </form>
  );
}
```

- [ ] **Step 8: Criar `ListaDeProdutos.tsx`**

```tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { produtosApi, QUERY_KEY_PRODUTOS } from '../api';
import { ProdutoForm } from './ProdutoForm';

export function ListaDeProdutos() {
  const [editando, setEditando] = useState<string | null>(null);
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_PRODUTOS, queryFn: async () => (await produtosApi.listar()).data });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar os produtos.</p>;

  return (
    <ul className="divide-y">
      {data.map((p) => (
        <li key={p.id} className="py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="min-w-0">
              <p className="font-medium">{p.nome}</p>
              <p className="truncate text-sm text-muted-foreground">
                {p.sku} · {p.unidade} {p.pendente_fiscal ? <span className="text-amber-600">· pendente fiscal</span> : null}
              </p>
            </div>
            <Button variant="outline" size="sm" aria-label={`Editar ${p.nome}`} onClick={() => setEditando(p.id)}>Editar</Button>
          </div>
          {editando === p.id ? <ProdutoForm produto={p} onSalvar={() => setEditando(null)} /> : null}
        </li>
      ))}
    </ul>
  );
}
```

- [ ] **Step 9: Criar `CategoriaFiscalForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { categoriasFiscaisApi, QUERY_KEY_CATEGORIAS_FISCAIS } from '../api';
import { categoriaFiscalSchema, type CategoriaFiscalFormDados } from '../schemas';

const VAZIO: CategoriaFiscalFormDados = { categoria: '', ncm: '', origem: '', tributacao_icms: '' };

export function CategoriaFiscalForm() {
  const queryClient = useQueryClient();
  const { data: categorias } = useQuery({ queryKey: QUERY_KEY_CATEGORIAS_FISCAIS, queryFn: async () => (await categoriasFiscaisApi.listar()).data });
  const form = useForm<CategoriaFiscalFormDados>({ resolver: zodResolver(categoriaFiscalSchema), defaultValues: VAZIO });
  const criar = useMutation({
    mutationFn: (d: CategoriaFiscalFormDados) => categoriasFiscaisApi.criar(d),
    onSuccess: () => {
      toast.success('Categoria fiscal criada.');
      form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CATEGORIAS_FISCAIS });
    },
  });
  const envioUnico = useEnvioUnico();

  return (
    <div className="space-y-3">
      <ul className="divide-y text-sm">
        {categorias?.map((c) => <li key={c.id} className="py-2">{c.categoria}</li>)}
      </ul>
      <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await criar.mutateAsync(d).catch(() => undefined); }))}>
        <Campo id="categoria_nome" label="Nova categoria" erro={form.formState.errors.categoria?.message}>
          {(a11y) => <Input {...a11y} {...form.register('categoria')} />}
        </Campo>
        <Campo id="categoria_ncm" label="NCM padrão" erro={form.formState.errors.ncm?.message}>
          {(a11y) => <Input {...a11y} {...form.register('ncm')} />}
        </Campo>
        <Campo id="categoria_origem" label="Origem padrão" erro={form.formState.errors.origem?.message}>
          {(a11y) => <Input {...a11y} {...form.register('origem')} />}
        </Campo>
        <Button type="submit" size="sm" disabled={form.formState.isSubmitting}>Adicionar categoria</Button>
      </form>
    </div>
  );
}
```

- [ ] **Step 10: Criar a página**

```tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CategoriaFiscalForm } from '@/features/produtos/components/CategoriaFiscalForm';
import { ListaDeProdutos } from '@/features/produtos/components/ListaDeProdutos';
import { ProdutoForm } from '@/features/produtos/components/ProdutoForm';

export default function ProdutosPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Produtos</h1>
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader><CardTitle>Catálogo</CardTitle></CardHeader>
          <CardContent><ListaDeProdutos /></CardContent>
        </Card>
        <div className="space-y-4">
          <Card>
            <CardHeader><CardTitle>Novo produto</CardTitle></CardHeader>
            <CardContent><ProdutoForm /></CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle>Categorias fiscais padrão</CardTitle></CardHeader>
            <CardContent><CategoriaFiscalForm /></CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 11: Acrescentar o item de navegação**

Em `frontend/components/layout/nav-items.ts`, importar o ícone `Package` (já importado) e acrescentar em `NAV_ITEMS`:

```ts
  { href: '/produtos', label: 'Produtos', icon: Package, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
```

- [ ] **Step 12: Rodar e confirmar que passa**

Run: `cd frontend && npx vitest run features/produtos && npx tsc --noEmit`
Expected: PASS, sem erros de tipo.

- [ ] **Step 13: Commit**

```bash
git add frontend/features/produtos frontend/app/\(app\)/produtos frontend/components/layout/nav-items.ts
git commit -m "feat(frontend): tela de produtos e categorias fiscais padrão"
```

---

### Tarefa 23: Frontend — Serviços

**Files:**
- Create: `frontend/features/servicos/types.ts`
- Create: `frontend/features/servicos/schemas.ts`
- Create: `frontend/features/servicos/api.ts`
- Create: `frontend/features/servicos/components/ServicoForm.tsx`
- Create: `frontend/features/servicos/components/ListaDeServicos.tsx`
- Create: `frontend/features/servicos/components/Servicos.test.tsx`
- Create: `frontend/app/(app)/servicos/page.tsx`
- Modify: `frontend/components/layout/nav-items.ts`

**Interfaces:**
- Consumes: `GET|POST /api/app/servicos`, `PUT /api/app/servicos/{id}` (Tarefa 7).
- Produces: página `/servicos`. Mesmo padrão da Tarefa 22.

- [ ] **Step 1: Escrever o teste (deve falhar: nada existe ainda)**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { servicosApi } from '../api';
import { ListaDeServicos } from './ListaDeServicos';
import { ServicoForm } from './ServicoForm';

vi.mock('../api', () => ({
  QUERY_KEY_SERVICOS: ['servicos'],
  servicosApi: { listar: vi.fn(), criar: vi.fn(), editar: vi.fn() },
}));

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('serviços', () => {
  it('rejeita código LC 116 fora do formato', async () => {
    renderizar(<ServicoForm />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Consultoria');
    await userEvent.type(screen.getByLabelText('Preço (centavos)'), '10000');
    await userEvent.type(screen.getByLabelText('Código LC 116'), '1401');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar serviço' }));

    expect(await screen.findByText(/formato 00\.00/i)).toBeInTheDocument();
    expect(servicosApi.criar).not.toHaveBeenCalled();
  });

  it('cria serviço completo', async () => {
    vi.mocked(servicosApi.criar).mockResolvedValue({
      data: { id: '1', nome: 'Consultoria', preco_centavos: 10000, codigo_lc116: '14.01', c_trib_nac: '140101', codigo_municipal: null, aliquota_iss: '5.00', nbs: null, pendente_fiscal: false },
    });
    renderizar(<ServicoForm />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Consultoria');
    await userEvent.type(screen.getByLabelText('Preço (centavos)'), '10000');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar serviço' }));

    await waitFor(() => expect(servicosApi.criar).toHaveBeenCalled());
  });

  it('lista serviços pendentes', async () => {
    vi.mocked(servicosApi.listar).mockResolvedValue({
      data: [{ id: '1', nome: 'Consultoria', preco_centavos: 10000, codigo_lc116: null, c_trib_nac: null, codigo_municipal: null, aliquota_iss: null, nbs: null, pendente_fiscal: true }],
    });
    renderizar(<ListaDeServicos />);

    expect(await screen.findByText('Consultoria')).toBeInTheDocument();
    expect(screen.getByText(/pendente/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd frontend && npx vitest run features/servicos`
Expected: FAIL (módulos inexistentes).

- [ ] **Step 3: Criar `types.ts`**

```ts
export interface Servico {
  id: string;
  nome: string;
  preco_centavos: number;
  codigo_lc116: string | null;
  c_trib_nac: string | null;
  codigo_municipal: string | null;
  aliquota_iss: string | null;
  nbs: string | null;
  pendente_fiscal: boolean;
}

export interface ServicoPayload {
  nome: string;
  preco_centavos: number;
  codigo_lc116: string | null;
  c_trib_nac: string | null;
  codigo_municipal: string | null;
  aliquota_iss: string | null;
  nbs: string | null;
}
```

- [ ] **Step 4: Criar `schemas.ts`**

```ts
import { z } from 'zod';

const vazioOuTexto = (max: number) => z.string().trim().max(max).optional().or(z.literal(''));

export const servicoSchema = z.object({
  nome: z.string().trim().min(1, 'Informe o nome.').max(150),
  preco_centavos: z.coerce.number().int().min(0, 'Informe um preço válido.'),
  codigo_lc116: z.string().trim().regex(/^(\d{2}\.\d{2})?$/, 'Use o formato 00.00.').optional().or(z.literal('')),
  c_trib_nac: z.string().trim().regex(/^(\d{6})?$/, 'Use 6 dígitos.').optional().or(z.literal('')),
  codigo_municipal: z.string().trim().regex(/^(\d{3})?$/, 'Use 3 dígitos.').optional().or(z.literal('')),
  aliquota_iss: z.string().trim().optional().or(z.literal('')),
  nbs: vazioOuTexto(9),
});

export type ServicoFormDados = z.infer<typeof servicoSchema>;
```

- [ ] **Step 5: Criar `api.ts`**

```ts
import { api } from '@/lib/api';
import type { ServicoFormDados } from './schemas';
import type { Servico, ServicoPayload } from './types';

export const QUERY_KEY_SERVICOS = ['servicos'] as const;

export function paraPayload(d: ServicoFormDados): ServicoPayload {
  return {
    nome: d.nome,
    preco_centavos: d.preco_centavos,
    codigo_lc116: d.codigo_lc116 || null,
    c_trib_nac: d.c_trib_nac || null,
    codigo_municipal: d.codigo_municipal || null,
    aliquota_iss: d.aliquota_iss || null,
    nbs: d.nbs || null,
  };
}

export const servicosApi = {
  listar: () => api<{ data: Servico[] }>('/api/app/servicos'),
  criar: (dados: ServicoPayload) => api<{ data: Servico }>('/api/app/servicos', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: ServicoPayload) => api<{ data: Servico }>(`/api/app/servicos/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
};
```

- [ ] **Step 6: Criar `ServicoForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { paraPayload, QUERY_KEY_SERVICOS, servicosApi } from '../api';
import { servicoSchema, type ServicoFormDados } from '../schemas';
import type { Servico } from '../types';

const VAZIO: ServicoFormDados = { nome: '', preco_centavos: 0, codigo_lc116: '', c_trib_nac: '', codigo_municipal: '', aliquota_iss: '', nbs: '' };

function paraFormulario(s: Servico): ServicoFormDados {
  return {
    nome: s.nome, preco_centavos: s.preco_centavos,
    codigo_lc116: s.codigo_lc116 ?? '', c_trib_nac: s.c_trib_nac ?? '',
    codigo_municipal: s.codigo_municipal ?? '', aliquota_iss: s.aliquota_iss ?? '', nbs: s.nbs ?? '',
  };
}

export function ServicoForm({ servico, onSalvar }: { servico?: Servico; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<ServicoFormDados>({ resolver: zodResolver(servicoSchema), defaultValues: servico ? paraFormulario(servico) : VAZIO });
  const salvar = useMutation({
    mutationFn: (d: ServicoFormDados) => (servico ? servicosApi.editar(servico.id, paraPayload(d)) : servicosApi.criar(paraPayload(d))),
    onSuccess: () => {
      toast.success(servico ? 'Serviço atualizado.' : 'Serviço criado.');
      if (!servico) form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_SERVICOS });
      onSalvar?.();
    },
    onError: (erro) => {
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Não foi possível salvar.' });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="servico_nome" label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id="servico_preco" label="Preço (centavos)" erro={erros.preco_centavos?.message}>
        {(a11y) => <Input {...a11y} type="number" min={0} {...form.register('preco_centavos')} />}
      </Campo>
      <Campo id="servico_lc116" label="Código LC 116" dica="Formato 00.00, ex.: 14.01." erro={erros.codigo_lc116?.message}>
        {(a11y) => <Input {...a11y} {...form.register('codigo_lc116')} />}
      </Campo>
      <Campo id="servico_ctribnac" label="cTribNac" dica="6 dígitos." erro={erros.c_trib_nac?.message}>
        {(a11y) => <Input {...a11y} {...form.register('c_trib_nac')} />}
      </Campo>
      <Campo id="servico_codmun" label="Código municipal" erro={erros.codigo_municipal?.message}>
        {(a11y) => <Input {...a11y} {...form.register('codigo_municipal')} />}
      </Campo>
      <Campo id="servico_iss" label="Alíquota de ISS (%)" erro={erros.aliquota_iss?.message}>
        {(a11y) => <Input {...a11y} {...form.register('aliquota_iss')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar serviço</Button>
    </form>
  );
}
```

- [ ] **Step 7: Criar `ListaDeServicos.tsx`**

```tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { QUERY_KEY_SERVICOS, servicosApi } from '../api';
import { ServicoForm } from './ServicoForm';

export function ListaDeServicos() {
  const [editando, setEditando] = useState<string | null>(null);
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_SERVICOS, queryFn: async () => (await servicosApi.listar()).data });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar os serviços.</p>;

  return (
    <ul className="divide-y">
      {data.map((s) => (
        <li key={s.id} className="py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="min-w-0">
              <p className="font-medium">{s.nome}</p>
              <p className="truncate text-sm text-muted-foreground">
                {s.pendente_fiscal ? <span className="text-amber-600">pendente fiscal</span> : 'dados fiscais completos'}
              </p>
            </div>
            <Button variant="outline" size="sm" aria-label={`Editar ${s.nome}`} onClick={() => setEditando(s.id)}>Editar</Button>
          </div>
          {editando === s.id ? <ServicoForm servico={s} onSalvar={() => setEditando(null)} /> : null}
        </li>
      ))}
    </ul>
  );
}
```

- [ ] **Step 8: Criar a página**

```tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ListaDeServicos } from '@/features/servicos/components/ListaDeServicos';
import { ServicoForm } from '@/features/servicos/components/ServicoForm';

export default function ServicosPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Serviços</h1>
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader><CardTitle>Catálogo</CardTitle></CardHeader>
          <CardContent><ListaDeServicos /></CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Novo serviço</CardTitle></CardHeader>
          <CardContent><ServicoForm /></CardContent>
        </Card>
      </div>
    </div>
  );
}
```

- [ ] **Step 9: Acrescentar o item de navegação**

Em `frontend/components/layout/nav-items.ts`, importar `Wrench` de `lucide-react` e acrescentar:

```ts
  { href: '/servicos', label: 'Serviços', icon: Wrench, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
```

- [ ] **Step 10: Rodar e confirmar que passa**

Run: `cd frontend && npx vitest run features/servicos && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add frontend/features/servicos frontend/app/\(app\)/servicos frontend/components/layout/nav-items.ts
git commit -m "feat(frontend): tela de serviços"
```

---

### Tarefa 24: Frontend — Pendências fiscais

**Files:**
- Create: `frontend/features/pendencias-fiscais/api.ts`
- Create: `frontend/features/pendencias-fiscais/components/PendenciasFiscais.tsx`
- Create: `frontend/features/pendencias-fiscais/components/PendenciasFiscais.test.tsx`
- Create: `frontend/app/(app)/pendencias-fiscais/page.tsx`
- Modify: `frontend/components/layout/nav-items.ts`

**Interfaces:**
- Consumes: `GET /api/app/produtos/pendencias-fiscais`, `POST /api/app/produtos/{id}/marcar-revisado` (Tarefa 9), tipos `Produto` (Tarefa 22) e `Servico` (Tarefa 23).
- Produces: página `/pendencias-fiscais`.

- [ ] **Step 1: Escrever o teste (deve falhar: nada existe ainda)**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { pendenciasFiscaisApi } from '../api';
import { PendenciasFiscais } from './PendenciasFiscais';

vi.mock('../api', () => ({
  QUERY_KEY_PENDENCIAS_FISCAIS: ['pendencias-fiscais'],
  pendenciasFiscaisApi: { listar: vi.fn(), marcarRevisado: vi.fn() },
}));

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('pendências fiscais', () => {
  it('lista produtos e serviços pendentes e marca como revisado', async () => {
    vi.mocked(pendenciasFiscaisApi.listar).mockResolvedValue({
      data: {
        produtos: [{ id: '1', sku: 'SKU-1', nome: 'Parafuso', unidade: 'UN', preco_centavos: 100, gtin: null, ncm: '12345678', cest: null, origem: 0, tributacao_icms: 'NORMAL', fiscal_fonte: 'PADRAO', fiscal_revisado_em: null, pendente_fiscal: true }],
        servicos: [{ id: '2', nome: 'Consultoria', preco_centavos: 1000, codigo_lc116: null, c_trib_nac: null, codigo_municipal: null, aliquota_iss: null, nbs: null, pendente_fiscal: true }],
      },
    });
    vi.mocked(pendenciasFiscaisApi.marcarRevisado).mockResolvedValue({ data: { id: '1' } as never });
    renderizar(<PendenciasFiscais podeRevisar />);

    expect(await screen.findByText('Parafuso')).toBeInTheDocument();
    expect(screen.getByText('Consultoria')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Marcar Parafuso como revisado' }));
    await waitFor(() => expect(pendenciasFiscaisApi.marcarRevisado).toHaveBeenCalledWith('1'));
  });

  it('sem pendências mostra mensagem positiva', async () => {
    vi.mocked(pendenciasFiscaisApi.listar).mockResolvedValue({ data: { produtos: [], servicos: [] } });
    renderizar(<PendenciasFiscais podeRevisar={false} />);

    expect(await screen.findByText(/nenhuma pendência/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd frontend && npx vitest run features/pendencias-fiscais`
Expected: FAIL (módulos inexistentes).

- [ ] **Step 3: Criar `api.ts`**

```ts
import { api } from '@/lib/api';
import type { Produto } from '@/features/produtos/types';
import type { Servico } from '@/features/servicos/types';

export const QUERY_KEY_PENDENCIAS_FISCAIS = ['pendencias-fiscais'] as const;

export const pendenciasFiscaisApi = {
  listar: () => api<{ data: { produtos: Produto[]; servicos: Servico[] } }>('/api/app/produtos/pendencias-fiscais'),
  marcarRevisado: (produtoId: string) => api<{ data: Produto }>(`/api/app/produtos/${produtoId}/marcar-revisado`, { method: 'POST' }),
};
```

- [ ] **Step 4: Criar `PendenciasFiscais.tsx`**

```tsx
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { pendenciasFiscaisApi, QUERY_KEY_PENDENCIAS_FISCAIS } from '../api';

export function PendenciasFiscais({ podeRevisar }: { podeRevisar: boolean }) {
  const queryClient = useQueryClient();
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_PENDENCIAS_FISCAIS, queryFn: async () => (await pendenciasFiscaisApi.listar()).data });
  const marcarRevisado = useMutation({
    mutationFn: (produtoId: string) => pendenciasFiscaisApi.marcarRevisado(produtoId),
    onSuccess: () => {
      toast.success('Produto marcado como revisado.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_PENDENCIAS_FISCAIS });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar as pendências.</p>;

  const semPendencias = data.produtos.length === 0 && data.servicos.length === 0;
  if (semPendencias) return <p className="text-muted-foreground">Nenhuma pendência fiscal. Tudo revisado.</p>;

  return (
    <div className="space-y-4">
      {data.produtos.length > 0 ? (
        <section>
          <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Produtos</h2>
          <ul className="divide-y">
            {data.produtos.map((p) => (
              <li key={p.id} className="flex items-center justify-between gap-2 py-2">
                <span>{p.nome} <span className="text-xs text-muted-foreground">({p.sku})</span></span>
                {podeRevisar && p.fiscal_fonte === 'PADRAO' ? (
                  <Button variant="outline" size="sm" aria-label={`Marcar ${p.nome} como revisado`} onClick={() => marcarRevisado.mutate(p.id)}>
                    Marcar revisado
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        </section>
      ) : null}
      {data.servicos.length > 0 ? (
        <section>
          <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Serviços</h2>
          <ul className="divide-y">
            {data.servicos.map((s) => <li key={s.id} className="py-2">{s.nome}</li>)}
          </ul>
        </section>
      ) : null}
    </div>
  );
}
```

- [ ] **Step 5: Criar a página**

```tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { PendenciasFiscais } from '@/features/pendencias-fiscais/components/PendenciasFiscais';

const PAPEIS_QUE_REVISAM = ['PROPRIETARIO', 'ADMIN', 'FISCAL'];

export default function PendenciasFiscaisPage() {
  const { data: usuario } = useUsuario();

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Pendências fiscais</h1>
      <Card>
        <CardHeader><CardTitle>Produtos e serviços sem os dados obrigatórios</CardTitle></CardHeader>
        <CardContent>
          <PendenciasFiscais podeRevisar={Boolean(usuario && PAPEIS_QUE_REVISAM.includes(usuario.papel))} />
        </CardContent>
      </Card>
    </div>
  );
}
```

- [ ] **Step 6: Acrescentar o item de navegação**

Em `frontend/components/layout/nav-items.ts`, importar `AlertTriangle` de `lucide-react` e acrescentar:

```ts
  { href: '/pendencias-fiscais', label: 'Pendências fiscais', icon: AlertTriangle, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
```

- [ ] **Step 7: Rodar e confirmar que passa**

Run: `cd frontend && npx vitest run features/pendencias-fiscais && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/features/pendencias-fiscais frontend/app/\(app\)/pendencias-fiscais frontend/components/layout/nav-items.ts
git commit -m "feat(frontend): tela de pendências fiscais de produtos e serviços"
```

---

### Tarefa 25: Frontend — Clientes, contatos e conversão de estágio

**Files:**
- Create: `frontend/features/clientes/types.ts`
- Create: `frontend/features/clientes/schemas.ts`
- Create: `frontend/features/clientes/api.ts`
- Create: `frontend/features/clientes/components/ClienteForm.tsx`
- Create: `frontend/features/clientes/components/ContatoForm.tsx`
- Create: `frontend/features/clientes/components/ListaDeClientes.tsx`
- Create: `frontend/features/clientes/components/Clientes.test.tsx`
- Create: `frontend/app/(app)/clientes/page.tsx`
- Modify: `frontend/components/layout/nav-items.ts`

**Interfaces:**
- Consumes: `GET|POST /api/app/clientes`, `PUT /api/app/clientes/{id}`, `POST /api/app/clientes/{id}/converter-em-cliente`, `POST|PUT /api/app/clientes/{id}/contatos[/...]` (Tarefas 11, 12, 13), `GET /api/app/emitente/cep/{cep}` (Tarefa 15, reaproveitado aqui só como consulta de endereço — não é dado do emitente).
- Produces: página `/clientes`.

- [ ] **Step 1: Escrever o teste (deve falhar: nada existe ainda)**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { clientesApi } from '../api';
import { ClienteForm } from './ClienteForm';
import { ListaDeClientes } from './ListaDeClientes';

vi.mock('../api', () => ({
  QUERY_KEY_CLIENTES: ['clientes'],
  clientesApi: { listar: vi.fn(), criar: vi.fn(), editar: vi.fn(), converterEmCliente: vi.fn() },
  contatosApi: { criar: vi.fn(), editar: vi.fn() },
  consultarCep: vi.fn(),
}));

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('clientes', () => {
  it('cria lead PF só com o nome', async () => {
    vi.mocked(clientesApi.criar).mockResolvedValue({
      data: { id: '1', tipo: 'PF', nome: 'Maria', cpf_cnpj: null, inscricao_estadual: null, ie_isento: false, email: null, telefone: null, logradouro: null, numero: null, bairro: null, cidade: null, uf: null, cep: null, codigo_ibge: null, tags: [], origem: null, estagio: 'LEAD' },
    });
    renderizar(<ClienteForm />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Maria');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar cliente' }));

    await waitFor(() => expect(clientesApi.criar).toHaveBeenCalledWith(expect.objectContaining({ nome: 'Maria', cpf_cnpj: null })));
  });

  it('CPF inválido mostra o erro devolvido pela API', async () => {
    vi.mocked(clientesApi.criar).mockRejectedValue(new ApiError(422, 'Dados inválidos.', { cpf_cnpj: ['Informe um CPF válido.'] }));
    renderizar(<ClienteForm />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Maria');
    await userEvent.type(screen.getByLabelText('CPF'), '111.111.111-11');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar cliente' }));

    expect(await screen.findByText('Informe um CPF válido.')).toBeInTheDocument();
  });

  it('converte lead em cliente', async () => {
    vi.mocked(clientesApi.converterEmCliente).mockResolvedValue({
      data: { id: '1', tipo: 'PF', nome: 'Maria', cpf_cnpj: null, inscricao_estadual: null, ie_isento: false, email: null, telefone: null, logradouro: null, numero: null, bairro: null, cidade: null, uf: null, cep: null, codigo_ibge: null, tags: [], origem: null, estagio: 'CLIENTE' },
    });
    const { clientesApi: api } = await import('../api');
    vi.mocked(api.listar).mockResolvedValue({
      data: [{ id: '1', tipo: 'PF', nome: 'Maria', cpf_cnpj: null, inscricao_estadual: null, ie_isento: false, email: null, telefone: null, logradouro: null, numero: null, bairro: null, cidade: null, uf: null, cep: null, codigo_ibge: null, tags: [], origem: null, estagio: 'LEAD' }],
    });
    renderizar(<ListaDeClientes podeEscrever />);

    await userEvent.click(await screen.findByRole('button', { name: 'Converter Maria em cliente' }));
    await waitFor(() => expect(api.converterEmCliente).toHaveBeenCalledWith('1'));
  });
});
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd frontend && npx vitest run features/clientes`
Expected: FAIL (módulos inexistentes).

- [ ] **Step 3: Criar `types.ts`**

```ts
export type TipoCliente = 'PF' | 'PJ';
export type EstagioCliente = 'LEAD' | 'CLIENTE';

export interface Contato {
  id: string;
  nome: string;
  cargo: string | null;
  email: string | null;
  telefone: string | null;
}

export interface Cliente {
  id: string;
  tipo: TipoCliente;
  nome: string;
  cpf_cnpj: string | null;
  inscricao_estadual: string | null;
  ie_isento: boolean;
  email: string | null;
  telefone: string | null;
  logradouro: string | null;
  numero: string | null;
  bairro: string | null;
  cidade: string | null;
  uf: string | null;
  cep: string | null;
  codigo_ibge: string | null;
  tags: string[];
  origem: string | null;
  estagio: EstagioCliente;
  contatos?: Contato[];
}

export interface ClientePayload {
  tipo: TipoCliente;
  nome: string;
  cpf_cnpj: string | null;
  inscricao_estadual: string | null;
  ie_isento: boolean;
  email: string | null;
  telefone: string | null;
  logradouro: string | null;
  numero: string | null;
  bairro: string | null;
  cidade: string | null;
  uf: string | null;
  cep: string | null;
  codigo_ibge: string | null;
  origem: string | null;
}
```

- [ ] **Step 4: Criar `schemas.ts`**

```ts
import { z } from 'zod';

const vazioOuTexto = (max: number) => z.string().trim().max(max).optional().or(z.literal(''));

export const clienteSchema = z.object({
  tipo: z.enum(['PF', 'PJ'], { error: 'Escolha o tipo.' }),
  nome: z.string().trim().min(1, 'Informe o nome.').max(150),
  cpf_cnpj: vazioOuTexto(18),
  inscricao_estadual: vazioOuTexto(20),
  ie_isento: z.boolean(),
  email: z.string().trim().email('Informe um e-mail válido.').optional().or(z.literal('')),
  telefone: vazioOuTexto(20),
  logradouro: vazioOuTexto(150),
  numero: vazioOuTexto(20),
  bairro: vazioOuTexto(100),
  cidade: vazioOuTexto(100),
  uf: vazioOuTexto(2),
  cep: vazioOuTexto(9),
  origem: vazioOuTexto(60),
});

export type ClienteFormDados = z.infer<typeof clienteSchema>;

export const contatoSchema = z.object({
  nome: z.string().trim().min(1, 'Informe o nome.').max(150),
  cargo: vazioOuTexto(100),
  email: z.string().trim().email('Informe um e-mail válido.').optional().or(z.literal('')),
  telefone: vazioOuTexto(20),
});

export type ContatoFormDados = z.infer<typeof contatoSchema>;
```

- [ ] **Step 5: Criar `api.ts`**

```ts
import { api } from '@/lib/api';
import type { ClienteFormDados, ContatoFormDados } from './schemas';
import type { Cliente, ClientePayload, Contato } from './types';

export const QUERY_KEY_CLIENTES = ['clientes'] as const;

export function paraPayload(d: ClienteFormDados): ClientePayload {
  return {
    tipo: d.tipo,
    nome: d.nome,
    cpf_cnpj: d.cpf_cnpj || null,
    inscricao_estadual: d.inscricao_estadual || null,
    ie_isento: d.ie_isento,
    email: d.email || null,
    telefone: d.telefone || null,
    logradouro: d.logradouro || null,
    numero: d.numero || null,
    bairro: d.bairro || null,
    cidade: d.cidade || null,
    uf: d.uf ? d.uf.toUpperCase() : null,
    cep: d.cep || null,
    codigo_ibge: null,
    origem: d.origem || null,
  };
}

export const clientesApi = {
  listar: () => api<{ data: Cliente[] }>('/api/app/clientes'),
  criar: (dados: ClientePayload) => api<{ data: Cliente }>('/api/app/clientes', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: ClientePayload) => api<{ data: Cliente }>(`/api/app/clientes/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  converterEmCliente: (id: string) => api<{ data: Cliente }>(`/api/app/clientes/${id}/converter-em-cliente`, { method: 'POST' }),
};

export const contatosApi = {
  criar: (clienteId: string, dados: ContatoFormDados) =>
    api<{ data: Contato }>(`/api/app/clientes/${clienteId}/contatos`, { method: 'POST', body: JSON.stringify(dados) }),
  editar: (clienteId: string, contatoId: string, dados: ContatoFormDados) =>
    api<{ data: Contato }>(`/api/app/clientes/${clienteId}/contatos/${contatoId}`, { method: 'PUT', body: JSON.stringify(dados) }),
};

/** Mesmo endpoint do wizard do emitente (Tarefa 15): é só uma consulta de CEP, sem relação com dado do emitente. */
export const consultarCep = (cep: string) =>
  api<{ data: { logradouro: string; bairro: string; cidade: string; uf: string; codigo_ibge: string } }>(`/api/app/emitente/cep/${cep}`);
```

- [ ] **Step 6: Criar `ClienteForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { clientesApi, consultarCep, paraPayload, QUERY_KEY_CLIENTES } from '../api';
import { clienteSchema, type ClienteFormDados } from '../schemas';
import type { Cliente } from '../types';

const VAZIO: ClienteFormDados = {
  tipo: 'PF', nome: '', cpf_cnpj: '', inscricao_estadual: '', ie_isento: false, email: '', telefone: '',
  logradouro: '', numero: '', bairro: '', cidade: '', uf: '', cep: '', origem: '',
};

function paraFormulario(c: Cliente): ClienteFormDados {
  return {
    tipo: c.tipo, nome: c.nome, cpf_cnpj: c.cpf_cnpj ?? '', inscricao_estadual: c.inscricao_estadual ?? '',
    ie_isento: c.ie_isento, email: c.email ?? '', telefone: c.telefone ?? '',
    logradouro: c.logradouro ?? '', numero: c.numero ?? '', bairro: c.bairro ?? '', cidade: c.cidade ?? '',
    uf: c.uf ?? '', cep: c.cep ?? '', origem: c.origem ?? '',
  };
}

export function ClienteForm({ cliente, onSalvar }: { cliente?: Cliente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<ClienteFormDados>({ resolver: zodResolver(clienteSchema), defaultValues: cliente ? paraFormulario(cliente) : VAZIO });
  const tipo = form.watch('tipo');
  const salvar = useMutation({
    mutationFn: (d: ClienteFormDados) => (cliente ? clientesApi.editar(cliente.id, paraPayload(d)) : clientesApi.criar(paraPayload(d))),
    onSuccess: () => {
      toast.success(cliente ? 'Cliente atualizado.' : 'Cliente criado.');
      if (!cliente) form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CLIENTES });
      onSalvar?.();
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        form.setError('root', { message: 'Não foi possível salvar. Tente novamente.' });
        return;
      }
      const campos = Object.entries(erro.errors);
      if (campos.length === 0) {
        form.setError('root', { message: erro.message });
        return;
      }
      for (const [campo, mensagens] of campos) {
        form.setError(campo as keyof ClienteFormDados, { message: mensagens[0] });
      }
    },
  });
  const buscarCep = useMutation({
    mutationFn: (cep: string) => consultarCep(cep),
    onSuccess: ({ data }) => {
      form.setValue('logradouro', data.logradouro);
      form.setValue('bairro', data.bairro);
      form.setValue('cidade', data.cidade);
      form.setValue('uf', data.uf);
    },
    onError: () => toast.error('CEP não encontrado. Preencha o endereço manualmente.'),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="cliente_tipo" label="Tipo" erro={erros.tipo?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('tipo')}>
            <option value="PF">Pessoa física</option>
            <option value="PJ">Pessoa jurídica</option>
          </SelectNativo>
        )}
      </Campo>
      <Campo id="cliente_nome" label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id="cliente_documento" label={tipo === 'PJ' ? 'CNPJ' : 'CPF'} dica="Pode ficar em branco por enquanto." erro={erros.cpf_cnpj?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cpf_cnpj')} />}
      </Campo>
      <Campo id="cliente_email" label="E-mail" erro={erros.email?.message}>
        {(a11y) => <Input {...a11y} type="email" {...form.register('email')} />}
      </Campo>
      <Campo id="cliente_telefone" label="Telefone" erro={erros.telefone?.message}>
        {(a11y) => <Input {...a11y} {...form.register('telefone')} />}
      </Campo>
      <div className="flex items-end gap-2">
        <div className="flex-1">
          <Campo id="cliente_cep" label="CEP" erro={erros.cep?.message}>
            {(a11y) => <Input {...a11y} {...form.register('cep')} />}
          </Campo>
        </div>
        <Button type="button" variant="outline" size="sm" disabled={buscarCep.isPending} onClick={() => buscarCep.mutate(form.getValues('cep'))}>
          Buscar
        </Button>
      </div>
      <Campo id="cliente_logradouro" label="Logradouro" erro={erros.logradouro?.message}>
        {(a11y) => <Input {...a11y} {...form.register('logradouro')} />}
      </Campo>
      <Campo id="cliente_numero" label="Número" erro={erros.numero?.message}>
        {(a11y) => <Input {...a11y} {...form.register('numero')} />}
      </Campo>
      <Campo id="cliente_bairro" label="Bairro" erro={erros.bairro?.message}>
        {(a11y) => <Input {...a11y} {...form.register('bairro')} />}
      </Campo>
      <Campo id="cliente_cidade" label="Cidade" erro={erros.cidade?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cidade')} />}
      </Campo>
      <Campo id="cliente_uf" label="UF" erro={erros.uf?.message}>
        {(a11y) => <Input {...a11y} maxLength={2} {...form.register('uf')} />}
      </Campo>
      <Campo id="cliente_origem" label="Origem" dica="Ex.: WhatsApp, indicação." erro={erros.origem?.message}>
        {(a11y) => <Input {...a11y} {...form.register('origem')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar cliente</Button>
    </form>
  );
}
```

- [ ] **Step 7: Criar `ContatoForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { contatosApi, QUERY_KEY_CLIENTES } from '../api';
import { contatoSchema, type ContatoFormDados } from '../schemas';

const VAZIO: ContatoFormDados = { nome: '', cargo: '', email: '', telefone: '' };

export function ContatoForm({ clienteId }: { clienteId: string }) {
  const queryClient = useQueryClient();
  const form = useForm<ContatoFormDados>({ resolver: zodResolver(contatoSchema), defaultValues: VAZIO });
  const criar = useMutation({
    mutationFn: (d: ContatoFormDados) => contatosApi.criar(clienteId, d),
    onSuccess: () => {
      toast.success('Contato adicionado.');
      form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CLIENTES });
    },
  });
  const envioUnico = useEnvioUnico();

  return (
    <form noValidate className="space-y-2" onSubmit={envioUnico(form.handleSubmit(async (d) => { await criar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="contato_nome" label="Nome do contato" erro={form.formState.errors.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id="contato_cargo" label="Cargo" erro={form.formState.errors.cargo?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cargo')} />}
      </Campo>
      <Button type="submit" size="sm" disabled={form.formState.isSubmitting}>Adicionar contato</Button>
    </form>
  );
}
```

- [ ] **Step 8: Criar `ListaDeClientes.tsx`**

```tsx
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { clientesApi, QUERY_KEY_CLIENTES } from '../api';
import { ClienteForm } from './ClienteForm';
import { ContatoForm } from './ContatoForm';

export function ListaDeClientes({ podeEscrever }: { podeEscrever: boolean }) {
  const queryClient = useQueryClient();
  const [editando, setEditando] = useState<string | null>(null);
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_CLIENTES, queryFn: async () => (await clientesApi.listar()).data });
  const converter = useMutation({
    mutationFn: (id: string) => clientesApi.converterEmCliente(id),
    onSuccess: () => {
      toast.success('Lead convertido em cliente.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CLIENTES });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar os clientes.</p>;

  return (
    <ul className="divide-y">
      {data.map((c) => (
        <li key={c.id} className="py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="min-w-0">
              <p className="font-medium">{c.nome} <span className="text-xs text-muted-foreground">({c.estagio === 'LEAD' ? 'lead' : 'cliente'})</span></p>
              <p className="truncate text-sm text-muted-foreground">{c.tipo} · {c.cpf_cnpj ?? 'sem documento'}</p>
            </div>
            <div className="flex gap-2">
              {podeEscrever && c.estagio === 'LEAD' ? (
                <Button variant="outline" size="sm" aria-label={`Converter ${c.nome} em cliente`} disabled={converter.isPending} onClick={() => converter.mutate(c.id)}>
                  Converter em cliente
                </Button>
              ) : null}
              {podeEscrever ? (
                <Button variant="outline" size="sm" aria-label={`Editar ${c.nome}`} onClick={() => setEditando(editando === c.id ? null : c.id)}>Editar</Button>
              ) : null}
            </div>
          </div>
          {editando === c.id ? (
            <div className="mt-3 space-y-3 border-t pt-3">
              <ClienteForm cliente={c} onSalvar={() => setEditando(null)} />
              {c.tipo === 'PJ' ? (
                <div>
                  <h3 className="mb-2 text-sm font-semibold text-muted-foreground">Contatos</h3>
                  <ul className="mb-2 space-y-1 text-sm">
                    {c.contatos?.map((ct) => <li key={ct.id}>{ct.nome}{ct.cargo ? ` · ${ct.cargo}` : ''}</li>)}
                  </ul>
                  <ContatoForm clienteId={c.id} />
                </div>
              ) : null}
            </div>
          ) : null}
        </li>
      ))}
    </ul>
  );
}
```

- [ ] **Step 9: Criar a página**

```tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ClienteForm } from '@/features/clientes/components/ClienteForm';
import { ListaDeClientes } from '@/features/clientes/components/ListaDeClientes';

const PAPEIS_QUE_ESCREVEM = ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR'];

export default function ClientesPage() {
  const { data: usuario } = useUsuario();
  const podeEscrever = Boolean(usuario && PAPEIS_QUE_ESCREVEM.includes(usuario.papel));

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Clientes</h1>
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader><CardTitle>Clientes e leads</CardTitle></CardHeader>
          <CardContent><ListaDeClientes podeEscrever={podeEscrever} /></CardContent>
        </Card>
        {podeEscrever ? (
          <Card>
            <CardHeader><CardTitle>Novo cliente</CardTitle></CardHeader>
            <CardContent><ClienteForm /></CardContent>
          </Card>
        ) : null}
      </div>
    </div>
  );
}
```

- [ ] **Step 10: Acrescentar o item de navegação**

Em `frontend/components/layout/nav-items.ts`, importar `Contact` de `lucide-react` e acrescentar:

```ts
  { href: '/clientes', label: 'Clientes', icon: Contact, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
```

- [ ] **Step 11: Rodar e confirmar que passa**

Run: `cd frontend && npx vitest run features/clientes && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 12: Commit**

```bash
git add frontend/features/clientes frontend/app/\(app\)/clientes frontend/components/layout/nav-items.ts
git commit -m "feat(frontend): tela de clientes, contatos e conversão de lead"
```

---

### Tarefa 26: Frontend — Wizard do emitente e Configurações fiscais

**Files:**
- Create: `frontend/features/emitente/types.ts`
- Create: `frontend/features/emitente/api.ts`
- Create: `frontend/features/emitente/schemas.ts`
- Create: `frontend/features/emitente/components/DadosDaEmpresaForm.tsx`
- Create: `frontend/features/emitente/components/DadosFiscaisForm.tsx`
- Create: `frontend/features/emitente/components/CertificadoForm.tsx`
- Create: `frontend/features/emitente/components/CscForm.tsx`
- Create: `frontend/features/emitente/components/SeriesForm.tsx`
- Create: `frontend/features/emitente/components/EmitenteWizard.tsx`
- Create: `frontend/features/emitente/components/Emitente.test.tsx`
- Create: `frontend/app/(app)/onboarding/page.tsx`
- Create: `frontend/app/(app)/configuracoes/fiscal/page.tsx`
- Modify: `frontend/components/layout/nav-items.ts`

**Interfaces:**
- Consumes: `GET /api/app/emitente`, `PUT .../empresa`, `PUT .../fiscal`, `POST .../certificado`, `PUT .../csc`, `PUT .../series/{modelo}`, `POST .../ambiente/producao` (Tarefas 15, 16, 17).
- Produces: páginas `/onboarding` (wizard) e `/configuracoes/fiscal` (os mesmos formulários, soltos).

**Antes de começar:** este arquivo usa upload de arquivo (`FormData`) — confirmar que a Tarefa 3 (`lib/api.ts` sem `Content-Type` forçado) já está integrada.

- [ ] **Step 1: Criar `types.ts`**

```ts
export type AmbienteFiscal = 'HOMOLOGACAO' | 'PRODUCAO';
export type CertificadoStatus = 'PENDENTE' | 'VALIDO' | 'VENCIDO' | 'INVALIDO';
export type ModeloDocumento = 'NFE' | 'NFCE' | 'DPS';

export interface EmitenteSerie {
  modelo: ModeloDocumento;
  serie: string;
  proximo_numero: number;
}

export interface Emitente {
  logradouro: string | null;
  numero: string | null;
  bairro: string | null;
  cidade: string | null;
  uf: string | null;
  cep: string | null;
  codigo_ibge: string | null;
  regime_tributario: string | null;
  inscricao_estadual: string | null;
  inscricao_municipal: string | null;
  cnae: string | null;
  ambiente_fiscal: AmbienteFiscal;
  certificado_status: CertificadoStatus;
  certificado_validade: string | null;
  certificado_titular: string | null;
  csc_homologacao_configurado: boolean;
  csc_producao_configurado: boolean;
  exige_csc: boolean;
  series: EmitenteSerie[];
}
```

- [ ] **Step 2: Criar `schemas.ts`**

```ts
import { z } from 'zod';

const vazioOuTexto = (max: number) => z.string().trim().max(max).optional().or(z.literal(''));

export const dadosDaEmpresaSchema = z.object({
  cep: vazioOuTexto(9),
  logradouro: vazioOuTexto(150),
  numero: vazioOuTexto(20),
  bairro: vazioOuTexto(100),
  cidade: vazioOuTexto(100),
  uf: vazioOuTexto(2),
  codigo_ibge: vazioOuTexto(7),
});
export type DadosDaEmpresaFormDados = z.infer<typeof dadosDaEmpresaSchema>;

export const dadosFiscaisSchema = z.object({
  regime_tributario: z.enum(['SIMPLES', 'MEI', 'NORMAL']).optional().or(z.literal('')),
  inscricao_estadual: vazioOuTexto(20),
  inscricao_municipal: vazioOuTexto(20),
  cnae: vazioOuTexto(10),
});
export type DadosFiscaisFormDados = z.infer<typeof dadosFiscaisSchema>;

export const certificadoSchema = z.object({ senha: z.string().min(1, 'Informe a senha do certificado.') });
export type CertificadoFormDados = z.infer<typeof certificadoSchema>;

export const cscSchema = z.object({
  csc_id_homologacao: vazioOuTexto(10),
  csc_token_homologacao: vazioOuTexto(100),
  csc_id_producao: vazioOuTexto(10),
  csc_token_producao: vazioOuTexto(100),
});
export type CscFormDados = z.infer<typeof cscSchema>;

export const serieSchema = z.object({
  serie: z.string().trim().min(1, 'Informe a série.').max(3),
  proximo_numero: z.coerce.number().int().min(1, 'O número inicial deve ser pelo menos 1.'),
});
export type SerieFormDados = z.infer<typeof serieSchema>;
```

- [ ] **Step 3: Criar `api.ts`**

```ts
import { api } from '@/lib/api';
import type { CscFormDados, DadosDaEmpresaFormDados, DadosFiscaisFormDados } from './schemas';
import type { Emitente, ModeloDocumento } from './types';

export const QUERY_KEY_EMITENTE = ['emitente'] as const;

export const emitenteApi = {
  buscar: () => api<{ data: Emitente }>('/api/app/emitente'),
  consultarCep: (cep: string) =>
    api<{ data: { logradouro: string; bairro: string; cidade: string; uf: string; codigo_ibge: string } }>(`/api/app/emitente/cep/${cep}`),
  atualizarEmpresa: (d: DadosDaEmpresaFormDados) =>
    api<{ data: Emitente }>('/api/app/emitente/empresa', {
      method: 'PUT',
      body: JSON.stringify({ cep: d.cep || null, logradouro: d.logradouro || null, numero: d.numero || null, bairro: d.bairro || null, cidade: d.cidade || null, uf: d.uf || null, codigo_ibge: d.codigo_ibge || null }),
    }),
  atualizarFiscal: (d: DadosFiscaisFormDados) =>
    api<{ data: Emitente }>('/api/app/emitente/fiscal', {
      method: 'PUT',
      body: JSON.stringify({ regime_tributario: d.regime_tributario || null, inscricao_estadual: d.inscricao_estadual || null, inscricao_municipal: d.inscricao_municipal || null, cnae: d.cnae || null }),
    }),
  uploadCertificado: (arquivo: File, senha: string) => {
    const corpo = new FormData();
    corpo.append('arquivo', arquivo);
    corpo.append('senha', senha);
    return api<{ data: Emitente }>('/api/app/emitente/certificado', { method: 'POST', body: corpo });
  },
  atualizarCsc: (d: CscFormDados) =>
    api<{ data: Emitente }>('/api/app/emitente/csc', {
      method: 'PUT',
      body: JSON.stringify({
        csc_id_homologacao: d.csc_id_homologacao || null, csc_token_homologacao: d.csc_token_homologacao || null,
        csc_id_producao: d.csc_id_producao || null, csc_token_producao: d.csc_token_producao || null,
      }),
    }),
  atualizarSerie: (modelo: ModeloDocumento, serie: string, proximoNumero: number) =>
    api<{ data: Emitente }>(`/api/app/emitente/series/${modelo}`, { method: 'PUT', body: JSON.stringify({ serie, proximo_numero: proximoNumero }) }),
  confirmarProducao: () => api<{ data: Emitente }>('/api/app/emitente/ambiente/producao', { method: 'POST', body: JSON.stringify({ confirmo: true }) }),
};
```

- [ ] **Step 4: Escrever o teste (deve falhar: nada existe ainda)**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { emitenteApi } from '../api';
import { CertificadoForm } from './CertificadoForm';
import { CscForm } from './CscForm';
import { DadosDaEmpresaForm } from './DadosDaEmpresaForm';
import { SeriesForm } from './SeriesForm';

vi.mock('../api', () => ({
  QUERY_KEY_EMITENTE: ['emitente'],
  emitenteApi: {
    buscar: vi.fn(), consultarCep: vi.fn(), atualizarEmpresa: vi.fn(), atualizarFiscal: vi.fn(),
    uploadCertificado: vi.fn(), atualizarCsc: vi.fn(), atualizarSerie: vi.fn(), confirmarProducao: vi.fn(),
  },
}));

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

const EMITENTE_VAZIO = {
  logradouro: null, numero: null, bairro: null, cidade: null, uf: null, cep: null, codigo_ibge: null,
  regime_tributario: null, inscricao_estadual: null, inscricao_municipal: null, cnae: null,
  ambiente_fiscal: 'HOMOLOGACAO' as const, certificado_status: 'PENDENTE' as const, certificado_validade: null, certificado_titular: null,
  csc_homologacao_configurado: false, csc_producao_configurado: false, exige_csc: false, series: [],
};

describe('emitente', () => {
  it('salva os dados da empresa', async () => {
    vi.mocked(emitenteApi.atualizarEmpresa).mockResolvedValue({ data: EMITENTE_VAZIO });
    renderizar(<DadosDaEmpresaForm emitente={EMITENTE_VAZIO} />);

    await userEvent.type(screen.getByLabelText('Logradouro'), 'Praça da Sé');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar endereço' }));

    await waitFor(() => expect(emitenteApi.atualizarEmpresa).toHaveBeenCalledWith(expect.objectContaining({ logradouro: 'Praça da Sé' })));
  });

  it('envia o certificado como FormData com arquivo e senha', async () => {
    vi.mocked(emitenteApi.uploadCertificado).mockResolvedValue({ data: { ...EMITENTE_VAZIO, certificado_status: 'VALIDO' } });
    renderizar(<CertificadoForm emitente={EMITENTE_VAZIO} />);

    const arquivo = new File(['conteudo'], 'certificado.pfx');
    await userEvent.upload(screen.getByLabelText('Arquivo do certificado (.pfx)'), arquivo);
    await userEvent.type(screen.getByLabelText('Senha do certificado'), 'minha-senha');
    await userEvent.click(screen.getByRole('button', { name: 'Enviar certificado' }));

    await waitFor(() => expect(emitenteApi.uploadCertificado).toHaveBeenCalledWith(arquivo, 'minha-senha'));
  });

  it('CSC não aparece como obrigatório quando o plano não tem NFC-e', () => {
    renderizar(<CscForm emitente={EMITENTE_VAZIO} />);

    expect(screen.queryByText(/obrigatório/i)).not.toBeInTheDocument();
  });

  it('CSC aparece como recomendado quando o plano tem NFC-e', () => {
    renderizar(<CscForm emitente={{ ...EMITENTE_VAZIO, exige_csc: true }} />);

    expect(screen.getByText(/seu plano inclui NFC-e/i)).toBeInTheDocument();
  });

  it('salva a série de NF-e', async () => {
    vi.mocked(emitenteApi.atualizarSerie).mockResolvedValue({ data: EMITENTE_VAZIO });
    renderizar(<SeriesForm emitente={EMITENTE_VAZIO} modelo="NFE" rotulo="NF-e" />);

    await userEvent.type(screen.getByLabelText('Série (NF-e)'), '1');
    await userEvent.type(screen.getByLabelText('Próximo número (NF-e)'), '1');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar série (NF-e)' }));

    await waitFor(() => expect(emitenteApi.atualizarSerie).toHaveBeenCalledWith('NFE', '1', 1));
  });
});
```

- [ ] **Step 5: Rodar e confirmar a falha**

Run: `cd frontend && npx vitest run features/emitente`
Expected: FAIL (módulos inexistentes).

- [ ] **Step 6: Criar `DadosDaEmpresaForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { dadosDaEmpresaSchema, type DadosDaEmpresaFormDados } from '../schemas';
import type { Emitente } from '../types';

export function DadosDaEmpresaForm({ emitente, onSalvar }: { emitente: Emitente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<DadosDaEmpresaFormDados>({
    resolver: zodResolver(dadosDaEmpresaSchema),
    defaultValues: {
      cep: emitente.cep ?? '', logradouro: emitente.logradouro ?? '', numero: emitente.numero ?? '',
      bairro: emitente.bairro ?? '', cidade: emitente.cidade ?? '', uf: emitente.uf ?? '', codigo_ibge: emitente.codigo_ibge ?? '',
    },
  });
  const buscarCep = useMutation({
    mutationFn: (cep: string) => emitenteApi.consultarCep(cep),
    onSuccess: ({ data }) => {
      form.setValue('logradouro', data.logradouro);
      form.setValue('bairro', data.bairro);
      form.setValue('cidade', data.cidade);
      form.setValue('uf', data.uf);
      form.setValue('codigo_ibge', data.codigo_ibge);
    },
    onError: () => toast.error('CEP não encontrado. Preencha o endereço manualmente.'),
  });
  const salvar = useMutation({
    mutationFn: (d: DadosDaEmpresaFormDados) => emitenteApi.atualizarEmpresa(d),
    onSuccess: () => {
      toast.success('Endereço salvo.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível salvar.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <div className="flex items-end gap-2">
        <div className="flex-1">
          <Campo id="empresa_cep" label="CEP" erro={erros.cep?.message}>
            {(a11y) => <Input {...a11y} {...form.register('cep')} />}
          </Campo>
        </div>
        <Button type="button" variant="outline" size="sm" disabled={buscarCep.isPending} onClick={() => buscarCep.mutate(form.getValues('cep'))}>
          Buscar
        </Button>
      </div>
      <Campo id="empresa_logradouro" label="Logradouro" erro={erros.logradouro?.message}>
        {(a11y) => <Input {...a11y} {...form.register('logradouro')} />}
      </Campo>
      <Campo id="empresa_numero" label="Número" erro={erros.numero?.message}>
        {(a11y) => <Input {...a11y} {...form.register('numero')} />}
      </Campo>
      <Campo id="empresa_bairro" label="Bairro" erro={erros.bairro?.message}>
        {(a11y) => <Input {...a11y} {...form.register('bairro')} />}
      </Campo>
      <Campo id="empresa_cidade" label="Cidade" erro={erros.cidade?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cidade')} />}
      </Campo>
      <Campo id="empresa_uf" label="UF" erro={erros.uf?.message}>
        {(a11y) => <Input {...a11y} maxLength={2} {...form.register('uf')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar endereço</Button>
    </form>
  );
}
```

- [ ] **Step 7: Criar `DadosFiscaisForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { dadosFiscaisSchema, type DadosFiscaisFormDados } from '../schemas';
import type { Emitente } from '../types';

export function DadosFiscaisForm({ emitente, onSalvar }: { emitente: Emitente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<DadosFiscaisFormDados>({
    resolver: zodResolver(dadosFiscaisSchema),
    defaultValues: {
      regime_tributario: (emitente.regime_tributario as DadosFiscaisFormDados['regime_tributario']) ?? '',
      inscricao_estadual: emitente.inscricao_estadual ?? '', inscricao_municipal: emitente.inscricao_municipal ?? '', cnae: emitente.cnae ?? '',
    },
  });
  const salvar = useMutation({
    mutationFn: (d: DadosFiscaisFormDados) => emitenteApi.atualizarFiscal(d),
    onSuccess: () => {
      toast.success('Dados fiscais salvos.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível salvar.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="fiscal_regime" label="Regime tributário" erro={erros.regime_tributario?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('regime_tributario')}>
            <option value="">Não informado</option>
            <option value="SIMPLES">Simples Nacional</option>
            <option value="MEI">MEI</option>
            <option value="NORMAL">Regime Normal</option>
          </SelectNativo>
        )}
      </Campo>
      <Campo id="fiscal_ie" label="Inscrição estadual" erro={erros.inscricao_estadual?.message}>
        {(a11y) => <Input {...a11y} {...form.register('inscricao_estadual')} />}
      </Campo>
      <Campo id="fiscal_im" label="Inscrição municipal" erro={erros.inscricao_municipal?.message}>
        {(a11y) => <Input {...a11y} {...form.register('inscricao_municipal')} />}
      </Campo>
      <Campo id="fiscal_cnae" label="CNAE" erro={erros.cnae?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cnae')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar dados fiscais</Button>
    </form>
  );
}
```

- [ ] **Step 8: Criar `CertificadoForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { certificadoSchema, type CertificadoFormDados } from '../schemas';
import type { Emitente } from '../types';

const ROTULO_STATUS: Record<Emitente['certificado_status'], string> = {
  PENDENTE: 'Nenhum certificado enviado ainda.',
  VALIDO: 'Certificado válido.',
  VENCIDO: 'Certificado vencido — envie um novo.',
  INVALIDO: 'Certificado inválido.',
};

export function CertificadoForm({ emitente, onSalvar }: { emitente: Emitente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const [arquivo, setArquivo] = useState<File | null>(null);
  const form = useForm<CertificadoFormDados>({ resolver: zodResolver(certificadoSchema), defaultValues: { senha: '' } });
  const enviar = useMutation({
    mutationFn: (d: CertificadoFormDados) => {
      if (!arquivo) throw new Error('Selecione o arquivo do certificado.');
      return emitenteApi.uploadCertificado(arquivo, d.senha);
    },
    onSuccess: () => {
      toast.success('Certificado enviado.');
      form.reset({ senha: '' });
      setArquivo(null);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível enviar o certificado.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await enviar.mutateAsync(d).catch(() => undefined); }))}>
      <p className="text-sm text-muted-foreground">{ROTULO_STATUS[emitente.certificado_status]}</p>
      <Campo id="certificado_arquivo" label="Arquivo do certificado (.pfx)">
        {(a11y) => <Input {...a11y} type="file" accept=".pfx,.p12" onChange={(e) => setArquivo(e.target.files?.[0] ?? null)} />}
      </Campo>
      <Campo id="certificado_senha" label="Senha do certificado" erro={erros.senha?.message}>
        {(a11y) => <Input {...a11y} type="password" {...form.register('senha')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting || !arquivo}>Enviar certificado</Button>
    </form>
  );
}
```

- [ ] **Step 9: Criar `CscForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { cscSchema, type CscFormDados } from '../schemas';
import type { Emitente } from '../types';

export function CscForm({ emitente, onSalvar }: { emitente: Emitente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<CscFormDados>({
    resolver: zodResolver(cscSchema),
    defaultValues: { csc_id_homologacao: '', csc_token_homologacao: '', csc_id_producao: '', csc_token_producao: '' },
  });
  const salvar = useMutation({
    mutationFn: (d: CscFormDados) => emitenteApi.atualizarCsc(d),
    onSuccess: () => {
      toast.success('CSC salvo.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível salvar.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      {emitente.exige_csc ? (
        <p className="text-sm text-amber-600">Seu plano inclui NFC-e: o CSC é necessário antes de emitir.</p>
      ) : (
        <p className="text-sm text-muted-foreground">Seu plano ainda não inclui NFC-e. Pode preencher mais tarde.</p>
      )}
      <Campo id="csc_id_homolog" label="ID do CSC (homologação)" erro={erros.csc_id_homologacao?.message}>
        {(a11y) => <Input {...a11y} {...form.register('csc_id_homologacao')} />}
      </Campo>
      <Campo id="csc_token_homolog" label="Token do CSC (homologação)" erro={erros.csc_token_homologacao?.message}>
        {(a11y) => <Input {...a11y} type="password" {...form.register('csc_token_homologacao')} />}
      </Campo>
      <Campo id="csc_id_prod" label="ID do CSC (produção)" erro={erros.csc_id_producao?.message}>
        {(a11y) => <Input {...a11y} {...form.register('csc_id_producao')} />}
      </Campo>
      <Campo id="csc_token_prod" label="Token do CSC (produção)" erro={erros.csc_token_producao?.message}>
        {(a11y) => <Input {...a11y} type="password" {...form.register('csc_token_producao')} />}
      </Campo>
      <p className="text-xs text-muted-foreground">
        {emitente.csc_homologacao_configurado ? 'Homologação já configurada. ' : ''}
        {emitente.csc_producao_configurado ? 'Produção já configurada.' : ''}
      </p>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar CSC</Button>
    </form>
  );
}
```

- [ ] **Step 10: Criar `SeriesForm.tsx`**

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { serieSchema, type SerieFormDados } from '../schemas';
import type { Emitente, ModeloDocumento } from '../types';

export function SeriesForm({ emitente, modelo, rotulo, onSalvar }: { emitente: Emitente; modelo: ModeloDocumento; rotulo: string; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const atual = emitente.series.find((s) => s.modelo === modelo);
  const form = useForm<SerieFormDados>({
    resolver: zodResolver(serieSchema),
    defaultValues: { serie: atual?.serie ?? '', proximo_numero: atual?.proximo_numero ?? 1 },
  });
  const salvar = useMutation({
    mutationFn: (d: SerieFormDados) => emitenteApi.atualizarSerie(modelo, d.serie, d.proximo_numero),
    onSuccess: () => {
      toast.success(`Série de ${rotulo} salva.`);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível salvar.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id={`serie_${modelo}`} label={`Série (${rotulo})`} erro={erros.serie?.message}>
        {(a11y) => <Input {...a11y} {...form.register('serie')} />}
      </Campo>
      <Campo id={`proximo_${modelo}`} label={`Próximo número (${rotulo})`} erro={erros.proximo_numero?.message}>
        {(a11y) => <Input {...a11y} type="number" min={1} {...form.register('proximo_numero')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>{`Salvar série (${rotulo})`}</Button>
    </form>
  );
}
```

- [ ] **Step 11: Rodar e confirmar que passa**

Run: `cd frontend && npx vitest run features/emitente`
Expected: PASS (5 testes).

- [ ] **Step 12: Criar `EmitenteWizard.tsx`**

```tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { CertificadoForm } from './CertificadoForm';
import { CscForm } from './CscForm';
import { DadosDaEmpresaForm } from './DadosDaEmpresaForm';
import { DadosFiscaisForm } from './DadosFiscaisForm';
import { SeriesForm } from './SeriesForm';

export function EmitenteWizard() {
  const router = useRouter();
  const { data: emitente, isPending } = useQuery({ queryKey: QUERY_KEY_EMITENTE, queryFn: async () => (await emitenteApi.buscar()).data });
  const [passo, setPasso] = useState(0);

  if (isPending || !emitente) return <p className="text-muted-foreground">Carregando...</p>;

  const passos = [
    { titulo: 'Dados da empresa', conteudo: <DadosDaEmpresaForm emitente={emitente} onSalvar={() => setPasso((p) => p + 1)} /> },
    { titulo: 'Dados fiscais', conteudo: <DadosFiscaisForm emitente={emitente} onSalvar={() => setPasso((p) => p + 1)} /> },
    { titulo: 'Certificado A1', conteudo: <CertificadoForm emitente={emitente} onSalvar={() => setPasso((p) => p + 1)} /> },
    ...(emitente.exige_csc ? [{ titulo: 'CSC', conteudo: <CscForm emitente={emitente} onSalvar={() => setPasso((p) => p + 1)} /> }] : []),
    { titulo: 'Séries', conteudo: <SeriesForm emitente={emitente} modelo="NFE" rotulo="NF-e" onSalvar={() => router.push('/dashboard')} /> },
  ];
  const atual = passos[passo] ?? passos[passos.length - 1];

  return (
    <div className="mx-auto max-w-md space-y-4">
      <p className="text-sm text-muted-foreground">Passo {passo + 1} de {passos.length}</p>
      <h1 className="text-xl font-semibold">{atual.titulo}</h1>
      {atual.conteudo}
      <button type="button" className="text-sm text-muted-foreground underline" onClick={() => router.push('/dashboard')}>
        Continuar depois (fica em Configurações)
      </button>
    </div>
  );
}
```

- [ ] **Step 13: Criar as páginas**

```tsx
'use client';

import { EmitenteWizard } from '@/features/emitente/components/EmitenteWizard';

export default function OnboardingPage() {
  return <EmitenteWizard />;
}
```

```tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CertificadoForm } from '@/features/emitente/components/CertificadoForm';
import { CscForm } from '@/features/emitente/components/CscForm';
import { DadosDaEmpresaForm } from '@/features/emitente/components/DadosDaEmpresaForm';
import { DadosFiscaisForm } from '@/features/emitente/components/DadosFiscaisForm';
import { SeriesForm } from '@/features/emitente/components/SeriesForm';
import { emitenteApi, QUERY_KEY_EMITENTE } from '@/features/emitente/api';

export default function ConfiguracoesFiscalPage() {
  const { data: emitente, isPending, isError } = useQuery({ queryKey: QUERY_KEY_EMITENTE, queryFn: async () => (await emitenteApi.buscar()).data });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError || !emitente) return <p role="alert">Não foi possível carregar os dados fiscais.</p>;

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Configurações fiscais</h1>
      <div className="grid gap-4 md:grid-cols-2">
        <Card><CardHeader><CardTitle>Empresa</CardTitle></CardHeader><CardContent><DadosDaEmpresaForm emitente={emitente} /></CardContent></Card>
        <Card><CardHeader><CardTitle>Dados fiscais</CardTitle></CardHeader><CardContent><DadosFiscaisForm emitente={emitente} /></CardContent></Card>
        <Card><CardHeader><CardTitle>Certificado A1</CardTitle></CardHeader><CardContent><CertificadoForm emitente={emitente} /></CardContent></Card>
        <Card><CardHeader><CardTitle>CSC (NFC-e)</CardTitle></CardHeader><CardContent><CscForm emitente={emitente} /></CardContent></Card>
        <Card><CardHeader><CardTitle>Série NF-e</CardTitle></CardHeader><CardContent><SeriesForm emitente={emitente} modelo="NFE" rotulo="NF-e" /></CardContent></Card>
        <Card><CardHeader><CardTitle>Série NFC-e</CardTitle></CardHeader><CardContent><SeriesForm emitente={emitente} modelo="NFCE" rotulo="NFC-e" /></CardContent></Card>
        <Card><CardHeader><CardTitle>Série NFS-e (DPS)</CardTitle></CardHeader><CardContent><SeriesForm emitente={emitente} modelo="DPS" rotulo="NFS-e" /></CardContent></Card>
      </div>
    </div>
  );
}
```

- [ ] **Step 14: Acrescentar o item de navegação**

Em `frontend/components/layout/nav-items.ts`, importar `Landmark` de `lucide-react` e acrescentar em `NAV_ITEMS`:

```ts
  { href: '/configuracoes/fiscal', label: 'Fiscal', icon: Landmark, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL'] },
```

- [ ] **Step 15: Rodar e confirmar que passa**

Run: `cd frontend && npx vitest run features/emitente && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 16: Commit**

```bash
git add frontend/features/emitente frontend/app/\(app\)/onboarding frontend/app/\(app\)/configuracoes/fiscal frontend/components/layout/nav-items.ts
git commit -m "feat(frontend): wizard de onboarding do emitente e Configurações fiscais"
```

---

### Tarefa 27: Frontend — Importação CSV (produtos, serviços, clientes)

**Files:**
- Create: `frontend/components/form/ImportarCsvForm.tsx`
- Create: `frontend/components/form/ImportarCsvForm.test.tsx`
- Modify: `frontend/app/(app)/produtos/page.tsx`
- Modify: `frontend/app/(app)/servicos/page.tsx`
- Modify: `frontend/app/(app)/clientes/page.tsx`

**Interfaces:**
- Consumes: `POST /api/app/{clientes,produtos,servicos}/importar` (Tarefa 20).
- Produces: `<ImportarCsvForm endpoint queryKey />` — componente genérico, sem acoplamento a um recurso específico.

- [ ] **Step 1: Escrever o teste (deve falhar: componente não existe)**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { api } from '@/lib/api';
import { ImportarCsvForm } from './ImportarCsvForm';

vi.mock('@/lib/api', async () => {
  const real = await vi.importActual<typeof import('@/lib/api')>('@/lib/api');
  return { ...real, api: vi.fn() };
});

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('ImportarCsvForm', () => {
  it('envia o arquivo e mostra os erros por linha', async () => {
    vi.mocked(api).mockResolvedValue({ data: { criados: 1, atualizados: 0, erros: [{ linha: 3, motivo: 'SKU duplicado.' }] } });
    renderizar(<ImportarCsvForm endpoint="/api/app/produtos/importar" queryKey={['produtos']} />);

    const arquivo = new File(['sku,nome\nA,B'], 'produtos.csv', { type: 'text/csv' });
    await userEvent.upload(screen.getByLabelText('Arquivo CSV'), arquivo);
    await userEvent.click(screen.getByRole('button', { name: 'Importar CSV' }));

    await waitFor(() => expect(api).toHaveBeenCalledWith('/api/app/produtos/importar', expect.objectContaining({ method: 'POST' })));
    expect(await screen.findByText('Linha 3: SKU duplicado.')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Rodar e confirmar a falha**

Run: `cd frontend && npx vitest run components/form/ImportarCsvForm.test.tsx`
Expected: FAIL (módulo inexistente).

- [ ] **Step 3: Criar o componente**

```tsx
'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { api, ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';

interface ResultadoImportacao {
  criados: number;
  atualizados: number;
  erros: { linha: number; motivo: string }[];
}

/** Genérico: funciona para clientes, produtos e serviços — só muda o endpoint. */
export function ImportarCsvForm({ endpoint, queryKey }: { endpoint: string; queryKey: readonly unknown[] }) {
  const queryClient = useQueryClient();
  const [arquivo, setArquivo] = useState<File | null>(null);
  const [resultado, setResultado] = useState<ResultadoImportacao | null>(null);
  const importar = useMutation({
    mutationFn: async () => {
      if (!arquivo) throw new Error('Selecione um arquivo CSV.');
      const corpo = new FormData();
      corpo.append('arquivo', arquivo);
      return api<{ data: ResultadoImportacao }>(endpoint, { method: 'POST', body: corpo });
    },
    onSuccess: ({ data }) => {
      setResultado(data);
      toast.success(`${data.criados} criado(s), ${data.atualizados} atualizado(s).`);
      void queryClient.invalidateQueries({ queryKey });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Não foi possível importar o arquivo.'),
  });
  const envioUnico = useEnvioUnico();

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(async () => { await importar.mutateAsync().catch(() => undefined); })}>
      <Input type="file" accept=".csv" aria-label="Arquivo CSV" onChange={(e) => setArquivo(e.target.files?.[0] ?? null)} />
      <Button type="submit" size="sm" disabled={importar.isPending || !arquivo}>Importar CSV</Button>
      {resultado && resultado.erros.length > 0 ? (
        <ul className="space-y-1 text-sm text-danger">
          {resultado.erros.map((e) => <li key={e.linha}>Linha {e.linha}: {e.motivo}</li>)}
        </ul>
      ) : null}
    </form>
  );
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `cd frontend && npx vitest run components/form/ImportarCsvForm.test.tsx`
Expected: PASS.

- [ ] **Step 5: Acrescentar o card de importação nas três páginas**

Em `frontend/app/(app)/produtos/page.tsx`, importar `ImportarCsvForm` e `QUERY_KEY_PRODUTOS`, e acrescentar um `<Card>` com `<ImportarCsvForm endpoint="/api/app/produtos/importar" queryKey={QUERY_KEY_PRODUTOS} />` ao lado dos já existentes.

Em `frontend/app/(app)/servicos/page.tsx`, o mesmo com `endpoint="/api/app/servicos/importar"` e `QUERY_KEY_SERVICOS`.

Em `frontend/app/(app)/clientes/page.tsx`, o mesmo com `endpoint="/api/app/clientes/importar"` e `QUERY_KEY_CLIENTES`, só quando `podeEscrever`.

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `cd frontend && npx vitest run && npx tsc --noEmit`
Expected: PASS em toda a suíte do frontend.

- [ ] **Step 7: Commit**

```bash
git add frontend/components/form/ImportarCsvForm.tsx frontend/components/form/ImportarCsvForm.test.tsx frontend/app/\(app\)/produtos/page.tsx frontend/app/\(app\)/servicos/page.tsx frontend/app/\(app\)/clientes/page.tsx
git commit -m "feat(frontend): importação CSV de clientes, produtos e serviços"
```

---

### Tarefa 28: Revisão final e fumaça ponta a ponta

**Files:** nenhum arquivo novo — esta tarefa só verifica o que as Tarefas 1–27 construíram.

**Interfaces:** nenhuma — é a tarefa de fechamento da fase, no mesmo padrão da revisão final da F1 (ver `PROGRESSO.md`).

- [ ] **Step 1: Suítes automatizadas**

Run:
```bash
cd backend && php artisan test
cd backend && vendor/bin/pint --test
cd backend && composer analyse
cd frontend && npm run test
cd frontend && npm run typecheck
cd frontend && npm run lint
cd frontend && npm run build
```
Expected: tudo verde. Qualquer falha é corrigida antes de prosseguir (não commitar com suíte vermelha).

- [ ] **Step 2: Conferir a cobertura das regras de ouro**

Grep por violações da regra "nunca `empty()` ou `?? 0` em campo fiscal":

Run: `grep -rn "empty(\$.*origem\|?? 0" backend/app/Modules/Catalog backend/app/Modules/Fiscal`
Expected: nenhum resultado. Se aparecer algo, é a Tarefa 5, 6 ou 16 com uma regressão — corrigir antes de prosseguir.

- [ ] **Step 3: Fumaça ponta a ponta com curl**

Seguindo o mesmo padrão da F1 (banco SQLite descartável, host `localhost`, login em `/api/app/auth/login`):

1. Subir a API localmente (`php artisan serve`) com um banco SQLite limpo e migrado.
2. Cadastrar uma empresa de teste (reaproveitando o fluxo de cadastro da F1) e fazer login.
3. `GET /api/app/emitente` → confere que nasce com `ambiente_fiscal: HOMOLOGACAO` e `certificado_status: PENDENTE`.
4. `PUT /api/app/emitente/empresa` e `PUT /api/app/emitente/fiscal` com dados de teste → `200`.
5. `POST /api/app/emitente/certificado` com um `.pfx` de teste (gerado com `openssl` na linha de comando, por exemplo `openssl req -x509 -newkey rsa:2048 -keyout k.pem -out c.pem -days 365 -nodes -subj "/CN=Empresa Teste"` seguido de `openssl pkcs12 -export -out teste.pfx -inkey k.pem -in c.pem -passout pass:teste123`) → `200` com `certificado_status: VALIDO`.
6. `PUT /api/app/emitente/series/NFE` → `200`.
7. `POST /api/app/clientes` (lead só com nome) → `201`.
8. `POST /api/app/clientes/{id}/converter-em-cliente` → `200` com `estagio: CLIENTE`.
9. `POST /api/app/produtos` com `origem: 0` → `201`, e `GET /api/app/produtos/pendencias-fiscais` confere que **não** aparece (campo `0` não é pendência).
10. `POST /api/app/servicos` → `201`.
11. `POST /api/app/clientes/importar` com um CSV de 2 linhas (1 válida, 1 inválida) → `200` com `criados: 1` e 1 item em `erros`.
12. Repetir os passos 7, 9 e 10 pelo frontend (`npm run dev`), navegando em `/clientes`, `/produtos`, `/servicos`, `/pendencias-fiscais` e `/onboarding`, nos temas claro e escuro e em largura de celular — conferir visualmente que os dados aparecem e os formulários validam.

Expected: todo o roteiro passa sem erro 500 e sem dado fiscal "chutado" (todo campo ausente aparece como pendência, nunca como zero ou vazio silencioso).

- [ ] **Step 4: Atualizar `PROGRESSO.md` e `TAREFAS.md`**

Marcar a F2 como concluída em `PROGRESSO.md` (seguindo o mesmo formato da F1: o que foi feito, decisões não óbvias, pendências encontradas na revisão) e mover a próxima tarefa para a F3 (motores NFePHP, NF-e e NFC-e). Reescrever a seção "Contexto necessário" só com o que a F3 precisa.

- [ ] **Step 5: Commit**

```bash
git add PROGRESSO.md TAREFAS.md
git commit -m "docs: F2 concluída, fechamento com suítes verdes e fumaça ponta a ponta"
```

