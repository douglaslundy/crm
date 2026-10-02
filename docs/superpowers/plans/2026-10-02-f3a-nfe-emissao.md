# F3a: emissão de NF-e (modelo 55) com NFePHP: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** um tenant cria um rascunho de NF-e, emite em homologação pela fila (NFePHP direto na SEFAZ), acompanha o status por polling e baixa o XML autorizado; a passagem para produção exige uma NF-e autorizada em homologação.

**Architecture:** módulo `Fiscal` ganha `Nota`, `NotaItem`, `NotaPagamento` e `NotaEvento`; resolvers puros (CFOP, CSOSN, indIEDest, cUF); Actions (`CriarRascunhoDeNota`, `IniciarEmissao`, `AplicarResultadoDaEmissao`, `ConciliarNota`); um `EmitirNotaJob` na fila `database`; a interface `EmissorDeNfe` com a implementação `NfePhpEmissor` (e um `EmissorFake` para testes); reconciliação agendada a cada 15 minutos. O frontend ganha `features/notas` com lista, formulário, detalhe com polling e a confirmação de produção.

**Tech Stack:** Laravel 12 / PHP 8.2 (backend), `nfephp-org/sped-nfe ^5.2` (schema `PL_009_V4`, versão 4.00), fila `database`, Next 16 + React + TanStack Query 5 + zod 4 + react-hook-form + vitest.

**Spec:** `docs/superpowers/specs/2026-10-01-f3a-nfe-emissao-design.md` (mestre: `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md` §7).

**Refinamento em relação ao spec (decidido ao planejar):** o contrato do emissor é dividido em `preparar` (monta e assina o XML, devolve a chave) e `transmitir` (envia e interpreta). Assim a `chave` e o `xml_enviado` são gravados **antes** de qualquer chamada de rede, e a reconciliação sempre consegue consultar a SEFAZ por chave. A Tarefa 8 atualiza o spec.

## Global Constraints

- `declare(strict_types=1)` em todo PHP. TypeScript strict, sem `any`.
- **Nunca chutar valor fiscal.** Dado ausente bloqueia com mensagem acionável (`EmissaoBloqueadaException`, código `FISCAL_*`). Zero é válido: usar `=== null`, nunca `empty()` nem `?? 0`.
- Regra tributária fica fora do emissor; o `NfePhpEmissor` só traduz formato.
- Frontend e API pública chamam as mesmas Actions; nenhuma regra no controller.
- Todo Model de tenant usa `BelongsToTenant` (Global Scope por `tenant_id`) e ganha teste de isolamento; todo endpoint novo ganha teste de isolamento.
- Schema só por migration. Segredos sempre criptografados e nunca em log. Nenhum dado real em fixtures.
- Dinheiro em centavos inteiros (`int`) até a montagem do XML. Quantidade em milésimos inteiros (`quantidade_milesimos`).
- Erros de negócio estendem `ErroDeNegocio` (`{message, codigo}`).
- Escopo: só NF-e (modelo 55), Simples Nacional (CRT 1), venda de mercadoria (CFOP 5102, 5405, 6102, 6404; CSOSN 102 e 500). NFC-e, cancelamento, inutilização, PDF, contingência e dashboard ficam para F3b e F3c.
- Papéis: PROPRIETARIO, ADMIN e FISCAL emitem; VENDEDOR cria, edita e exclui rascunho; LEITURA só consulta.
- Windows: arquivos voltam com CRLF; use a ferramenta Write/Edit (heredocs longos no Bash falham). Comandos PHP rodam em `backend/`, os do frontend em `frontend/`.

## Review Focus

Entradas que o spec implica e nenhuma tarefa de comportamento principal exercita; cada uma tem um teste na tarefa dona.

1. **Desconto igual ao subtotal (nota de R$ 0,00)** ou maior que o subtotal: bloqueia com `FISCAL_DESCONTO_INVALIDO` (Tarefa 4 e 5). Esperado: mensagem clara, nunca XML com total negativo.
2. **Emissão duplicada** (duplo clique, fila reentrega, `POST /emitir` com a nota já `PROCESSANDO`): não consome número novo nem despacha segundo job (Tarefa 9).
3. **Cliente editado depois do rascunho** (UF ou IE mudou): a emissão usa o dado atual e congela o snapshot; o retry reusa o snapshot (Tarefa 9).
4. **Dado com acento e caracteres especiais** (`&`, `<`, aspas, emojis) em nome, descrição e `infCpl`: o XML continua válido (Tarefa 12).
5. **Worker caiu no meio da emissão** (`PROCESSANDO` sem resposta): a reconciliação não perde nem duplica a nota (Tarefa 11).

---

## Estrutura de arquivos

Backend (`backend/`), tudo em `app/Modules/Fiscal/` salvo indicação:

| Arquivo | Responsabilidade |
|---|---|
| `database/migrations/2026_10_02_000001_create_notas_tables.php` | `notas`, `nota_itens`, `nota_pagamentos`, `nota_eventos` |
| `Domain/Enums/{StatusNota,FormaPagamento,SituacaoNaSefaz}.php` | enums |
| `Domain/Models/{Nota,NotaItem,NotaPagamento,NotaEvento}.php` | Models com `BelongsToTenant` |
| `Domain/Exceptions/{EmissaoBloqueadaException,NotaNaoEditavelException}.php` | erros de negócio |
| `Domain/{TabelaIbge,Quantidade,RateioDeDesconto}.php` | helpers puros |
| `Domain/Resolvers/{CrtResolver,CfopSaidaResolver,TributacaoIcmsSaidaResolver,IndicadorIeDestinatarioResolver}.php` | regras tributárias puras |
| `Domain/Emissao/{NotaParaEmissao,EmitenteParaEmissao,DestinatarioParaEmissao,ItemParaEmissao,PagamentoParaEmissao,XmlAssinado,ResultadoDeEmissao,ResultadoDeConsulta}.php` | DTOs |
| `Domain/Contracts/EmissorDeNfe.php` | contrato do motor |
| `Application/Emissao/{VerificadorDoEmitente,PreparadorDaNota}.php` | bloqueios e snapshot fiscal |
| `Application/Actions/{CriarRascunhoDeNota,AtualizarRascunhoDeNota,ExcluirRascunhoDeNota,IniciarEmissao,AplicarResultadoDaEmissao,ConciliarNota}.php` | casos de uso |
| `Application/{ContadorDeDocumentosMes,PoliticaDeNotas}.php` | limite do plano e papéis |
| `Application/Jobs/EmitirNotaJob.php` | fila |
| `Console/ReconciliarNotasCommand.php` | `fiscal:reconciliar-processando` |
| `Infrastructure/NfePhp/{CertificadoStore,MontadorDeXml,InterpretadorDeResposta,NfePhpEmissor}.php` | motor real |
| `Http/{Controllers/NotaController,Requests/*,Resources/NotaResource}.php`, `routes-app.php` | API |
| `tests/Fixtures/{EmissorFake,CenarioDeNota}.php` | apoio de teste |

Frontend (`frontend/`): `features/notas/{types,schemas,api}.ts`, `features/notas/components/{StatusDaNota,ListaDeNotas,NotaForm,DetalheDaNota,Notas.test}.tsx`, `app/(app)/notas/{page,nova/page,[id]/page}.tsx`, `features/emitente/components/ConfirmarProducao.tsx`, `components/layout/nav-items.ts`.

---

### Task 1: Dependência `sped-nfe`, fila `database` e configuração

**Files:**
- Modify: `backend/composer.json` (via `composer require`), `backend/.env.example`
- Create: `backend/config/fiscal.php`
- Test: `backend/tests/Unit/Fiscal/ConfiguracaoFiscalTest.php`

**Interfaces:**
- Produces: `config('fiscal.ver_proc')` (string), `config('fiscal.minutos_para_nunca_enviada')` (int, 10), `config('fiscal.tentativas_numero_ocupado')` (int, 5).

- [ ] **Step 1: Instalar a biblioteca**

Run (em `backend/`): `composer require nfephp-org/sped-nfe:^5.2`
Expected: instala `nfephp-org/sped-nfe` 5.x e dependências (`sped-common`, etc.) sem erro. Se faltar extensão PHP, o composer diz qual (`soap`, `intl`, `openssl`, `dom`, `curl` e `zlib` já estão ativas neste ambiente).

- [ ] **Step 2: Anotar a versão instalada**

Run: `composer show nfephp-org/sped-nfe | head -5`
Anote a versão exata no `PROGRESSO.md` (as assinaturas de `tag*()` mudam entre versões; as Tarefas 12 e 13 conferem contra o vendor).

- [ ] **Step 3: Escrever o teste da configuração**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use Tests\TestCase;

class ConfiguracaoFiscalTest extends TestCase
{
    public function test_valores_padrao_da_configuracao_fiscal(): void
    {
        $this->assertSame(10, config('fiscal.minutos_para_nunca_enviada'));
        $this->assertSame(5, config('fiscal.tentativas_numero_ocupado'));
        $this->assertNotSame('', config('fiscal.ver_proc'));
    }
}
```

- [ ] **Step 4: Rodar e ver falhar**

Run: `php artisan test --filter=ConfiguracaoFiscalTest`
Expected: FAIL (valores nulos).

- [ ] **Step 5: Criar `config/fiscal.php`**

```php
<?php

declare(strict_types=1);

return [
    /** Versão do aplicativo emissor enviada em `verProc` (máx. 20 caracteres). */
    'ver_proc' => env('FISCAL_VER_PROC', 'plataforma-1.0'),

    /** Nota PROCESSANDO sem resposta há mais que isso vai para ERRO na reconciliação. */
    'minutos_para_nunca_enviada' => 10,

    /** Quantas vezes pular número ocupado por nota cancelada (cStat 539). */
    'tentativas_numero_ocupado' => 5,
];
```

- [ ] **Step 6: Fila `database` fora dos testes**

Em `.env.example`, trocar `QUEUE_CONNECTION=sync` por `QUEUE_CONNECTION=database`. O `phpunit.xml` continua `sync` (os testes executam o job inline). Se existir `.env` local, trocar também. Conferir que a tabela `jobs` já existe (`0001_01_01_000002_create_jobs_table.php`).

- [ ] **Step 7: Rodar e ver passar**

Run: `php artisan test --filter=ConfiguracaoFiscalTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/.env.example backend/config/fiscal.php backend/tests/Unit/Fiscal/ConfiguracaoFiscalTest.php
git commit -m "chore(fiscal): sped-nfe, fila database e config fiscal"
```

---

### Task 2: Migration, enums, models e factories da nota

**Files:**
- Create: `backend/database/migrations/2026_10_02_000001_create_notas_tables.php`
- Create: `app/Modules/Fiscal/Domain/Enums/{StatusNota,FormaPagamento,SituacaoNaSefaz}.php`
- Create: `app/Modules/Fiscal/Domain/Models/{Nota,NotaItem,NotaPagamento,NotaEvento}.php`
- Create: `database/factories/{NotaFactory,NotaItemFactory}.php`
- Test: `backend/tests/Feature/Fiscal/NotaIsolamentoTest.php`

**Interfaces:**
- Produces:
  - `StatusNota` (`Rascunho|Processando|Autorizada|Rejeitada|Erro|Cancelada`) com `editavel(): bool` (Rascunho, Rejeitada, Erro).
  - `FormaPagamento` (`Dinheiro='01'|Credito='03'|Debito='04'|Pix='17'|Outros='99'`).
  - `SituacaoNaSefaz` (`Autorizada|Cancelada|NaoEncontrada|Erro`).
  - `Nota` com `itens(): HasMany`, `pagamentos(): HasMany`, `eventos(): HasMany`, `cliente(): BelongsTo`; `NotaItem`, `NotaPagamento`, `NotaEvento` (`registrar(Nota, ?StatusNota $de, StatusNota $para, ?string $detalhe, ?string $usuarioId): self`).

- [ ] **Step 1: Escrever o teste de isolamento**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\NotaPagamento;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotaIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_nota_e_isolada_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Nota::factory()->create());
    }

    public function test_item_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => NotaItem::factory()->create());
    }

    public function test_pagamento_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => NotaPagamento::query()->create([
            'nota_id' => Nota::factory()->create()->id, 'tpag' => '01', 'valor_centavos' => 100,
        ]));
    }

    public function test_evento_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => NotaEvento::query()->create([
            'nota_id' => Nota::factory()->create()->id, 'status_para' => 'RASCUNHO',
        ]));
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

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=NotaIsolamentoTest`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Migration**

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
        Schema::create('notas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('emitente_id')->constrained('emitentes');
            $table->foreignUuid('cliente_id')->constrained('clientes');
            $table->string('status', 12)->default('RASCUNHO');
            $table->string('ambiente', 12)->nullable();
            $table->string('natureza_operacao', 60)->default('Venda de mercadoria');
            $table->boolean('consumidor_final')->nullable();
            $table->bigInteger('subtotal_centavos')->default(0);
            $table->bigInteger('desconto_centavos')->default(0);
            $table->bigInteger('total_centavos')->default(0);
            $table->text('informacoes_complementares')->nullable();
            $table->json('destinatario')->nullable();
            $table->string('serie', 3)->nullable();
            $table->unsignedBigInteger('numero')->nullable();
            $table->unsignedSmallInteger('tentativas_numero')->default(0);
            $table->string('chave', 44)->nullable();
            $table->string('protocolo', 20)->nullable();
            $table->string('cstat', 4)->nullable();
            $table->text('motivo')->nullable();
            $table->text('mensagem_erro')->nullable();
            $table->longText('xml_enviado')->nullable();
            $table->longText('xml_autorizado')->nullable();
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('emitido_em')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            $table->unique(['tenant_id', 'ambiente', 'serie', 'numero']);
            $table->index('chave');
        });

        Schema::create('nota_itens', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('nota_id')->constrained('notas')->cascadeOnDelete();
            $table->foreignUuid('produto_id')->constrained('produtos');
            $table->unsignedSmallInteger('ordem');
            $table->unsignedBigInteger('quantidade_milesimos');
            $table->bigInteger('valor_unitario_centavos');
            $table->bigInteger('desconto_centavos')->default(0);
            $table->bigInteger('total_centavos')->default(0);
            $table->string('sku', 60)->nullable();
            $table->string('descricao', 120)->nullable();
            $table->string('unidade', 6)->nullable();
            $table->string('gtin', 14)->nullable();
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 6)->nullable();
            $table->string('csosn', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('nota_pagamentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('nota_id')->constrained('notas')->cascadeOnDelete();
            $table->string('tpag', 2);
            $table->string('xpag', 60)->nullable();
            $table->bigInteger('valor_centavos');
            $table->timestamps();
        });

        Schema::create('nota_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('nota_id')->constrained('notas')->cascadeOnDelete();
            $table->string('status_de', 12)->nullable();
            $table->string('status_para', 12);
            $table->text('detalhe')->nullable();
            $table->foreignUuid('usuario_id')->nullable()->constrained('usuarios');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_eventos');
        Schema::dropIfExists('nota_pagamentos');
        Schema::dropIfExists('nota_itens');
        Schema::dropIfExists('notas');
    }
};
```

- [ ] **Step 4: Enums**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum StatusNota: string
{
    case Rascunho = 'RASCUNHO';
    case Processando = 'PROCESSANDO';
    case Autorizada = 'AUTORIZADA';
    case Rejeitada = 'REJEITADA';
    case Erro = 'ERRO';
    case Cancelada = 'CANCELADA';

    /** Rascunho, rejeitada e erro voltam a ser editáveis e podem ser reemitidas. */
    public function editavel(): bool
    {
        return in_array($this, [self::Rascunho, self::Rejeitada, self::Erro], true);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

/** Valor = código `tPag` da NF-e. */
enum FormaPagamento: string
{
    case Dinheiro = '01';
    case Credito = '03';
    case Debito = '04';
    case Pix = '17';
    case Outros = '99';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum SituacaoNaSefaz: string
{
    case Autorizada = 'AUTORIZADA';
    case Cancelada = 'CANCELADA';
    case NaoEncontrada = 'NAO_ENCONTRADA';
    case Erro = 'ERRO';
}
```

- [ ] **Step 5: Models**

`Nota.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\NotaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $emitente_id
 * @property string $cliente_id
 * @property StatusNota $status
 * @property ?string $ambiente
 * @property string $natureza_operacao
 * @property ?bool $consumidor_final
 * @property int $subtotal_centavos
 * @property int $desconto_centavos
 * @property int $total_centavos
 * @property ?string $informacoes_complementares
 * @property ?array<string, mixed> $destinatario
 * @property ?string $serie
 * @property ?int $numero
 * @property int $tentativas_numero
 * @property ?string $chave
 * @property ?string $protocolo
 * @property ?string $cstat
 * @property ?string $motivo
 * @property ?string $mensagem_erro
 * @property ?string $xml_enviado
 * @property ?string $xml_autorizado
 * @property ?CarbonInterface $iniciada_em
 * @property ?CarbonInterface $emitido_em
 * @property CarbonInterface $created_at
 */
class Nota extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NotaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'notas';

    /** Campos de retorno da SEFAZ só mudam pelas Actions (forceFill). */
    protected $fillable = [
        'emitente_id', 'cliente_id', 'consumidor_final', 'desconto_centavos', 'informacoes_complementares',
        'subtotal_centavos', 'total_centavos',
    ];

    protected $hidden = ['xml_enviado', 'xml_autorizado'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'RASCUNHO', 'natureza_operacao' => 'Venda de mercadoria'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatusNota::class,
            'consumidor_final' => 'boolean',
            'destinatario' => 'array',
            'iniciada_em' => 'datetime',
            'emitido_em' => 'datetime',
        ];
    }

    /** @return HasMany<NotaItem, $this> */
    public function itens(): HasMany
    {
        return $this->hasMany(NotaItem::class)->orderBy('ordem');
    }

    /** @return HasMany<NotaPagamento, $this> */
    public function pagamentos(): HasMany
    {
        return $this->hasMany(NotaPagamento::class);
    }

    /** @return HasMany<NotaEvento, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(NotaEvento::class)->orderBy('id');
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    protected static function newFactory(): NotaFactory
    {
        return NotaFactory::new();
    }
}
```

`NotaItem.php` (mesmo padrão; `@property` dos campos; `protected $fillable = ['nota_id','produto_id','ordem','quantidade_milesimos','valor_unitario_centavos','desconto_centavos','total_centavos','sku','descricao','unidade','gtin','ncm','cest','cfop','origem','tributacao_icms','csosn'];` com `protected $table = 'nota_itens';` e `belongsTo(Nota)`):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\NotaItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $nota_id
 * @property string $produto_id
 * @property int $ordem
 * @property int $quantidade_milesimos
 * @property int $valor_unitario_centavos
 * @property int $desconto_centavos
 * @property int $total_centavos
 * @property ?string $sku
 * @property ?string $descricao
 * @property ?string $unidade
 * @property ?string $gtin
 * @property ?string $ncm
 * @property ?string $cest
 * @property ?string $cfop
 * @property ?int $origem
 * @property ?string $tributacao_icms
 * @property ?string $csosn
 */
class NotaItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NotaItemFactory> */
    use HasFactory;

    protected $table = 'nota_itens';

    protected $fillable = [
        'nota_id', 'produto_id', 'ordem', 'quantidade_milesimos', 'valor_unitario_centavos', 'desconto_centavos', 'total_centavos',
        'sku', 'descricao', 'unidade', 'gtin', 'ncm', 'cest', 'cfop', 'origem', 'tributacao_icms', 'csosn',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['origem' => 'integer'];
    }

    /** @return BelongsTo<Nota, $this> */
    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    protected static function newFactory(): NotaItemFactory
    {
        return NotaItemFactory::new();
    }
}
```

`NotaPagamento.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $nota_id
 * @property string $tpag
 * @property ?string $xpag
 * @property int $valor_centavos
 */
class NotaPagamento extends Model
{
    use BelongsToTenant;

    protected $table = 'nota_pagamentos';

    protected $fillable = ['nota_id', 'tpag', 'xpag', 'valor_centavos'];
}
```

`NotaEvento.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $nota_id
 * @property ?string $status_de
 * @property string $status_para
 * @property ?string $detalhe
 * @property ?string $usuario_id
 * @property CarbonInterface $created_at
 */
class NotaEvento extends Model
{
    use BelongsToTenant;

    protected $table = 'nota_eventos';

    protected $fillable = ['nota_id', 'status_de', 'status_para', 'detalhe', 'usuario_id'];

    public static function registrar(Nota $nota, ?StatusNota $de, StatusNota $para, ?string $detalhe = null, ?string $usuarioId = null): self
    {
        return self::query()->create([
            'nota_id' => $nota->id,
            'status_de' => $de?->value,
            'status_para' => $para->value,
            'detalhe' => $detalhe,
            'usuario_id' => $usuarioId,
        ]);
    }
}
```

- [ ] **Step 6: Factories**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Nota> */
class NotaFactory extends Factory
{
    protected $model = Nota::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'emitente_id' => Emitente::factory(),
            'cliente_id' => Cliente::factory(),
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotaItem> */
class NotaItemFactory extends Factory
{
    protected $model = NotaItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nota_id' => Nota::factory(),
            'produto_id' => Produto::factory(),
            'ordem' => 1,
            'quantidade_milesimos' => 1000,
            'valor_unitario_centavos' => 1000,
            'total_centavos' => 1000,
        ];
    }
}
```

Atenção: os factories encadeados criam `Emitente`, `Cliente` e `Produto` sob o mesmo `TenantContext` (o trait `BelongsToTenant` preenche o `tenant_id` do contexto). O `foreignUuid('tenant_id')->constrained` exige o tenant existir; o teste já cria os tenants antes.

- [ ] **Step 7: Rodar e ver passar**

Run: `php artisan test --filter=NotaIsolamentoTest`
Expected: PASS (4 testes).

- [ ] **Step 8: Commit**

```bash
git add backend/database backend/app/Modules/Fiscal/Domain backend/tests/Feature/Fiscal/NotaIsolamentoTest.php
git commit -m "feat(fiscal): modelo de dados da nota (notas, itens, pagamentos e eventos)"
```

---

### Task 3: Resolvers tributários, tabela IBGE e quantidade

**Files:**
- Create: `app/Modules/Fiscal/Domain/{TabelaIbge,Quantidade}.php`
- Create: `app/Modules/Fiscal/Domain/Exceptions/EmissaoBloqueadaException.php`
- Create: `app/Modules/Fiscal/Domain/Resolvers/{CrtResolver,CfopSaidaResolver,TributacaoIcmsSaidaResolver,IndicadorIeDestinatarioResolver}.php`
- Test: `tests/Unit/Fiscal/ResolversTest.php`, `tests/Unit/Fiscal/TabelaIbgeTest.php`, `tests/Unit/Fiscal/QuantidadeTest.php`

**Interfaces:**
- Produces:
  - `EmissaoBloqueadaException::__construct(string $codigo, string $mensagem)` (status 422, `codigo()` devolve o código).
  - `TabelaIbge::codigoDaUf(string $uf): int` (lança `EmissaoBloqueadaException('FISCAL_UF_INVALIDA', ...)`).
  - `CrtResolver::crt(?string $regime): int` (SIMPLES e MEI dão 1; NORMAL dá `FISCAL_REGIME_NAO_SUPORTADO`; null/outro dá `FISCAL_EMITENTE_INCOMPLETO`).
  - `CfopSaidaResolver::resolver(string $ufOrigem, string $ufDestino, TributacaoIcms $tributacao): string`.
  - `TributacaoIcmsSaidaResolver::csosn(TributacaoIcms $tributacao): string`.
  - `IndicadorIeDestinatarioResolver::resolver(TipoCliente $tipo, ?string $ie, bool $isento): int`.
  - `Quantidade::paraMilesimos(string $texto): int` e `Quantidade::formatar(int $milesimos, int $casas): string`.

- [ ] **Step 1: Testes**

`ResolversTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Resolvers\CfopSaidaResolver;
use App\Modules\Fiscal\Domain\Resolvers\CrtResolver;
use App\Modules\Fiscal\Domain\Resolvers\IndicadorIeDestinatarioResolver;
use App\Modules\Fiscal\Domain\Resolvers\TributacaoIcmsSaidaResolver;
use PHPUnit\Framework\TestCase;

class ResolversTest extends TestCase
{
    public function test_crt_simples_e_mei_sao_1(): void
    {
        $this->assertSame(1, (new CrtResolver)->crt('SIMPLES'));
        $this->assertSame(1, (new CrtResolver)->crt('MEI'));
    }

    public function test_crt_regime_normal_bloqueia_com_codigo_proprio(): void
    {
        $this->expectBloqueio('FISCAL_REGIME_NAO_SUPORTADO', fn () => (new CrtResolver)->crt('NORMAL'));
    }

    public function test_crt_nulo_bloqueia_como_emitente_incompleto(): void
    {
        $this->expectBloqueio('FISCAL_EMITENTE_INCOMPLETO', fn () => (new CrtResolver)->crt(null));
    }

    public function test_cfop_da_venda(): void
    {
        $r = new CfopSaidaResolver;
        $this->assertSame('5102', $r->resolver('SP', 'SP', TributacaoIcms::Normal));
        $this->assertSame('5405', $r->resolver('SP', 'SP', TributacaoIcms::St));
        $this->assertSame('6102', $r->resolver('SP', 'MG', TributacaoIcms::Normal));
        $this->assertSame('6404', $r->resolver('SP', 'MG', TributacaoIcms::St));
    }

    public function test_cfop_com_uf_invalida_bloqueia(): void
    {
        $this->expectBloqueio('FISCAL_UF_INVALIDA', fn () => (new CfopSaidaResolver)->resolver('SP', 'XX', TributacaoIcms::Normal));
    }

    public function test_csosn_do_simples(): void
    {
        $r = new TributacaoIcmsSaidaResolver;
        $this->assertSame('102', $r->csosn(TributacaoIcms::Normal));
        $this->assertSame('500', $r->csosn(TributacaoIcms::St));
    }

    public function test_indicador_de_ie(): void
    {
        $r = new IndicadorIeDestinatarioResolver;
        $this->assertSame(9, $r->resolver(TipoCliente::Pf, null, false));
        $this->assertSame(1, $r->resolver(TipoCliente::Pj, '123456', false));
        $this->assertSame(2, $r->resolver(TipoCliente::Pj, null, true));
    }

    public function test_pj_sem_ie_e_sem_isento_bloqueia(): void
    {
        $this->expectBloqueio('FISCAL_DESTINATARIO_SEM_IE', fn () => (new IndicadorIeDestinatarioResolver)->resolver(TipoCliente::Pj, null, false));
        $this->expectBloqueio('FISCAL_DESTINATARIO_SEM_IE', fn () => (new IndicadorIeDestinatarioResolver)->resolver(TipoCliente::Pj, '', false));
    }

    public function test_pj_com_ie_e_isento_ao_mesmo_tempo_bloqueia(): void
    {
        $this->expectBloqueio('FISCAL_DESTINATARIO_IE_CONTRADITORIA', fn () => (new IndicadorIeDestinatarioResolver)->resolver(TipoCliente::Pj, '123', true));
    }

    private function expectBloqueio(string $codigo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Esperava bloqueio {$codigo}.");
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame($codigo, $e->codigo());
        }
    }
}
```

`TabelaIbgeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\TabelaIbge;
use PHPUnit\Framework\TestCase;

class TabelaIbgeTest extends TestCase
{
    public function test_codigos_das_27_ufs(): void
    {
        $esperado = [
            'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29, 'CE' => 23, 'DF' => 53, 'ES' => 32, 'GO' => 52,
            'MA' => 21, 'MT' => 51, 'MS' => 50, 'MG' => 31, 'PA' => 15, 'PB' => 25, 'PR' => 41, 'PE' => 26, 'PI' => 22,
            'RJ' => 33, 'RN' => 24, 'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42, 'SP' => 35, 'SE' => 28, 'TO' => 17,
        ];
        foreach ($esperado as $uf => $codigo) {
            $this->assertSame($codigo, TabelaIbge::codigoDaUf($uf), $uf);
        }
    }

    public function test_aceita_minuscula_e_recusa_uf_desconhecida(): void
    {
        $this->assertSame(35, TabelaIbge::codigoDaUf('sp'));
        $this->expectException(EmissaoBloqueadaException::class);
        TabelaIbge::codigoDaUf('XX');
    }
}
```

`QuantidadeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Quantidade;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QuantidadeTest extends TestCase
{
    public function test_converte_texto_em_milesimos(): void
    {
        $this->assertSame(1000, Quantidade::paraMilesimos('1'));
        $this->assertSame(2500, Quantidade::paraMilesimos('2.5'));
        $this->assertSame(1, Quantidade::paraMilesimos('0.001'));
    }

    public function test_recusa_formato_invalido_e_zero(): void
    {
        foreach (['', 'abc', '1.2345', '-1', '1,5', '0', '0.000'] as $texto) {
            try {
                Quantidade::paraMilesimos($texto);
                $this->fail("Aceitou '{$texto}'.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_formata_com_casas_fixas(): void
    {
        $this->assertSame('2.5000', Quantidade::formatar(2500, 4));
        $this->assertSame('2.500', Quantidade::formatar(2500, 3));
        $this->assertSame('0.0010', Quantidade::formatar(1, 4));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test tests/Unit/Fiscal --filter="Resolvers|TabelaIbge|Quantidade"`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`EmissaoBloqueadaException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

/** Dado fiscal ausente ou regra não suportada: a emissão para aqui, com mensagem que diz como resolver. */
final class EmissaoBloqueadaException extends ErroDeNegocio
{
    public function __construct(private readonly string $codigoDoBloqueio, string $mensagem)
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return $this->codigoDoBloqueio;
    }
}
```

`TabelaIbge.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;

final class TabelaIbge
{
    private const CODIGOS = [
        'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29, 'CE' => 23, 'DF' => 53, 'ES' => 32, 'GO' => 52,
        'MA' => 21, 'MT' => 51, 'MS' => 50, 'MG' => 31, 'PA' => 15, 'PB' => 25, 'PR' => 41, 'PE' => 26, 'PI' => 22,
        'RJ' => 33, 'RN' => 24, 'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42, 'SP' => 35, 'SE' => 28, 'TO' => 17,
    ];

    public static function codigoDaUf(string $uf): int
    {
        return self::CODIGOS[strtoupper($uf)]
            ?? throw new EmissaoBloqueadaException('FISCAL_UF_INVALIDA', "UF \"{$uf}\" inválida. Corrija o endereço.");
    }
}
```

`Quantidade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use InvalidArgumentException;

/** Quantidade em milésimos inteiros: nada de float em dado fiscal. */
final class Quantidade
{
    public static function paraMilesimos(string $texto): int
    {
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/', $texto, $m) !== 1) {
            throw new InvalidArgumentException('Quantidade inválida: use até 3 casas decimais com ponto.');
        }
        $milesimos = ((int) $m[1]) * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
        if ($milesimos <= 0) {
            throw new InvalidArgumentException('A quantidade deve ser maior que zero.');
        }

        return $milesimos;
    }

    public static function formatar(int $milesimos, int $casas): string
    {
        $inteiro = intdiv($milesimos, 1000);
        $fracao = str_pad((string) ($milesimos % 1000), 3, '0', STR_PAD_LEFT);

        return $inteiro.'.'.str_pad($fracao, $casas, '0');
    }
}
```

`CrtResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Resolvers;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;

/** Fonte única da classificação Simples. MEI é Simples. */
final class CrtResolver
{
    public function crt(?string $regime): int
    {
        return match ($regime) {
            'SIMPLES', 'MEI' => 1,
            'NORMAL' => throw new EmissaoBloqueadaException(
                'FISCAL_REGIME_NAO_SUPORTADO',
                'A emissão para o Regime Normal ainda não está disponível. Por enquanto a plataforma emite para Simples Nacional e MEI.',
            ),
            default => throw new EmissaoBloqueadaException(
                'FISCAL_EMITENTE_INCOMPLETO',
                'Informe o regime tributário em Configurações > Fiscal antes de emitir.',
            ),
        };
    }
}
```

`CfopSaidaResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Resolvers;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Fiscal\Domain\TabelaIbge;

final class CfopSaidaResolver
{
    public function resolver(string $ufOrigem, string $ufDestino, TributacaoIcms $tributacao): string
    {
        TabelaIbge::codigoDaUf($ufOrigem);
        TabelaIbge::codigoDaUf($ufDestino);
        $interna = strtoupper($ufOrigem) === strtoupper($ufDestino);

        return match ($tributacao) {
            TributacaoIcms::Normal => $interna ? '5102' : '6102',
            TributacaoIcms::St => $interna ? '5405' : '6404',
        };
    }
}
```

`TributacaoIcmsSaidaResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Resolvers;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;

/** CSOSN do Simples Nacional: 102 (sem permissão de crédito) e 500 (ST já retido). */
final class TributacaoIcmsSaidaResolver
{
    public function csosn(TributacaoIcms $tributacao): string
    {
        return match ($tributacao) {
            TributacaoIcms::Normal => '102',
            TributacaoIcms::St => '500',
        };
    }
}
```

`IndicadorIeDestinatarioResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Resolvers;

use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;

/** `indIEDest`: 1 contribuinte, 2 isento, 9 não contribuinte. Não confundir com `indFinal`. */
final class IndicadorIeDestinatarioResolver
{
    public function resolver(TipoCliente $tipo, ?string $ie, bool $isento): int
    {
        if ($tipo === TipoCliente::Pf) {
            return 9;
        }

        $temIe = $ie !== null && trim($ie) !== '';
        if ($temIe && $isento) {
            throw new EmissaoBloqueadaException('FISCAL_DESTINATARIO_IE_CONTRADITORIA', 'O cliente está marcado como isento mas tem inscrição estadual. Corrija o cadastro.');
        }
        if ($isento) {
            return 2;
        }
        if ($temIe) {
            return 1;
        }

        throw new EmissaoBloqueadaException('FISCAL_DESTINATARIO_SEM_IE', 'Cliente pessoa jurídica sem inscrição estadual. Informe a IE ou marque como isento no cadastro do cliente.');
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test tests/Unit/Fiscal`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal/Domain backend/tests/Unit/Fiscal
git commit -m "feat(fiscal): resolvers de CRT, CFOP, CSOSN e indIEDest, tabela IBGE e quantidade"
```

---

### Task 4: Rateio de desconto e totais

**Files:**
- Create: `app/Modules/Fiscal/Domain/RateioDeDesconto.php`
- Test: `tests/Unit/Fiscal/RateioDeDescontoTest.php`

**Interfaces:**
- Produces: `RateioDeDesconto::ratear(int $descontoCentavos, array $totaisDosItens): array` (lista de `int`, mesma ordem; soma = desconto; nenhum item recebe mais que o próprio total; lança `EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', ...)` se desconto < 0 ou > soma). `RateioDeDesconto::totalDoItem(int $quantidadeMilesimos, int $valorUnitarioCentavos): int` (arredonda meio para cima, só aritmética inteira).

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\RateioDeDesconto;
use PHPUnit\Framework\TestCase;

class RateioDeDescontoTest extends TestCase
{
    public function test_total_do_item_arredonda_meio_para_cima(): void
    {
        $this->assertSame(1000, RateioDeDesconto::totalDoItem(1000, 1000));
        $this->assertSame(2500, RateioDeDesconto::totalDoItem(2500, 1000));
        $this->assertSame(335, RateioDeDesconto::totalDoItem(1500, 223)); // 1,5 x 2,23 = 3,345 reais = 334,5 centavos, meio para cima
    }

    public function test_rateio_soma_exatamente_o_desconto(): void
    {
        $partes = RateioDeDesconto::ratear(100, [3333, 3333, 3334]);
        $this->assertSame(100, array_sum($partes));
        $this->assertCount(3, $partes);
    }

    public function test_residuo_vai_para_o_ultimo_item(): void
    {
        $this->assertSame([33, 33, 34], RateioDeDesconto::ratear(100, [1000, 1000, 1000]));
    }

    public function test_desconto_zero_nao_altera_nada(): void
    {
        $this->assertSame([0, 0], RateioDeDesconto::ratear(0, [500, 500]));
    }

    public function test_desconto_igual_ao_subtotal_zera_todos_os_itens(): void
    {
        $this->assertSame([500, 700], RateioDeDesconto::ratear(1200, [500, 700]));
    }

    public function test_nenhum_item_recebe_mais_que_o_proprio_total(): void
    {
        $partes = RateioDeDesconto::ratear(1199, [1, 1198, 1]);
        $this->assertSame(1199, array_sum($partes));
        foreach ([1, 1198, 1] as $i => $total) {
            $this->assertLessThanOrEqual($total, $partes[$i]);
        }
    }

    public function test_desconto_maior_que_o_subtotal_ou_negativo_bloqueia(): void
    {
        foreach ([1201, -1] as $desconto) {
            try {
                RateioDeDesconto::ratear($desconto, [500, 700]);
                $this->fail('Esperava bloqueio.');
            } catch (EmissaoBloqueadaException $e) {
                $this->assertSame('FISCAL_DESCONTO_INVALIDO', $e->codigo());
            }
        }
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=RateioDeDescontoTest`
Expected: FAIL (classe inexistente).

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;

/** Aritmética inteira em centavos. O resíduo do rateio vai para o último item que ainda comporta. */
final class RateioDeDesconto
{
    /** quantidade (milésimos) x valor unitário (centavos), arredondado meio para cima. */
    public static function totalDoItem(int $quantidadeMilesimos, int $valorUnitarioCentavos): int
    {
        return intdiv($quantidadeMilesimos * $valorUnitarioCentavos + 500, 1000);
    }

    /**
     * @param  list<int>  $totaisDosItens
     * @return list<int>
     */
    public static function ratear(int $descontoCentavos, array $totaisDosItens): array
    {
        $subtotal = array_sum($totaisDosItens);
        if ($descontoCentavos < 0 || $descontoCentavos > $subtotal) {
            throw new EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', 'O desconto não pode ser negativo nem maior que o subtotal dos itens.');
        }
        if ($descontoCentavos === 0 || $subtotal === 0) {
            return array_fill(0, count($totaisDosItens), 0);
        }

        $partes = array_map(fn (int $total): int => intdiv($descontoCentavos * $total, $subtotal), $totaisDosItens);
        $residuo = $descontoCentavos - array_sum($partes);

        for ($i = count($partes) - 1; $i >= 0 && $residuo > 0; $i--) {
            $cabe = $totaisDosItens[$i] - $partes[$i];
            $acrescimo = min($residuo, $cabe);
            $partes[$i] += $acrescimo;
            $residuo -= $acrescimo;
        }

        return $partes;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=RateioDeDescontoTest`
Expected: PASS. Confirme à mão que `[1000,1000,1000]` com 100 dá `[33,33,34]` (33+33 = 66, resíduo 34 vai para o último, que comporta).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal/Domain/RateioDeDesconto.php backend/tests/Unit/Fiscal/RateioDeDescontoTest.php
git commit -m "feat(fiscal): rateio de desconto e total do item em aritmética inteira"
```

---

### Task 5: Verificador do emitente e preparador da nota (bloqueios e snapshot fiscal)

**Files:**
- Create: `app/Modules/Fiscal/Application/Emissao/{VerificadorDoEmitente,PreparadorDaNota}.php`
- Create: `tests/Fixtures/CenarioDeNota.php`
- Test: `tests/Feature/Fiscal/BloqueiosDeEmissaoTest.php`

**Interfaces:**
- Consumes: resolvers e `RateioDeDesconto` (Tarefas 3 e 4), models da Tarefa 2, `EntitlementService`.
- Produces:
  - `VerificadorDoEmitente::garantirApto(Emitente $emitente, Tenant $tenant): void` (lança `EmissaoBloqueadaException`).
  - `PreparadorDaNota::preparar(Nota $nota, Emitente $emitente): void` — valida destinatário, produtos e pagamentos e **congela** o snapshot: preenche as colunas fiscais de cada `NotaItem`, `desconto_centavos` por item, `total_centavos`, `subtotal_centavos` e `destinatario` (array) da nota. Não muda o status.
  - Trait de teste `Tests\Fixtures\CenarioDeNota` com `montarCenario(): void` e propriedades `$tenant`, `$admin`, `$emitente`, `$cliente` (PF completo, SP), `$produto` (completo, R$ 10,00).

- [ ] **Step 1: Trait de cenário**

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;

/** Tenant com plano de NF-e, emitente apto (Simples, SP, certificado válido, série 1), cliente PF e produto completos. */
trait CenarioDeNota
{
    protected Tenant $tenant;

    protected Usuario $admin;

    protected Emitente $emitente;

    protected Cliente $cliente;

    protected Produto $produto;

    protected function montarCenario(): void
    {
        $this->tenant = Tenant::factory()->for(
            Plano::factory()->comModulos(Modulo::FiscalNfe)->comLimites([Recurso::DocumentosMes->value => 100]),
        )->create(['cnpj' => '11444777000161']);
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);

        $this->emitente = Emitente::factory()->completo()->create([
            'inscricao_estadual' => '110042490114',
            'certificado_status' => CertificadoStatus::Valido,
            'certificado_validade' => now()->addYear()->toDateString(),
        ]);
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfe, 'serie' => '1', 'proximo_numero' => 1]);

        $this->cliente = Cliente::factory()->comCpfValido()->create([
            'nome' => 'Maria Teste', 'estagio' => EstagioCliente::Cliente, 'tipo' => TipoCliente::Pf,
            'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
            'uf' => 'SP', 'cep' => '01001000', 'codigo_ibge' => '3550308',
        ]);
        $this->produto = Produto::factory()->completo()->create(['preco_centavos' => 1000, 'sku' => 'P-1', 'nome' => 'Produto Um']);
    }
}
```

Se `EmitenteSerieFactory` não tiver `modelo` no `definition`, o `create([...])` acima já informa. Se o `Plano::factory()->comModulos()` não existir com esse nome, use o que a `PlanoFactory` expõe (ela expõe `comModulos` e `comLimites`).

- [ ] **Step 2: Teste dos bloqueios**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Fiscal\Application\Emissao\PreparadorDaNota;
use App\Modules\Fiscal\Application\Emissao\VerificadorDoEmitente;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\NotaPagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class BloqueiosDeEmissaoTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function nota(int $quantidadeMilesimos = 1000, int $unitario = 1000, int $desconto = 0, ?int $pagamento = null): Nota
    {
        $nota = Nota::factory()->create([
            'emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id, 'desconto_centavos' => $desconto,
        ]);
        $total = intdiv($quantidadeMilesimos * $unitario + 500, 1000);
        NotaItem::factory()->create([
            'nota_id' => $nota->id, 'produto_id' => $this->produto->id, 'ordem' => 1,
            'quantidade_milesimos' => $quantidadeMilesimos, 'valor_unitario_centavos' => $unitario, 'total_centavos' => $total,
        ]);
        NotaPagamento::query()->create(['nota_id' => $nota->id, 'tpag' => '01', 'valor_centavos' => $pagamento ?? ($total - $desconto)]);

        return $nota->refresh();
    }

    private function preparar(Nota $nota): void
    {
        app(PreparadorDaNota::class)->preparar($nota, $this->emitente->refresh());
    }

    private function assertBloqueio(string $codigo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Esperava o bloqueio {$codigo}.");
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame($codigo, $e->codigo(), $e->getMessage());
        }
    }

    public function test_nota_valida_congela_o_snapshot_fiscal(): void
    {
        $nota = $this->nota();
        $this->preparar($nota);

        $item = $nota->itens()->first();
        $this->assertSame('5102', $item->cfop);
        $this->assertSame('102', $item->csosn);
        $this->assertSame(0, $item->origem);
        $this->assertSame('12345678', $item->ncm);
        $this->assertSame(1000, $nota->refresh()->total_centavos);
        $this->assertSame(9, $nota->destinatario['ind_ie_dest']);
        $this->assertSame('SP', $nota->destinatario['uf']);
    }

    public function test_cfop_interestadual_quando_o_cliente_e_de_outra_uf(): void
    {
        $this->cliente->update(['uf' => 'MG', 'codigo_ibge' => '3106200']);
        $nota = $this->nota();
        $this->preparar($nota);
        $this->assertSame('6102', $nota->itens()->first()->cfop);
    }

    public function test_origem_zero_e_valida_mas_origem_nula_bloqueia(): void
    {
        $this->produto->update(['origem' => null]);
        $this->assertBloqueio('FISCAL_PRODUTO_INCOMPLETO', fn () => $this->preparar($this->nota()));
    }

    public function test_produto_sem_ncm_bloqueia_citando_o_produto(): void
    {
        $this->produto->update(['ncm' => null]);
        try {
            $this->preparar($this->nota());
            $this->fail('Esperava bloqueio.');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_PRODUTO_INCOMPLETO', $e->codigo());
            $this->assertStringContainsString('Produto Um', $e->getMessage());
            $this->assertStringContainsString('NCM', $e->getMessage());
        }
    }

    public function test_produto_sem_tributacao_ou_st_sem_cest_bloqueia(): void
    {
        $this->produto->update(['tributacao_icms' => null]);
        $this->assertBloqueio('FISCAL_PRODUTO_INCOMPLETO', fn () => $this->preparar($this->nota()));

        $this->produto->update(['tributacao_icms' => TributacaoIcms::St, 'cest' => null]);
        $this->assertBloqueio('FISCAL_PRODUTO_INCOMPLETO', fn () => $this->preparar($this->nota()));
    }

    public function test_cliente_sem_endereco_ou_sem_uf_bloqueia(): void
    {
        $this->cliente->update(['uf' => null]);
        $this->assertBloqueio('FISCAL_DESTINATARIO_INCOMPLETO', fn () => $this->preparar($this->nota()));
    }

    public function test_cliente_sem_documento_bloqueia(): void
    {
        $this->cliente->update(['cpf_cnpj' => null]);
        $this->assertBloqueio('FISCAL_DESTINATARIO_INCOMPLETO', fn () => $this->preparar($this->nota()));
    }

    public function test_pj_exige_escolha_explicita_de_consumidor_final(): void
    {
        $this->cliente->update(['tipo' => TipoCliente::Pj, 'cpf_cnpj' => '11444777000161', 'inscricao_estadual' => '110042490114']);
        $nota = $this->nota();
        $this->assertBloqueio('FISCAL_CONSUMIDOR_FINAL_NAO_INFORMADO', fn () => $this->preparar($nota));

        $nota->update(['consumidor_final' => false]);
        $this->preparar($nota->refresh());
        $this->assertSame(1, $nota->refresh()->destinatario['ind_ie_dest']);
        $this->assertSame(0, $nota->destinatario['ind_final']);
    }

    public function test_pf_e_sempre_consumidor_final(): void
    {
        $nota = $this->nota();
        $this->preparar($nota);
        $this->assertSame(1, $nota->refresh()->destinatario['ind_final']);
    }

    public function test_soma_dos_pagamentos_diferente_do_total_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_PAGAMENTO_DIVERGENTE', fn () => $this->preparar($this->nota(pagamento: 900)));
    }

    public function test_pagamento_outros_sem_descricao_bloqueia(): void
    {
        $nota = $this->nota();
        $nota->pagamentos()->update(['tpag' => '99', 'xpag' => null]);
        $this->assertBloqueio('FISCAL_PAGAMENTO_SEM_DESCRICAO', fn () => $this->preparar($nota->refresh()));
    }

    public function test_nota_sem_itens_ou_sem_pagamentos_bloqueia(): void
    {
        $semItens = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $this->assertBloqueio('FISCAL_NOTA_SEM_ITENS', fn () => $this->preparar($semItens));

        $nota = $this->nota();
        $nota->pagamentos()->delete();
        $this->assertBloqueio('FISCAL_PAGAMENTO_DIVERGENTE', fn () => $this->preparar($nota->refresh()));
    }

    public function test_desconto_igual_ao_subtotal_bloqueia_nota_de_valor_zero(): void
    {
        $this->assertBloqueio('FISCAL_DESCONTO_INVALIDO', fn () => $this->preparar($this->nota(desconto: 1000)));
    }

    public function test_desconto_maior_que_o_subtotal_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_DESCONTO_INVALIDO', fn () => $this->preparar($this->nota(desconto: 1500)));
    }

    public function test_emitente_apto_passa(): void
    {
        app(VerificadorDoEmitente::class)->garantirApto($this->emitente->refresh(), $this->tenant);
        $this->addToAssertionCount(1);
    }

    public function test_emitente_incompleto_ou_regime_normal_ou_certificado_invalido_bloqueia(): void
    {
        $v = app(VerificadorDoEmitente::class);

        $this->emitente->update(['inscricao_estadual' => null]);
        $this->assertBloqueio('FISCAL_EMITENTE_INCOMPLETO', fn () => $v->garantirApto($this->emitente->refresh(), $this->tenant));
        $this->emitente->update(['inscricao_estadual' => '110042490114', 'regime_tributario' => 'NORMAL']);
        $this->assertBloqueio('FISCAL_REGIME_NAO_SUPORTADO', fn () => $v->garantirApto($this->emitente->refresh(), $this->tenant));
        $this->emitente->update(['regime_tributario' => 'SIMPLES', 'certificado_status' => CertificadoStatus::Vencido]);
        $this->assertBloqueio('FISCAL_CERTIFICADO_INVALIDO', fn () => $v->garantirApto($this->emitente->refresh(), $this->tenant));
        $this->emitente->update(['certificado_status' => CertificadoStatus::Valido, 'certificado_validade' => now()->subDay()->toDateString()]);
        $this->assertBloqueio('FISCAL_CERTIFICADO_INVALIDO', fn () => $v->garantirApto($this->emitente->refresh(), $this->tenant));
    }

    public function test_emitente_sem_serie_nfe_bloqueia(): void
    {
        $this->emitente->series()->delete();
        $this->assertBloqueio('FISCAL_SERIE_NAO_CONFIGURADA', fn () => app(VerificadorDoEmitente::class)->garantirApto($this->emitente->refresh(), $this->tenant));
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --filter=BloqueiosDeEmissaoTest`
Expected: FAIL (classes inexistentes).

- [ ] **Step 4: `VerificadorDoEmitente`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Emissao;

use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Resolvers\CrtResolver;
use App\Modules\Fiscal\Domain\TabelaIbge;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** O emitente está apto a emitir NF-e? Cada falta vira um bloqueio que diz onde corrigir. */
final class VerificadorDoEmitente
{
    public function __construct(private readonly CrtResolver $crt) {}

    public function garantirApto(Emitente $emitente, Tenant $tenant): void
    {
        $faltas = [];
        foreach ([
            'logradouro' => 'logradouro', 'numero' => 'número', 'bairro' => 'bairro', 'cidade' => 'cidade',
            'uf' => 'UF', 'cep' => 'CEP', 'codigo_ibge' => 'código IBGE do município', 'inscricao_estadual' => 'inscrição estadual',
        ] as $campo => $rotulo) {
            $valor = $emitente->getAttribute($campo);
            if ($valor === null || trim((string) $valor) === '') {
                $faltas[] = $rotulo;
            }
        }
        if ($faltas !== []) {
            throw new EmissaoBloqueadaException('FISCAL_EMITENTE_INCOMPLETO', 'Complete em Configurações > Fiscal: '.implode(', ', $faltas).'.');
        }

        $this->crt->crt($emitente->regime_tributario);
        TabelaIbge::codigoDaUf((string) $emitente->uf);

        $validade = $emitente->certificado_validade;
        if ($emitente->certificado_status !== CertificadoStatus::Valido || $validade === null || $validade->lt(now()->startOfDay())) {
            throw new EmissaoBloqueadaException('FISCAL_CERTIFICADO_INVALIDO', 'O certificado A1 está ausente, inválido ou vencido. Envie um certificado válido em Configurações > Fiscal.');
        }

        if (! $emitente->series()->where('modelo', ModeloDocumento::Nfe)->exists()) {
            throw new EmissaoBloqueadaException('FISCAL_SERIE_NAO_CONFIGURADA', 'Cadastre a série e o próximo número da NF-e em Configurações > Fiscal.');
        }

        if (trim($tenant->cnpj) === '') {
            throw new EmissaoBloqueadaException('FISCAL_EMITENTE_INCOMPLETO', 'O CNPJ da empresa não está cadastrado.');
        }
    }
}
```

- [ ] **Step 5: `PreparadorDaNota`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Emissao;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\FormaPagamento;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\RateioDeDesconto;
use App\Modules\Fiscal\Domain\Resolvers\CfopSaidaResolver;
use App\Modules\Fiscal\Domain\Resolvers\IndicadorIeDestinatarioResolver;
use App\Modules\Fiscal\Domain\Resolvers\TributacaoIcmsSaidaResolver;

/**
 * Valida o que depende do cliente, dos produtos e dos pagamentos e congela o resultado na nota
 * (snapshot fiscal). Assim a nota não muda se o cadastro for editado depois.
 */
final class PreparadorDaNota
{
    public function __construct(
        private readonly CfopSaidaResolver $cfop,
        private readonly TributacaoIcmsSaidaResolver $csosn,
        private readonly IndicadorIeDestinatarioResolver $indicadorIe,
    ) {}

    public function preparar(Nota $nota, Emitente $emitente): void
    {
        $itens = $nota->itens()->get();
        if ($itens->isEmpty()) {
            throw new EmissaoBloqueadaException('FISCAL_NOTA_SEM_ITENS', 'Adicione ao menos um item à nota.');
        }

        $cliente = Cliente::query()->findOrFail($nota->cliente_id);
        $destinatario = $this->destinatario($nota, $cliente);

        $totais = $itens->map(fn ($i): int => RateioDeDesconto::totalDoItem($i->quantidade_milesimos, $i->valor_unitario_centavos))->all();
        $descontos = RateioDeDesconto::ratear($nota->desconto_centavos, array_values($totais));
        $subtotal = array_sum($totais);
        $total = $subtotal - $nota->desconto_centavos;
        if ($total <= 0) {
            throw new EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', 'O total da nota deve ser maior que zero.');
        }

        foreach ($itens->values() as $i => $item) {
            $produto = Produto::query()->findOrFail($item->produto_id);
            $tributacao = $this->exigirProduto($produto);
            $item->update([
                'sku' => $produto->sku,
                'descricao' => $produto->nome,
                'unidade' => $produto->unidade,
                'gtin' => $produto->gtin,
                'ncm' => $produto->ncm,
                'cest' => $produto->cest,
                'origem' => $produto->origem,
                'tributacao_icms' => $tributacao->value,
                'cfop' => $this->cfop->resolver((string) $emitente->uf, $destinatario['uf'], $tributacao),
                'csosn' => $this->csosn->csosn($tributacao),
                'desconto_centavos' => $descontos[$i],
                'total_centavos' => $totais[$i],
            ]);
        }

        $this->exigirPagamentos($nota, $total);

        $nota->forceFill(['subtotal_centavos' => $subtotal, 'total_centavos' => $total, 'destinatario' => $destinatario])->save();
    }

    /** @return \App\Modules\Catalog\Domain\Enums\TributacaoIcms */
    private function exigirProduto(Produto $produto)
    {
        $faltas = [];
        if ($produto->ncm === null || trim($produto->ncm) === '') {
            $faltas[] = 'NCM';
        }
        if ($produto->origem === null) {
            $faltas[] = 'origem da mercadoria';
        }
        if ($produto->tributacao_icms === null) {
            $faltas[] = 'tributação de ICMS';
        } elseif ($produto->tributacao_icms->value === 'ST' && ($produto->cest === null || trim($produto->cest) === '')) {
            $faltas[] = 'CEST (obrigatório para ST)';
        }
        if ($faltas !== []) {
            throw new EmissaoBloqueadaException('FISCAL_PRODUTO_INCOMPLETO', "O produto \"{$produto->nome}\" está sem: ".implode(', ', $faltas).'. Complete em Produtos.');
        }

        return $produto->tributacao_icms;
    }

    /** @return array<string, mixed> */
    private function destinatario(Nota $nota, Cliente $c): array
    {
        $documento = $c->cpf_cnpj === null ? '' : preg_replace('/\D/', '', $c->cpf_cnpj);
        $faltas = [];
        if ($documento === '') {
            $faltas[] = 'CPF/CNPJ';
        }
        foreach (['logradouro' => 'logradouro', 'numero' => 'número', 'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'UF', 'cep' => 'CEP', 'codigo_ibge' => 'código IBGE'] as $campo => $rotulo) {
            $valor = $c->getAttribute($campo);
            if ($valor === null || trim((string) $valor) === '') {
                $faltas[] = $rotulo;
            }
        }
        if ($faltas !== []) {
            throw new EmissaoBloqueadaException('FISCAL_DESTINATARIO_INCOMPLETO', "O cliente \"{$c->nome}\" está sem: ".implode(', ', $faltas).'. Complete em Clientes.');
        }

        $indIeDest = $this->indicadorIe->resolver($c->tipo, $c->inscricao_estadual, $c->ie_isento);

        if ($c->tipo === TipoCliente::Pf) {
            $indFinal = 1;
        } elseif ($nota->consumidor_final === null) {
            throw new EmissaoBloqueadaException('FISCAL_CONSUMIDOR_FINAL_NAO_INFORMADO', 'Informe se a venda é para consumidor final (cliente pessoa jurídica).');
        } else {
            $indFinal = $nota->consumidor_final ? 1 : 0;
        }

        return [
            'tipo' => $c->tipo->value, 'documento' => $documento, 'nome' => $c->nome,
            'ind_ie_dest' => $indIeDest, 'ind_final' => $indFinal,
            'ie' => $indIeDest === 1 ? preg_replace('/\D/', '', (string) $c->inscricao_estadual) : null,
            'logradouro' => $c->logradouro, 'numero' => $c->numero, 'bairro' => $c->bairro,
            'codigo_ibge' => $c->codigo_ibge, 'cidade' => $c->cidade, 'uf' => strtoupper((string) $c->uf), 'cep' => preg_replace('/\D/', '', (string) $c->cep),
            'email' => $c->email,
        ];
    }

    private function exigirPagamentos(Nota $nota, int $total): void
    {
        $pagamentos = $nota->pagamentos()->get();
        if ($pagamentos->sum('valor_centavos') !== $total) {
            throw new EmissaoBloqueadaException('FISCAL_PAGAMENTO_DIVERGENTE', 'A soma dos pagamentos deve ser igual ao total da nota.');
        }
        foreach ($pagamentos as $p) {
            if ($p->tpag === FormaPagamento::Outros->value && ($p->xpag === null || trim($p->xpag) === '')) {
                throw new EmissaoBloqueadaException('FISCAL_PAGAMENTO_SEM_DESCRICAO', 'Informe a descrição da forma de pagamento "Outros".');
            }
        }
    }
}
```

Ajuste de tipo: troque o docblock `@return \App\Modules\Catalog\Domain\Enums\TributacaoIcms` por um `use` de `TributacaoIcms` e o tipo de retorno real `: TributacaoIcms` no método `exigirProduto` (PHPStan nível 6 exige). O restante fica igual.

- [ ] **Step 6: Rodar e ver passar**

Run: `php artisan test --filter=BloqueiosDeEmissaoTest`
Expected: PASS. Se algum teste falhar por detalhe de factory (campo `uf` do cliente, certificado), ajuste o `CenarioDeNota`, não a regra.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/Fiscal/Application/Emissao backend/tests/Fixtures/CenarioDeNota.php backend/tests/Feature/Fiscal/BloqueiosDeEmissaoTest.php
git commit -m "feat(fiscal): bloqueios de emissão e snapshot fiscal da nota"
```

---

### Task 6: Contador `DOCUMENTOS_MES` e limite do plano

**Files:**
- Create: `app/Modules/Fiscal/Application/ContadorDeDocumentosMes.php`
- Modify: `app/Modules/Fiscal/Providers/FiscalServiceProvider.php`
- Test: `tests/Feature/Fiscal/ContadorDeDocumentosMesTest.php`

**Interfaces:**
- Produces: `ContadorDeDocumentosMes implements ContadorDeUso` (`recurso()` = `Recurso::DocumentosMes`; `contar(Tenant)` = notas `AUTORIZADA` com `ambiente = PRODUCAO` e `emitido_em` no mês corrente, ignorando o `TenantScope`). Registrado no `RegistroDeContadores` pelo `FiscalServiceProvider`.

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class ContadorDeDocumentosMesTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function nota(StatusNota $status, string $ambiente, \DateTimeInterface $emitidoEm): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill(['status' => $status, 'ambiente' => $ambiente, 'emitido_em' => $emitidoEm])->save();
    }

    public function test_conta_so_autorizadas_em_producao_no_mes_corrente(): void
    {
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now());
        $this->nota(StatusNota::Autorizada, 'HOMOLOGACAO', now());
        $this->nota(StatusNota::Rejeitada, 'PRODUCAO', now());
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now()->subMonth());

        $this->assertSame(1, app(EntitlementService::class)->uso($this->tenant, Recurso::DocumentosMes));
    }

    public function test_limite_do_plano_bloqueia_com_codigo_estavel(): void
    {
        $this->tenant->plano->limites()->where('recurso', Recurso::DocumentosMes->value)->update(['limite' => 1]);
        $this->tenant->unsetRelation('plano');
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now());

        $this->expectException(LimiteDoPlanoAtingidoException::class);
        app(EntitlementService::class)->garantirCapacidade($this->tenant->refresh(), Recurso::DocumentosMes);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=ContadorDeDocumentosMesTest`
Expected: FAIL (`RecursoSemContadorException`).

- [ ] **Step 3: Implementar e registrar**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** Homologação é teste e não consome o plano (spec F3a §2). */
final class ContadorDeDocumentosMes implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::DocumentosMes;
    }

    public function contar(Tenant $tenant): int
    {
        return Nota::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', StatusNota::Autorizada)
            ->where('ambiente', 'PRODUCAO')
            ->whereBetween('emitido_em', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }
}
```

No `FiscalServiceProvider`, acrescente `use App\Modules\Fiscal\Application\ContadorDeDocumentosMes; use App\Modules\Platform\Application\RegistroDeContadores;` e, dentro de `boot()`, antes da rota: `$this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeDocumentosMes);` (mesmo padrão do `CatalogServiceProvider`).

- [ ] **Step 4: Rodar e ver passar; conferir testes de consumo existentes**

Run: `php artisan test --filter="ContadorDeDocumentosMesTest|Entitlement|Consumo"`
Expected: PASS. Se um teste antigo contava os recursos registrados e agora vê `DOCUMENTOS_MES`, atualize a expectativa dele (o recurso passou a existir de fato).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests
git commit -m "feat(fiscal): contador DOCUMENTOS_MES (autorizadas em produção)"
```

---

### Task 7: Rascunho da nota (criar, atualizar, excluir) e política de papéis

**Files:**
- Create: `app/Modules/Fiscal/Application/PoliticaDeNotas.php`
- Create: `app/Modules/Fiscal/Application/Actions/{CriarRascunhoDeNota,AtualizarRascunhoDeNota,ExcluirRascunhoDeNota}.php`
- Create: `app/Modules/Fiscal/Domain/Exceptions/NotaNaoEditavelException.php`
- Test: `tests/Feature/Fiscal/RascunhoDeNotaTest.php`

**Interfaces:**
- Produces:
  - `PoliticaDeNotas::garantirPodeRascunhar(Usuario): void` (PROPRIETARIO, ADMIN, FISCAL, VENDEDOR) e `garantirPodeEmitir(Usuario): void` (PROPRIETARIO, ADMIN, FISCAL); lançam `AcessoNegadoException`.
  - `CriarRascunhoDeNota::executar(array $dados, Usuario $autor): Nota` e `AtualizarRascunhoDeNota::executar(Nota $nota, array $dados, Usuario $autor): Nota`. `$dados` (já validado pelo FormRequest): `cliente_id` (string), `consumidor_final` (?bool), `desconto_centavos` (int), `informacoes_complementares` (?string), `itens` (list de `{produto_id, quantidade: string, valor_unitario_centavos: ?int}`), `pagamentos` (list de `{tpag, xpag: ?string, valor_centavos}`).
  - `ExcluirRascunhoDeNota::executar(Nota $nota): void` (só `RASCUNHO`).
  - `NotaNaoEditavelException` (409, código `NOTA_NAO_EDITAVEL`).
- O rascunho calcula `subtotal_centavos` e `total_centavos` com `RateioDeDesconto::totalDoItem`, mas **não** valida a parte fiscal (isso é da emissão). Desconto maior que o subtotal no rascunho é aceito como rascunho e só bloqueia ao emitir. Registra um `NotaEvento` ao criar.

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\AtualizarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\ExcluirRascunhoDeNota;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class RascunhoDeNotaTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    /** @return array<string, mixed> */
    private function dados(): array
    {
        return [
            'cliente_id' => $this->cliente->id,
            'consumidor_final' => null,
            'desconto_centavos' => 100,
            'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '2.5', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 2400]],
        ];
    }

    public function test_cria_rascunho_com_totais_e_preco_do_cadastro(): void
    {
        $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertSame(2500, $nota->subtotal_centavos);
        $this->assertSame(2400, $nota->total_centavos);
        $item = $nota->itens()->first();
        $this->assertSame(2500, $item->quantidade_milesimos);
        $this->assertSame(1000, $item->valor_unitario_centavos);
        $this->assertSame(1, $nota->pagamentos()->count());
        $this->assertSame(1, $nota->eventos()->count());
    }

    public function test_preco_informado_prevalece_sobre_o_do_cadastro(): void
    {
        $dados = $this->dados();
        $dados['itens'][0]['valor_unitario_centavos'] = 800;
        $nota = app(CriarRascunhoDeNota::class)->executar($dados, $this->admin);
        $this->assertSame(800, $nota->itens()->first()->valor_unitario_centavos);
    }

    public function test_atualiza_substituindo_itens_e_pagamentos(): void
    {
        $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
        $dados = $this->dados();
        $dados['desconto_centavos'] = 0;
        $dados['itens'][0]['quantidade'] = '1';
        $dados['pagamentos'] = [['tpag' => '17', 'xpag' => null, 'valor_centavos' => 1000]];

        $nota = app(AtualizarRascunhoDeNota::class)->executar($nota, $dados, $this->admin);

        $this->assertSame(1000, $nota->total_centavos);
        $this->assertSame(1, $nota->itens()->count());
        $this->assertSame('17', $nota->pagamentos()->first()->tpag);
    }

    public function test_nota_autorizada_ou_processando_nao_e_editavel(): void
    {
        foreach ([StatusNota::Autorizada, StatusNota::Processando, StatusNota::Cancelada] as $status) {
            $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
            $nota->forceFill(['status' => $status])->save();
            try {
                app(AtualizarRascunhoDeNota::class)->executar($nota, $this->dados(), $this->admin);
                $this->fail('Esperava NotaNaoEditavelException.');
            } catch (NotaNaoEditavelException $e) {
                $this->assertSame('NOTA_NAO_EDITAVEL', $e->codigo());
            }
        }
    }

    public function test_rejeitada_e_erro_voltam_a_ser_editaveis(): void
    {
        foreach ([StatusNota::Rejeitada, StatusNota::Erro] as $status) {
            $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
            $nota->forceFill(['status' => $status])->save();
            $atualizada = app(AtualizarRascunhoDeNota::class)->executar($nota, $this->dados(), $this->admin);
            $this->assertSame($status, $atualizada->status);
        }
    }

    public function test_so_rascunho_pode_ser_excluido(): void
    {
        $nota = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
        app(ExcluirRascunhoDeNota::class)->executar($nota);
        $this->assertSame(0, \App\Modules\Fiscal\Domain\Models\Nota::query()->count());

        $outra = app(CriarRascunhoDeNota::class)->executar($this->dados(), $this->admin);
        $outra->forceFill(['status' => StatusNota::Rejeitada])->save();
        $this->expectException(NotaNaoEditavelException::class);
        app(ExcluirRascunhoDeNota::class)->executar($outra);
    }

    public function test_vendedor_rascunha_e_admin_emite(): void
    {
        $politica = app(PoliticaDeNotas::class);
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $politica->garantirPodeRascunhar($vendedor);
        $politica->garantirPodeEmitir($this->admin);
        $this->addToAssertionCount(2);
    }

    public function test_vendedor_nao_emite(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->expectException(AcessoNegadoException::class);
        app(PoliticaDeNotas::class)->garantirPodeEmitir($vendedor);
    }

    public function test_leitura_nao_rascunha(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->expectException(AcessoNegadoException::class);
        app(PoliticaDeNotas::class)->garantirPodeRascunhar($leitura);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=RascunhoDeNotaTest`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`NotaNaoEditavelException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class NotaNaoEditavelException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Esta nota não pode mais ser alterada.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 409;
    }

    public function codigo(): string
    {
        return 'NOTA_NAO_EDITAVEL';
    }
}
```

`PoliticaDeNotas.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem faz o quê com notas (spec F3a §4). LEITURA só consulta. */
final class PoliticaDeNotas
{
    public function garantirPodeRascunhar(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin, Papel::Fiscal, Papel::Vendedor], true)) {
            throw new AcessoNegadoException('Você não tem permissão para criar ou alterar notas.');
        }
    }

    public function garantirPodeEmitir(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin, Papel::Fiscal], true)) {
            throw new AcessoNegadoException('Você não tem permissão para emitir notas.');
        }
    }
}
```

`CriarRascunhoDeNota.php` (e `AtualizarRascunhoDeNota` reutiliza o mesmo preenchimento via uma classe interna `PreenchedorDeRascunho`; para manter DRY, ponha a lógica em `Application/Actions/Concerns/` não é necessário: faça `AtualizarRascunhoDeNota` chamar um método público do `CriarRascunhoDeNota`):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Fiscal\Domain\Quantidade;
use App\Modules\Fiscal\Domain\RateioDeDesconto;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class CriarRascunhoDeNota
{
    public function __construct(private readonly PoliticaDeNotas $politica) {}

    /** @param array<string, mixed> $dados */
    public function executar(array $dados, Usuario $autor): Nota
    {
        $this->politica->garantirPodeRascunhar($autor);

        return DB::transaction(function () use ($dados, $autor): Nota {
            $emitente = Emitente::query()->firstOrCreate([], []);
            $nota = new Nota;
            $nota->emitente_id = $emitente->id;
            $nota->save();
            $this->preencher($nota, $dados);
            NotaEvento::registrar($nota, null, StatusNota::Rascunho, 'Rascunho criado', $autor->id);

            return $nota->refresh();
        });
    }

    /**
     * Grava cabeçalho, itens e pagamentos (substitui os existentes) e recalcula os totais.
     *
     * @param  array<string, mixed>  $dados
     */
    public function preencher(Nota $nota, array $dados): void
    {
        $itens = [];
        $subtotal = 0;
        foreach (array_values($dados['itens']) as $i => $linha) {
            $produto = Produto::query()->findOrFail($linha['produto_id']);
            $milesimos = Quantidade::paraMilesimos((string) $linha['quantidade']);
            $unitario = $linha['valor_unitario_centavos'] ?? $produto->preco_centavos;
            $total = RateioDeDesconto::totalDoItem($milesimos, $unitario);
            $subtotal += $total;
            $itens[] = [
                'produto_id' => $produto->id, 'ordem' => $i + 1, 'quantidade_milesimos' => $milesimos,
                'valor_unitario_centavos' => $unitario, 'total_centavos' => $total,
            ];
        }

        $desconto = (int) $dados['desconto_centavos'];
        $nota->fill([
            'cliente_id' => $dados['cliente_id'],
            'consumidor_final' => $dados['consumidor_final'] ?? null,
            'desconto_centavos' => $desconto,
            'informacoes_complementares' => $dados['informacoes_complementares'] ?? null,
            'subtotal_centavos' => $subtotal,
            'total_centavos' => $subtotal - $desconto,
        ])->save();

        $nota->itens()->delete();
        $nota->itens()->createMany($itens);
        $nota->pagamentos()->delete();
        $nota->pagamentos()->createMany(array_map(fn (array $p): array => [
            'tpag' => $p['tpag'], 'xpag' => $p['xpag'] ?? null, 'valor_centavos' => (int) $p['valor_centavos'],
        ], array_values($dados['pagamentos'])));
    }
}
```

`AtualizarRascunhoDeNota.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class AtualizarRascunhoDeNota
{
    public function __construct(private readonly PoliticaDeNotas $politica, private readonly CriarRascunhoDeNota $criar) {}

    /** @param array<string, mixed> $dados */
    public function executar(Nota $nota, array $dados, Usuario $autor): Nota
    {
        $this->politica->garantirPodeRascunhar($autor);

        return DB::transaction(function () use ($nota, $dados): Nota {
            $nota = Nota::query()->whereKey($nota->id)->lockForUpdate()->firstOrFail();
            if (! $nota->status->editavel()) {
                throw new NotaNaoEditavelException;
            }
            $this->criar->preencher($nota, $dados);

            return $nota->refresh();
        });
    }
}
```

`ExcluirRascunhoDeNota.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Support\Facades\DB;

final class ExcluirRascunhoDeNota
{
    public function executar(Nota $nota): void
    {
        DB::transaction(function () use ($nota): void {
            $nota = Nota::query()->whereKey($nota->id)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Rascunho) {
                throw new NotaNaoEditavelException('Só é possível excluir uma nota em rascunho.');
            }
            $nota->delete();
        });
    }
}
```

(Autorização por papel de `ExcluirRascunhoDeNota` fica no controller com `PoliticaDeNotas::garantirPodeRascunhar`, Tarefa 15.)

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=RascunhoDeNotaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/RascunhoDeNotaTest.php
git commit -m "feat(fiscal): rascunho de nota (criar, atualizar e excluir) e política de papéis"
```

---

### Task 8: Contrato do emissor, DTOs e `EmissorFake`

**Files:**
- Create: `app/Modules/Fiscal/Domain/Emissao/{NotaParaEmissao,EmitenteParaEmissao,DestinatarioParaEmissao,ItemParaEmissao,PagamentoParaEmissao,XmlAssinado,ResultadoDeEmissao,ResultadoDeConsulta}.php`
- Create: `app/Modules/Fiscal/Domain/Contracts/EmissorDeNfe.php`
- Create: `tests/Fixtures/EmissorFake.php`
- Modify: `docs/superpowers/specs/2026-10-01-f3a-nfe-emissao-design.md` (§3.1: contrato em duas etapas)
- Test: `tests/Unit/Fiscal/NotaParaEmissaoTest.php`

**Interfaces:**
- Produces (todos `final readonly`):
  - `EmitenteParaEmissao(string $cnpj, string $razaoSocial, ?string $nomeFantasia, string $ie, int $crt, string $logradouro, string $numero, string $bairro, string $codigoIbge, string $cidade, string $uf, string $cep, ?string $cnae)`.
  - `DestinatarioParaEmissao(string $tipo, string $documento, string $nome, int $indIeDest, int $indFinal, ?string $ie, string $logradouro, string $numero, string $bairro, string $codigoIbge, string $cidade, string $uf, string $cep, ?string $email)`.
  - `ItemParaEmissao(int $ordem, string $sku, string $descricao, string $unidade, ?string $gtin, string $ncm, ?string $cest, string $cfop, int $origem, string $csosn, int $quantidadeMilesimos, int $valorUnitarioCentavos, int $descontoCentavos, int $totalCentavos)`.
  - `PagamentoParaEmissao(string $tpag, ?string $xpag, int $valorCentavos)`.
  - `NotaParaEmissao(string $notaId, string $ambiente, string $serie, int $numero, string $naturezaOperacao, int $idDest, EmitenteParaEmissao $emitente, DestinatarioParaEmissao $destinatario, array $itens, array $pagamentos, int $subtotalCentavos, int $descontoCentavos, int $totalCentavos, ?string $informacoesComplementares)` e `static deNota(Nota $nota, Emitente $emitente, Tenant $tenant): self` (lê só o snapshot; `idDest` = 1 se UF do emitente = UF do destinatário, senão 2).
  - `XmlAssinado(string $chave, string $xml)`.
  - `ResultadoDeEmissao(StatusNota $status, ?string $cstat, ?string $motivo, ?string $protocolo, ?string $xmlAutorizado, ?string $mensagemErro, ?string $chaveOcupante = null)` com fábricas `autorizada(string $protocolo, string $xmlAutorizado, string $cstat = '100')`, `rejeitada(string $cstat, string $motivo)`, `numeroOcupado(string $chaveOcupante, string $motivo)` (REJEITADA, cstat 539), `processando()`, `erro(string $mensagem)`.
  - `ResultadoDeConsulta(SituacaoNaSefaz $situacao, ?string $protocolo, ?string $xmlAutorizado, ?string $cstat, ?string $motivo)` com fábricas `autorizada(...)`, `cancelada()`, `naoEncontrada()`, `erro(string $motivo)`.
  - `EmissorDeNfe`: `preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado`; `transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao`; `consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado): ResultadoDeConsulta`.
  - `Tests\Fixtures\EmissorFake implements EmissorDeNfe`: `public array $chamadas = []` (lista de strings `preparar|transmitir|consultar`); `enfileirarEmissao(ResultadoDeEmissao ...)`, `enfileirarConsulta(ResultadoDeConsulta ...)`, `public ?Throwable $falharEmPreparar`, `public ?Throwable $falharEmTransmitir`; `preparar` devolve `XmlAssinado` com chave de 44 dígitos determinística (`'35'.str_pad((string) $numero, 42, '0', STR_PAD_LEFT)`) e XML `<NFe>fake</NFe>`; `transmitir` e `consultarPorChave` consomem a fila (sem item na fila: `transmitir` devolve `autorizada('135000000000001', '<nfeProc>fake</nfeProc>')`, e `consultarPorChave` devolve `naoEncontrada()`).

- [ ] **Step 1: Teste de `NotaParaEmissao::deNota`**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Emissao\PreparadorDaNota;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\NotaPagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class NotaParaEmissaoTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    public function test_monta_o_dto_a_partir_do_snapshot(): void
    {
        $this->montarCenario();
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        NotaItem::factory()->create(['nota_id' => $nota->id, 'produto_id' => $this->produto->id, 'ordem' => 1, 'quantidade_milesimos' => 2000, 'valor_unitario_centavos' => 1000, 'total_centavos' => 2000]);
        NotaPagamento::query()->create(['nota_id' => $nota->id, 'tpag' => '01', 'valor_centavos' => 2000]);
        app(PreparadorDaNota::class)->preparar($nota, $this->emitente);
        $nota->forceFill(['ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 7])->save();

        $dto = NotaParaEmissao::deNota($nota->refresh(), $this->emitente->refresh(), $this->tenant);

        $this->assertSame(7, $dto->numero);
        $this->assertSame('11444777000161', $dto->emitente->cnpj);
        $this->assertSame(1, $dto->emitente->crt);
        $this->assertSame(1, $dto->idDest);
        $this->assertSame('5102', $dto->itens[0]->cfop);
        $this->assertSame(0, $dto->itens[0]->origem);
        $this->assertSame(2000, $dto->totalCentavos);
        $this->assertSame('01', $dto->pagamentos[0]->tpag);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=NotaParaEmissaoTest`
Expected: FAIL.

- [ ] **Step 3: Implementar DTOs, contrato e `deNota`**

Cada DTO é `final readonly class` com construtor promovido, com os campos exatamente como em **Interfaces**. Os de resultado:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\StatusNota;

/** REJEITADA é decisão da SEFAZ; ERRO é falha técnica ou incerteza. Nunca misturar. */
final readonly class ResultadoDeEmissao
{
    public function __construct(
        public StatusNota $status,
        public ?string $cstat,
        public ?string $motivo,
        public ?string $protocolo,
        public ?string $xmlAutorizado,
        public ?string $mensagemErro,
        public ?string $chaveOcupante = null,
    ) {}

    public static function autorizada(string $protocolo, string $xmlAutorizado, string $cstat = '100'): self
    {
        return new self(StatusNota::Autorizada, $cstat, 'Autorizado o uso da NF-e', $protocolo, $xmlAutorizado, null);
    }

    public static function rejeitada(string $cstat, string $motivo): self
    {
        return new self(StatusNota::Rejeitada, $cstat, $motivo, null, null, null);
    }

    public static function numeroOcupado(string $chaveOcupante, string $motivo): self
    {
        return new self(StatusNota::Rejeitada, '539', $motivo, null, null, null, $chaveOcupante);
    }

    public static function processando(): self
    {
        return new self(StatusNota::Processando, null, null, null, null, null);
    }

    public static function erro(string $mensagem): self
    {
        return new self(StatusNota::Erro, null, null, null, null, $mensagem);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;

final readonly class ResultadoDeConsulta
{
    public function __construct(
        public SituacaoNaSefaz $situacao,
        public ?string $protocolo = null,
        public ?string $xmlAutorizado = null,
        public ?string $cstat = null,
        public ?string $motivo = null,
    ) {}

    public static function autorizada(string $protocolo, ?string $xmlAutorizado, string $cstat = '100'): self
    {
        return new self(SituacaoNaSefaz::Autorizada, $protocolo, $xmlAutorizado, $cstat, 'Autorizado o uso da NF-e');
    }

    public static function cancelada(string $cstat = '101'): self
    {
        return new self(SituacaoNaSefaz::Cancelada, null, null, $cstat, 'Cancelamento de NF-e homologado');
    }

    public static function naoEncontrada(): self
    {
        return new self(SituacaoNaSefaz::NaoEncontrada, null, null, '217', 'NF-e não consta na base de dados da SEFAZ');
    }

    public static function erro(string $motivo): self
    {
        return new self(SituacaoNaSefaz::Erro, null, null, null, $motivo);
    }
}
```

Contrato:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Contracts;

use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Models\Emitente;

/** O motor só traduz formato: nenhuma regra tributária mora aqui. */
interface EmissorDeNfe
{
    /** Monta e assina o XML. Sem rede: a chave pode ser gravada antes de qualquer envio. */
    public function preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado;

    /** Envia e interpreta a resposta. Falha de comunicação lança exceção (vira ERRO no job). */
    public function transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao;

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado): ResultadoDeConsulta;
}
```

`NotaParaEmissao::deNota` (dentro da própria classe):

```php
public static function deNota(Nota $nota, Emitente $emitente, Tenant $tenant): self
{
    $d = $nota->destinatario ?? throw new \LogicException('Nota sem snapshot do destinatário: prepare antes de emitir.');
    $crt = (new CrtResolver)->crt($emitente->regime_tributario);

    $itens = $nota->itens()->get()->map(fn (NotaItem $i): ItemParaEmissao => new ItemParaEmissao(
        $i->ordem, (string) $i->sku, (string) $i->descricao, (string) $i->unidade, $i->gtin, (string) $i->ncm, $i->cest,
        (string) $i->cfop, (int) $i->origem, (string) $i->csosn, $i->quantidade_milesimos, $i->valor_unitario_centavos,
        $i->desconto_centavos, $i->total_centavos,
    ))->all();
    $pagamentos = $nota->pagamentos()->get()->map(fn (NotaPagamento $p): PagamentoParaEmissao => new PagamentoParaEmissao(
        $p->tpag, $p->xpag, $p->valor_centavos,
    ))->all();

    return new self(
        $nota->id, (string) $nota->ambiente, (string) $nota->serie, (int) $nota->numero, $nota->natureza_operacao,
        strtoupper((string) $emitente->uf) === $d['uf'] ? 1 : 2,
        new EmitenteParaEmissao(
            preg_replace('/\D/', '', $tenant->cnpj) ?? '', $tenant->razao_social, $tenant->nome_fantasia,
            preg_replace('/\D/', '', (string) $emitente->inscricao_estadual) ?? '', $crt,
            (string) $emitente->logradouro, (string) $emitente->numero, (string) $emitente->bairro, (string) $emitente->codigo_ibge,
            (string) $emitente->cidade, strtoupper((string) $emitente->uf), (string) $emitente->cep, $emitente->cnae,
        ),
        new DestinatarioParaEmissao(
            $d['tipo'], $d['documento'], $d['nome'], $d['ind_ie_dest'], $d['ind_final'], $d['ie'], $d['logradouro'], $d['numero'],
            $d['bairro'], $d['codigo_ibge'], $d['cidade'], $d['uf'], $d['cep'], $d['email'],
        ),
        $itens, $pagamentos, $nota->subtotal_centavos, $nota->desconto_centavos, $nota->total_centavos, $nota->informacoes_complementares,
    );
}
```

Observação: a IE do emitente `ISENTO` (valor que o `EmitenteFactory::completo` usa por padrão) viraria string vazia após `preg_replace`; o `VerificadorDoEmitente` exige IE preenchida, mas aceita a palavra `ISENTO`? **Não aceita:** o `CenarioDeNota` sobrescreve para `110042490114`. Se o emitente for realmente isento de IE, o spec não cobre na F3a (pendência). A `NotaParaEmissao` repassa a IE só com dígitos.

`EmissorFake`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Throwable;

final class EmissorFake implements EmissorDeNfe
{
    /** @var list<string> */
    public array $chamadas = [];

    public ?Throwable $falharEmPreparar = null;

    public ?Throwable $falharEmTransmitir = null;

    /** @var list<ResultadoDeEmissao> */
    private array $emissoes = [];

    /** @var list<ResultadoDeConsulta> */
    private array $consultas = [];

    public function enfileirarEmissao(ResultadoDeEmissao ...$resultados): void
    {
        array_push($this->emissoes, ...$resultados);
    }

    public function enfileirarConsulta(ResultadoDeConsulta ...$resultados): void
    {
        array_push($this->consultas, ...$resultados);
    }

    public function preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado
    {
        $this->chamadas[] = 'preparar';
        if ($this->falharEmPreparar !== null) {
            throw $this->falharEmPreparar;
        }

        return new XmlAssinado('35'.str_pad((string) $nota->numero, 42, '0', STR_PAD_LEFT), '<NFe>fake</NFe>');
    }

    public function transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao
    {
        $this->chamadas[] = 'transmitir';
        if ($this->falharEmTransmitir !== null) {
            throw $this->falharEmTransmitir;
        }

        return array_shift($this->emissoes) ?? ResultadoDeEmissao::autorizada('135000000000001', '<nfeProc>fake</nfeProc>');
    }

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado): ResultadoDeConsulta
    {
        $this->chamadas[] = 'consultar';

        return array_shift($this->consultas) ?? ResultadoDeConsulta::naoEncontrada();
    }
}
```

No spec, §3.1: troque "Interface `EmissorDeNfe`: `emitir(NotaParaEmissao): ResultadoDeEmissao` e `consultarPorChave(...)`" por "`preparar` (monta e assina, devolve a chave), `transmitir` (envia e interpreta) e `consultarPorChave`", e acrescente em §3.3 que o job grava `chave` e `xml_enviado` entre as duas etapas.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=NotaParaEmissaoTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal/Domain backend/tests docs/superpowers/specs/2026-10-01-f3a-nfe-emissao-design.md
git commit -m "feat(fiscal): contrato do emissor, DTOs de emissão e EmissorFake"
```

---

### Task 9: `IniciarEmissao` (trava, bloqueios, numeração e despacho)

**Files:**
- Create: `app/Modules/Fiscal/Application/Actions/IniciarEmissao.php`
- Create: `app/Modules/Fiscal/Application/Jobs/EmitirNotaJob.php` (esqueleto; o corpo vem na Tarefa 10)
- Test: `tests/Feature/Fiscal/IniciarEmissaoTest.php`

**Interfaces:**
- Consumes: `VerificadorDoEmitente`, `PreparadorDaNota`, `EntitlementService`, `PoliticaDeNotas`, `NotaEvento::registrar`.
- Produces: `IniciarEmissao::executar(string $notaId, Usuario $autor): Nota`. Regras:
  - Já `AUTORIZADA` ou `PROCESSANDO`: devolve a nota sem mudar nada e **sem** despachar.
  - `CANCELADA`: lança `NotaNaoEditavelException`.
  - Exige papel de emitir, módulo `FISCAL_NFE`, `tenant->situacao->permiteEscrita()` (senão `AssinaturaSemEscritaException::para($situacao)`), emitente apto e, em produção, `garantirCapacidade(DocumentosMes)`.
  - Aloca o número (trava `EmitenteSerie`) se a nota não tem número **ou** se o ambiente atual do emitente é diferente do `ambiente` gravado na nota; senão reaproveita.
  - Grava `ambiente`, `serie`, `numero`, `iniciada_em`, zera `chave`, `xml_enviado`, `cstat`, `motivo`, `mensagem_erro`, `tentativas_numero` (só se número novo) e marca `PROCESSANDO` com evento.
  - **Depois** da transação: `EmitirNotaJob::dispatch($nota->id, $nota->tenant_id)`.
- `EmitirNotaJob` (esqueleto): `final class EmitirNotaJob implements ShouldQueue` com `use Dispatchable, InteractsWithQueue, Queueable;`, `public int $tries = 1; public int $timeout = 180;`, construtor `(public readonly string $notaId, public readonly string $tenantId)` e `handle()` vazio por ora.

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class IniciarEmissaoTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        Queue::fake();
    }

    private function rascunho(): Nota
    {
        return app(CriarRascunhoDeNota::class)->executar([
            'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0,
            'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $this->admin);
    }

    public function test_inicia_a_emissao_aloca_numero_e_despacha_o_job(): void
    {
        $nota = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);

        $this->assertSame(StatusNota::Processando, $nota->status);
        $this->assertSame(1, $nota->numero);
        $this->assertSame('1', $nota->serie);
        $this->assertSame('HOMOLOGACAO', $nota->ambiente);
        $this->assertSame(2, $this->emitente->series()->first()->proximo_numero);
        Queue::assertPushed(EmitirNotaJob::class, fn (EmitirNotaJob $j): bool => $j->notaId === $nota->id && $j->tenantId === $this->tenant->id);
    }

    public function test_emissao_duplicada_nao_consome_numero_nem_despacha_segundo_job(): void
    {
        $id = $this->rascunho()->id;
        app(IniciarEmissao::class)->executar($id, $this->admin);
        $segunda = app(IniciarEmissao::class)->executar($id, $this->admin);

        $this->assertSame(StatusNota::Processando, $segunda->status);
        $this->assertSame(2, $this->emitente->series()->first()->proximo_numero);
        Queue::assertPushed(EmitirNotaJob::class, 1);
    }

    public function test_nota_autorizada_nao_reemite(): void
    {
        $nota = $this->rascunho();
        $nota->forceFill(['status' => StatusNota::Autorizada, 'numero' => 5])->save();
        app(IniciarEmissao::class)->executar($nota->id, $this->admin);
        Queue::assertNothingPushed();
    }

    public function test_nota_cancelada_nao_pode_ser_emitida(): void
    {
        $nota = $this->rascunho();
        $nota->forceFill(['status' => StatusNota::Cancelada])->save();
        $this->expectException(NotaNaoEditavelException::class);
        app(IniciarEmissao::class)->executar($nota->id, $this->admin);
    }

    public function test_retry_de_rejeitada_reaproveita_o_numero_e_limpa_o_retorno(): void
    {
        $nota = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
        $nota->forceFill(['status' => StatusNota::Rejeitada, 'cstat' => '999', 'motivo' => 'x', 'chave' => str_repeat('1', 44), 'xml_enviado' => '<x/>'])->save();

        $de_novo = app(IniciarEmissao::class)->executar($nota->id, $this->admin);

        $this->assertSame(1, $de_novo->numero);
        $this->assertSame(2, $this->emitente->series()->first()->proximo_numero);
        $this->assertNull($de_novo->chave);
        $this->assertNull($de_novo->xml_enviado);
        $this->assertNull($de_novo->cstat);
        $this->assertSame(StatusNota::Processando, $de_novo->status);
    }

    public function test_numeros_distintos_para_notas_distintas(): void
    {
        $a = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
        $b = app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
        $this->assertSame([1, 2], [$a->numero, $b->numero]);
    }

    public function test_bloqueio_fiscal_nao_consome_numero_nem_muda_status(): void
    {
        $this->produto->update(['ncm' => null]);
        $nota = $this->rascunho();

        try {
            app(IniciarEmissao::class)->executar($nota->id, $this->admin);
            $this->fail('Esperava bloqueio.');
        } catch (EmissaoBloqueadaException) {
            $this->assertSame(StatusNota::Rascunho, $nota->refresh()->status);
            $this->assertNull($nota->numero);
            $this->assertSame(1, $this->emitente->series()->first()->proximo_numero);
            Queue::assertNothingPushed();
        }
    }

    public function test_usa_o_cadastro_atual_do_cliente_e_congela_o_snapshot(): void
    {
        $nota = $this->rascunho();
        $this->cliente->update(['uf' => 'MG', 'codigo_ibge' => '3106200']);
        $emitida = app(IniciarEmissao::class)->executar($nota->id, $this->admin);
        $this->assertSame('MG', $emitida->destinatario['uf']);

        $this->cliente->update(['uf' => 'RJ', 'codigo_ibge' => '3304557']);
        $emitida->forceFill(['status' => StatusNota::Erro])->save();
        $this->assertSame('MG', $emitida->refresh()->destinatario['uf'], 'snapshot não muda sozinho');
    }

    public function test_exige_papel_modulo_e_situacao(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $id = $this->rascunho()->id;
        try {
            app(IniciarEmissao::class)->executar($id, $vendedor);
            $this->fail('Vendedor não emite.');
        } catch (AcessoNegadoException) {
            $this->addToAssertionCount(1);
        }

        $this->tenant->plano->modulos()->delete();
        $this->tenant->unsetRelation('plano');
        try {
            app(IniciarEmissao::class)->executar($id, $this->admin);
            $this->fail('Sem módulo.');
        } catch (ModuloNaoContratadoException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_assinatura_suspensa_nao_emite(): void
    {
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        $this->expectException(\App\Modules\Tenancy\Domain\Exceptions\AssinaturaSemEscritaException::class);
        app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
    }

    public function test_limite_do_plano_so_vale_em_producao(): void
    {
        $this->tenant->plano->limites()->where('recurso', 'DOCUMENTOS_MES')->update(['limite' => 0]);
        $this->tenant->unsetRelation('plano');

        app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin); // homologação: passa

        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        $this->expectException(LimiteDoPlanoAtingidoException::class);
        app(IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);
    }
}
```

Ajustes na hora de implementar: confirme o namespace real de `AssinaturaSemEscritaException` (`grep -rn "class AssinaturaSemEscritaException" app`) e use o mesmo no `use` do teste e da Action.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=IniciarEmissaoTest`
Expected: FAIL.

- [ ] **Step 3: Esqueleto do job**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class EmitirNotaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $notaId, public readonly string $tenantId) {}

    public function handle(): void
    {
        // Corpo na Tarefa 10.
    }
}
```

- [ ] **Step 4: `IniciarEmissao`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\Emissao\PreparadorDaNota;
use App\Modules\Fiscal\Application\Emissao\VerificadorDoEmitente;
use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaNaoEditavelException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Exceptions\AssinaturaSemEscritaException;
use Illuminate\Support\Facades\DB;

final class IniciarEmissao
{
    public function __construct(
        private readonly PoliticaDeNotas $politica,
        private readonly EntitlementService $planos,
        private readonly VerificadorDoEmitente $verificador,
        private readonly PreparadorDaNota $preparador,
    ) {}

    public function executar(string $notaId, Usuario $autor): Nota
    {
        $this->politica->garantirPodeEmitir($autor);

        $nota = DB::transaction(function () use ($notaId, $autor): array {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();

            if (in_array($nota->status, [StatusNota::Autorizada, StatusNota::Processando], true)) {
                return [$nota, false];
            }
            if (! $nota->status->editavel()) {
                throw new NotaNaoEditavelException('Esta nota não pode ser emitida.');
            }

            $tenant = $autor->tenant;
            if (! $tenant->situacao->permiteEscrita()) {
                throw AssinaturaSemEscritaException::para($tenant->situacao);
            }
            $this->planos->garantirModulo($tenant, Modulo::FiscalNfe);

            $emitente = Emitente::query()->findOrFail($nota->emitente_id);
            $this->verificador->garantirApto($emitente, $tenant);
            if ($emitente->ambiente_fiscal === AmbienteFiscal::Producao) {
                $this->planos->garantirCapacidade($tenant, Recurso::DocumentosMes);
            }

            $this->preparador->preparar($nota, $emitente);

            $ambiente = $emitente->ambiente_fiscal->value;
            $numeroNovo = $nota->numero === null || $nota->ambiente !== $ambiente;
            if ($numeroNovo) {
                $serie = EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', ModeloDocumento::Nfe)->lockForUpdate()->firstOrFail();
                $nota->numero = $serie->proximo_numero;
                $nota->serie = $serie->serie;
                $serie->update(['proximo_numero' => $serie->proximo_numero + 1]);
            }

            $anterior = $nota->status;
            $nota->forceFill([
                'status' => StatusNota::Processando, 'ambiente' => $ambiente, 'iniciada_em' => now(),
                'chave' => null, 'protocolo' => null, 'xml_enviado' => null, 'xml_autorizado' => null,
                'cstat' => null, 'motivo' => null, 'mensagem_erro' => null,
                'tentativas_numero' => $numeroNovo ? 0 : $nota->tentativas_numero,
            ])->save();
            NotaEvento::registrar($nota, $anterior, StatusNota::Processando, "Emissão iniciada (nº {$nota->numero}, série {$nota->serie}, {$ambiente})", $autor->id);

            return [$nota, true];
        });

        [$nota, $despachar] = $nota;
        if ($despachar) {
            EmitirNotaJob::dispatch($nota->id, $nota->tenant_id);
        }

        return $nota->refresh();
    }
}
```

Observação sobre `proximo_numero` ao reaproveitar um número depois de trocar o ambiente: o contador é compartilhado entre ambientes (uma série só), então homologação e produção consomem da mesma fila de números. Isso está de acordo com o spec; registre em `PROGRESSO.md` como decisão.

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter=IniciarEmissaoTest`
Expected: PASS. (`Queue::fake()` impede a execução do job; o corpo do job vem na Tarefa 10.)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/IniciarEmissaoTest.php
git commit -m "feat(fiscal): IniciarEmissao (bloqueios, numeração com lock, idempotência e despacho)"
```

---

### Task 10: `AplicarResultadoDaEmissao` e corpo do `EmitirNotaJob`

**Files:**
- Create: `app/Modules/Fiscal/Application/Actions/AplicarResultadoDaEmissao.php`
- Modify: `app/Modules/Fiscal/Application/Jobs/EmitirNotaJob.php`
- Modify: `app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (binding temporário do `EmissorDeNfe`; o definitivo vem na Tarefa 14)
- Test: `tests/Feature/Fiscal/EmitirNotaJobTest.php`

**Interfaces:**
- Consumes: `EmissorDeNfe`, `NotaParaEmissao::deNota`, `EmissorFake`.
- Produces:
  - `AplicarResultadoDaEmissao::executar(string $notaId, ResultadoDeEmissao $r): Nota`: só age se a nota está `PROCESSANDO` (senão devolve sem mudar). Por status:
    - `Autorizada`: grava `status`, `cstat`, `motivo`, `protocolo`, `xml_autorizado`, `emitido_em`.
    - `Rejeitada` com `cstat === '539'`: delega a `TratarNumeroOcupado` (abaixo).
    - `Rejeitada`: grava `status`, `cstat`, `motivo`.
    - `Erro`: grava `status` e `mensagem_erro`.
    - `Processando`: mantém `PROCESSANDO` e grava `cstat`/`motivo`.
    - Sempre cria `NotaEvento`.
  - `AplicarResultadoDaEmissao::aplicarConsulta(string $notaId, ResultadoDeConsulta $r): Nota` (usado pela reconciliação, Tarefa 11).
  - `TratarNumeroOcupado` (método privado dentro da Action, mesma classe): usa `EmissorDeNfe::consultarPorChave` na `chaveOcupante`. `Cancelada` e `tentativas_numero < config('fiscal.tentativas_numero_ocupado')`: aloca o próximo número (lock na `EmitenteSerie`), incrementa `tentativas_numero`, zera `chave`/`xml_enviado`, mantém `PROCESSANDO` e **redespacha** `EmitirNotaJob`. `Autorizada`: `ERRO` com mensagem que cita a chave ("O número X já foi autorizado na SEFAZ pela chave ...; concilie manualmente"). Qualquer outra situação ou tentativas esgotadas: `ERRO`.
  - `EmitirNotaJob::handle(EmissorDeNfe $emissor, AplicarResultadoDaEmissao $aplicar, TenantContext $contexto)`:
    1. `$contexto->set($this->tenantId)`; carrega a nota e o emitente; se `status !== Processando`, retorna.
    2. Se a nota já tem `chave` (reenvio depois de erro): `consultarPorChave`; `Autorizada` → `aplicarConsulta` e retorna; `Cancelada` → `ERRO`; senão segue.
    3. `try { dto = NotaParaEmissao::deNota(...); xml = emissor->preparar(...); grava chave e xml_enviado; resultado = emissor->transmitir(...); aplicar->executar(...) } catch (Throwable $e) { report($e); aplicar->executar($id, ResultadoDeEmissao::erro('Falha técnica ao emitir: '.mensagem curta)) }`.
    4. `finally`: `$contexto->clear()`.

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class EmitirNotaJobTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    /** Com QUEUE_CONNECTION=sync dos testes, `IniciarEmissao` já executa o job. */
    private function emitir(): Nota
    {
        $nota = app(CriarRascunhoDeNota::class)->executar([
            'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0,
            'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $this->admin);

        return app(IniciarEmissao::class)->executar($nota->id, $this->admin)->refresh();
    }

    public function test_autorizada_grava_protocolo_xml_e_data(): void
    {
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('135000000000001', $nota->protocolo);
        $this->assertSame('<nfeProc>fake</nfeProc>', $nota->xml_autorizado);
        $this->assertSame('<NFe>fake</NFe>', $nota->xml_enviado);
        $this->assertNotNull($nota->chave);
        $this->assertNotNull($nota->emitido_em);
        $this->assertSame(['preparar', 'transmitir'], $this->emissor->chamadas);
    }

    public function test_rejeitada_grava_cstat_e_motivo_e_preserva_o_numero(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('232', 'IE do destinatário não informada'));
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Rejeitada, $nota->status);
        $this->assertSame('232', $nota->cstat);
        $this->assertSame('IE do destinatário não informada', $nota->motivo);
        $this->assertSame(1, $nota->numero);
    }

    public function test_excecao_inesperada_vira_erro_e_nunca_rejeitada(): void
    {
        $this->emissor->falharEmTransmitir = new RuntimeException('cURL error 28: timeout');
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('timeout', (string) $nota->mensagem_erro);
        $this->assertNotNull($nota->chave, 'a chave foi gravada antes do envio');
        $this->assertSame(1, $nota->numero);
    }

    public function test_falha_ao_preparar_vira_erro_sem_chave(): void
    {
        $this->emissor->falharEmPreparar = new RuntimeException('XSD: elemento inválido');
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertNull($nota->chave);
    }

    public function test_resultado_processando_mantem_processando(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::processando());
        $this->assertSame(StatusNota::Processando, $this->emitir()->status);
    }

    public function test_retry_com_chave_consulta_antes_de_reenviar_e_concilia_autorizada(): void
    {
        $nota = $this->emitir();
        $nota->forceFill(['status' => StatusNota::Erro, 'chave' => str_repeat('3', 44), 'xml_enviado' => '<NFe>x</NFe>'])->save();
        $this->emissor->chamadas = [];
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000009', '<nfeProc>real</nfeProc>'));

        $de_novo = app(IniciarEmissao::class)->executar($nota->id, $this->admin)->refresh();

        $this->assertSame(StatusNota::Autorizada, $de_novo->status);
        $this->assertSame('135000000000009', $de_novo->protocolo);
        $this->assertSame(['consultar'], $this->emissor->chamadas, 'não reenviou uma nota já autorizada');
    }

    public function test_numero_ocupado_por_nota_cancelada_avanca_e_reemite(): void
    {
        $this->emissor->enfileirarEmissao(
            ResultadoDeEmissao::numeroOcupado(str_repeat('9', 44), 'Duplicidade de NF-e'),
            ResultadoDeEmissao::autorizada('135000000000002', '<nfeProc>ok</nfeProc>'),
        );
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::cancelada());

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame(2, $nota->numero, 'pulou o número ocupado por nota cancelada');
        $this->assertSame(1, $nota->tentativas_numero);
    }

    public function test_numero_ocupado_por_nota_autorizada_vira_erro_para_conciliacao_manual(): void
    {
        $chave = str_repeat('8', 44);
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::numeroOcupado($chave, 'Duplicidade de NF-e'));
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000003', null));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString($chave, (string) $nota->mensagem_erro);
        $this->assertSame(1, $nota->numero, 'não avançou: criaria uma duplicata real');
    }

    public function test_numero_ocupado_respeita_o_limite_de_tentativas(): void
    {
        config(['fiscal.tentativas_numero_ocupado' => 2]);
        $this->emissor->enfileirarEmissao(...array_fill(0, 5, ResultadoDeEmissao::numeroOcupado(str_repeat('7', 44), 'Duplicidade')));
        $this->emissor->enfileirarConsulta(...array_fill(0, 5, ResultadoDeConsulta::cancelada()));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertSame(2, $nota->tentativas_numero);
    }

    public function test_job_ignora_nota_que_nao_esta_processando(): void
    {
        $nota = $this->emitir();
        $this->emissor->chamadas = [];
        (new \App\Modules\Fiscal\Application\Jobs\EmitirNotaJob($nota->id, $this->tenant->id))->handle(
            $this->emissor, app(\App\Modules\Fiscal\Application\Actions\AplicarResultadoDaEmissao::class), app(\App\Modules\Tenancy\Domain\TenantContext::class),
        );
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_eventos_registram_a_trilha(): void
    {
        $nota = $this->emitir();
        $this->assertSame(['RASCUNHO', 'PROCESSANDO', 'AUTORIZADA'], $nota->eventos()->pluck('status_para')->all());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=EmitirNotaJobTest`
Expected: FAIL.

- [ ] **Step 3: `AplicarResultadoDaEmissao`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use Illuminate\Support\Facades\DB;

/** Única porta de saída de uma emissão: toda mudança de status vinda da SEFAZ passa por aqui, com lock. */
final class AplicarResultadoDaEmissao
{
    public function __construct(private readonly EmissorDeNfe $emissor) {}

    public function executar(string $notaId, ResultadoDeEmissao $r): Nota
    {
        $redespachar = false;
        $nota = DB::transaction(function () use ($notaId, $r, &$redespachar): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Processando) {
                return $nota;
            }

            if ($r->status === StatusNota::Rejeitada && $r->cstat === '539') {
                $redespachar = $this->tratarNumeroOcupado($nota, $r);

                return $nota;
            }

            $anterior = $nota->status;
            $campos = ['status' => $r->status, 'cstat' => $r->cstat, 'motivo' => $r->motivo];
            if ($r->status === StatusNota::Autorizada) {
                $campos += ['protocolo' => $r->protocolo, 'xml_autorizado' => $r->xmlAutorizado, 'emitido_em' => now()];
            }
            if ($r->status === StatusNota::Erro) {
                $campos['mensagem_erro'] = $r->mensagemErro;
            }
            $nota->forceFill($campos)->save();

            if ($r->status !== StatusNota::Processando) {
                NotaEvento::registrar($nota, $anterior, $r->status, $r->mensagemErro ?? ($r->cstat !== null ? "cStat {$r->cstat}: {$r->motivo}" : null));
            }

            return $nota;
        });

        if ($redespachar) {
            EmitirNotaJob::dispatch($nota->id, $nota->tenant_id);
        }

        return $nota->refresh();
    }

    /** Usado pela reconciliação (Tarefa 11) e pelo reenvio com chave existente. */
    public function aplicarConsulta(string $notaId, ResultadoDeConsulta $r): Nota
    {
        return DB::transaction(function () use ($notaId, $r): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Processando && $nota->status !== StatusNota::Erro) {
                return $nota;
            }
            $anterior = $nota->status;

            if ($r->situacao === SituacaoNaSefaz::Autorizada) {
                if ($r->xmlAutorizado === null) {
                    $nota->forceFill(['status' => StatusNota::Erro, 'mensagem_erro' => "A nota consta como autorizada na SEFAZ (protocolo {$r->protocolo}), mas o XML não pôde ser recomposto. Baixe o XML no portal da SEFAZ."])->save();
                    NotaEvento::registrar($nota, $anterior, StatusNota::Erro, $nota->mensagem_erro);

                    return $nota;
                }
                $nota->forceFill([
                    'status' => StatusNota::Autorizada, 'cstat' => $r->cstat, 'motivo' => $r->motivo, 'protocolo' => $r->protocolo,
                    'xml_autorizado' => $r->xmlAutorizado, 'emitido_em' => $nota->emitido_em ?? now(), 'mensagem_erro' => null,
                ])->save();
                NotaEvento::registrar($nota, $anterior, StatusNota::Autorizada, 'Conciliada com a SEFAZ');
            } elseif ($r->situacao === SituacaoNaSefaz::Cancelada) {
                $nota->forceFill(['status' => StatusNota::Cancelada, 'cstat' => $r->cstat, 'motivo' => $r->motivo])->save();
                NotaEvento::registrar($nota, $anterior, StatusNota::Cancelada, 'Cancelamento encontrado na SEFAZ');
            }

            return $nota;
        });
    }

    /** @return bool true quando o job deve ser despachado de novo com o próximo número */
    private function tratarNumeroOcupado(Nota $nota, ResultadoDeEmissao $r): bool
    {
        $chave = (string) $r->chaveOcupante;
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        $consulta = $this->emissor->consultarPorChave($emitente, $chave, null);
        $limite = (int) config('fiscal.tentativas_numero_ocupado');

        if ($consulta->situacao === SituacaoNaSefaz::Cancelada && $nota->tentativas_numero < $limite) {
            $serie = EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', ModeloDocumento::Nfe)->lockForUpdate()->firstOrFail();
            $nota->forceFill([
                'numero' => $serie->proximo_numero, 'tentativas_numero' => $nota->tentativas_numero + 1,
                'chave' => null, 'xml_enviado' => null, 'cstat' => '539', 'motivo' => $r->motivo,
            ])->save();
            $serie->update(['proximo_numero' => $serie->proximo_numero + 1]);
            NotaEvento::registrar($nota, StatusNota::Processando, StatusNota::Processando, "Número ocupado por nota cancelada ({$chave}); avançou para o {$nota->numero}");

            return true;
        }

        $mensagem = $consulta->situacao === SituacaoNaSefaz::Autorizada
            ? "O número {$nota->numero} já foi autorizado na SEFAZ pela chave {$chave}. Concilie manualmente antes de emitir de novo."
            : "Não foi possível liberar um número para a série (cStat 539, chave ocupante {$chave}). Verifique a numeração em Configurações > Fiscal.";
        $nota->forceFill(['status' => StatusNota::Erro, 'cstat' => '539', 'motivo' => $r->motivo, 'mensagem_erro' => $mensagem])->save();
        NotaEvento::registrar($nota, StatusNota::Processando, StatusNota::Erro, $mensagem);

        return false;
    }
}
```

`NotaParaEmissao` fica sem uso nesse arquivo: remova o `use` não utilizado se o Pint reclamar.

- [ ] **Step 4: Corpo do job**

```php
public function handle(EmissorDeNfe $emissor, AplicarResultadoDaEmissao $aplicar, TenantContext $contexto): void
{
    $contexto->set($this->tenantId);
    try {
        $nota = Nota::query()->find($this->notaId);
        if ($nota === null || $nota->status !== StatusNota::Processando) {
            return;
        }
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);

        if ($nota->chave !== null) {
            $consulta = $emissor->consultarPorChave($emitente, $nota->chave, $nota->xml_enviado);
            if ($consulta->situacao === SituacaoNaSefaz::Autorizada) {
                $aplicar->aplicarConsulta($nota->id, $consulta);

                return;
            }
            if ($consulta->situacao === SituacaoNaSefaz::Cancelada) {
                $aplicar->executar($nota->id, ResultadoDeEmissao::erro("A chave {$nota->chave} consta como cancelada na SEFAZ. Edite e emita novamente."));

                return;
            }
        }

        $tenant = Tenant::query()->findOrFail($nota->tenant_id);
        $dto = NotaParaEmissao::deNota($nota, $emitente, $tenant);
        $xml = $emissor->preparar($emitente, $dto);
        $nota->forceFill(['chave' => $xml->chave, 'xml_enviado' => $xml->xml])->save();

        $aplicar->executar($nota->id, $emissor->transmitir($emitente, $dto, $xml));
    } catch (Throwable $e) {
        report($e);
        $aplicar->executar($this->notaId, ResultadoDeEmissao::erro('Falha técnica ao emitir: '.mb_substr($e->getMessage(), 0, 300)));
    } finally {
        $contexto->clear();
    }
}
```

Imports necessários: `EmissorDeNfe`, `AplicarResultadoDaEmissao`, `NotaParaEmissao`, `ResultadoDeEmissao`, `SituacaoNaSefaz`, `StatusNota`, `Emitente`, `Nota`, `Tenant`, `TenantContext`, `Throwable`. O `Tenant` não tem `BelongsToTenant`, então `Tenant::query()->findOrFail` funciona sem contexto.

- [ ] **Step 5: Binding provisório**

No `FiscalServiceProvider::register()` (crie o método se não existir): `$this->app->bind(EmissorDeNfe::class, fn () => throw new \LogicException('Emissor NFePHP ainda não configurado.'));` — a Tarefa 14 troca pelo binding real. Os testes sobrescrevem com `$this->app->instance(...)`.

- [ ] **Step 6: Rodar e ver passar**

Run: `php artisan test --filter="EmitirNotaJobTest|IniciarEmissaoTest"`
Expected: PASS. No `IniciarEmissaoTest` o `Queue::fake()` continua evitando a execução do job.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/EmitirNotaJobTest.php
git commit -m "feat(fiscal): aplicação do resultado da emissão, job de emissão e tratamento do cStat 539"
```

---

### Task 11: Reconciliação (`ConciliarNota` e comando agendado)

**Files:**
- Create: `app/Modules/Fiscal/Application/Actions/ConciliarNota.php`
- Create: `app/Modules/Fiscal/Console/ReconciliarNotasCommand.php`
- Modify: `app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (registrar o comando, igual ao `PlatformServiceProvider`), `routes/console.php`
- Test: `tests/Feature/Fiscal/ReconciliarNotasTest.php`

**Interfaces:**
- Produces:
  - `ConciliarNota::executar(Nota $nota): Nota`: nota `PROCESSANDO` com `chave`: `consultarPorChave`; `Autorizada` ou `Cancelada` → `aplicarConsulta`; `NaoEncontrada` e `iniciada_em` há mais que `config('fiscal.minutos_para_nunca_enviada')` minutos → `ERRO` ("A SEFAZ não recebeu a nota... emita novamente; o número foi preservado"); `Erro` da consulta → não muda (tenta no próximo ciclo). Nota `PROCESSANDO` **sem chave** há mais que o limite → `ERRO` ("A emissão não chegou a ser enviada..."). Dentro do prazo → não muda.
  - Comando `fiscal:reconciliar-processando`: percorre `Nota::withoutTenantScope()->where('status', PROCESSANDO)`, para cada uma `TenantContext::set(tenant_id)`, chama `ConciliarNota`, captura exceções por nota (`report`) e limpa o contexto; imprime `N notas verificadas`.
  - Agendamento: `Schedule::command('fiscal:reconciliar-processando')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();`.

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class ReconciliarNotasTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    private function processando(?string $chave, int $minutosAtras): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Processando, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => random_int(1, 9999),
            'chave' => $chave, 'xml_enviado' => $chave === null ? null : '<NFe>x</NFe>', 'iniciada_em' => now()->subMinutes($minutosAtras),
        ])->save();

        return $nota;
    }

    private function rodar(): void
    {
        $this->artisan('fiscal:reconciliar-processando')->assertSuccessful();
        app(TenantContext::class)->set($this->tenant->id);
    }

    public function test_concilia_nota_autorizada_na_sefaz(): void
    {
        $nota = $this->processando(str_repeat('1', 44), 30);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000010', '<nfeProc>x</nfeProc>'));

        $this->rodar();

        $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);
        $this->assertSame('135000000000010', $nota->protocolo);
    }

    public function test_marca_cancelada_quando_a_sefaz_diz_cancelada(): void
    {
        $nota = $this->processando(str_repeat('2', 44), 30);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::cancelada());
        $this->rodar();
        $this->assertSame(StatusNota::Cancelada, $nota->refresh()->status);
    }

    public function test_nao_encontrada_depois_do_prazo_vira_erro_e_preserva_o_numero(): void
    {
        $nota = $this->processando(str_repeat('3', 44), 30);
        $numero = $nota->numero;
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::naoEncontrada());
        $this->rodar();

        $this->assertSame(StatusNota::Erro, $nota->refresh()->status);
        $this->assertSame($numero, $nota->numero);
    }

    public function test_nao_encontrada_dentro_do_prazo_continua_processando(): void
    {
        $nota = $this->processando(str_repeat('4', 44), 2);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::naoEncontrada());
        $this->rodar();
        $this->assertSame(StatusNota::Processando, $nota->refresh()->status);
    }

    public function test_nunca_enviada_depois_do_prazo_vira_erro_sem_consultar(): void
    {
        $nota = $this->processando(null, 30);
        $this->rodar();

        $this->assertSame(StatusNota::Erro, $nota->refresh()->status);
        $this->assertStringContainsString('não chegou a ser enviada', (string) $nota->mensagem_erro);
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_nunca_enviada_dentro_do_prazo_e_deixada_em_paz(): void
    {
        $nota = $this->processando(null, 3);
        $this->rodar();
        $this->assertSame(StatusNota::Processando, $nota->refresh()->status);
    }

    public function test_erro_na_consulta_nao_muda_a_nota(): void
    {
        $nota = $this->processando(str_repeat('5', 44), 30);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::erro('timeout'));
        $this->rodar();
        $this->assertSame(StatusNota::Processando, $nota->refresh()->status);
    }

    public function test_varre_notas_de_todos_os_tenants_sem_vazar_contexto(): void
    {
        $nota = $this->processando(str_repeat('6', 44), 30);
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        // contexto do outro tenant ativo: o comando precisa enxergar a nota do primeiro mesmo assim
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000011', '<nfeProc/>'));

        $this->artisan('fiscal:reconciliar-processando')->assertSuccessful();

        $this->assertSame(StatusNota::Autorizada, Nota::withoutTenantScope()->findOrFail($nota->id)->status);
        $this->assertNull(app(TenantContext::class)->id(), 'o comando limpa o contexto');
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=ReconciliarNotasTest`
Expected: FAIL (comando inexistente).

- [ ] **Step 3: `ConciliarNota`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;

final class ConciliarNota
{
    public function __construct(private readonly EmissorDeNfe $emissor, private readonly AplicarResultadoDaEmissao $aplicar) {}

    public function executar(Nota $nota): Nota
    {
        if ($nota->status !== StatusNota::Processando) {
            return $nota;
        }

        $vencida = $nota->iniciada_em === null
            || $nota->iniciada_em->lt(now()->subMinutes((int) config('fiscal.minutos_para_nunca_enviada')));

        if ($nota->chave === null) {
            return $vencida
                ? $this->aplicar->executar($nota->id, ResultadoDeEmissao::erro('A emissão não chegou a ser enviada à SEFAZ. Emita novamente: o número foi preservado.'))
                : $nota;
        }

        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        $consulta = $this->emissor->consultarPorChave($emitente, $nota->chave, $nota->xml_enviado);

        return match ($consulta->situacao) {
            SituacaoNaSefaz::Autorizada, SituacaoNaSefaz::Cancelada => $this->aplicar->aplicarConsulta($nota->id, $consulta),
            SituacaoNaSefaz::NaoEncontrada => $vencida
                ? $this->aplicar->executar($nota->id, ResultadoDeEmissao::erro('A SEFAZ não recebeu a nota. Emita novamente: o número foi preservado.'))
                : $nota,
            SituacaoNaSefaz::Erro => $nota,
        };
    }
}
```

- [ ] **Step 4: Comando e agendamento**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Console;

use App\Modules\Fiscal\Application\Actions\ConciliarNota;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Console\Command;
use Throwable;

final class ReconciliarNotasCommand extends Command
{
    protected $signature = 'fiscal:reconciliar-processando';

    protected $description = 'Concilia com a SEFAZ as notas que ficaram em PROCESSANDO (inclusive as nunca enviadas).';

    public function handle(ConciliarNota $conciliar, TenantContext $contexto): int
    {
        $notas = Nota::withoutTenantScope()->where('status', StatusNota::Processando)->orderBy('iniciada_em')->get();

        foreach ($notas as $nota) {
            $contexto->set($nota->tenant_id);
            try {
                $conciliar->executar($nota);
            } catch (Throwable $e) {
                report($e);
            } finally {
                $contexto->clear();
            }
        }

        $this->info("{$notas->count()} notas verificadas.");

        return self::SUCCESS;
    }
}
```

No `FiscalServiceProvider::boot()` acrescente `if ($this->app->runningInConsole()) { $this->commands([ReconciliarNotasCommand::class]); }` (igual ao `PlatformServiceProvider`). Em `routes/console.php`:

```php
Schedule::command('fiscal:reconciliar-processando')->everyFifteenMinutes()
    ->withoutOverlapping()->onOneServer();
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter=ReconciliarNotasTest`
Expected: PASS. Confirme com `php artisan schedule:list` que a tarefa aparece a cada 15 minutos.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Fiscal backend/routes/console.php backend/tests/Feature/Fiscal/ReconciliarNotasTest.php
git commit -m "feat(fiscal): reconciliação de notas PROCESSANDO a cada 15 minutos"
```

---

### Task 12: Motor NFePHP, parte 1: `CertificadoStore` e `MontadorDeXml` (+ validação contra o XSD)

**Files:**
- Create: `app/Modules/Fiscal/Infrastructure/NfePhp/{CertificadoStore,MontadorDeXml}.php`
- Test: `tests/Unit/Fiscal/MontadorDeXmlTest.php`

**Interfaces:**
- Consumes: `NotaParaEmissao` e DTOs (Tarefa 8), `TabelaIbge`, `Quantidade`.
- Produces:
  - `CertificadoStore::paraEmitente(Emitente $emitente): \NFePHP\Common\Certificate` (decifra `certificado_pfx_encrypted` e `certificado_senha_encrypted` em memória; nunca grava em disco nem loga).
  - `MontadorDeXml::montar(NotaParaEmissao $nota, ?string $cNF = null): array{xml: string, chave: string}`: XML **não assinado** pela `Make`; lança `RuntimeException` com os erros da `Make` se `getErrors()` não estiver vazio.

**Antes de começar (obrigatório):** confira as assinaturas reais instaladas, porque variam por versão.

- [ ] **Step 1: Conferir o vendor**

Run (em `backend/`): `grep -rn "public function tag" vendor/nfephp-org/sped-nfe/src/Make.php vendor/nfephp-org/sped-nfe/src/Traits/*.php | grep -E "taginfNFe|tagide|tagemit|tagenderEmit|tagdest|tagenderDest|tagprod|tagimposto|tagICMSSN|tagPIS|tagCOFINS|tagICMSTot|tagtransp|tagpag|tagdetPag|taginfAdic"`
Confirme que todos existem com um único parâmetro `\stdClass`. Se a versão instalada diferir de 5.2.x e algum método mudou, ajuste o código abaixo e registre no `PROGRESSO.md`. Confirme também o caminho do XSD: `ls vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/ | head`.

- [ ] **Step 2: Teste (monta, assina com certificado autoassinado de teste e valida contra o XSD)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Emissao\DestinatarioParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\EmitenteParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ItemParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\PagamentoParaEmissao;
use App\Modules\Fiscal\Infrastructure\NfePhp\MontadorDeXml;
use NFePHP\Common\Certificate;
use NFePHP\Common\Validator;
use NFePHP\NFe\Tools;
use PHPUnit\Framework\TestCase;

class MontadorDeXmlTest extends TestCase
{
    private function nota(string $nomeItem = 'Produto Um', ?string $infCpl = null, int $descontoItem = 0): NotaParaEmissao
    {
        $emitente = new EmitenteParaEmissao('11444777000161', 'Empresa Teste LTDA', null, '110042490114', 1, 'Rua Teste', '100', 'Centro', '3550308', 'São Paulo', 'SP', '01001000', '4520001');
        $dest = new DestinatarioParaEmissao('PF', '52998224725', 'Maria Teste', 9, 1, null, 'Rua A', '10', 'Centro', '3550308', 'São Paulo', 'SP', '01001000', null);
        $total = 2500 - $descontoItem;
        $item = new ItemParaEmissao(1, 'P-1', $nomeItem, 'UN', null, '12345678', null, '5102', 0, '102', 2500, 1000, $descontoItem, 2500);

        return new NotaParaEmissao('nota-1', 'HOMOLOGACAO', '1', 7, 'Venda de mercadoria', 1, $emitente, $dest, [$item], [new PagamentoParaEmissao('01', null, $total)], 2500, $descontoItem, $total, $infCpl);
    }

    private function certificadoDeTeste(): Certificate
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'EMPRESA TESTE:11444777000161'], $chave);
        $cert = openssl_csr_sign($csr, null, $chave, 365);
        openssl_pkcs12_export($cert, $pfx, $chave, 'teste');

        return Certificate::readPfx($pfx, 'teste');
    }

    private function assinarEValidar(string $xml): void
    {
        $tools = new Tools(json_encode([
            'atualizacao' => '2026-10-02 00:00:00', 'tpAmb' => 2, 'razaosocial' => 'Empresa Teste LTDA', 'siglaUF' => 'SP',
            'cnpj' => '11444777000161', 'schemes' => 'PL_009_V4', 'versao' => '4.00',
        ], JSON_THROW_ON_ERROR), $this->certificadoDeTeste());
        $tools->model(55);
        $assinado = $tools->signNFe($xml);

        $xsd = dirname(__DIR__, 3).'/vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/nfe_v4.00.xsd';
        $this->assertTrue(Validator::isValid($assinado, $xsd), 'XML fora do XSD: '.json_encode(Validator::$errors ?? []));
    }

    public function test_monta_xml_valido_contra_o_xsd_e_com_chave_de_44_digitos(): void
    {
        ['xml' => $xml, 'chave' => $chave] = (new MontadorDeXml)->montar($this->nota(), '12345678');

        $this->assertMatchesRegularExpression('/^\d{44}$/', $chave);
        $this->assertStringStartsWith('35', $chave, 'cUF de SP');
        $this->assertStringContainsString('<mod>55</mod>', $xml);
        $this->assertStringContainsString('<CSOSN>102</CSOSN>', $xml);
        $this->assertStringContainsString('<cEAN>SEM GTIN</cEAN>', $xml);
        $this->assertStringContainsString('<vProd>25.00</vProd>', $xml);
        $this->assertStringContainsString('<qCom>2.5000</qCom>', $xml);
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<CRT>1</CRT>', $xml);
        $this->assertStringNotContainsString('<CST>', $xml, 'Simples usa CSOSN, nunca CST');
        // Estrutura da chave: cUF(2) AAMM(4) CNPJ(14) mod(2) série(3) nNF(9) tpEmis(1) cNF(8) cDV(1)
        $this->assertSame('11444777000161', substr($chave, 6, 14));
        $this->assertSame('55', substr($chave, 20, 2), 'modelo');
        $this->assertSame('001', substr($chave, 22, 3), 'série 1');
        $this->assertSame('000000007', substr($chave, 25, 9), 'número 7');
        $this->assertSame('12345678', substr($chave, 35, 8), 'cNF');
        $this->assertStringContainsString('<cNF>12345678</cNF>', $xml);
        $this->assertStringContainsString('Id="NFe'.$chave.'"', $xml);
        $this->assinarEValidar($xml);
    }

    public function test_caracteres_especiais_e_acentos_nao_quebram_o_xml(): void
    {
        $nota = $this->nota('Pão & Café <"especial"> ção ‘aspas’', "Obs: R\$ 10 & 'x' <b> \u{1F600} \t tab\nquebra");
        ['xml' => $xml] = (new MontadorDeXml)->montar($nota, '12345678');

        $this->assertNotFalse(simplexml_load_string($xml), 'XML bem formado');
        $this->assinarEValidar($xml);
    }

    public function test_desconto_do_item_vai_em_vDesc(): void
    {
        ['xml' => $xml] = (new MontadorDeXml)->montar($this->nota(descontoItem: 100), '12345678');
        $this->assertStringContainsString('<vDesc>1.00</vDesc>', $xml);
        $this->assertStringContainsString('<vNF>24.00</vNF>', $xml);
        $this->assinarEValidar($xml);
    }

    public function test_cnpj_do_emitente_vazio_lanca_excecao(): void
    {
        $n = $this->nota();
        $semCnpj = new NotaParaEmissao($n->notaId, $n->ambiente, $n->serie, $n->numero, $n->naturezaOperacao, $n->idDest,
            new EmitenteParaEmissao('', 'X', null, '123', 1, 'R', '1', 'B', '3550308', 'SP', 'SP', '01001000', null),
            $n->destinatario, $n->itens, $n->pagamentos, $n->subtotalCentavos, $n->descontoCentavos, $n->totalCentavos, null);

        $this->expectException(\RuntimeException::class);
        (new MontadorDeXml)->montar($semCnpj);
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --filter=MontadorDeXmlTest`
Expected: FAIL (classe inexistente).

- [ ] **Step 4: `CertificadoStore`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Illuminate\Support\Facades\Crypt;
use NFePHP\Common\Certificate;

/** O `.pfx` e a senha ficam criptografados no banco e só existem decifrados em memória, dentro desta chamada. */
final class CertificadoStore
{
    public function paraEmitente(Emitente $emitente): Certificate
    {
        if ($emitente->certificado_pfx_encrypted === null || $emitente->certificado_senha_encrypted === null) {
            throw new EmissaoBloqueadaException('FISCAL_CERTIFICADO_INVALIDO', 'Envie o certificado A1 em Configurações > Fiscal.');
        }

        $pfx = base64_decode(Crypt::decryptString($emitente->certificado_pfx_encrypted), true);
        if ($pfx === false) {
            throw new EmissaoBloqueadaException('FISCAL_CERTIFICADO_INVALIDO', 'O certificado armazenado está corrompido. Envie-o novamente.');
        }

        return Certificate::readPfx($pfx, Crypt::decryptString($emitente->certificado_senha_encrypted));
    }
}
```

(`ProcessarCertificado` grava `Crypt::encryptString(base64_encode($binario))`, por isso o `base64_decode` aqui.)

- [ ] **Step 5: `MontadorDeXml`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Quantidade;
use App\Modules\Fiscal\Domain\TabelaIbge;
use NFePHP\NFe\Make;
use RuntimeException;
use stdClass;

/** Só traduz formato: tudo que chega aqui já foi resolvido e validado (CFOP, CSOSN, indIEDest...). */
final class MontadorDeXml
{
    /** @return array{xml: string, chave: string} */
    public function montar(NotaParaEmissao $nota, ?string $cNF = null): array
    {
        $e = $nota->emitente;
        if (trim($e->cnpj) === '') {
            throw new RuntimeException('CNPJ do emitente vazio: a Make geraria a chave com zeros.');
        }
        $cUF = TabelaIbge::codigoDaUf($e->uf);
        $tpAmb = $nota->ambiente === 'PRODUCAO' ? 1 : 2;

        $make = new Make;
        $make->taginfNFe($this->std(['versao' => '4.00', 'Id' => null, 'pk_nItem' => '']));

        $ide = ['cUF' => $cUF, 'natOp' => $this->texto($nota->naturezaOperacao, 60), 'mod' => 55, 'serie' => (int) $nota->serie, 'nNF' => $nota->numero,
            'dhEmi' => now()->setTimezone('America/Sao_Paulo')->format('c'), 'tpNF' => 1, 'idDest' => $nota->idDest, 'cMunFG' => $e->codigoIbge,
            'tpImp' => 1, 'tpEmis' => 1, 'tpAmb' => $tpAmb, 'finNFe' => 1, 'indFinal' => $nota->destinatario->indFinal, 'indPres' => 1,
            'procEmi' => 0, 'verProc' => config('fiscal.ver_proc')];
        if ($cNF !== null) {
            $ide['cNF'] = $cNF;
        }
        $make->tagide($this->std($ide));

        $make->tagemit($this->std(['CNPJ' => $e->cnpj, 'xNome' => $this->texto($e->razaoSocial, 60), 'xFant' => $e->nomeFantasia === null ? null : $this->texto($e->nomeFantasia, 60), 'IE' => $e->ie, 'CRT' => $e->crt]));
        $make->tagenderEmit($this->std([
            'xLgr' => $this->texto($e->logradouro, 60), 'nro' => $this->texto($e->numero, 60), 'xBairro' => $this->texto($e->bairro, 60),
            'cMun' => $e->codigoIbge, 'xMun' => $this->texto($e->cidade, 60), 'UF' => $e->uf, 'CEP' => $e->cep, 'cPais' => 1058, 'xPais' => 'Brasil',
        ]));

        $d = $nota->destinatario;
        $dest = ['xNome' => $this->texto($d->nome, 60), 'indIEDest' => $d->indIeDest];
        $dest[strlen($d->documento) > 11 ? 'CNPJ' : 'CPF'] = $d->documento;
        if ($d->indIeDest === 1 && $d->ie !== null) {
            $dest['IE'] = $d->ie;
        }
        if ($d->email !== null) {
            $dest['email'] = $d->email;
        }
        $make->tagdest($this->std($dest));
        $make->tagenderDest($this->std([
            'xLgr' => $this->texto($d->logradouro, 60), 'nro' => $this->texto($d->numero, 60), 'xBairro' => $this->texto($d->bairro, 60),
            'cMun' => $d->codigoIbge, 'xMun' => $this->texto($d->cidade, 60), 'UF' => $d->uf, 'CEP' => $d->cep, 'cPais' => 1058, 'xPais' => 'Brasil',
        ]));

        foreach ($nota->itens as $i) {
            $vUn = $this->reais($i->valorUnitarioCentavos);
            $prod = ['item' => $i->ordem, 'cProd' => $this->texto($i->sku, 60), 'cEAN' => $i->gtin ?? 'SEM GTIN', 'cEANTrib' => $i->gtin ?? 'SEM GTIN',
                'xProd' => $this->texto($i->descricao, 120), 'NCM' => $i->ncm, 'CFOP' => $i->cfop, 'uCom' => $i->unidade, 'uTrib' => $i->unidade,
                'qCom' => Quantidade::formatar($i->quantidadeMilesimos, 4), 'qTrib' => Quantidade::formatar($i->quantidadeMilesimos, 4),
                'vUnCom' => $vUn, 'vUnTrib' => $vUn, 'vProd' => $this->reais($i->totalCentavos), 'indTot' => 1];
            if ($i->cest !== null) {
                $prod['CEST'] = $i->cest;
            }
            if ($i->descontoCentavos > 0) {
                $prod['vDesc'] = $this->reais($i->descontoCentavos);
            }
            $make->tagprod($this->std($prod));
            $make->tagimposto($this->std(['item' => $i->ordem]));
            $make->tagICMSSN($this->std(['item' => $i->ordem, 'orig' => $i->origem, 'CSOSN' => $i->csosn]));
            $make->tagPIS($this->std(['item' => $i->ordem, 'CST' => '49', 'vBC' => 0, 'pPIS' => 0, 'vPIS' => 0]));
            $make->tagCOFINS($this->std(['item' => $i->ordem, 'CST' => '49', 'vBC' => 0, 'pCOFINS' => 0, 'vCOFINS' => 0]));
        }

        $make->tagICMSTot($this->std([
            'vBC' => 0, 'vICMS' => 0, 'vICMSDeson' => 0, 'vFCP' => 0, 'vBCST' => 0, 'vST' => 0, 'vFCPST' => 0, 'vFCPSTRet' => 0,
            'vProd' => $this->reais($nota->subtotalCentavos), 'vFrete' => 0, 'vSeg' => 0, 'vDesc' => $this->reais($nota->descontoCentavos),
            'vII' => 0, 'vIPI' => 0, 'vIPIDevol' => 0, 'vPIS' => 0, 'vCOFINS' => 0, 'vOutro' => 0, 'vNF' => $this->reais($nota->totalCentavos),
        ]));
        $make->tagtransp($this->std(['modFrete' => 9]));
        $make->tagpag($this->std([]));
        foreach ($nota->pagamentos as $p) {
            $det = ['indPag' => 0, 'tPag' => $p->tpag, 'vPag' => $this->reais($p->valorCentavos)];
            if ($p->tpag === '99') {
                $det['xPag'] = $this->texto((string) $p->xpag, 60);
            }
            $make->tagdetPag($this->std($det));
        }
        if ($nota->informacoesComplementares !== null && trim($nota->informacoesComplementares) !== '') {
            $make->taginfAdic($this->std(['infCpl' => $this->texto($nota->informacoesComplementares, 5000)]));
        }

        $xml = $make->getXML();
        $erros = $make->getErrors();
        if ($erros !== []) {
            throw new RuntimeException('Falha ao montar o XML da NF-e: '.implode(' | ', $erros));
        }

        return ['xml' => $xml, 'chave' => $make->getChave()];
    }

    /** @param array<string, mixed> $campos */
    private function std(array $campos): stdClass
    {
        return (object) $campos;
    }

    /** Dinheiro em centavos inteiros para "0.00", sem float. */
    private function reais(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Sanitiza texto livre ao que o schema aceita: sem caracteres de controle, espaços colapsados, corte no limite.
     * O escape de `&`, `<` e aspas fica a cargo da DOM da biblioteca.
     */
    private function texto(string $valor, int $limite): string
    {
        $limpo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valor) ?? '';
        $limpo = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $limpo) ?? '';
        $limpo = trim((string) preg_replace('/\s+/u', ' ', $limpo));

        return mb_substr($limpo, 0, $limite);
    }
}
```

Atenção: `tagICMSTot` e `tagpag` variam por versão; se `getErrors()` apontar campo ausente ou desconhecido, ajuste só os campos citados (confira o `$possible` do método no vendor). O `tagimposto` pode ser omitido nas versões em que `tagICMSSN` já o cria. `vDesc` zerado em `tagICMSTot` é `0.00`; use `$this->reais(0)` se a lib exigir string.

- [ ] **Step 6: Rodar, ajustar contra o vendor e ver passar**

Run: `php artisan test --filter=MontadorDeXmlTest`
Expected: PASS, com o XML assinado válido no XSD. Se a validação falhar, o teste imprime os erros do `Validator`; corrija o campo apontado (nunca desligue a validação).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/Fiscal/Infrastructure backend/tests/Unit/Fiscal/MontadorDeXmlTest.php
git commit -m "feat(fiscal): montagem do XML da NF-e com a Make e validação contra o XSD"
```

---

### Task 13: Motor NFePHP, parte 2: `InterpretadorDeResposta` e `NfePhpEmissor`

**Files:**
- Create: `app/Modules/Fiscal/Infrastructure/NfePhp/{InterpretadorDeResposta,NfePhpEmissor}.php`
- Modify: `app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (binding real)
- Test: `tests/Unit/Fiscal/InterpretadorDeRespostaTest.php`

**Interfaces:**
- Consumes: `MontadorDeXml`, `CertificadoStore`, DTOs.
- Produces:
  - `InterpretadorDeResposta::emissao(string $xmlAssinado, string $respostaXml): ResultadoDeEmissao` e `consulta(string $respostaXml, ?string $xmlEnviado): ResultadoDeConsulta` (puros: sem rede).
  - `NfePhpEmissor implements EmissorDeNfe` (`preparar` monta e assina; `transmitir` assina já feito e envia com `sefazEnviaLote(..., indSinc=1)`; `consultarPorChave` usa `sefazConsultaChave`).

- [ ] **Step 1: Conferir o vendor**

Run: `grep -n "public function sefazEnviaLote\|public function sefazConsultaChave\|public function signNFe" vendor/nfephp-org/sped-nfe/src/Tools.php vendor/nfephp-org/sped-nfe/src/Common/Tools.php; grep -n "public static function toAuthorize" vendor/nfephp-org/sped-nfe/src/Complements.php`
Confirme as assinaturas (`sefazEnviaLote(array $aXml, string $idLote = '', int $indSinc = 0, bool $compactar = false, &$xmls = [])`; `Complements::toAuthorize(string $request, string $response, ?string $modelo = null)`).

- [ ] **Step 2: Teste do interpretador, com respostas XML escritas à mão (sem dado real)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Infrastructure\NfePhp\InterpretadorDeResposta;
use PHPUnit\Framework\TestCase;

class InterpretadorDeRespostaTest extends TestCase
{
    private const CHAVE = '35261011444777000161550010000000071123456789';

    private function envio(string $cStatProt, string $xMotivo = 'Autorizado o uso da NF-e', bool $comProt = true, string $cStatLote = '104'): string
    {
        $prot = $comProt
            ? '<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><chNFe>'.self::CHAVE."</chNFe><dhRecbto>2026-10-02T10:00:00-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>x</digVal><cStat>{$cStatProt}</cStat><xMotivo>{$xMotivo}</xMotivo></infProt></protNFe>"
            : '';

        return '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><verAplic>SP</verAplic>'
            ."<cStat>{$cStatLote}</cStat><xMotivo>Lote processado</xMotivo><cUF>35</cUF><dhRecbto>2026-10-02T10:00:00-03:00</dhRecbto>{$prot}</retEnviNFe>";
    }

    private function nfeAssinada(): string
    {
        return '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe'.self::CHAVE.'" versao="4.00"/></NFe>';
    }

    public function test_cstat_100_autoriza_e_monta_o_nfeproc(): void
    {
        $r = (new InterpretadorDeResposta)->emissao($this->nfeAssinada(), $this->envio('100'));

        $this->assertSame(StatusNota::Autorizada, $r->status);
        $this->assertSame('135260000000001', $r->protocolo);
        $this->assertStringContainsString('<nfeProc', (string) $r->xmlAutorizado);
        $this->assertStringContainsString('<protNFe', (string) $r->xmlAutorizado);
    }

    public function test_outro_cstat_no_protocolo_e_rejeicao_da_sefaz(): void
    {
        $r = (new InterpretadorDeResposta)->emissao($this->nfeAssinada(), $this->envio('232', 'Rejeicao: IE do destinatario nao informada'));

        $this->assertSame(StatusNota::Rejeitada, $r->status);
        $this->assertSame('232', $r->cstat);
        $this->assertStringContainsString('IE do destinatario', (string) $r->motivo);
    }

    public function test_539_devolve_a_chave_ocupante(): void
    {
        $motivo = 'Rejeicao: Duplicidade de NF-e, com diferenca na Chave de Acesso [chNFe:35261011444777000161550010000000079999999999]';
        $r = (new InterpretadorDeResposta)->emissao($this->nfeAssinada(), $this->envio('539', $motivo));

        $this->assertSame('539', $r->cstat);
        $this->assertSame('35261011444777000161550010000000079999999999', $r->chaveOcupante);
    }

    public function test_lote_sem_protocolo_com_rejeicao_de_lote_e_rejeitada(): void
    {
        $r = (new InterpretadorDeResposta)->emissao($this->nfeAssinada(), $this->envio('', 'Rejeicao: Lote invalido', false, '225'));
        $this->assertSame(StatusNota::Rejeitada, $r->status);
        $this->assertSame('225', $r->cstat);
    }

    public function test_lote_em_processamento_mantem_processando(): void
    {
        $r = (new InterpretadorDeResposta)->emissao($this->nfeAssinada(), $this->envio('', 'Lote em processamento', false, '105'));
        $this->assertSame(StatusNota::Processando, $r->status);
    }

    public function test_resposta_ilegivel_vira_erro(): void
    {
        $r = (new InterpretadorDeResposta)->emissao($this->nfeAssinada(), 'isto nao e xml');
        $this->assertSame(StatusNota::Erro, $r->status);
    }

    public function test_consulta_100_autorizada_com_xml_recomposto(): void
    {
        $xml = '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><verAplic>SP</verAplic><cStat>100</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo><cUF>35</cUF><dhRecbto>2026-10-02T10:00:00-03:00</dhRecbto><chNFe>'.self::CHAVE.'</chNFe>'
            .'<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><chNFe>'.self::CHAVE.'</chNFe><dhRecbto>2026-10-02T10:00:00-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>x</digVal><cStat>100</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo></infProt></protNFe></retConsSitNFe>';

        $r = (new InterpretadorDeResposta)->consulta($xml, $this->nfeAssinada());

        $this->assertSame(SituacaoNaSefaz::Autorizada, $r->situacao);
        $this->assertSame('135260000000001', $r->protocolo);
        $this->assertStringContainsString('<nfeProc', (string) $r->xmlAutorizado);
    }

    public function test_consulta_autorizada_sem_xml_enviado_nao_inventa_xml(): void
    {
        $xml = '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><cStat>100</cStat><xMotivo>Autorizado</xMotivo><protNFe versao="4.00"><infProt><nProt>135260000000001</nProt><cStat>100</cStat></infProt></protNFe></retConsSitNFe>';
        $r = (new InterpretadorDeResposta)->consulta($xml, null);
        $this->assertSame(SituacaoNaSefaz::Autorizada, $r->situacao);
        $this->assertNull($r->xmlAutorizado);
    }

    public function test_consulta_cancelada_nao_encontrada_e_outros(): void
    {
        $i = new InterpretadorDeResposta;
        $mk = fn (string $c): string => '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><cStat>'.$c.'</cStat><xMotivo>m</xMotivo></retConsSitNFe>';

        $this->assertSame(SituacaoNaSefaz::Cancelada, $i->consulta($mk('101'), null)->situacao);
        $this->assertSame(SituacaoNaSefaz::Cancelada, $i->consulta($mk('151'), null)->situacao);
        $this->assertSame(SituacaoNaSefaz::NaoEncontrada, $i->consulta($mk('217'), null)->situacao);
        $this->assertSame(SituacaoNaSefaz::Erro, $i->consulta($mk('999'), null)->situacao);
        $this->assertSame(SituacaoNaSefaz::Erro, $i->consulta('lixo', null)->situacao);
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --filter=InterpretadorDeRespostaTest`
Expected: FAIL.

- [ ] **Step 4: `InterpretadorDeResposta`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use NFePHP\NFe\Complements;
use SimpleXMLElement;
use Throwable;

/** Funções puras: traduzem a resposta da SEFAZ nos resultados do domínio. Nada de rede aqui. */
final class InterpretadorDeResposta
{
    public function emissao(string $xmlAssinado, string $respostaXml): ResultadoDeEmissao
    {
        $resp = $this->carregar($respostaXml);
        if ($resp === null) {
            return ResultadoDeEmissao::erro('Resposta ilegível da SEFAZ ao autorizar a nota.');
        }
        $resp->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $prot = $resp->xpath('//n:protNFe/n:infProt')[0] ?? null;

        if ($prot === null) {
            $cStat = (string) ($resp->xpath('//n:cStat')[0] ?? '');
            $motivo = (string) ($resp->xpath('//n:xMotivo')[0] ?? '');
            if (in_array($cStat, ['103', '105'], true)) {
                return ResultadoDeEmissao::processando();
            }

            return $cStat === '' ? ResultadoDeEmissao::erro('Resposta da SEFAZ sem protocolo nem status.') : ResultadoDeEmissao::rejeitada($cStat, $motivo);
        }

        $cStat = (string) $prot->cStat;
        $motivo = (string) $prot->xMotivo;

        if ($cStat === '100') {
            try {
                return ResultadoDeEmissao::autorizada((string) $prot->nProt, Complements::toAuthorize($xmlAssinado, $respostaXml));
            } catch (Throwable $e) {
                return ResultadoDeEmissao::erro('A SEFAZ autorizou a nota (protocolo '.(string) $prot->nProt.') mas o XML autorizado não pôde ser montado: '.$e->getMessage());
            }
        }

        if ($cStat === '539' && preg_match('/chNFe:(\d{44})/', $motivo, $m) === 1) {
            return ResultadoDeEmissao::numeroOcupado($m[1], $motivo);
        }

        return ResultadoDeEmissao::rejeitada($cStat, $motivo);
    }

    public function consulta(string $respostaXml, ?string $xmlEnviado): ResultadoDeConsulta
    {
        $resp = $this->carregar($respostaXml);
        if ($resp === null) {
            return ResultadoDeConsulta::erro('Resposta ilegível da SEFAZ na consulta.');
        }
        $resp->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $cStat = (string) ($resp->xpath('//n:retConsSitNFe/n:cStat | /n:retConsSitNFe/n:cStat')[0] ?? $resp->xpath('//n:cStat')[0] ?? '');
        $motivo = (string) ($resp->xpath('//n:xMotivo')[0] ?? '');

        return match (true) {
            $cStat === '100' => $this->autorizadaNaConsulta($resp, $respostaXml, $xmlEnviado),
            in_array($cStat, ['101', '151'], true) => ResultadoDeConsulta::cancelada($cStat),
            $cStat === '217' => ResultadoDeConsulta::naoEncontrada(),
            default => ResultadoDeConsulta::erro("cStat {$cStat}: {$motivo}"),
        };
    }

    private function autorizadaNaConsulta(SimpleXMLElement $resp, string $respostaXml, ?string $xmlEnviado): ResultadoDeConsulta
    {
        $protocolo = (string) ($resp->xpath('//n:protNFe/n:infProt/n:nProt')[0] ?? '');
        $xml = null;
        if ($xmlEnviado !== null) {
            try {
                $xml = Complements::toAuthorize($xmlEnviado, $respostaXml);
            } catch (Throwable) {
                $xml = null;
            }
        }

        return ResultadoDeConsulta::autorizada($protocolo, $xml);
    }

    private function carregar(string $xml): ?SimpleXMLElement
    {
        $anterior = libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return $obj === false ? null : $obj;
    }
}
```

Observações: (1) `Complements::toAuthorize` no teste `test_cstat_100...` usa um `NFe` mínimo; se a lib recusar um XML sem a estrutura completa, use no teste uma NF-e assinada gerada como no `MontadorDeXmlTest` (extraia o helper de assinatura para uma trait `Tests\Fixtures\AssinaNfeDeTeste`). (2) A expressão XPath da consulta é redundante de propósito; se o PHPStan ou o teste reclamarem, simplifique para `$resp->xpath('//n:cStat')[0]`, que pega o primeiro `cStat` do documento (o do `retConsSitNFe`).

- [ ] **Step 5: `NfePhpEmissor`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Tenancy\Domain\Models\Tenant;
use NFePHP\NFe\Tools;

/**
 * Fala direto com a SEFAZ da UF do emitente, com o certificado A1 do próprio CNPJ.
 * Falha de comunicação (SoapException, timeout) propaga como exceção: o job a transforma em ERRO.
 */
final class NfePhpEmissor implements EmissorDeNfe
{
    public function __construct(
        private readonly CertificadoStore $certificados,
        private readonly MontadorDeXml $montador,
        private readonly InterpretadorDeResposta $interpretador,
    ) {}

    public function preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado
    {
        ['xml' => $xml, 'chave' => $chave] = $this->montador->montar($nota);
        $assinado = $this->tools($emitente, $nota->emitente->razaoSocial, $nota->emitente->cnpj, $nota->ambiente)->signNFe($xml);

        return new XmlAssinado($chave, $assinado);
    }

    public function transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao
    {
        $tools = $this->tools($emitente, $nota->emitente->razaoSocial, $nota->emitente->cnpj, $nota->ambiente);
        $resposta = $tools->sefazEnviaLote([$xml->xml], (string) $nota->numero, 1);

        return $this->interpretador->emissao($xml->xml, $resposta);
    }

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado): ResultadoDeConsulta
    {
        $tenant = Tenant::query()->findOrFail($emitente->tenant_id);
        $tools = $this->tools($emitente, $tenant->razao_social, preg_replace('/\D/', '', $tenant->cnpj) ?? '', $emitente->ambiente_fiscal->value);

        return $this->interpretador->consulta($tools->sefazConsultaChave($chave), $xmlEnviado);
    }

    private function tools(Emitente $emitente, string $razaoSocial, string $cnpj, string $ambiente): Tools
    {
        $tools = new Tools(json_encode([
            'atualizacao' => now()->format('Y-m-d H:i:s'),
            'tpAmb' => $ambiente === 'PRODUCAO' ? 1 : 2,
            'razaosocial' => $razaoSocial,
            'siglaUF' => strtoupper((string) $emitente->uf),
            'cnpj' => $cnpj,
            'schemes' => 'PL_009_V4',
            'versao' => '4.00',
        ], JSON_THROW_ON_ERROR), $this->certificados->paraEmitente($emitente));
        $tools->model(55);

        return $tools;
    }
}
```

- [ ] **Step 6: Binding real**

No `FiscalServiceProvider::register()`, troque o binding provisório por `$this->app->bind(EmissorDeNfe::class, NfePhpEmissor::class);` (o container injeta `CertificadoStore`, `MontadorDeXml` e `InterpretadorDeResposta`, todos sem dependências).

- [ ] **Step 7: Rodar e ver passar**

Run: `php artisan test --filter="InterpretadorDeRespostaTest|MontadorDeXmlTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests
git commit -m "feat(fiscal): NfePhpEmissor e interpretador das respostas da SEFAZ"
```

---

### Task 14: Endpoints `/api/app/notas` (requests, resource, controller, rotas)

**Files:**
- Create: `app/Modules/Fiscal/Http/Requests/SalvarNotaRequest.php`
- Create: `app/Modules/Fiscal/Http/Resources/{NotaResource,NotaStatusResource}.php`
- Create: `app/Modules/Fiscal/Http/Controllers/NotaController.php`
- Modify: `app/Modules/Fiscal/Http/routes-app.php`
- Test: `tests/Feature/Fiscal/NotasApiTest.php`

**Interfaces:**
- Consumes: Actions das Tarefas 7, 9; `PoliticaDeNotas`; `EmissorFake` nos testes.
- Produces: rotas (todas dentro do grupo `empresa`):
  - `GET /notas` (query `status`, `de`, `ate` no formato `AAAA-MM-DD`, `page`; 25 por página, mais recentes primeiro), `GET /notas/{nota}`, `GET /notas/{nota}/status`, `POST /notas` (201), `PUT /notas/{nota}`, `DELETE /notas/{nota}` (204), `POST /notas/{nota}/emitir` (202 com `NotaStatusResource`), `GET /notas/{nota}/xml` (download `NFe-<numero>.xml`, só `AUTORIZADA`; senão 409 `NOTA_SEM_XML`).
  - Corpo de `POST`/`PUT /notas`: `cliente_id` (uuid existente), `consumidor_final` (nullable boolean), `desconto_centavos` (integer min 0), `informacoes_complementares` (nullable string max 5000), `itens` (array min 1, max 990; cada `produto_id` uuid existente, `quantidade` string regex `^\d{1,9}(\.\d{1,3})?$`, `valor_unitario_centavos` nullable integer min 0), `pagamentos` (array min 1; cada `tpag` in `01,03,04,17,99`, `xpag` nullable string max 60, `valor_centavos` integer min 1).
  - `NotaResource`: `id, status, ambiente, numero, serie, chave, protocolo, cstat, motivo, mensagem_erro, natureza_operacao, consumidor_final, subtotal_centavos, desconto_centavos, total_centavos, informacoes_complementares, emitido_em, created_at, cliente {id, nome}`, `itens[]`, `pagamentos[]`, `eventos[]` (quando carregados). **Nunca** expõe `xml_enviado` nem `xml_autorizado`.
  - `NotaStatusResource`: `id, status, numero, serie, chave, cstat, motivo, mensagem_erro`.
  - O route model binding usa o Global Scope: nota de outro tenant dá 404.

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class NotasApiTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    /** @return array<string, mixed> */
    private function corpo(array $sobrescrever = []): array
    {
        return array_replace([
            'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0, 'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $sobrescrever);
    }

    private function criar(): string
    {
        return $this->spa()->actingAs($this->admin)->postJson('/api/app/notas', $this->corpo())->assertCreated()->json('data.id');
    }

    public function test_cria_lista_e_mostra_rascunho(): void
    {
        $id = $this->criar();

        $this->spa()->actingAs($this->admin)->getJson('/api/app/notas')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'RASCUNHO');
        $this->spa()->actingAs($this->admin)->getJson("/api/app/notas/{$id}")->assertOk()
            ->assertJsonPath('data.total_centavos', 1000)->assertJsonPath('data.cliente.nome', 'Maria Teste')
            ->assertJsonCount(1, 'data.itens')->assertJsonCount(1, 'data.pagamentos');
    }

    public function test_filtra_por_status(): void
    {
        $id = $this->criar();
        Nota::query()->findOrFail($id)->forceFill(['status' => StatusNota::Autorizada])->save();
        $this->criar();

        $this->spa()->actingAs($this->admin)->getJson('/api/app/notas?status=AUTORIZADA')->assertOk()->assertJsonCount(1, 'data');
        $this->spa()->actingAs($this->admin)->getJson('/api/app/notas?status=INVALIDO')->assertStatus(422);
    }

    public function test_validacao_do_corpo(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/notas', $this->corpo(['itens' => []]))->assertStatus(422)->assertJsonValidationErrors('itens');
        $this->spa()->actingAs($this->admin)->postJson('/api/app/notas', $this->corpo(['pagamentos' => [['tpag' => '77', 'valor_centavos' => 1000]]]))->assertStatus(422);
        $this->spa()->actingAs($this->admin)->postJson('/api/app/notas', $this->corpo(['itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1,5']]]))->assertStatus(422);
        $this->spa()->actingAs($this->admin)->postJson('/api/app/notas', $this->corpo(['cliente_id' => '00000000-0000-0000-0000-000000000000']))->assertStatus(422);
    }

    public function test_emitir_responde_202_e_autoriza_com_o_fake(): void
    {
        $id = $this->criar();

        $this->spa()->actingAs($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202)->assertJsonStructure(['data' => ['id', 'status']]);

        $this->spa()->actingAs($this->admin)->getJson("/api/app/notas/{$id}/status")->assertOk()
            ->assertJsonPath('data.status', 'AUTORIZADA')->assertJsonPath('data.numero', 1);
    }

    public function test_bloqueio_fiscal_vira_422_com_codigo(): void
    {
        $this->produto->update(['ncm' => null]);
        $id = $this->criar();

        $this->spa()->actingAs($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(422)
            ->assertJsonPath('codigo', 'FISCAL_PRODUTO_INCOMPLETO');
    }

    public function test_rejeicao_da_sefaz_fica_no_recurso(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('232', 'IE do destinatário não informada'));
        $id = $this->criar();
        $this->spa()->actingAs($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);

        $this->spa()->actingAs($this->admin)->getJson("/api/app/notas/{$id}")->assertJsonPath('data.status', 'REJEITADA')
            ->assertJsonPath('data.cstat', '232')->assertJsonPath('data.motivo', 'IE do destinatário não informada');
    }

    public function test_rejeitada_pode_ser_editada_e_reemitida(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('232', 'x'));
        $id = $this->criar();
        $this->spa()->actingAs($this->admin)->postJson("/api/app/notas/{$id}/emitir");

        $this->spa()->actingAs($this->admin)->putJson("/api/app/notas/{$id}", $this->corpo(['desconto_centavos' => 0]))->assertOk();
        $this->spa()->actingAs($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);
        $this->spa()->actingAs($this->admin)->getJson("/api/app/notas/{$id}/status")->assertJsonPath('data.status', 'AUTORIZADA')->assertJsonPath('data.numero', 1);
    }

    public function test_autorizada_nao_e_editavel_nem_excluivel(): void
    {
        $id = $this->criar();
        $this->spa()->actingAs($this->admin)->postJson("/api/app/notas/{$id}/emitir");

        $this->spa()->actingAs($this->admin)->putJson("/api/app/notas/{$id}", $this->corpo())->assertStatus(409)->assertJsonPath('codigo', 'NOTA_NAO_EDITAVEL');
        $this->spa()->actingAs($this->admin)->deleteJson("/api/app/notas/{$id}")->assertStatus(409);
    }

    public function test_exclui_rascunho(): void
    {
        $id = $this->criar();
        $this->spa()->actingAs($this->admin)->deleteJson("/api/app/notas/{$id}")->assertNoContent();
        $this->spa()->actingAs($this->admin)->getJson("/api/app/notas/{$id}")->assertNotFound();
    }

    public function test_baixa_o_xml_so_de_nota_autorizada_e_nunca_o_expoe_no_json(): void
    {
        $id = $this->criar();
        $this->spa()->actingAs($this->admin)->get("/api/app/notas/{$id}/xml")->assertStatus(409)->assertJsonPath('codigo', 'NOTA_SEM_XML');

        $this->spa()->actingAs($this->admin)->postJson("/api/app/notas/{$id}/emitir");
        $resp = $this->spa()->actingAs($this->admin)->get("/api/app/notas/{$id}/xml")->assertOk();
        $this->assertSame('<nfeProc>fake</nfeProc>', $resp->streamedContent() ?: $resp->getContent());
        $this->assertStringContainsString('NFe-1.xml', (string) $resp->headers->get('Content-Disposition'));

        $json = $this->spa()->actingAs($this->admin)->getJson("/api/app/notas/{$id}")->getContent();
        $this->assertStringNotContainsString('nfeProc', (string) $json);
    }

    public function test_papeis(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        $fiscal = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Fiscal]);
        $id = $this->criar();

        $this->spa()->actingAs($vendedor)->postJson('/api/app/notas', $this->corpo())->assertCreated();
        $this->spa()->actingAs($vendedor)->postJson("/api/app/notas/{$id}/emitir")->assertForbidden();
        $this->spa()->actingAs($leitura)->getJson('/api/app/notas')->assertOk();
        $this->spa()->actingAs($leitura)->postJson('/api/app/notas', $this->corpo())->assertForbidden();
        $this->spa()->actingAs($leitura)->putJson("/api/app/notas/{$id}", $this->corpo())->assertForbidden();
        $this->spa()->actingAs($leitura)->deleteJson("/api/app/notas/{$id}")->assertForbidden();
        $this->spa()->actingAs($fiscal)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);
    }

    public function test_isolamento_entre_tenants(): void
    {
        $id = $this->criar();
        $outro = Tenant::factory()->for(Plano::factory())->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($intruso)->getJson('/api/app/notas')->assertOk()->assertJsonCount(0, 'data');
        $this->spa()->actingAs($intruso)->getJson("/api/app/notas/{$id}")->assertNotFound();
        $this->spa()->actingAs($intruso)->getJson("/api/app/notas/{$id}/status")->assertNotFound();
        $this->spa()->actingAs($intruso)->putJson("/api/app/notas/{$id}", $this->corpo())->assertNotFound();
        $this->spa()->actingAs($intruso)->deleteJson("/api/app/notas/{$id}")->assertNotFound();
        $this->spa()->actingAs($intruso)->postJson("/api/app/notas/{$id}/emitir")->assertNotFound();
        $this->spa()->actingAs($intruso)->get("/api/app/notas/{$id}/xml")->assertNotFound();
    }

    public function test_cliente_e_produto_de_outro_tenant_sao_rejeitados(): void
    {
        $outro = Tenant::factory()->for(Plano::factory())->create();
        app(\App\Modules\Tenancy\Domain\TenantContext::class)->set($outro->id);
        $clienteAlheio = \App\Modules\Customers\Domain\Models\Cliente::factory()->create();
        $produtoAlheio = \App\Modules\Catalog\Domain\Models\Produto::factory()->completo()->create();
        app(\App\Modules\Tenancy\Domain\TenantContext::class)->set($this->tenant->id);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/notas', $this->corpo(['cliente_id' => $clienteAlheio->id]))->assertStatus(422);
        $this->spa()->actingAs($this->admin)->postJson('/api/app/notas', $this->corpo(['itens' => [['produto_id' => $produtoAlheio->id, 'quantidade' => '1']]]))->assertStatus(422);
    }

    public function test_sem_autenticacao_401(): void
    {
        $this->spa()->getJson('/api/app/notas')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=NotasApiTest`
Expected: FAIL (404 nas rotas).

- [ ] **Step 3: `SalvarNotaRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SalvarNotaRequest extends FormRequest
{
    /**
     * `exists` com o Rule do tenant: o Global Scope não vale em regras de validação, então a checagem
     * filtra por `tenant_id` explicitamente.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'cliente_id' => ['required', 'uuid', Rule::exists('clientes', 'id')->where('tenant_id', $tenantId)],
            'consumidor_final' => ['nullable', 'boolean'],
            'desconto_centavos' => ['required', 'integer', 'min:0'],
            'informacoes_complementares' => ['nullable', 'string', 'max:5000'],
            'itens' => ['required', 'array', 'min:1', 'max:990'],
            'itens.*.produto_id' => ['required', 'uuid', Rule::exists('produtos', 'id')->where('tenant_id', $tenantId)],
            'itens.*.quantidade' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'itens.*.valor_unitario_centavos' => ['nullable', 'integer', 'min:0'],
            'pagamentos' => ['required', 'array', 'min:1'],
            'pagamentos.*.tpag' => ['required', Rule::in(['01', '03', '04', '17', '99'])],
            'pagamentos.*.xpag' => ['nullable', 'string', 'max:60'],
            'pagamentos.*.valor_centavos' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        /** @var array<string, mixed> $validado */
        $validado = $this->validated();

        return $validado;
    }
}
```

Atenção: `quantidade` chega como string; se o frontend enviar número JSON, o `string` falha. O frontend (Tarefa 18) sempre envia string.

- [ ] **Step 4: Resources**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Nota */
final class NotaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'ambiente' => $this->ambiente,
            'numero' => $this->numero,
            'serie' => $this->serie,
            'chave' => $this->chave,
            'protocolo' => $this->protocolo,
            'cstat' => $this->cstat,
            'motivo' => $this->motivo,
            'mensagem_erro' => $this->mensagem_erro,
            'natureza_operacao' => $this->natureza_operacao,
            'consumidor_final' => $this->consumidor_final,
            'subtotal_centavos' => $this->subtotal_centavos,
            'desconto_centavos' => $this->desconto_centavos,
            'total_centavos' => $this->total_centavos,
            'informacoes_complementares' => $this->informacoes_complementares,
            'emitido_em' => $this->emitido_em?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'cliente' => $this->whenLoaded('cliente', fn (): array => ['id' => $this->cliente->id, 'nome' => $this->cliente->nome]),
            'itens' => $this->whenLoaded('itens', fn () => $this->itens->map(fn ($i): array => [
                'id' => $i->id, 'produto_id' => $i->produto_id, 'ordem' => $i->ordem, 'sku' => $i->sku, 'descricao' => $i->descricao,
                'unidade' => $i->unidade, 'quantidade' => \App\Modules\Fiscal\Domain\Quantidade::formatar($i->quantidade_milesimos, 3),
                'valor_unitario_centavos' => $i->valor_unitario_centavos, 'desconto_centavos' => $i->desconto_centavos, 'total_centavos' => $i->total_centavos,
                'cfop' => $i->cfop, 'csosn' => $i->csosn,
            ])->all()),
            'pagamentos' => $this->whenLoaded('pagamentos', fn () => $this->pagamentos->map(fn ($p): array => [
                'tpag' => $p->tpag, 'xpag' => $p->xpag, 'valor_centavos' => $p->valor_centavos,
            ])->all()),
            'eventos' => $this->whenLoaded('eventos', fn () => $this->eventos->map(fn ($e): array => [
                'status_de' => $e->status_de, 'status_para' => $e->status_para, 'detalhe' => $e->detalhe, 'em' => $e->created_at->toIso8601String(),
            ])->all()),
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Nota */
final class NotaStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'status' => $this->status->value, 'numero' => $this->numero, 'serie' => $this->serie,
            'chave' => $this->chave, 'cstat' => $this->cstat, 'motivo' => $this->motivo, 'mensagem_erro' => $this->mensagem_erro,
        ];
    }
}
```

Troque o FQCN inline de `Quantidade` por um `use` no topo do arquivo.

- [ ] **Step 5: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\AtualizarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\ExcluirRascunhoDeNota;
use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Http\Requests\SalvarNotaRequest;
use App\Modules\Fiscal\Http\Resources\NotaResource;
use App\Modules\Fiscal\Http\Resources\NotaStatusResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class NotaController
{
    public function __construct(private readonly PoliticaDeNotas $politica) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $dados = $request->validate([
            'status' => ['nullable', Rule::in(array_column(StatusNota::cases(), 'value'))],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $notas = Nota::query()->with('cliente')
            ->when($dados['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($dados['de'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($dados['ate'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('created_at')->paginate(25);

        return NotaResource::collection($notas);
    }

    public function show(Nota $nota): NotaResource
    {
        return new NotaResource($nota->load(['cliente', 'itens', 'pagamentos', 'eventos']));
    }

    public function status(Nota $nota): NotaStatusResource
    {
        return new NotaStatusResource($nota);
    }

    public function store(SalvarNotaRequest $request, CriarRascunhoDeNota $acao): JsonResponse
    {
        $nota = $acao->executar($request->dados(), $this->autor($request));

        return (new NotaResource($nota->load(['cliente', 'itens', 'pagamentos', 'eventos'])))->response()->setStatusCode(201);
    }

    public function update(SalvarNotaRequest $request, Nota $nota, AtualizarRascunhoDeNota $acao): NotaResource
    {
        $nota = $acao->executar($nota, $request->dados(), $this->autor($request));

        return new NotaResource($nota->load(['cliente', 'itens', 'pagamentos', 'eventos']));
    }

    public function destroy(Request $request, Nota $nota, ExcluirRascunhoDeNota $acao): Response
    {
        $this->politica->garantirPodeRascunhar($this->autor($request));
        $acao->executar($nota);

        return response()->noContent();
    }

    public function emitir(Request $request, Nota $nota, IniciarEmissao $acao): JsonResponse
    {
        $nota = $acao->executar($nota->id, $this->autor($request));

        return (new NotaStatusResource($nota))->response()->setStatusCode(202);
    }

    public function xml(Nota $nota): Response
    {
        $nota->makeVisible('xml_autorizado');
        if ($nota->status !== StatusNota::Autorizada || $nota->xml_autorizado === null) {
            throw new class('Só notas autorizadas têm XML para baixar.') extends ErroDeNegocio
            {
                public function status(): int
                {
                    return 409;
                }

                public function codigo(): string
                {
                    return 'NOTA_SEM_XML';
                }
            };
        }

        return response($nota->xml_autorizado, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="NFe-'.$nota->numero.'.xml"',
        ]);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

Extraia a exceção anônima do `xml()` para uma classe `NotaSemXmlException` (`app/Modules/Fiscal/Domain/Exceptions/`, 409, `NOTA_SEM_XML`), no mesmo padrão de `NotaNaoEditavelException`, e use-a aqui (classes anônimas dificultam o PHPStan).

Papéis: `store` e `update` passam por `CriarRascunhoDeNota`/`AtualizarRascunhoDeNota` (que já chamam `garantirPodeRascunhar`); `destroy` chama a política no controller; `emitir` passa por `IniciarEmissao` (`garantirPodeEmitir`). **Ordem importa para o isolamento:** o route model binding resolve `Nota $nota` antes da Action, então o 404 do intruso vem antes do 403.

- [ ] **Step 6: Rotas**

Em `routes-app.php`, dentro do grupo `empresa`:

```php
Route::get('notas', [NotaController::class, 'index'])->name('app.notas.index');
Route::post('notas', [NotaController::class, 'store'])->name('app.notas.store');
Route::get('notas/{nota}', [NotaController::class, 'show'])->name('app.notas.show');
Route::put('notas/{nota}', [NotaController::class, 'update'])->name('app.notas.update');
Route::delete('notas/{nota}', [NotaController::class, 'destroy'])->name('app.notas.destroy');
Route::get('notas/{nota}/status', [NotaController::class, 'status'])->name('app.notas.status');
Route::post('notas/{nota}/emitir', [NotaController::class, 'emitir'])->name('app.notas.emitir');
Route::get('notas/{nota}/xml', [NotaController::class, 'xml'])->name('app.notas.xml');
```

Adicione `use App\Modules\Fiscal\Http\Controllers\NotaController;`.

- [ ] **Step 7: Rodar e ver passar**

Run: `php artisan test --filter=NotasApiTest`
Expected: PASS. Se `test_baixa_o_xml...` falhar por causa do `$hidden`, o `makeVisible` no controller resolve; confirme que o JSON do `show` continua sem XML.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/NotasApiTest.php
git commit -m "feat(fiscal): API de notas (rascunho, emissão 202, status, XML) com isolamento e papéis"
```

---

### Task 15: `ConfirmarProducao` exige uma NF-e autorizada em homologação

**Files:**
- Modify: `app/Modules/Fiscal/Application/Actions/ConfirmarProducao.php`
- Modify: `app/Modules/Fiscal/Http/Resources/EmitenteResource.php`
- Modify: `tests/Feature/Fiscal/ConfiguracaoFiscalTest.php`
- Test: o mesmo arquivo (acrescentar casos)

**Interfaces:**
- Produces: `ConfirmarProducao::executar(Emitente $emitente, Usuario $autor): Emitente` passa a lançar `EmissaoBloqueadaException('FISCAL_SEM_EMISSAO_DE_TESTE', 'Emita ao menos uma NF-e de teste em homologação antes de ir para produção.')` quando não existe nota `AUTORIZADA` com `ambiente = HOMOLOGACAO`. `EmitenteResource` ganha `emissao_de_teste_autorizada: bool`.

- [ ] **Step 1: Ajustar e acrescentar testes**

No `ConfiguracaoFiscalTest`, o teste existente `test_confirmar_producao_muda_o_ambiente_e_audita` passa a precisar de uma nota autorizada em homologação antes. Acrescente ao `setUp` ou ao teste:

```php
private function nfeDeTesteAutorizada(): void
{
    app(\App\Modules\Tenancy\Domain\TenantContext::class)->set($this->tenant->id);
    $emitente = \App\Modules\Fiscal\Domain\Models\Emitente::query()->firstOrCreate([], []);
    $nota = \App\Modules\Fiscal\Domain\Models\Nota::factory()->create([
        'emitente_id' => $emitente->id, 'cliente_id' => \App\Modules\Customers\Domain\Models\Cliente::factory()->create()->id,
    ]);
    $nota->forceFill(['status' => \App\Modules\Fiscal\Domain\Enums\StatusNota::Autorizada, 'ambiente' => 'HOMOLOGACAO'])->save();
}

public function test_confirmar_producao_sem_emissao_de_teste_bloqueia(): void
{
    $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])
        ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_SEM_EMISSAO_DE_TESTE');
    $this->spa()->actingAs($this->admin)->getJson('/api/app/emitente')->assertJsonPath('data.ambiente_fiscal', 'HOMOLOGACAO')
        ->assertJsonPath('data.emissao_de_teste_autorizada', false);
}

public function test_nota_autorizada_em_producao_nao_conta_como_emissao_de_teste(): void
{
    $this->nfeDeTesteAutorizada();
    \App\Modules\Fiscal\Domain\Models\Nota::query()->update(['ambiente' => 'PRODUCAO']);
    $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])->assertStatus(422);
}
```

E, em `test_confirmar_producao_muda_o_ambiente_e_audita`, chame `$this->nfeDeTesteAutorizada();` na primeira linha, e acrescente `->assertJsonPath('data.emissao_de_teste_autorizada', true)` ao `GET /emitente` depois do passo de preparar a nota (num teste novo `test_resource_indica_emissao_de_teste_autorizada`).

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=ConfiguracaoFiscalTest`
Expected: FAIL nos casos novos.

- [ ] **Step 3: Implementar**

```php
public function executar(Emitente $emitente, Usuario $autor): Emitente
{
    $temTeste = Nota::query()->where('status', StatusNota::Autorizada)->where('ambiente', AmbienteFiscal::Homologacao->value)->exists();
    if (! $temTeste) {
        throw new EmissaoBloqueadaException('FISCAL_SEM_EMISSAO_DE_TESTE', 'Emita ao menos uma NF-e de teste em homologação antes de ir para produção.');
    }

    $emitente->update(['ambiente_fiscal' => AmbienteFiscal::Producao]);
    // activity() permanece como está
}
```

No `EmitenteResource`, acrescente:

```php
'emissao_de_teste_autorizada' => Nota::query()->where('status', StatusNota::Autorizada)->where('ambiente', 'HOMOLOGACAO')->exists(),
```

(Consulta com Global Scope: só do tenant corrente. Acrescente os `use`.)

- [ ] **Step 4: Rodar e ver passar; rodar a suíte do emitente**

Run: `php artisan test --filter="ConfiguracaoFiscalTest|EmitenteTest|FiscalIsolamentoTest"`
Expected: PASS. Atualize o tipo `Emitente` do frontend na Tarefa 21 (campo novo).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/ConfiguracaoFiscalTest.php
git commit -m "feat(fiscal): produção exige uma NF-e autorizada em homologação"
```

---

### Task 16: Revisão do backend (suítes, estilo e análise estática)

**Files:** nenhum novo; correções que surgirem.

- [ ] **Step 1: Suíte completa**

Run (em `backend/`): `php artisan test`
Expected: tudo PASS (261 testes anteriores mais os novos). Se `RecursoSemContadorException` ou contagem de consumo quebrar testes antigos por causa de `DOCUMENTOS_MES`, ajuste a expectativa deles.

- [ ] **Step 2: Estilo**

Run: `vendor/bin/pint --test`
Se falhar, rode `vendor/bin/pint` e revise o diff.

- [ ] **Step 3: Análise estática**

Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: sem erros. Corrija tipos (`array` com generics, `@var` em `Model::query()->find`). O `sped-nfe` não tem tipos completos; use `@phpstan-ignore` só no ponto exato da lib, com comentário do motivo.

- [ ] **Step 4: Regra do `?? 0` e `empty()`**

Run: `grep -rnE "empty\(|\?\? 0" backend/app/Modules/Fiscal backend/app/Modules/Catalog`
Expected: nenhuma ocorrência em código fiscal. (Se houver, justifique ou troque por `=== null`.)

- [ ] **Step 5: Atualizar o `PROGRESSO.md` e commitar**

Registre: backend da F3a concluído (Tarefas 1 a 15), versão instalada do `sped-nfe`, decisão de contador de numeração compartilhado entre ambientes, e que o frontend é a próxima etapa.

```bash
git add -A backend PROGRESSO.md
git commit -m "chore(fiscal): revisão do backend da F3a (suítes, pint e phpstan)"
```

---

### Task 17: Frontend: tipos, schemas e API de notas

**Files:**
- Create: `frontend/features/notas/{types,schemas,api}.ts`
- Test: `frontend/features/notas/schemas.test.ts`

**Interfaces:**
- Produces:
  - `types.ts`: `StatusNota`, `Nota`, `NotaItem`, `NotaPagamento`, `NotaEvento`, `NotaStatus`, `NotaPayload`, `FormaPagamento` (`'01'|'03'|'04'|'17'|'99'`) e `ROTULO_STATUS: Record<StatusNota, string>`, `ROTULO_PAGAMENTO: Record<FormaPagamento, string>`.
  - `schemas.ts`: `notaSchema` (zod) e `NotaFormDados`; `quantidade` como string `^\d{1,9}([.,]\d{1,3})?$`; função `paraPayload(d: NotaFormDados): NotaPayload` (troca vírgula por ponto, converte reais em centavos com `reaisParaCentavos`).
  - `api.ts`: `QUERY_KEY_NOTAS`, `notasApi { listar(status?), buscar(id), status(id), criar(payload), editar(id, payload), excluir(id), emitir(id) }` e `urlDoXml(id): string`.

- [ ] **Step 1: Teste dos schemas**

```ts
import { describe, expect, it } from 'vitest';
import { notaSchema, paraPayload, type NotaFormDados } from './schemas';

const BASE: NotaFormDados = {
  cliente_id: '11111111-1111-4111-8111-111111111111',
  consumidor_final: '',
  desconto: '0,00',
  informacoes_complementares: '',
  itens: [{ produto_id: '22222222-2222-4222-8222-222222222222', quantidade: '2,5', valor_unitario: '' }],
  pagamentos: [{ tpag: '01', xpag: '', valor: '25,00' }],
};

describe('notaSchema', () => {
  it('aceita uma nota válida', () => {
    expect(notaSchema.safeParse(BASE).success).toBe(true);
  });

  it('exige ao menos um item e um pagamento', () => {
    expect(notaSchema.safeParse({ ...BASE, itens: [] }).success).toBe(false);
    expect(notaSchema.safeParse({ ...BASE, pagamentos: [] }).success).toBe(false);
  });

  it('recusa quantidade com mais de 3 casas ou zero', () => {
    expect(notaSchema.safeParse({ ...BASE, itens: [{ ...BASE.itens[0], quantidade: '1,2345' }] }).success).toBe(false);
    expect(notaSchema.safeParse({ ...BASE, itens: [{ ...BASE.itens[0], quantidade: '0' }] }).success).toBe(false);
  });

  it('exige descrição quando a forma de pagamento é Outros', () => {
    const r = notaSchema.safeParse({ ...BASE, pagamentos: [{ tpag: '99', xpag: '', valor: '25,00' }] });
    expect(r.success).toBe(false);
  });
});

describe('paraPayload', () => {
  it('converte vírgula em ponto, reais em centavos e vazio em null', () => {
    const p = paraPayload(BASE);
    expect(p.itens[0]).toEqual({ produto_id: BASE.itens[0].produto_id, quantidade: '2.5', valor_unitario_centavos: null });
    expect(p.pagamentos[0]).toEqual({ tpag: '01', xpag: null, valor_centavos: 2500 });
    expect(p.desconto_centavos).toBe(0);
    expect(p.consumidor_final).toBeNull();
    expect(p.informacoes_complementares).toBeNull();
  });

  it('mapeia consumidor final sim e não', () => {
    expect(paraPayload({ ...BASE, consumidor_final: 'sim' }).consumidor_final).toBe(true);
    expect(paraPayload({ ...BASE, consumidor_final: 'nao' }).consumidor_final).toBe(false);
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run (em `frontend/`): `npx vitest run features/notas/schemas.test.ts`
Expected: FAIL (módulo inexistente).

- [ ] **Step 3: `types.ts`**

```ts
export type StatusNota = 'RASCUNHO' | 'PROCESSANDO' | 'AUTORIZADA' | 'REJEITADA' | 'ERRO' | 'CANCELADA';
export type FormaPagamento = '01' | '03' | '04' | '17' | '99';

export const ROTULO_STATUS: Record<StatusNota, string> = {
  RASCUNHO: 'Rascunho',
  PROCESSANDO: 'Processando',
  AUTORIZADA: 'Autorizada',
  REJEITADA: 'Rejeitada',
  ERRO: 'Erro',
  CANCELADA: 'Cancelada',
};

export const ROTULO_PAGAMENTO: Record<FormaPagamento, string> = {
  '01': 'Dinheiro',
  '03': 'Cartão de crédito',
  '04': 'Cartão de débito',
  '17': 'PIX',
  '99': 'Outros',
};

export interface NotaItem {
  id: number;
  produto_id: string;
  ordem: number;
  sku: string | null;
  descricao: string | null;
  unidade: string | null;
  quantidade: string;
  valor_unitario_centavos: number;
  desconto_centavos: number;
  total_centavos: number;
  cfop: string | null;
  csosn: string | null;
}

export interface NotaPagamento {
  tpag: FormaPagamento;
  xpag: string | null;
  valor_centavos: number;
}

export interface NotaEvento {
  status_de: StatusNota | null;
  status_para: StatusNota;
  detalhe: string | null;
  em: string;
}

export interface Nota {
  id: string;
  status: StatusNota;
  ambiente: 'HOMOLOGACAO' | 'PRODUCAO' | null;
  numero: number | null;
  serie: string | null;
  chave: string | null;
  protocolo: string | null;
  cstat: string | null;
  motivo: string | null;
  mensagem_erro: string | null;
  natureza_operacao: string;
  consumidor_final: boolean | null;
  subtotal_centavos: number;
  desconto_centavos: number;
  total_centavos: number;
  informacoes_complementares: string | null;
  emitido_em: string | null;
  created_at: string;
  cliente?: { id: string; nome: string };
  itens?: NotaItem[];
  pagamentos?: NotaPagamento[];
  eventos?: NotaEvento[];
}

export type NotaStatus = Pick<Nota, 'id' | 'status' | 'numero' | 'serie' | 'chave' | 'cstat' | 'motivo' | 'mensagem_erro'>;

export interface NotaPayload {
  cliente_id: string;
  consumidor_final: boolean | null;
  desconto_centavos: number;
  informacoes_complementares: string | null;
  itens: { produto_id: string; quantidade: string; valor_unitario_centavos: number | null }[];
  pagamentos: { tpag: FormaPagamento; xpag: string | null; valor_centavos: number }[];
}
```

- [ ] **Step 4: `schemas.ts`**

```ts
import { z } from 'zod';
import { reaisParaCentavos } from '@/lib/formatos';
import type { NotaPayload } from './types';

const DINHEIRO = /^\d+(,\d{1,2})?$/;
const QUANTIDADE = /^\d{1,9}([.,]\d{1,3})?$/;

const itemSchema = z.object({
  produto_id: z.string().uuid('Escolha um produto.'),
  quantidade: z
    .string()
    .regex(QUANTIDADE, 'Use até 3 casas decimais.')
    .refine((v) => Number(v.replace(',', '.')) > 0, 'A quantidade deve ser maior que zero.'),
  valor_unitario: z.string().refine((v) => v === '' || DINHEIRO.test(v), 'Use o formato 10,50.'),
});

const pagamentoSchema = z
  .object({
    tpag: z.enum(['01', '03', '04', '17', '99']),
    xpag: z.string().max(60, 'No máximo 60 caracteres.'),
    valor: z.string().regex(DINHEIRO, 'Use o formato 10,50.'),
  })
  .refine((p) => p.tpag !== '99' || p.xpag.trim() !== '', { message: 'Descreva a forma de pagamento.', path: ['xpag'] });

export const notaSchema = z.object({
  cliente_id: z.string().uuid('Escolha um cliente.'),
  consumidor_final: z.enum(['', 'sim', 'nao']),
  desconto: z.string().regex(DINHEIRO, 'Use o formato 10,50.'),
  informacoes_complementares: z.string().max(5000, 'No máximo 5000 caracteres.'),
  itens: z.array(itemSchema).min(1, 'Adicione ao menos um item.'),
  pagamentos: z.array(pagamentoSchema).min(1, 'Adicione ao menos um pagamento.'),
});

export type NotaFormDados = z.infer<typeof notaSchema>;

export function paraPayload(d: NotaFormDados): NotaPayload {
  return {
    cliente_id: d.cliente_id,
    consumidor_final: d.consumidor_final === '' ? null : d.consumidor_final === 'sim',
    desconto_centavos: reaisParaCentavos(d.desconto),
    informacoes_complementares: d.informacoes_complementares.trim() === '' ? null : d.informacoes_complementares,
    itens: d.itens.map((i) => ({
      produto_id: i.produto_id,
      quantidade: i.quantidade.replace(',', '.'),
      valor_unitario_centavos: i.valor_unitario === '' ? null : reaisParaCentavos(i.valor_unitario),
    })),
    pagamentos: d.pagamentos.map((p) => ({
      tpag: p.tpag,
      xpag: p.xpag.trim() === '' ? null : p.xpag,
      valor_centavos: reaisParaCentavos(p.valor),
    })),
  };
}
```

- [ ] **Step 5: `api.ts`**

```ts
import { api } from '@/lib/api';
import type { Nota, NotaPayload, NotaStatus, StatusNota } from './types';

export const QUERY_KEY_NOTAS = ['notas'] as const;

export const notasApi = {
  listar: (status?: StatusNota) =>
    api<{ data: Nota[] }>(`/api/app/notas${status ? `?status=${status}` : ''}`),
  buscar: (id: string) => api<{ data: Nota }>(`/api/app/notas/${id}`),
  status: (id: string) => api<{ data: NotaStatus }>(`/api/app/notas/${id}/status`),
  criar: (dados: NotaPayload) => api<{ data: Nota }>('/api/app/notas', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: NotaPayload) => api<{ data: Nota }>(`/api/app/notas/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  excluir: (id: string) => api<void>(`/api/app/notas/${id}`, { method: 'DELETE' }),
  emitir: (id: string) => api<{ data: NotaStatus }>(`/api/app/notas/${id}/emitir`, { method: 'POST' }),
};

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

/** O download é uma navegação com cookie de sessão, não um fetch JSON. */
export const urlDoXml = (id: string): string => `${API_URL}/api/app/notas/${id}/xml`;
```

- [ ] **Step 6: Rodar e ver passar; typecheck**

Run: `npx vitest run features/notas/schemas.test.ts && npx tsc --noEmit`
Expected: PASS e sem erros de tipo.

- [ ] **Step 7: Commit**

```bash
git add frontend/features/notas
git commit -m "feat(frontend): tipos, schemas e API de notas"
```

---

### Task 18: Frontend: `StatusDaNota`, lista de notas e item de menu

**Files:**
- Create: `frontend/features/notas/components/{StatusDaNota,ListaDeNotas}.tsx`
- Create: `frontend/app/(app)/notas/page.tsx`
- Modify: `frontend/components/layout/nav-items.ts`
- Test: `frontend/features/notas/components/Notas.test.tsx` (começa aqui, cresce nas Tarefas 19 e 20)

**Interfaces:**
- Produces: `<StatusDaNota status ambiente? />` (badge com cor e texto; cores com `text-success`/`text-warning`/`text-danger`/`text-info` já existentes nos tokens; confirme as classes em `app/globals.css` e use as que existem), `<ListaDeNotas />` (filtro por status, lista com número, cliente, total, status, ambiente e link para `/notas/{id}`), e a página `/notas` com botão "Nova NF-e" (só para quem pode rascunhar: PROPRIETARIO, ADMIN, FISCAL, VENDEDOR).

- [ ] **Step 1: Teste**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { notasApi } from '../api';
import type { Nota } from '../types';
import { ListaDeNotas } from './ListaDeNotas';
import { StatusDaNota } from './StatusDaNota';

vi.mock('../api', () => ({
  QUERY_KEY_NOTAS: ['notas'],
  urlDoXml: (id: string) => `http://api.test/api/app/notas/${id}/xml`,
  notasApi: { listar: vi.fn(), buscar: vi.fn(), status: vi.fn(), criar: vi.fn(), editar: vi.fn(), excluir: vi.fn(), emitir: vi.fn() },
}));

export function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

export const NOTA: Nota = {
  id: 'n1', status: 'AUTORIZADA', ambiente: 'HOMOLOGACAO', numero: 7, serie: '1', chave: '3'.repeat(44), protocolo: '135', cstat: '100', motivo: 'Autorizado',
  mensagem_erro: null, natureza_operacao: 'Venda de mercadoria', consumidor_final: true, subtotal_centavos: 2500, desconto_centavos: 0, total_centavos: 2500,
  informacoes_complementares: null, emitido_em: '2026-10-02T10:00:00-03:00', created_at: '2026-10-02T09:00:00-03:00', cliente: { id: 'c1', nome: 'Maria Teste' },
};

describe('StatusDaNota', () => {
  it('mostra o texto do status e o ambiente (nunca só cor)', () => {
    render(<StatusDaNota status="AUTORIZADA" ambiente="HOMOLOGACAO" />);
    expect(screen.getByText('Autorizada')).toBeInTheDocument();
    expect(screen.getByText('Homologação')).toBeInTheDocument();
  });
});

describe('ListaDeNotas', () => {
  it('lista as notas com número, cliente e total', async () => {
    vi.mocked(notasApi.listar).mockResolvedValue({ data: [NOTA] });
    renderizar(<ListaDeNotas />);

    expect(await screen.findByText('Maria Teste')).toBeInTheDocument();
    expect(screen.getByText(/Nº 7/)).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*25,00/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Abrir nota 7/ })).toHaveAttribute('href', '/notas/n1');
  });

  it('filtra por status', async () => {
    vi.mocked(notasApi.listar).mockResolvedValue({ data: [] });
    renderizar(<ListaDeNotas />);
    await screen.findByText('Nenhuma nota encontrada.');

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'REJEITADA');
    await waitFor(() => expect(notasApi.listar).toHaveBeenLastCalledWith('REJEITADA'));
  });

  it('avisa quando a lista falha ao carregar', async () => {
    vi.mocked(notasApi.listar).mockRejectedValue(new Error('x'));
    renderizar(<ListaDeNotas />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Não foi possível carregar as notas.');
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `npx vitest run features/notas/components/Notas.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Implementar**

`StatusDaNota.tsx`:

```tsx
import { cn } from '@/lib/utils';
import { ROTULO_STATUS, type StatusNota } from '../types';

const COR: Record<StatusNota, string> = {
  RASCUNHO: 'bg-muted text-muted-foreground',
  PROCESSANDO: 'bg-warning/15 text-warning',
  AUTORIZADA: 'bg-success/15 text-success',
  REJEITADA: 'bg-danger/15 text-danger',
  ERRO: 'bg-danger/15 text-danger',
  CANCELADA: 'bg-muted text-muted-foreground',
};

/** Texto sempre presente: a cor sozinha não basta (acessibilidade). O ambiente fica visível ao lado. */
export function StatusDaNota({ status, ambiente }: { status: StatusNota; ambiente?: 'HOMOLOGACAO' | 'PRODUCAO' | null }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5 text-xs font-medium">
      <span className={cn('rounded-full px-2 py-0.5', COR[status])}>{ROTULO_STATUS[status]}</span>
      {ambiente ? (
        <span className={cn('rounded-full px-2 py-0.5', ambiente === 'PRODUCAO' ? 'bg-danger/15 text-danger' : 'bg-info/15 text-info')}>
          {ambiente === 'PRODUCAO' ? 'Produção' : 'Homologação'}
        </span>
      ) : null}
    </span>
  );
}
```

Antes de usar, confira as classes de cor existentes: `grep -n "success\|warning\|danger\|info" frontend/app/globals.css`. Use as que existem; se `warning`, `success` ou `info` não existirem como tokens, **acrescente-os** (claro e escuro, contraste AA) em `globals.css` e no mapeamento de tema, em vez de usar cores soltas.

`ListaDeNotas.tsx`:

```tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { useState } from 'react';
import { Label } from '@/components/ui/label';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { formatarCentavos } from '@/lib/formatos';
import { notasApi, QUERY_KEY_NOTAS } from '../api';
import { ROTULO_STATUS, type StatusNota } from '../types';
import { StatusDaNota } from './StatusDaNota';

export function ListaDeNotas() {
  const [status, setStatus] = useState<StatusNota | ''>('');
  const { data, isPending, isError } = useQuery({
    queryKey: [...QUERY_KEY_NOTAS, status],
    queryFn: async () => (await notasApi.listar(status === '' ? undefined : status)).data,
  });

  return (
    <div className="space-y-3">
      <div className="max-w-xs space-y-2">
        <Label htmlFor="filtro_status">Status</Label>
        <SelectNativo id="filtro_status" value={status} onChange={(e) => setStatus(e.target.value as StatusNota | '')}>
          <option value="">Todos</option>
          {(Object.keys(ROTULO_STATUS) as StatusNota[]).map((s) => <option key={s} value={s}>{ROTULO_STATUS[s]}</option>)}
        </SelectNativo>
      </div>

      {isPending ? <p className="text-muted-foreground">Carregando...</p> : null}
      {isError ? <p role="alert">Não foi possível carregar as notas.</p> : null}
      {data && data.length === 0 ? <p className="text-muted-foreground">Nenhuma nota encontrada.</p> : null}
      {data && data.length > 0 ? (
        <ul className="divide-y">
          {data.map((n) => (
            <li key={n.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
              <div className="min-w-0">
                <p className="font-medium">{n.cliente?.nome ?? 'Sem cliente'}</p>
                <p className="text-sm text-muted-foreground">{n.numero !== null ? `Nº ${n.numero} · série ${n.serie}` : 'Sem número'} · {formatarCentavos(n.total_centavos)}</p>
              </div>
              <div className="flex items-center gap-3">
                <StatusDaNota status={n.status} ambiente={n.ambiente} />
                <Link href={`/notas/${n.id}`} aria-label={`Abrir nota ${n.numero ?? 'sem número'}`} className="text-sm underline">Abrir</Link>
              </div>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
```

`app/(app)/notas/page.tsx`:

```tsx
'use client';

import Link from 'next/link';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ListaDeNotas } from '@/features/notas/components/ListaDeNotas';

const PAPEIS_QUE_RASCUNHAM = ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR'];

export default function NotasPage() {
  const { data: usuario } = useUsuario();
  const podeCriar = Boolean(usuario && PAPEIS_QUE_RASCUNHAM.includes(usuario.papel));

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <h1 className="text-xl font-semibold">Notas fiscais</h1>
        {podeCriar ? <Button asChild><Link href="/notas/nova">Nova NF-e</Link></Button> : null}
      </div>
      <Card>
        <CardHeader><CardTitle>NF-e</CardTitle></CardHeader>
        <CardContent><ListaDeNotas /></CardContent>
      </Card>
    </div>
  );
}
```

Se `Button` não suportar `asChild` neste projeto, use `<Link className="...">` estilizado como botão (confira `components/ui/button.tsx`).

Menu, em `nav-items.ts` (importar `FileText` de `lucide-react`):

```ts
{ href: '/notas', label: 'Notas', icon: FileText, papeis: ['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'] },
```

Posicione depois de "Clientes". Atualize o teste do `nav-items` existente, se houver um que conte itens.

- [ ] **Step 4: Rodar e ver passar**

Run: `npx vitest run features/notas components/layout && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(frontend): lista de notas, badge de status e item de menu"
```

---

### Task 19: Frontend: formulário da nota (nova e edição)

**Files:**
- Create: `frontend/features/notas/components/NotaForm.tsx`
- Create: `frontend/app/(app)/notas/nova/page.tsx`
- Modify: `frontend/features/notas/components/Notas.test.tsx`

**Interfaces:**
- Consumes: `produtosApi.listar`, `clientesApi.listar` (e `QUERY_KEY_PRODUTOS`, `QUERY_KEY_CLIENTES`), `notasApi`, `notaSchema`, `paraPayload`.
- Produces: `<NotaForm nota? onSalvo?(nota: Nota) />`. Sem `nota`: cria e, em sucesso, navega para `/notas/{id}`. Com `nota` (rascunho, rejeitada ou erro): edita. Campos: cliente (select de clientes com `estagio === 'CLIENTE'`), "Consumidor final?" (select Sim/Não, **só quando o cliente escolhido é PJ**), itens (lista dinâmica com `useFieldArray`: produto, quantidade, preço opcional), desconto, pagamentos (lista dinâmica: forma, descrição só se "Outros", valor), informações complementares. Mostra o total calculado (subtotal dos itens menos desconto) e a diferença para a soma dos pagamentos, só como ajuda visual (a validação real é do servidor).
- Erros do servidor: `ApiError` com `errors` vira erro por campo; `codigo` `FISCAL_*` e mensagem aparecem como erro geral (`root`).

- [ ] **Step 1: Acrescentar testes ao `Notas.test.tsx`**

```tsx
import { clientesApi } from '@/features/clientes/api';
import { produtosApi } from '@/features/produtos/api';
import { NotaForm } from './NotaForm';

vi.mock('@/features/clientes/api', () => ({ QUERY_KEY_CLIENTES: ['clientes'], clientesApi: { listar: vi.fn() } }));
vi.mock('@/features/produtos/api', () => ({ QUERY_KEY_PRODUTOS: ['produtos'], produtosApi: { listar: vi.fn() } }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn() }) }));

const CLIENTE_PF = { id: '11111111-1111-4111-8111-111111111111', tipo: 'PF', nome: 'Maria Teste', estagio: 'CLIENTE' };
const CLIENTE_PJ = { id: '33333333-3333-4333-8333-333333333333', tipo: 'PJ', nome: 'Empresa X', estagio: 'CLIENTE' };
const PRODUTO = { id: '22222222-2222-4222-8222-222222222222', sku: 'P-1', nome: 'Produto Um', preco_centavos: 1000 };

describe('NotaForm', () => {
  function preparar() {
    vi.mocked(clientesApi.listar).mockResolvedValue({ data: [CLIENTE_PF, CLIENTE_PJ] as never });
    vi.mocked(produtosApi.listar).mockResolvedValue({ data: [PRODUTO] as never });
  }

  it('cria o rascunho com itens e pagamento no formato da API', async () => {
    preparar();
    vi.mocked(notasApi.criar).mockResolvedValue({ data: { ...NOTA, id: 'novo', status: 'RASCUNHO' } });
    renderizar(<NotaForm />);

    await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE_PF.id);
    await userEvent.selectOptions(screen.getByLabelText('Produto 1'), PRODUTO.id);
    await userEvent.clear(screen.getByLabelText('Quantidade 1'));
    await userEvent.type(screen.getByLabelText('Quantidade 1'), '2,5');
    await userEvent.clear(screen.getByLabelText('Valor do pagamento 1'));
    await userEvent.type(screen.getByLabelText('Valor do pagamento 1'), '25,00');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar rascunho' }));

    await waitFor(() => expect(notasApi.criar).toHaveBeenCalledWith(expect.objectContaining({
      cliente_id: CLIENTE_PF.id,
      itens: [{ produto_id: PRODUTO.id, quantidade: '2.5', valor_unitario_centavos: null }],
      pagamentos: [{ tpag: '01', xpag: null, valor_centavos: 2500 }],
    })));
  });

  it('pergunta se é consumidor final só para cliente PJ', async () => {
    preparar();
    renderizar(<NotaForm />);

    await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE_PF.id);
    expect(screen.queryByLabelText('Consumidor final?')).not.toBeInTheDocument();

    await userEvent.selectOptions(screen.getByLabelText('Cliente'), CLIENTE_PJ.id);
    expect(screen.getByLabelText('Consumidor final?')).toBeInTheDocument();
  });

  it('mostra a descrição quando o pagamento é Outros', async () => {
    preparar();
    renderizar(<NotaForm />);
    await screen.findByLabelText('Cliente');

    expect(screen.queryByLabelText('Descrição do pagamento 1')).not.toBeInTheDocument();
    await userEvent.selectOptions(screen.getByLabelText('Forma de pagamento 1'), '99');
    expect(screen.getByLabelText('Descrição do pagamento 1')).toBeInTheDocument();
  });

  it('adiciona e remove itens', async () => {
    preparar();
    renderizar(<NotaForm />);
    await screen.findByLabelText('Cliente');

    await userEvent.click(screen.getByRole('button', { name: 'Adicionar item' }));
    expect(screen.getByLabelText('Produto 2')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Remover item 2' }));
    expect(screen.queryByLabelText('Produto 2')).not.toBeInTheDocument();
  });

  it('mostra o erro de bloqueio fiscal devolvido pelo servidor', async () => {
    preparar();
    const { ApiError } = await import('@/lib/api');
    vi.mocked(notasApi.criar).mockRejectedValue(new ApiError(422, 'O produto "Produto Um" está sem: NCM.', {}, { codigo: 'FISCAL_PRODUTO_INCOMPLETO' }));
    renderizar(<NotaForm />);

    await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE_PF.id);
    await userEvent.selectOptions(screen.getByLabelText('Produto 1'), PRODUTO.id);
    await userEvent.type(screen.getByLabelText('Quantidade 1'), '1');
    await userEvent.clear(screen.getByLabelText('Valor do pagamento 1'));
    await userEvent.type(screen.getByLabelText('Valor do pagamento 1'), '10,00');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar rascunho' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('está sem: NCM');
  });
});
```

Os `vi.mock` de `clientes/api`, `produtos/api` e `next/navigation` ficam no topo do arquivo (o Vitest os iça). Ajuste os imports repetidos ao consolidar o arquivo.

- [ ] **Step 2: Rodar e ver falhar**

Run: `npx vitest run features/notas/components/Notas.test.tsx`
Expected: FAIL (`NotaForm` inexistente).

- [ ] **Step 3: Implementar `NotaForm`**

Siga o padrão de `ClienteForm`/`ProdutoForm`: `useForm<NotaFormDados>({ resolver: zodResolver(notaSchema), defaultValues })`, `useFieldArray` para `itens` e `pagamentos`, `useWatch` (nunca `form.watch()`), `useEnvioUnico`, `Campo` para cada campo e `SelectNativo`/`Input`. Estrutura:

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useFieldArray, useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { clientesApi, QUERY_KEY_CLIENTES } from '@/features/clientes/api';
import { produtosApi, QUERY_KEY_PRODUTOS } from '@/features/produtos/api';
import { ApiError } from '@/lib/api';
import { centavosParaReais, formatarCentavos, reaisParaCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { notasApi, QUERY_KEY_NOTAS } from '../api';
import { notaSchema, paraPayload, type NotaFormDados } from '../schemas';
import { ROTULO_PAGAMENTO, type FormaPagamento, type Nota } from '../types';

const ITEM_VAZIO = { produto_id: '', quantidade: '1', valor_unitario: '' };
const PAGAMENTO_VAZIO = { tpag: '01' as FormaPagamento, xpag: '', valor: '0,00' };

function paraFormulario(n: Nota): NotaFormDados {
  return {
    cliente_id: n.cliente?.id ?? '',
    consumidor_final: n.consumidor_final === null ? '' : n.consumidor_final ? 'sim' : 'nao',
    desconto: centavosParaReais(n.desconto_centavos),
    informacoes_complementares: n.informacoes_complementares ?? '',
    itens: (n.itens ?? []).map((i) => ({ produto_id: i.produto_id, quantidade: i.quantidade.replace('.', ','), valor_unitario: centavosParaReais(i.valor_unitario_centavos) })),
    pagamentos: (n.pagamentos ?? []).map((p) => ({ tpag: p.tpag, xpag: p.xpag ?? '', valor: centavosParaReais(p.valor_centavos) })),
  };
}

export function NotaForm({ nota, onSalvo }: { nota?: Nota; onSalvo?: (nota: Nota) => void }) {
  // implementar conforme os comentários abaixo
}
```

Corpo do componente (implementar exatamente):
1. `useQuery` de clientes (`QUERY_KEY_CLIENTES`, filtra `estagio === 'CLIENTE'` no render) e de produtos (`QUERY_KEY_PRODUTOS`).
2. `const form = useForm<NotaFormDados>({ resolver: zodResolver(notaSchema), defaultValues: nota ? paraFormulario(nota) : { cliente_id: '', consumidor_final: '', desconto: '0,00', informacoes_complementares: '', itens: [ITEM_VAZIO], pagamentos: [PAGAMENTO_VAZIO] } })`.
3. `useFieldArray({ control: form.control, name: 'itens' })` e `name: 'pagamentos'`.
4. `useWatch({ control, name: ['cliente_id', 'itens', 'pagamentos', 'desconto'] })` para: tipo do cliente (PJ mostra "Consumidor final?"), forma de pagamento por linha (Outros mostra a descrição) e totais.
5. Total estimado: soma de `quantidade × (valor_unitario ou preço do produto)` em **milésimos × centavos com `Math.trunc((m*c + 500) / 1000)`** (a mesma conta do servidor, só como ajuda visual), menos `reaisParaCentavos(desconto)`. Mostrar "Total: R$ X" e, se a soma dos pagamentos for diferente, o aviso "Pagamentos somam R$ Y (diferença de R$ Z)". Não bloquear o envio por isso.
6. `useMutation` para salvar: `nota ? notasApi.editar(nota.id, paraPayload(d)) : notasApi.criar(paraPayload(d))`; `onSuccess`: toast "Rascunho salvo.", invalida `QUERY_KEY_NOTAS`, chama `onSalvo?.(data)` e, sem `nota`, `router.push('/notas/' + data.id)`; `onError`: `ApiError` com `errors` → `form.setError(campo, ...)`, senão `form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível salvar. Tente novamente.' })`.
7. Renderizar o erro raiz em `<p role="alert" className="text-sm text-danger">`.
8. Rótulos acessíveis exatos usados nos testes: `Cliente`, `Consumidor final?`, `Produto {n}`, `Quantidade {n}`, `Preço unitário {n}` (opcional, dica "Vazio usa o preço do cadastro"), `Remover item {n}`, botão `Adicionar item`, `Forma de pagamento {n}`, `Descrição do pagamento {n}` (só com tpag 99), `Valor do pagamento {n}`, `Remover pagamento {n}`, botão `Adicionar pagamento`, `Desconto (R$)`, `Informações complementares`, botão `Salvar rascunho`.

`app/(app)/notas/nova/page.tsx`:

```tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { NotaForm } from '@/features/notas/components/NotaForm';

export default function NovaNotaPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Nova NF-e</h1>
      <Card>
        <CardHeader><CardTitle>Dados da nota</CardTitle></CardHeader>
        <CardContent><NotaForm /></CardContent>
      </Card>
    </div>
  );
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `npx vitest run features/notas && npx tsc --noEmit && npx eslint features/notas app/\(app\)/notas`
Expected: PASS, sem erros de tipo nem de lint (use `useWatch`; sem `any`).

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(frontend): formulário de nova NF-e e edição do rascunho"
```

---

### Task 20: Frontend: detalhe da nota (emitir, polling, XML e trilha)

**Files:**
- Create: `frontend/features/notas/components/DetalheDaNota.tsx`
- Create: `frontend/app/(app)/notas/[id]/page.tsx`
- Modify: `frontend/features/notas/components/Notas.test.tsx`

**Interfaces:**
- Consumes: `notasApi.buscar/status/emitir/excluir`, `urlDoXml`, `NotaForm`, `StatusDaNota`, `useUsuario`.
- Produces: `<DetalheDaNota id />`:
  - Busca a nota (`QUERY_KEY_NOTAS + id`). Enquanto `PROCESSANDO`, faz polling do `status` a cada 2 s com `refetchInterval` (parando em qualquer outro status) e, ao mudar de status, recarrega a nota completa.
  - Cabeçalho: número e série (se houver), cliente, `StatusDaNota` com ambiente, total.
  - `REJEITADA`: mostra "cStat X: motivo" em destaque (`role="alert"`). `ERRO`: mostra `mensagem_erro`. Ambos oferecem "Corrigir e emitir de novo" (abre o `NotaForm` de edição).
  - `AUTORIZADA`: mostra chave e protocolo e o link "Baixar XML" (`<a href={urlDoXml(id)}>`).
  - Botões (só para quem emite: PROPRIETARIO, ADMIN, FISCAL): "Emitir NF-e" quando editável (`RASCUNHO`, `REJEITADA`, `ERRO`); desabilitado enquanto envia (usar `useEnvioUnico`). Quem só rascunha (VENDEDOR) vê "Você pode editar, mas só um usuário fiscal pode emitir.". "Excluir rascunho" só em `RASCUNHO`.
  - Erro de bloqueio fiscal (`ApiError` 422 com `codigo` `FISCAL_*`) vira `role="alert"` com a mensagem acionável, sem mudar o status.
  - Trilha: lista de `eventos` (status, detalhe e hora).

- [ ] **Step 1: Testes**

```tsx
import { act } from '@testing-library/react';
import { DetalheDaNota } from './DetalheDaNota';

vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: vi.fn() }));
import { useUsuario } from '@/features/auth/hooks/useUsuario';

const rascunho: Nota = { ...NOTA, status: 'RASCUNHO', numero: null, serie: null, chave: null, protocolo: null, cstat: null, motivo: null, ambiente: null, emitido_em: null,
  itens: [], pagamentos: [], eventos: [{ status_de: null, status_para: 'RASCUNHO', detalhe: 'Rascunho criado', em: '2026-10-02T09:00:00-03:00' }] };

function comPapel(papel: string) {
  vi.mocked(useUsuario).mockReturnValue({ data: { papel } } as never);
}

describe('DetalheDaNota', () => {
  it('emite a nota e mostra o status de processamento', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: rascunho });
    vi.mocked(notasApi.emitir).mockResolvedValue({ data: { id: 'n1', status: 'PROCESSANDO', numero: 1, serie: '1', chave: null, cstat: null, motivo: null, mensagem_erro: null } });
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Emitir NF-e' }));
    await waitFor(() => expect(notasApi.emitir).toHaveBeenCalledWith('n1'));
  });

  it('mostra cStat e motivo da rejeição e oferece corrigir', async () => {
    comPapel('FISCAL');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...rascunho, status: 'REJEITADA', cstat: '232', motivo: 'IE do destinatário não informada', ambiente: 'HOMOLOGACAO', numero: 3, serie: '1' } });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByRole('alert')).toHaveTextContent('cStat 232: IE do destinatário não informada');
    expect(screen.getByRole('button', { name: 'Corrigir e emitir de novo' })).toBeInTheDocument();
  });

  it('mostra o erro técnico sem chamar de rejeição', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...rascunho, status: 'ERRO', mensagem_erro: 'Falha técnica ao emitir: timeout' } });
    renderizar(<DetalheDaNota id="n1" />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Falha técnica ao emitir: timeout');
    expect(screen.queryByText(/cStat/)).not.toBeInTheDocument();
  });

  it('autorizada mostra chave, protocolo e o link do XML', async () => {
    comPapel('LEITURA');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByText(NOTA.chave as string)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Baixar XML' })).toHaveAttribute('href', 'http://api.test/api/app/notas/n1/xml');
    expect(screen.queryByRole('button', { name: 'Emitir NF-e' })).not.toBeInTheDocument();
  });

  it('vendedor edita mas não emite', async () => {
    comPapel('VENDEDOR');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: rascunho });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByText(/só um usuário fiscal pode emitir/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Emitir NF-e' })).not.toBeInTheDocument();
  });

  it('mostra o bloqueio fiscal do servidor ao emitir', async () => {
    comPapel('ADMIN');
    const { ApiError } = await import('@/lib/api');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: rascunho });
    vi.mocked(notasApi.emitir).mockRejectedValue(new ApiError(422, 'Complete em Configurações > Fiscal: inscrição estadual.', {}, { codigo: 'FISCAL_EMITENTE_INCOMPLETO' }));
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Emitir NF-e' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('inscrição estadual');
  });

  it('faz polling enquanto PROCESSANDO e para ao autorizar', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...rascunho, status: 'PROCESSANDO', ambiente: 'HOMOLOGACAO', numero: 1, serie: '1' } });
    vi.mocked(notasApi.status)
      .mockResolvedValueOnce({ data: { id: 'n1', status: 'PROCESSANDO', numero: 1, serie: '1', chave: null, cstat: null, motivo: null, mensagem_erro: null } })
      .mockResolvedValue({ data: { id: 'n1', status: 'AUTORIZADA', numero: 1, serie: '1', chave: '3'.repeat(44), cstat: '100', motivo: 'Autorizado', mensagem_erro: null } });
    renderizar(<DetalheDaNota id="n1" />);

    await screen.findByText('Processando');
    await act(async () => { await vi.advanceTimersByTimeAsync(2100); });
    await act(async () => { await vi.advanceTimersByTimeAsync(2100); });
    await waitFor(() => expect(notasApi.status.mock.calls.length).toBeGreaterThanOrEqual(2));
    vi.useRealTimers();
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `npx vitest run features/notas/components/Notas.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Implementar `DetalheDaNota`**

Pontos obrigatórios (siga os padrões de `ListaDeClientes`):
- `const { data: nota } = useQuery({ queryKey: [...QUERY_KEY_NOTAS, id], queryFn: async () => (await notasApi.buscar(id)).data })`.
- Polling: `useQuery({ queryKey: [...QUERY_KEY_NOTAS, id, 'status'], queryFn: async () => (await notasApi.status(id)).data, enabled: nota?.status === 'PROCESSANDO', refetchInterval: 2000 })`; um `useEffect` que, quando `status.data?.status` muda para um valor diferente de `PROCESSANDO`, chama `queryClient.invalidateQueries({ queryKey: [...QUERY_KEY_NOTAS, id] })` e a lista. (Sem `setState` dentro de efeito que dispare cascata: apenas invalida.)
- Emitir: `useMutation({ mutationFn: () => notasApi.emitir(id), onSuccess: invalidar + toast.info('Emissão iniciada.'), onError: define o erro local })`; o erro vira estado local `erroEmissao: string | null` (de `ApiError.message`) exibido em `role="alert"`, e limpo ao tentar de novo.
- Papéis: `PAPEIS_QUE_EMITEM = ['PROPRIETARIO','ADMIN','FISCAL']`, `PAPEIS_QUE_RASCUNHAM = [...,'VENDEDOR']`.
- Edição: estado `editando`; quando `true`, renderiza `<NotaForm nota={nota} onSalvo={() => setEditando(false)} />` no lugar do resumo. O botão "Corrigir e emitir de novo" (REJEITADA/ERRO) e "Editar" (RASCUNHO) ligam o `editando`.
- Formatação: `formatarCentavos`, `formatarData` (para `em` use `new Date(iso).toLocaleString('pt-BR')`).
- Excluir (só `RASCUNHO`): `notasApi.excluir(id)` e `router.push('/notas')`; confirmar com um segundo clique ("Confirmar exclusão"), sem `window.confirm` (evita diálogo bloqueante).

`app/(app)/notas/[id]/page.tsx`:

```tsx
'use client';

import { use } from 'react';
import { DetalheDaNota } from '@/features/notas/components/DetalheDaNota';

export default function NotaPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  return <DetalheDaNota id={id} />;
}
```

(Next 16: `params` é uma `Promise`; confirme o padrão nas outras páginas dinâmicas do projeto, se houver, e siga-o.)

- [ ] **Step 4: Rodar e ver passar**

Run: `npx vitest run features/notas && npx tsc --noEmit && npx eslint features/notas app`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(frontend): detalhe da nota com emissão, polling, XML e trilha de eventos"
```

---

### Task 21: Frontend: confirmação de produção em Configurações › Fiscal

**Files:**
- Create: `frontend/features/emitente/components/ConfirmarProducao.tsx`
- Modify: `frontend/features/emitente/types.ts` (campo `emissao_de_teste_autorizada: boolean`), `frontend/app/(app)/configuracoes/fiscal/page.tsx`
- Modify: `frontend/features/emitente/components/Emitente.test.tsx` (ajustar `EMITENTE_VAZIO` com o campo novo) e acrescentar testes

**Interfaces:**
- Consumes: `emitenteApi.confirmarProducao()` (já existe), `QUERY_KEY_EMITENTE`.
- Produces: `<ConfirmarProducao emitente />`: faixa de ambiente inconfundível (HOMOLOGAÇÃO em azul "Notas sem valor fiscal"; PRODUÇÃO em vermelho "Notas com valor fiscal"). Em homologação: se `emissao_de_teste_autorizada` for `false`, mostra "Emita ao menos uma NF-e de teste em homologação para liberar a produção." com link para `/notas/nova` e botão desabilitado; se `true`, mostra a caixa "Entendo que as notas emitidas em produção têm valor fiscal" (checkbox) e o botão "Ir para produção" (habilitado só com o checkbox marcado). Em produção: só a faixa. O servidor continua sendo a fonte da regra (um 422 `FISCAL_SEM_EMISSAO_DE_TESTE` aparece em `role="alert"`).

- [ ] **Step 1: Testes**

```tsx
describe('ConfirmarProducao', () => {
  it('bloqueia a ida para produção sem emissão de teste autorizada', () => {
    renderizar(<ConfirmarProducao emitente={{ ...EMITENTE_VAZIO, emissao_de_teste_autorizada: false }} />);
    expect(screen.getByText(/Emita ao menos uma NF-e de teste/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ir para produção' })).not.toBeInTheDocument();
  });

  it('exige a caixa de entendimento e então confirma', async () => {
    vi.mocked(emitenteApi.confirmarProducao).mockResolvedValue({ data: { ...EMITENTE_VAZIO, ambiente_fiscal: 'PRODUCAO', emissao_de_teste_autorizada: true } });
    renderizar(<ConfirmarProducao emitente={{ ...EMITENTE_VAZIO, emissao_de_teste_autorizada: true }} />);

    const botao = screen.getByRole('button', { name: 'Ir para produção' });
    expect(botao).toBeDisabled();
    await userEvent.click(screen.getByLabelText(/valor fiscal/));
    expect(botao).toBeEnabled();
    await userEvent.click(botao);

    await waitFor(() => expect(emitenteApi.confirmarProducao).toHaveBeenCalled());
  });

  it('em produção mostra só a faixa de ambiente', () => {
    renderizar(<ConfirmarProducao emitente={{ ...EMITENTE_VAZIO, ambiente_fiscal: 'PRODUCAO', emissao_de_teste_autorizada: true }} />);
    expect(screen.getByText(/PRODUÇÃO/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ir para produção' })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `npx vitest run features/emitente`
Expected: FAIL.

- [ ] **Step 3: Implementar**

Em `types.ts`, acrescente `emissao_de_teste_autorizada: boolean;` à interface do emitente. Em `Emitente.test.tsx`, acrescente `emissao_de_teste_autorizada: false` a `EMITENTE_VAZIO`.

`ConfirmarProducao.tsx` segue o padrão de `CertificadoForm`: `useMutation` com `mutationFn: () => emitenteApi.confirmarProducao()`, `useEnvioUnico`, `queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE })` e o checkbox como `<input type="checkbox" id="entendo_producao" />` com `<label htmlFor>` "Entendo que as notas emitidas em produção têm valor fiscal". Faixa:

```tsx
<div role="status" className={emitente.ambiente_fiscal === 'PRODUCAO' ? 'rounded-md bg-danger/15 p-3 text-sm font-medium text-danger' : 'rounded-md bg-info/15 p-3 text-sm font-medium text-info'}>
  {emitente.ambiente_fiscal === 'PRODUCAO' ? 'Ambiente: PRODUÇÃO. As notas emitidas têm valor fiscal.' : 'Ambiente: HOMOLOGAÇÃO. As notas emitidas não têm valor fiscal.'}
</div>
```

Em `configuracoes/fiscal/page.tsx`, acrescente um `Card` "Ambiente de emissão" com `<ConfirmarProducao emitente={emitente} />` antes do card de "Empresa".

- [ ] **Step 4: Rodar e ver passar**

Run: `npx vitest run features/emitente && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(frontend): confirmação de produção com faixa de ambiente"
```

---

### Task 22: Revisão do frontend (suíte, typecheck, lint e build)

- [ ] **Step 1: Suíte, tipos, lint e build**

Run (em `frontend/`): `npx vitest run && npx tsc --noEmit && npx eslint . && npm run build`
Expected: tudo verde (o único warning tolerado é o antigo de `CadastroForm.tsx:113`). Se o `CadastroForm` estourar 5 s na suíte completa, é o problema conhecido; confirme rodando `npx vitest run features/cadastro` isolado.

- [ ] **Step 2: Conferência de tema e celular**

Se possível, abra `npm run dev` e confira `/notas`, `/notas/nova` e `/notas/{id}` nos temas claro e escuro e em largura de celular (itens e pagamentos não podem estourar a largura; badges legíveis). Registre no `PROGRESSO.md` se não for feito (como na F2).

- [ ] **Step 3: Commit de eventuais correções**

```bash
git add -A frontend
git commit -m "chore(frontend): revisão da F3a (suíte, tipos, lint e build)"
```

---

### Task 23: Fumaça ponta a ponta e fechamento da F3a

**Files:**
- Modify: `PROGRESSO.md`, `TAREFAS.md`
- Temporários no scratchpad: `smoke-f3a.sh`

- [ ] **Step 1: Fumaça com curl e `EmissorFake`**

Reaproveite o `smoke.sh` da F2 (SQLite descartável, host `localhost`, cookie jar, `X-XSRF-TOKEN`, `Origin`/`Referer`, acentos com `\uXXXX`). Para o emissor falso, **não** altere código de produção: rode o servidor com um provider de teste via variável de ambiente é intrusivo; em vez disso, faça a fumaça em dois blocos:
1. **Via HTTP real, até o limite do motor:** cadastro, login, emitente (empresa, fiscal, certificado `.pfx` gerado com openssl, série NFE), cliente PF completo (com CPF válido, endereço e IBGE), produto completo (NCM, origem 0, tributação), `POST /api/app/notas`, `GET /notas/{id}`, `POST /notas/{id}/emitir`. Sem acesso à SEFAZ o `NfePhpEmissor` real falha na transmissão: esperado **`status=ERRO`** com `mensagem_erro` de comunicação e `chave` preenchida (prova de que o XML foi montado, assinado e a chave gravada antes do envio). Rode o worker (`php artisan queue:work --once`) porque a fila local é `database`.
2. **Bloqueios:** repita com o produto sem NCM e confirme `422 FISCAL_PRODUTO_INCOMPLETO`; com o certificado vencido, `FISCAL_CERTIFICADO_INVALIDO`.

Registre no `PROGRESSO.md` o resultado de cada passo.

- [ ] **Step 2: Roteiro da emissão real em homologação (para o usuário)**

Escreva no `PROGRESSO.md` (seção "Roteiro de homologação real da F3a"):
1. Ter um certificado A1 do CNPJ de teste e a IE habilitada para NF-e em homologação na UF.
2. Em Configurações > Fiscal: endereço com IBGE, regime Simples, IE, CNAE, certificado, série NF-e (série 1, próximo número 1).
3. Cadastrar cliente PF com CPF, endereço e IBGE; produto com NCM real, origem, tributação ICMS.
4. Em Notas > Nova NF-e, emitir; esperar `AUTORIZADA`; baixar o XML.
5. Se vier `REJEITADA`, anotar `cStat` e `motivo` e conferir em `assets/cstat-table.md` da skill; **não** contornar a regra.
6. Só então "Ir para produção".
O worker precisa estar de pé: `php artisan queue:work` e o agendador `php artisan schedule:work`.

- [ ] **Step 3: Atualizar `TAREFAS.md` e `PROGRESSO.md`**

Marque a F3a como concluída (23 tarefas), liste as pendências conhecidas (Regime Normal, DIFAL, IBS/CBS, IE `ISENTO` do emitente, outras naturezas, contador de numeração compartilhado entre ambientes, ausência de remoto Git para o CI), reescreva "Contexto necessário" para a F3b (NFC-e: CSC e QR Code, cancelamento, inutilização e PDF) e a "Próxima tarefa" como "F3b: brainstorming do spec".

- [ ] **Step 4: Verificação final**

Run: `cd backend && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G` e `cd ../frontend && npx vitest run && npx tsc --noEmit && npx eslint . && npm run build`
Expected: tudo verde.

- [ ] **Step 5: Commit e recomendação**

```bash
git add PROGRESSO.md TAREFAS.md
git commit -m "docs: F3a concluída (emissão de NF-e), roteiro de homologação real e próximos passos"
```

Recomende `/compact` ou `/clear` antes de iniciar a F3b.

---

## Autoavaliação do plano

**Cobertura do spec:** §3.1 (modelos, enums, resolvers, emissor, Actions, job, comando, contador, dependência) nas Tarefas 1 a 13; §3.2 (estados) Tarefas 2 e 7; §3.3 (fluxo, com o refinamento preparar/transmitir) Tarefas 9 e 10; §3.4 (bloqueios) Tarefas 3, 5 e 9; §3.5 (XML) Tarefa 12; §3.6 (envio, 539) Tarefas 10 e 13; §3.7 (reconciliação) Tarefa 11; §3.8 (produção) Tarefas 15 e 21; §4 (API) Tarefa 14; §5 (frontend) Tarefas 17 a 21; §6 (testes) distribuídos; §7 (critério de pronto) Tarefa 23.

**Itens que o executor deve conferir contra o vendor (não são placeholders, são pontos dependentes da versão):** nomes e campos dos `tag*()` da `Make` e o caminho do XSD (Tarefa 12, Step 1), assinaturas de `sefazEnviaLote` e `Complements::toAuthorize` (Tarefa 13, Step 1), classes de cor existentes nos tokens do tema (Tarefa 18) e o namespace de `AssinaturaSemEscritaException` (Tarefa 9).

**Consistência de tipos:** `EmissorDeNfe` (`preparar`, `transmitir`, `consultarPorChave`), `ResultadoDeEmissao`/`ResultadoDeConsulta` e `XmlAssinado` definidos na Tarefa 8 e usados nas Tarefas 10, 11 e 13; `NotaParaEmissao::deNota` (Tarefa 8) lê o snapshot gravado por `PreparadorDaNota` (Tarefa 5); `PoliticaDeNotas` (Tarefa 7) usada nas Tarefas 9 e 14.
