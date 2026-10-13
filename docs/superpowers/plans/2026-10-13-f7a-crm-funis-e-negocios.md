# F7a: CRM, funis e negócios, plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar o módulo `Crm` (funis e etapas configuráveis, negócios com itens do catálogo, ganho e perda com motivo, visibilidade por vendedor), a API `api/app/crm/*` e o kanban no frontend.

**Architecture:** Módulo `Crm` no molde dos outros (`Domain/Application/Http/Providers`). `Crm` nunca importa Models de outros módulos: declara contratos em `Crm/Domain/Contracts`, implementados por Customers, Catalog e Identity e registrados nos respectivos `ServiceProvider`. Negócio guarda `etapa_id` e `status` (`ABERTO`/`GANHO`/`PERDIDO`). Mudanças disparam eventos de domínio após o commit. Frontend em `features/crm` com kanban `@dnd-kit` e menu "Mover para...".

**Tech Stack:** Laravel 12 + PHPUnit (SQLite em memória nos testes), Next.js 16 + TanStack Query + react-hook-form + zod + Vitest/Testing Library, `@dnd-kit`.

**Spec:** `docs/superpowers/specs/2026-10-13-crm-f7a-funis-e-negocios-design.md`

## Global Constraints

- pt-BR em tudo; `declare(strict_types=1)` em todo PHP; TypeScript strict, sem `any`.
- Todo Model de tenant usa `BelongsToTenant` (Global Scope por `tenant_id`). Todo endpoint novo ganha teste de isolamento entre duas empresas.
- Mudanças de schema só por migration. Nenhum dado real em fixtures.
- Dinheiro em centavos inteiros. Zero é valor válido: usar `=== null`, nunca `empty()` nem `?? 0` para decidir ausência.
- `Crm` não acessa tabelas nem Models de outros módulos; só contratos de `Crm/Domain/Contracts`.
- Autorização e gate de plano dentro das Actions (`PoliticaDoCrm`), nunca só no controller.
- Vendedor que pede negócio fora da sua visibilidade recebe 404, não 403.
- Frontend: `formatarCentavos`, `useEnvioUnico`, `useWatch` (nunca `form.watch`), componentes até 350 linhas (gate de lint), sem `console`. Antes de código de página nova, ler `frontend/node_modules/next/dist/docs/` (aviso do `frontend/AGENTS.md`).
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01FMN1dtbryeZMk179gK2iic`. Sem push.
- Backend: `cd backend && php artisan test --filter=...`. Frontend: `cd frontend && npx vitest run ...`, `npx eslint`, `npx tsc --noEmit`.

## Review Focus

- Vendedor com interruptor ligado lendo, movendo, ganhando ou perdendo negócio de outro vendedor (404 em todos os caminhos, inclusive `itens`).
- Etapa ou funil de outra empresa (ou de outro funil) enviados no `POST negocios` ou `mover`.
- Item de catálogo de outra empresa ou inexistente em `itens`.
- Dois movimentos seguidos no mesmo cartão: posições sem buracos nem repetição na origem e no destino.
- Valor do negócio com itens ignorando o `valor_centavos` do request; valor `0` sem itens aceito.
- Lead criado junto do negócio quando a transação falha depois (nenhum cliente órfão).
- Empresa sem o módulo `CRM` no plano e papel Fiscal em qualquer endpoint.
- Funil padrão e etapas com negócio aberto não podem ser desativados.

---

## Mapa de arquivos

**Backend, criar:**
- `database/migrations/2026_10_15_000001_create_crm_tables.php`
- `app/Modules/Crm/Providers/CrmServiceProvider.php`
- `app/Modules/Crm/Domain/Enums/{StatusDoNegocio,TipoDeItem}.php`
- `app/Modules/Crm/Domain/Models/{ConfiguracaoDoCrm,Funil,Etapa,MotivoDePerda,Negocio,ItemDoNegocio}.php`
- `app/Modules/Crm/Domain/Contracts/{ConsultaDeClientes,CatalogoDeItens,DiretorioDeUsuarios}.php`
- `app/Modules/Crm/Domain/{ResumoDoCliente,ItemDescrito}.php`
- `app/Modules/Crm/Domain/Exceptions/RegraDoCrmException.php`
- `app/Modules/Crm/Domain/Events/{NegocioCriado,NegocioMovido,NegocioGanho,NegocioPerdido}.php`
- `app/Modules/Crm/Application/{PoliticaDoCrm,PreparadorDoCrm,ApresentadorDeNegocios}.php`
- `app/Modules/Crm/Application/Actions/*.php` (uma por Action da spec §4)
- `app/Modules/Crm/Http/routes-app.php`, `Controllers/*`, `Requests/*`, `Resources/*`
- `app/Modules/Customers/Application/ConsultaDeClientesDoCustomers.php`
- `app/Modules/Catalog/Application/CatalogoDeItensDoCatalog.php`
- `app/Modules/Identity/Application/DiretorioDeUsuariosDoIdentity.php`
- `database/factories/{FunilFactory,EtapaFactory,MotivoDePerdaFactory,NegocioFactory}.php`
- `tests/Support/ComCrm.php` e `tests/Feature/Crm/*Test.php`

**Backend, modificar:** `bootstrap/providers.php`, `CustomersServiceProvider.php`, `CatalogServiceProvider.php`, `IdentityServiceProvider.php`, spec da F7a (contrato novo).

**Frontend, criar:** `features/crm/{types.ts,api.ts,schemas.ts,movimento.ts}`, `features/crm/hooks/*`, `features/crm/components/*`, `app/(app)/crm/negocios/page.tsx`, `app/(app)/crm/negocios/[id]/page.tsx`, `app/(app)/configuracoes/crm/page.tsx`.

**Frontend, modificar:** `package.json` (`@dnd-kit/*`), `components/layout/nav-items.ts`, `components/layout/MenuDeNavegacao.tsx`, `components/layout/nav-items.test.ts`.

**Docs, modificar:** `PROGRESSO.md`, `TAREFAS.md`.

---

### Task 1: Esquema, Models, factories e provider

**Files:**
- Create: `backend/database/migrations/2026_10_15_000001_create_crm_tables.php`
- Create: `backend/app/Modules/Crm/Providers/CrmServiceProvider.php`
- Create: `backend/app/Modules/Crm/Domain/Enums/StatusDoNegocio.php`, `TipoDeItem.php`
- Create: `backend/app/Modules/Crm/Domain/Models/ConfiguracaoDoCrm.php`, `Funil.php`, `Etapa.php`, `MotivoDePerda.php`, `Negocio.php`, `ItemDoNegocio.php`
- Create: `backend/database/factories/FunilFactory.php`, `EtapaFactory.php`, `MotivoDePerdaFactory.php`, `NegocioFactory.php`
- Create: `backend/tests/Support/ComCrm.php`
- Modify: `backend/bootstrap/providers.php`
- Test: `backend/tests/Feature/Crm/CrmModelosTest.php`

**Interfaces:**
- Produces: `StatusDoNegocio::{Aberto,Ganho,Perdido}` (valores `ABERTO|GANHO|PERDIDO`); `TipoDeItem::{Produto,Servico}` (`PRODUTO|SERVICO`); Models `Funil` (`etapas()`), `Etapa`, `MotivoDePerda`, `Negocio` (`itens()`, `scopeVisiveisPara`, `garantirAberto()`), `ItemDoNegocio`, `ConfiguracaoDoCrm` (`vendedorVeSoOsSeus(): bool` estático); trait de teste `Tests\Support\ComCrm` com `prepararCrm(Papel $papel = Papel::Admin): void`, `$this->tenant`, `$this->admin`, `usuarioDoPapel(Papel $papel, ?Tenant $tenant = null): Usuario`.

- [ ] **Step 1: Escrever o teste de isolamento que falha**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmModelosTest extends TestCase
{
    use RefreshDatabase;

    public function test_funil_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Funil::factory()->create());
    }

    public function test_etapa_e_isolada_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Etapa::factory()->create());
    }

    public function test_motivo_de_perda_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => MotivoDePerda::factory()->create());
    }

    public function test_negocio_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Negocio::factory()->create());
    }

    /** @param Closure(): Model $criar */
    private function verificaIsolamento(Closure $criar): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($a->id);
        $doA = $criar();
        $contexto->set($b->id);
        $criar();

        $this->assertNull($doA::query()->find($doA->getKey()));
        $contexto->set($a->id);
        $this->assertNotNull($doA::query()->find($doA->getKey()));
        $this->assertSame(1, $doA::query()->count());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=CrmModelosTest`
Expected: FAIL (`Class "App\Modules\Crm\Domain\Models\Funil" not found`).

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
        Schema::create('crm_configuracoes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants');
            $table->boolean('vendedor_ve_so_os_seus')->default(true);
            $table->timestamps();
        });

        Schema::create('crm_funis', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('nome', 100);
            $table->boolean('padrao')->default(false);
            $table->boolean('ativo')->default(true);
            $table->unsignedInteger('posicao')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'posicao']);
        });

        Schema::create('crm_etapas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('funil_id')->constrained('crm_funis')->cascadeOnDelete();
            $table->string('nome', 100);
            $table->unsignedInteger('posicao')->default(0);
            $table->unsignedTinyInteger('probabilidade')->default(0);
            $table->boolean('ativa')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'funil_id', 'posicao']);
        });

        Schema::create('crm_motivos_de_perda', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('nome', 100);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'ativo']);
        });

        Schema::create('crm_negocios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('titulo', 150);
            // Sem chave estrangeira: cliente e responsável pertencem a outros módulos (spec §3).
            $table->uuid('cliente_id');
            $table->foreignUuid('funil_id')->constrained('crm_funis');
            $table->foreignUuid('etapa_id')->constrained('crm_etapas');
            $table->uuid('responsavel_id');
            $table->string('status', 10)->default('ABERTO');
            $table->unsignedBigInteger('valor_centavos')->default(0);
            $table->date('previsao_fechamento')->nullable();
            $table->unsignedInteger('posicao_na_etapa')->default(0);
            $table->foreignUuid('motivo_de_perda_id')->nullable()->constrained('crm_motivos_de_perda');
            $table->text('observacao_da_perda')->nullable();
            $table->timestamp('fechado_em')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'funil_id', 'status']);
            $table->index(['tenant_id', 'etapa_id', 'posicao_na_etapa']);
            $table->index(['tenant_id', 'responsavel_id']);
            $table->index(['tenant_id', 'cliente_id']);
        });

        Schema::create('crm_itens_do_negocio', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('negocio_id')->constrained('crm_negocios')->cascadeOnDelete();
            $table->string('tipo', 10);
            $table->uuid('item_id');
            $table->string('descricao', 150);
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('valor_unitario_centavos');
            $table->timestamps();
            $table->index(['tenant_id', 'negocio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_itens_do_negocio');
        Schema::dropIfExists('crm_negocios');
        Schema::dropIfExists('crm_motivos_de_perda');
        Schema::dropIfExists('crm_etapas');
        Schema::dropIfExists('crm_funis');
        Schema::dropIfExists('crm_configuracoes');
    }
};
```

- [ ] **Step 4: Enums**

`StatusDoNegocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

enum StatusDoNegocio: string
{
    case Aberto = 'ABERTO';
    case Ganho = 'GANHO';
    case Perdido = 'PERDIDO';
}
```

`TipoDeItem.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

enum TipoDeItem: string
{
    case Produto = 'PRODUTO';
    case Servico = 'SERVICO';
}
```

- [ ] **Step 5: Models**

`ConfiguracaoDoCrm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property bool $vendedor_ve_so_os_seus
 */
class ConfiguracaoDoCrm extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'crm_configuracoes';

    protected $fillable = ['vendedor_ve_so_os_seus'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vendedor_ve_so_os_seus' => 'boolean'];
    }

    /** Sem linha de configuração vale o padrão: vendedor vê só os seus. */
    public static function vendedorVeSoOsSeus(): bool
    {
        $valor = self::query()->value('vendedor_ve_so_os_seus');

        return $valor === null ? true : (bool) $valor;
    }
}
```

`Funil.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\FunilFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $nome
 * @property bool $padrao
 * @property bool $ativo
 * @property int $posicao
 */
class Funil extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FunilFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_funis';

    protected $fillable = ['nome', 'padrao', 'ativo', 'posicao'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['padrao' => 'boolean', 'ativo' => 'boolean', 'posicao' => 'integer'];
    }

    /** @return HasMany<Etapa, $this> */
    public function etapas(): HasMany
    {
        return $this->hasMany(Etapa::class, 'funil_id')->orderBy('posicao');
    }

    protected static function newFactory(): FunilFactory
    {
        return FunilFactory::new();
    }
}
```

`Etapa.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\EtapaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $funil_id
 * @property string $nome
 * @property int $posicao
 * @property int $probabilidade
 * @property bool $ativa
 */
class Etapa extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EtapaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_etapas';

    protected $fillable = ['funil_id', 'nome', 'posicao', 'probabilidade', 'ativa'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['posicao' => 'integer', 'probabilidade' => 'integer', 'ativa' => 'boolean'];
    }

    /** @return BelongsTo<Funil, $this> */
    public function funil(): BelongsTo
    {
        return $this->belongsTo(Funil::class, 'funil_id');
    }

    protected static function newFactory(): EtapaFactory
    {
        return EtapaFactory::new();
    }
}
```

`MotivoDePerda.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\MotivoDePerdaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $nome
 * @property bool $ativo
 */
class MotivoDePerda extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MotivoDePerdaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_motivos_de_perda';

    protected $fillable = ['nome', 'ativo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    protected static function newFactory(): MotivoDePerdaFactory
    {
        return MotivoDePerdaFactory::new();
    }
}
```

`Negocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\NegocioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $titulo
 * @property string $cliente_id
 * @property string $funil_id
 * @property string $etapa_id
 * @property string $responsavel_id
 * @property StatusDoNegocio $status
 * @property int $valor_centavos
 * @property ?CarbonInterface $previsao_fechamento
 * @property int $posicao_na_etapa
 * @property ?string $motivo_de_perda_id
 * @property ?string $observacao_da_perda
 * @property ?CarbonInterface $fechado_em
 */
class Negocio extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NegocioFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_negocios';

    protected $fillable = [
        'titulo', 'cliente_id', 'funil_id', 'etapa_id', 'responsavel_id', 'status', 'valor_centavos',
        'previsao_fechamento', 'posicao_na_etapa', 'motivo_de_perda_id', 'observacao_da_perda', 'fechado_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatusDoNegocio::class,
            'valor_centavos' => 'integer',
            'posicao_na_etapa' => 'integer',
            'previsao_fechamento' => 'date',
            'fechado_em' => 'datetime',
        ];
    }

    /** @return HasMany<ItemDoNegocio, $this> */
    public function itens(): HasMany
    {
        return $this->hasMany(ItemDoNegocio::class, 'negocio_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * Visibilidade (spec §5): vendedor com o interruptor ligado só enxerga os próprios negócios.
     *
     * @param  Builder<Negocio>  $query
     * @return Builder<Negocio>
     */
    public function scopeVisiveisPara(Builder $query, Usuario $usuario): Builder
    {
        if ($usuario->papel === Papel::Vendedor && ConfiguracaoDoCrm::vendedorVeSoOsSeus()) {
            $query->where('responsavel_id', $usuario->id);
        }

        return $query;
    }

    public function garantirAberto(): void
    {
        if ($this->status !== StatusDoNegocio::Aberto) {
            throw RegraDoCrmException::negocioFechado();
        }
    }

    protected static function newFactory(): NegocioFactory
    {
        return NegocioFactory::new();
    }
}
```

`ItemDoNegocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot do item no momento em que entrou no negócio (spec D3).
 *
 * @property string $id
 * @property string $negocio_id
 * @property TipoDeItem $tipo
 * @property string $item_id
 * @property string $descricao
 * @property int $quantidade
 * @property int $valor_unitario_centavos
 */
class ItemDoNegocio extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'crm_itens_do_negocio';

    protected $fillable = ['tipo', 'item_id', 'descricao', 'quantidade', 'valor_unitario_centavos'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tipo' => TipoDeItem::class, 'quantidade' => 'integer', 'valor_unitario_centavos' => 'integer'];
    }
}
```

`RegraDoCrmException.php` (necessária já aqui porque `Negocio` a referencia):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class RegraDoCrmException extends ErroDeNegocio
{
    public function __construct(string $mensagem, private readonly string $codigoDoErro)
    {
        parent::__construct($mensagem);
    }

    public static function negocioFechado(): self
    {
        return new self('Negócio fechado não pode ser alterado.', 'NEGOCIO_FECHADO');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return $this->codigoDoErro;
    }
}
```

- [ ] **Step 6: Factories**

`FunilFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Models\Funil;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Funil> */
class FunilFactory extends Factory
{
    protected $model = Funil::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['nome' => fake()->words(2, true), 'padrao' => false, 'ativo' => true, 'posicao' => 0];
    }

    public function padrao(): static
    {
        return $this->state(fn (): array => ['padrao' => true, 'nome' => 'Vendas']);
    }
}
```

`EtapaFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Etapa> */
class EtapaFactory extends Factory
{
    protected $model = Etapa::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'funil_id' => Funil::factory(),
            'nome' => fake()->words(2, true),
            'posicao' => 0,
            'probabilidade' => 10,
            'ativa' => true,
        ];
    }
}
```

`MotivoDePerdaFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Models\MotivoDePerda;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MotivoDePerda> */
class MotivoDePerdaFactory extends Factory
{
    protected $model = MotivoDePerda::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['nome' => fake()->words(2, true), 'ativo' => true];
    }
}
```

`NegocioFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Negocio> */
class NegocioFactory extends Factory
{
    protected $model = Negocio::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'titulo' => fake()->sentence(3),
            'cliente_id' => fn (): string => Cliente::factory()->create()->id,
            'funil_id' => Funil::factory(),
            'etapa_id' => fn (array $atributos): string => Etapa::factory()->create(['funil_id' => $atributos['funil_id']])->id,
            'responsavel_id' => fn (): string => Usuario::factory()
                ->create(['tenant_id' => app(TenantContext::class)->require()])->id,
            'status' => StatusDoNegocio::Aberto,
            'valor_centavos' => 0,
            'posicao_na_etapa' => 0,
        ];
    }
}
```

- [ ] **Step 7: Provider, registro e trait de teste**

`CrmServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $rotas = __DIR__.'/../Http/routes-app.php';

        if (is_file($rotas)) {
            Route::middleware('api')->prefix('api/app/crm')->group($rotas);
        }
    }
}
```

Em `backend/bootstrap/providers.php`, importar `App\Modules\Crm\Providers\CrmServiceProvider` (em ordem alfabética entre `CatalogServiceProvider` e `CustomersServiceProvider`) e acrescentar `CrmServiceProvider::class,` depois de `CustomersServiceProvider::class,`.

`tests/Support/ComCrm.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;

trait ComCrm
{
    private Tenant $tenant;

    private Usuario $admin;

    protected function prepararCrm(Papel $papel = Papel::Admin): void
    {
        $this->tenant = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Crm))->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    protected function usuarioDoPapel(Papel $papel, ?Tenant $tenant = null): Usuario
    {
        return Usuario::factory()->for($tenant ?? $this->tenant)->create(['papel' => $papel]);
    }
}
```

- [ ] **Step 8: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=CrmModelosTest`
Expected: PASS (4 testes).

- [ ] **Step 9: Commit**

```bash
git add backend/database backend/app/Modules/Crm backend/bootstrap/providers.php backend/tests/Support backend/tests/Feature/Crm
git commit -m "feat(crm): esquema, Models e factories do módulo Crm"
```

---

### Task 2: Contratos e implementações em Customers, Catalog e Identity

**Files:**
- Create: `backend/app/Modules/Crm/Domain/Contracts/ConsultaDeClientes.php`, `CatalogoDeItens.php`, `DiretorioDeUsuarios.php`
- Create: `backend/app/Modules/Crm/Domain/ResumoDoCliente.php`, `ItemDescrito.php`
- Create: `backend/app/Modules/Customers/Application/ConsultaDeClientesDoCustomers.php`
- Create: `backend/app/Modules/Catalog/Application/CatalogoDeItensDoCatalog.php`
- Create: `backend/app/Modules/Identity/Application/DiretorioDeUsuariosDoIdentity.php`
- Modify: `CustomersServiceProvider.php`, `CatalogServiceProvider.php`, `IdentityServiceProvider.php` (método `register()` com o bind)
- Modify: `docs/superpowers/specs/2026-10-13-crm-f7a-funis-e-negocios-design.md` (terceiro contrato na tabela da §4)
- Test: `backend/tests/Feature/Crm/ContratosDoCrmTest.php`

**Interfaces:**
- Consumes: `CriarCliente::executar(Usuario, array): Cliente`, `ConverterEmCliente::executar(Cliente): Cliente`, Models `Cliente`, `Produto`, `Servico`, `Usuario`.
- Produces:
  - `ConsultaDeClientes::resumo(string $id): ?ResumoDoCliente`
  - `ConsultaDeClientes::resumos(array $ids): array` (mapa `id => ResumoDoCliente`)
  - `ConsultaDeClientes::criarLead(Usuario $autor, string $nome, ?string $telefone, ?string $email): string` (devolve o id)
  - `ConsultaDeClientes::converterEmCliente(string $id): void`
  - `CatalogoDeItens::descrever(TipoDeItem $tipo, string $itemId): ?ItemDescrito`
  - `DiretorioDeUsuarios::ehDaEmpresa(string $id): bool`, `DiretorioDeUsuarios::nomes(array $ids): array` (mapa `id => nome`)
  - `ResumoDoCliente(id, nome, telefone, email, ehLead)`; `ItemDescrito(tipo, itemId, descricao, valorUnitarioCentavos)`

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Crm\Domain\Contracts\CatalogoDeItens;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class ContratosDoCrmTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_cria_lead_pelo_contrato_de_clientes(): void
    {
        $id = app(ConsultaDeClientes::class)->criarLead($this->admin, 'Maria', '11999990000', null);

        $cliente = Cliente::query()->findOrFail($id);
        $this->assertSame(EstagioCliente::Lead, $cliente->estagio);
        $this->assertSame('Maria', $cliente->nome);
    }

    public function test_resumos_devolve_so_clientes_do_tenant(): void
    {
        $meu = Cliente::factory()->create();
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheio = Cliente::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $mapa = app(ConsultaDeClientes::class)->resumos([$meu->id, $alheio->id]);

        $this->assertSame([$meu->id], array_keys($mapa));
        $this->assertTrue($mapa[$meu->id]->ehLead);
    }

    public function test_converte_em_cliente(): void
    {
        $lead = Cliente::factory()->create();

        app(ConsultaDeClientes::class)->converterEmCliente($lead->id);

        $this->assertFalse(app(ConsultaDeClientes::class)->resumo($lead->id)?->ehLead);
    }

    public function test_descreve_produto_e_servico_com_preco_em_centavos(): void
    {
        $produto = Produto::factory()->create(['nome' => 'Caneca', 'preco_centavos' => 2500]);
        $servico = Servico::factory()->create(['nome' => 'Instalação', 'preco_centavos' => 9000]);
        $catalogo = app(CatalogoDeItens::class);

        $this->assertSame(2500, $catalogo->descrever(TipoDeItem::Produto, $produto->id)?->valorUnitarioCentavos);
        $this->assertSame('Instalação', $catalogo->descrever(TipoDeItem::Servico, $servico->id)?->descricao);
        $this->assertNull($catalogo->descrever(TipoDeItem::Produto, $servico->id));
    }

    public function test_diretorio_de_usuarios_respeita_o_tenant(): void
    {
        $meu = $this->usuarioDoPapel(Papel::Vendedor);
        $outro = Tenant::factory()->create();
        $alheio = $this->usuarioDoPapel(Papel::Vendedor, $outro);
        $diretorio = app(DiretorioDeUsuarios::class);

        $this->assertTrue($diretorio->ehDaEmpresa($meu->id));
        $this->assertFalse($diretorio->ehDaEmpresa($alheio->id));
        $this->assertSame([$meu->id => $meu->nome], $diretorio->nomes([$meu->id, $alheio->id]));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=ContratosDoCrmTest`
Expected: FAIL (`Target [App\Modules\Crm\Domain\Contracts\ConsultaDeClientes] is not instantiable`).

- [ ] **Step 3: Contratos e DTOs**

`ResumoDoCliente.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

final class ResumoDoCliente
{
    public function __construct(
        public readonly string $id,
        public readonly string $nome,
        public readonly ?string $telefone,
        public readonly ?string $email,
        public readonly bool $ehLead,
    ) {}
}
```

`ItemDescrito.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Domain\Enums\TipoDeItem;

final class ItemDescrito
{
    public function __construct(
        public readonly TipoDeItem $tipo,
        public readonly string $itemId,
        public readonly string $descricao,
        public readonly int $valorUnitarioCentavos,
    ) {}
}
```

`Contracts/ConsultaDeClientes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Contracts;

use App\Modules\Crm\Domain\ResumoDoCliente;
use App\Modules\Identity\Domain\Models\Usuario;

/** Implementado por Customers. O Crm nunca toca o Model de cliente. */
interface ConsultaDeClientes
{
    public function resumo(string $id): ?ResumoDoCliente;

    /**
     * @param  list<string>  $ids
     * @return array<string, ResumoDoCliente> mapa id => resumo; ids inexistentes no tenant ficam de fora
     */
    public function resumos(array $ids): array;

    /** Cria um lead (`estagio = LEAD`) e devolve o id. */
    public function criarLead(Usuario $autor, string $nome, ?string $telefone, ?string $email): string;

    public function converterEmCliente(string $id): void;
}
```

`Contracts/CatalogoDeItens.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Contracts;

use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Crm\Domain\ItemDescrito;

/** Implementado por Catalog. Devolve null quando o item não existe no tenant. */
interface CatalogoDeItens
{
    public function descrever(TipoDeItem $tipo, string $itemId): ?ItemDescrito;
}
```

`Contracts/DiretorioDeUsuarios.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Contracts;

/** Implementado por Identity. Sempre dentro do tenant do contexto atual. */
interface DiretorioDeUsuarios
{
    /** Usuário ativo da empresa atual. */
    public function ehDaEmpresa(string $id): bool;

    /**
     * @param  list<string>  $ids
     * @return array<string, string> mapa id => nome
     */
    public function nomes(array $ids): array;
}
```

- [ ] **Step 4: Implementações**

`Customers/Application/ConsultaDeClientesDoCustomers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\ResumoDoCliente;
use App\Modules\Customers\Application\Actions\ConverterEmCliente;
use App\Modules\Customers\Application\Actions\CriarCliente;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Models\Usuario;

final class ConsultaDeClientesDoCustomers implements ConsultaDeClientes
{
    public function __construct(
        private readonly CriarCliente $criar,
        private readonly ConverterEmCliente $converter,
    ) {}

    public function resumo(string $id): ?ResumoDoCliente
    {
        return $this->resumos([$id])[$id] ?? null;
    }

    public function resumos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $mapa = [];
        foreach (Cliente::query()->whereIn('id', $ids)->get() as $cliente) {
            $mapa[$cliente->id] = new ResumoDoCliente(
                $cliente->id,
                $cliente->nome,
                $cliente->telefone,
                $cliente->email,
                $cliente->estagio === EstagioCliente::Lead,
            );
        }

        return $mapa;
    }

    public function criarLead(Usuario $autor, string $nome, ?string $telefone, ?string $email): string
    {
        return $this->criar->executar($autor, [
            'tipo' => 'PF',
            'nome' => $nome,
            'telefone' => $telefone,
            'email' => $email === null ? null : mb_strtolower($email),
            'estagio' => 'LEAD',
            'tags' => [],
        ])->id;
    }

    public function converterEmCliente(string $id): void
    {
        $this->converter->executar(Cliente::query()->findOrFail($id));
    }
}
```

`Catalog/Application/CatalogoDeItensDoCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Crm\Domain\Contracts\CatalogoDeItens;
use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Crm\Domain\ItemDescrito;

final class CatalogoDeItensDoCatalog implements CatalogoDeItens
{
    public function descrever(TipoDeItem $tipo, string $itemId): ?ItemDescrito
    {
        $item = $tipo === TipoDeItem::Produto
            ? Produto::query()->find($itemId)
            : Servico::query()->find($itemId);

        return $item === null ? null : new ItemDescrito($tipo, $item->id, $item->nome, $item->preco_centavos);
    }
}
```

`Identity/Application/DiretorioDeUsuariosDoIdentity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\TenantContext;

final class DiretorioDeUsuariosDoIdentity implements DiretorioDeUsuarios
{
    public function __construct(private readonly TenantContext $contexto) {}

    public function ehDaEmpresa(string $id): bool
    {
        return Usuario::query()
            ->where('tenant_id', $this->contexto->require())
            ->where('ativo', true)
            ->whereKey($id)
            ->exists();
    }

    public function nomes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $nomes */
        $nomes = Usuario::query()
            ->where('tenant_id', $this->contexto->require())
            ->whereIn('id', $ids)
            ->pluck('nome', 'id')
            ->all();

        return $nomes;
    }
}
```

- [ ] **Step 5: Registrar os binds**

Em cada um dos três `ServiceProvider` (`final class`, hoje só com `boot()`), acrescentar acima de `boot()`:

```php
    public function register(): void
    {
        $this->app->bind(ConsultaDeClientes::class, ConsultaDeClientesDoCustomers::class);
    }
```

com os `use` correspondentes. Em `CatalogServiceProvider`: `$this->app->bind(CatalogoDeItens::class, CatalogoDeItensDoCatalog::class);`. Em `IdentityServiceProvider`: `$this->app->bind(DiretorioDeUsuarios::class, DiretorioDeUsuariosDoIdentity::class);`. Se o provider já tiver `register()`, acrescentar a linha nele.

- [ ] **Step 6: Atualizar a spec com o terceiro contrato**

Em `docs/superpowers/specs/2026-10-13-crm-f7a-funis-e-negocios-design.md`, §4, acrescentar à tabela de contratos a linha:

```
| `DiretorioDeUsuarios` | Identity | `ehDaEmpresa(id)` (usuário ativo da empresa atual), `nomes(ids)` (mapa id => nome) |
```

e, em §2, acrescentar à D6: "Inclui o diretório de usuários (responsável do negócio), para o `Crm` não importar o Model `Usuario`."

- [ ] **Step 7: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=ContratosDoCrmTest`
Expected: PASS (5 testes).

- [ ] **Step 8: Commit**

```bash
git add backend/app backend/tests docs/superpowers/specs/2026-10-13-crm-f7a-funis-e-negocios-design.md
git commit -m "feat(crm): contratos de clientes, catálogo e usuários"
```

---

### Task 3: Política, preparador e Actions de configuração

**Files:**
- Create: `backend/app/Modules/Crm/Application/PoliticaDoCrm.php`, `PreparadorDoCrm.php`
- Create: `backend/app/Modules/Crm/Application/Actions/AtualizarConfiguracao.php`, `CriarMotivoDePerda.php`, `AtualizarMotivoDePerda.php`, `CriarFunil.php`, `AtualizarFunil.php`, `CriarEtapa.php`, `AtualizarEtapa.php`, `ReordenarEtapas.php`
- Test: `backend/tests/Feature/Crm/ConfiguracaoDoCrmTest.php`

**Interfaces:**
- Consumes: Models da Task 1, `RegraDoCrmException(string $mensagem, string $codigo)`, `EntitlementService::garantirModulo(Tenant, Modulo)`, `AcessoNegadoException`.
- Produces:
  - `PoliticaDoCrm::garantirPodeLer(Usuario)`, `garantirPodeOperarNegocios(Usuario)`, `garantirPodeConfigurar(Usuario)`, `podeReatribuir(Usuario): bool`
  - `PreparadorDoCrm::garantir(Usuario $autor): void` (idempotente: funil padrão "Vendas" com Qualificação 10, Proposta 40, Negociação 70; motivos Preço, Concorrente, Sem resposta, Outro; configuração)
  - `AtualizarConfiguracao::executar(Usuario, bool $vendedorVeSoOsSeus): ConfiguracaoDoCrm`
  - `CriarMotivoDePerda::executar(Usuario, string $nome): MotivoDePerda`; `AtualizarMotivoDePerda::executar(Usuario, MotivoDePerda, array $dados): MotivoDePerda` (`nome?`, `ativo?`)
  - `CriarFunil::executar(Usuario, string $nome): Funil`; `AtualizarFunil::executar(Usuario, Funil, array $dados): Funil` (`nome?`, `ativo?`)
  - `CriarEtapa::executar(Usuario, Funil, string $nome, int $probabilidade): Etapa`; `AtualizarEtapa::executar(Usuario, Etapa, array $dados): Etapa` (`nome?`, `probabilidade?`, `ativa?`)
  - `ReordenarEtapas::executar(Usuario, Funil, array $idsEmOrdem): void`

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\AtualizarConfiguracao;
use App\Modules\Crm\Application\Actions\AtualizarEtapa;
use App\Modules\Crm\Application\Actions\AtualizarFunil;
use App\Modules\Crm\Application\Actions\AtualizarMotivoDePerda;
use App\Modules\Crm\Application\Actions\CriarEtapa;
use App\Modules\Crm\Application\Actions\CriarFunil;
use App\Modules\Crm\Application\Actions\CriarMotivoDePerda;
use App\Modules\Crm\Application\Actions\ReordenarEtapas;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class ConfiguracaoDoCrmTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_preparador_semeia_funil_etapas_e_motivos_uma_unica_vez(): void
    {
        app(PreparadorDoCrm::class)->garantir($this->admin);
        app(PreparadorDoCrm::class)->garantir($this->admin);

        $funil = Funil::query()->where('padrao', true)->sole();
        $this->assertSame('Vendas', $funil->nome);
        $this->assertSame([10, 40, 70], $funil->etapas->pluck('probabilidade')->all());
        $this->assertSame(4, MotivoDePerda::query()->count());
        $this->assertTrue(ConfiguracaoDoCrm::vendedorVeSoOsSeus());
    }

    public function test_so_proprietario_e_admin_configuram(): void
    {
        $this->expectException(AcessoNegadoException::class);

        app(CriarFunil::class)->executar($this->usuarioDoPapel(Papel::Vendedor), 'Pós-venda');
    }

    public function test_fiscal_nao_tem_acesso_ao_crm(): void
    {
        $this->expectException(AcessoNegadoException::class);

        app(CriarFunil::class)->executar($this->usuarioDoPapel(Papel::Fiscal), 'Pós-venda');
    }

    public function test_empresa_sem_modulo_crm_e_barrada(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->expectException(ModuloNaoContratadoException::class);

        app(CriarFunil::class)->executar($admin, 'Pós-venda');
    }

    public function test_cria_funil_e_etapa_nas_posicoes_seguintes(): void
    {
        $funil = app(CriarFunil::class)->executar($this->admin, 'Pós-venda');
        $a = app(CriarEtapa::class)->executar($this->admin, $funil, 'Aberto', 0);
        $b = app(CriarEtapa::class)->executar($this->admin, $funil, 'Resolvido', 100);

        $this->assertSame([0, 1], [$a->posicao, $b->posicao]);
    }

    public function test_probabilidade_fora_de_0_a_100_e_recusada(): void
    {
        $funil = Funil::factory()->create();

        $this->expectException(RegraDoCrmException::class);

        app(CriarEtapa::class)->executar($this->admin, $funil, 'X', 101);
    }

    public function test_funil_padrao_nao_desativa(): void
    {
        $funil = Funil::factory()->padrao()->create();

        try {
            app(AtualizarFunil::class)->executar($this->admin, $funil, ['ativo' => false]);
            $this->fail('Deveria recusar.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('FUNIL_PADRAO_PROTEGIDO', $e->codigo());
        }
    }

    public function test_etapa_com_negocio_aberto_nao_desativa_mas_sem_negocio_sim(): void
    {
        $funil = Funil::factory()->create();
        $comNegocio = Etapa::factory()->create(['funil_id' => $funil->id]);
        $vazia = Etapa::factory()->create(['funil_id' => $funil->id]);
        Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $comNegocio->id]);

        app(AtualizarEtapa::class)->executar($this->admin, $vazia, ['ativa' => false]);
        $this->assertFalse($vazia->fresh()->ativa);

        $this->expectException(RegraDoCrmException::class);
        app(AtualizarEtapa::class)->executar($this->admin, $comNegocio, ['ativa' => false]);
    }

    public function test_reordenar_exige_exatamente_as_etapas_do_funil(): void
    {
        $funil = Funil::factory()->create();
        $a = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 0]);
        $b = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 1]);

        app(ReordenarEtapas::class)->executar($this->admin, $funil, [$b->id, $a->id]);
        $this->assertSame([$b->id, $a->id], $funil->etapas()->pluck('id')->all());

        $this->expectException(RegraDoCrmException::class);
        app(ReordenarEtapas::class)->executar($this->admin, $funil, [$a->id]);
    }

    public function test_motivo_so_desativa_e_interruptor_atualiza(): void
    {
        $motivo = app(CriarMotivoDePerda::class)->executar($this->admin, 'Preço');
        app(AtualizarMotivoDePerda::class)->executar($this->admin, $motivo, ['ativo' => false]);
        $this->assertFalse($motivo->fresh()->ativo);

        app(AtualizarConfiguracao::class)->executar($this->admin, false);
        $this->assertFalse(ConfiguracaoDoCrm::vendedorVeSoOsSeus());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=ConfiguracaoDoCrmTest`
Expected: FAIL (classes não existem).

- [ ] **Step 3: Política e preparador**

`PoliticaDoCrm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem faz o quê no CRM (spec F7a §5). Fiscal não tem acesso. */
final class PoliticaDoCrm
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function garantirPodeLer(Usuario $autor): void
    {
        $this->garantir($autor, [Papel::Proprietario, Papel::Admin, Papel::Vendedor, Papel::Leitura], 'Você não tem acesso ao CRM.');
    }

    public function garantirPodeOperarNegocios(Usuario $autor): void
    {
        $this->garantir($autor, [Papel::Proprietario, Papel::Admin, Papel::Vendedor], 'Você não tem permissão para alterar negócios.');
    }

    public function garantirPodeConfigurar(Usuario $autor): void
    {
        $this->garantir($autor, [Papel::Proprietario, Papel::Admin], 'Só proprietário e administrador configuram o CRM.');
    }

    public function podeReatribuir(Usuario $autor): bool
    {
        return in_array($autor->papel, [Papel::Proprietario, Papel::Admin], true);
    }

    /** @param list<Papel> $papeis */
    private function garantir(Usuario $autor, array $papeis, string $mensagem): void
    {
        if (! in_array($autor->papel, $papeis, true) || $autor->tenant === null) {
            throw new AcessoNegadoException($mensagem);
        }

        $this->entitlements->garantirModulo($autor->tenant, Modulo::Crm);
    }
}
```

`PreparadorDoCrm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Semeia o CRM da empresa no primeiro uso. Idempotente. */
final class PreparadorDoCrm
{
    private const ETAPAS = [['Qualificação', 10], ['Proposta', 40], ['Negociação', 70]];

    private const MOTIVOS = ['Preço', 'Concorrente', 'Sem resposta', 'Outro'];

    public function garantir(Usuario $autor): void
    {
        DB::transaction(function () use ($autor): void {
            // Serializa a semeadura de uma mesma empresa.
            Tenant::query()->lockForUpdate()->findOrFail($autor->tenant_id);

            if (! Funil::query()->where('padrao', true)->exists()) {
                $funil = Funil::create(['nome' => 'Vendas', 'padrao' => true, 'ativo' => true, 'posicao' => 0]);
                foreach (self::ETAPAS as $posicao => [$nome, $probabilidade]) {
                    $funil->etapas()->create(['nome' => $nome, 'posicao' => $posicao, 'probabilidade' => $probabilidade, 'ativa' => true]);
                }
            }

            if (! MotivoDePerda::query()->exists()) {
                foreach (self::MOTIVOS as $nome) {
                    MotivoDePerda::create(['nome' => $nome, 'ativo' => true]);
                }
            }

            if (! ConfiguracaoDoCrm::query()->exists()) {
                ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
            }
        });
    }
}
```

- [ ] **Step 4: Actions de configuração**

`AtualizarConfiguracao.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Identity\Domain\Models\Usuario;

final class AtualizarConfiguracao
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, bool $vendedorVeSoOsSeus): ConfiguracaoDoCrm
    {
        $this->politica->garantirPodeConfigurar($autor);

        $configuracao = ConfiguracaoDoCrm::query()->first() ?? new ConfiguracaoDoCrm;
        $configuracao->fill(['vendedor_ve_so_os_seus' => $vendedorVeSoOsSeus])->save();

        return $configuracao;
    }
}
```

`CriarMotivoDePerda.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarMotivoDePerda
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, string $nome): MotivoDePerda
    {
        $this->politica->garantirPodeConfigurar($autor);

        return MotivoDePerda::create(['nome' => trim($nome), 'ativo' => true]);
    }
}
```

`AtualizarMotivoDePerda.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Identity\Domain\Models\Usuario;

final class AtualizarMotivoDePerda
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param array{nome?: string, ativo?: bool} $dados */
    public function executar(Usuario $autor, MotivoDePerda $motivo, array $dados): MotivoDePerda
    {
        $this->politica->garantirPodeConfigurar($autor);

        $motivo->update(array_intersect_key($dados, ['nome' => 1, 'ativo' => 1]));

        return $motivo;
    }
}
```

`CriarFunil.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarFunil
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, string $nome): Funil
    {
        $this->politica->garantirPodeConfigurar($autor);

        $ultima = Funil::query()->max('posicao');

        return Funil::create([
            'nome' => trim($nome),
            'padrao' => false,
            'ativo' => true,
            'posicao' => $ultima === null ? 0 : (int) $ultima + 1,
        ]);
    }
}
```

`AtualizarFunil.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;

final class AtualizarFunil
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param array{nome?: string, ativo?: bool} $dados */
    public function executar(Usuario $autor, Funil $funil, array $dados): Funil
    {
        $this->politica->garantirPodeConfigurar($autor);

        if (($dados['ativo'] ?? true) === false) {
            if ($funil->padrao) {
                throw new RegraDoCrmException('O funil padrão não pode ser desativado.', 'FUNIL_PADRAO_PROTEGIDO');
            }
            if (Negocio::query()->where('funil_id', $funil->id)->where('status', StatusDoNegocio::Aberto)->exists()) {
                throw new RegraDoCrmException('Mova ou feche os negócios abertos antes de desativar o funil.', 'FUNIL_COM_NEGOCIOS');
            }
        }

        $funil->update(array_intersect_key($dados, ['nome' => 1, 'ativo' => 1]));

        return $funil;
    }
}
```

`CriarEtapa.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarEtapa
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, Funil $funil, string $nome, int $probabilidade): Etapa
    {
        $this->politica->garantirPodeConfigurar($autor);

        if ($probabilidade < 0 || $probabilidade > 100) {
            throw new RegraDoCrmException('A probabilidade vai de 0 a 100.', 'PROBABILIDADE_INVALIDA');
        }

        $ultima = Etapa::query()->where('funil_id', $funil->id)->max('posicao');

        return Etapa::create([
            'funil_id' => $funil->id,
            'nome' => trim($nome),
            'posicao' => $ultima === null ? 0 : (int) $ultima + 1,
            'probabilidade' => $probabilidade,
            'ativa' => true,
        ]);
    }
}
```

`AtualizarEtapa.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;

final class AtualizarEtapa
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param array{nome?: string, probabilidade?: int, ativa?: bool} $dados */
    public function executar(Usuario $autor, Etapa $etapa, array $dados): Etapa
    {
        $this->politica->garantirPodeConfigurar($autor);

        if (isset($dados['probabilidade']) && ($dados['probabilidade'] < 0 || $dados['probabilidade'] > 100)) {
            throw new RegraDoCrmException('A probabilidade vai de 0 a 100.', 'PROBABILIDADE_INVALIDA');
        }

        if (($dados['ativa'] ?? true) === false
            && Negocio::query()->where('etapa_id', $etapa->id)->where('status', StatusDoNegocio::Aberto)->exists()) {
            throw new RegraDoCrmException('Mova os negócios abertos antes de desativar a etapa.', 'ETAPA_COM_NEGOCIOS');
        }

        $etapa->update(array_intersect_key($dados, ['nome' => 1, 'probabilidade' => 1, 'ativa' => 1]));

        return $etapa;
    }
}
```

`ReordenarEtapas.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ReordenarEtapas
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param list<string> $idsEmOrdem todas as etapas do funil, na nova ordem */
    public function executar(Usuario $autor, Funil $funil, array $idsEmOrdem): void
    {
        $this->politica->garantirPodeConfigurar($autor);

        /** @var list<string> $atuais */
        $atuais = Etapa::query()->where('funil_id', $funil->id)->pluck('id')->all();
        $pedidos = array_values(array_unique($idsEmOrdem));
        sort($atuais);
        $ordenados = $pedidos;
        sort($ordenados);

        if ($ordenados !== $atuais || count($pedidos) !== count($idsEmOrdem)) {
            throw new RegraDoCrmException('Informe todas as etapas do funil, sem repetir.', 'ORDEM_INVALIDA');
        }

        DB::transaction(function () use ($pedidos): void {
            foreach ($pedidos as $posicao => $id) {
                Etapa::query()->whereKey($id)->update(['posicao' => $posicao]);
            }
        });
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=ConfiguracaoDoCrmTest`
Expected: PASS (10 testes).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm
git commit -m "feat(crm): política, preparador e Actions de configuração"
```

---

### Task 4: API de configuração (funis, etapas, motivos, interruptor)

**Files:**
- Create: `backend/app/Modules/Crm/Http/routes-app.php`
- Create: `backend/app/Modules/Crm/Http/Controllers/FunisController.php`, `EtapasController.php`, `MotivosDePerdaController.php`, `ConfiguracaoController.php`
- Create: `backend/app/Modules/Crm/Http/Requests/FunilRequest.php`, `EtapaRequest.php`, `OrdemDasEtapasRequest.php`, `MotivoDePerdaRequest.php`, `ConfiguracaoRequest.php`
- Create: `backend/app/Modules/Crm/Http/Resources/FunilResource.php`, `EtapaResource.php`, `MotivoDePerdaResource.php`, `ConfiguracaoResource.php`
- Test: `backend/tests/Feature/Crm/ConfiguracaoApiTest.php`

**Interfaces:**
- Consumes: Actions e `PreparadorDoCrm` da Task 3.
- Produces (JSON): `GET /api/app/crm/funis` → `{data: [{id, nome, padrao, ativo, posicao, etapas: [{id, funil_id, nome, posicao, probabilidade, ativa}]}]}` (inclui inativos; chama `PreparadorDoCrm::garantir` antes). `GET motivos-de-perda` → `{data: [{id, nome, ativo}]}`. `GET configuracao` → `{data: {vendedor_ve_so_os_seus}}`. Leitura exige `garantirPodeLer`.

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class ConfiguracaoApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_lista_semeia_o_funil_padrao_na_primeira_vez(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/funis')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Vendas')
            ->assertJsonPath('data.0.padrao', true)
            ->assertJsonCount(3, 'data.0.etapas');
    }

    public function test_cria_funil_etapa_e_reordena(): void
    {
        $id = $this->spaComo($this->admin)->postJson('/api/app/crm/funis', ['nome' => 'Pós-venda'])
            ->assertCreated()->json('data.id');
        $a = $this->spaComo($this->admin)->postJson("/api/app/crm/funis/{$id}/etapas", ['nome' => 'A', 'probabilidade' => 0])
            ->assertCreated()->json('data.id');
        $b = $this->spaComo($this->admin)->postJson("/api/app/crm/funis/{$id}/etapas", ['nome' => 'B', 'probabilidade' => 50])
            ->assertCreated()->json('data.id');

        $this->spaComo($this->admin)->putJson("/api/app/crm/funis/{$id}/etapas/ordem", ['etapas' => [$b, $a]])
            ->assertOk();
        $this->spaComo($this->admin)->putJson("/api/app/crm/etapas/{$a}", ['probabilidade' => 20])
            ->assertOk()->assertJsonPath('data.probabilidade', 20);
    }

    public function test_probabilidade_invalida_devolve_422(): void
    {
        $funil = Funil::factory()->create();

        $this->spaComo($this->admin)->postJson("/api/app/crm/funis/{$funil->id}/etapas", ['nome' => 'X', 'probabilidade' => 101])
            ->assertStatus(422);
    }

    public function test_motivos_e_configuracao(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/crm/motivos-de-perda', ['nome' => 'Prazo'])->assertCreated();
        $this->spaComo($this->admin)->getJson('/api/app/crm/motivos-de-perda')->assertOk()->assertJsonFragment(['nome' => 'Prazo']);
        $this->spaComo($this->admin)->putJson('/api/app/crm/configuracao', ['vendedor_ve_so_os_seus' => false])
            ->assertOk()->assertJsonPath('data.vendedor_ve_so_os_seus', false);
    }

    public function test_vendedor_le_mas_nao_configura(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);

        $this->spaComo($vendedor)->getJson('/api/app/crm/funis')->assertOk();
        $this->spaComo($vendedor)->postJson('/api/app/crm/funis', ['nome' => 'X'])->assertForbidden();
        $this->spaComo($vendedor)->putJson('/api/app/crm/configuracao', ['vendedor_ve_so_os_seus' => false])->assertForbidden();
    }

    public function test_fiscal_e_barrado_ate_na_leitura(): void
    {
        $this->spaComo($this->usuarioDoPapel(Papel::Fiscal))->getJson('/api/app/crm/funis')->assertForbidden();
    }

    public function test_empresa_sem_modulo_crm_recebe_403_com_codigo(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($tenant->id);

        $this->spaComo($admin)->getJson('/api/app/crm/funis')
            ->assertForbidden()->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_isolamento_funil_de_outra_empresa_da_404(): void
    {
        $outra = Tenant::factory()->for(Plano::factory()->comModulos(\App\Modules\Platform\Domain\Enums\Modulo::Crm))->create();
        app(TenantContext::class)->set($outra->id);
        $alheio = Funil::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->putJson("/api/app/crm/funis/{$alheio->id}", ['nome' => 'Invadido'])->assertNotFound();
        $this->spaComo($this->admin)->postJson("/api/app/crm/funis/{$alheio->id}/etapas", ['nome' => 'X', 'probabilidade' => 1])
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=ConfiguracaoApiTest`
Expected: FAIL (404 nas rotas).

- [ ] **Step 3: Requests**

`FunilRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FunilRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $criando = $this->isMethod('POST');

        return [
            'nome' => [$criando ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
```

`EtapaRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EtapaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $criando = $this->isMethod('POST');

        return [
            'nome' => [$criando ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'probabilidade' => [$criando ? 'required' : 'sometimes', 'integer'],
            'ativa' => ['sometimes', 'boolean'],
        ];
    }
}
```

`OrdemDasEtapasRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OrdemDasEtapasRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['etapas' => ['required', 'array', 'min:1'], 'etapas.*' => ['uuid']];
    }
}
```

`MotivoDePerdaRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MotivoDePerdaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $criando = $this->isMethod('POST');

        return [
            'nome' => [$criando ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
```

`ConfiguracaoRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfiguracaoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['vendedor_ve_so_os_seus' => ['required', 'boolean']];
    }
}
```

- [ ] **Step 4: Resources**

`EtapaResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Domain\Models\Etapa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Etapa */
final class EtapaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'funil_id' => $this->funil_id,
            'nome' => $this->nome,
            'posicao' => $this->posicao,
            'probabilidade' => $this->probabilidade,
            'ativa' => $this->ativa,
        ];
    }
}
```

`FunilResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Domain\Models\Funil;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Funil */
final class FunilResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'padrao' => $this->padrao,
            'ativo' => $this->ativo,
            'posicao' => $this->posicao,
            'etapas' => EtapaResource::collection($this->whenLoaded('etapas')),
        ];
    }
}
```

`MotivoDePerdaResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Domain\Models\MotivoDePerda;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MotivoDePerda */
final class MotivoDePerdaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'nome' => $this->nome, 'ativo' => $this->ativo];
    }
}
```

`ConfiguracaoResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ConfiguracaoDoCrm */
final class ConfiguracaoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['vendedor_ve_so_os_seus' => $this->vendedor_ve_so_os_seus];
    }
}
```

- [ ] **Step 5: Controllers e rotas**

`FunisController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarFunil;
use App\Modules\Crm\Application\Actions\CriarFunil;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Http\Requests\FunilRequest;
use App\Modules\Crm\Http\Resources\FunilResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FunisController
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly PreparadorDoCrm $preparador,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);
        $this->preparador->garantir($autor);

        return FunilResource::collection(Funil::query()->with('etapas')->orderBy('posicao')->get());
    }

    public function store(FunilRequest $request, CriarFunil $criar): JsonResponse
    {
        $funil = $criar->executar($this->autor($request), $request->string('nome')->toString());

        return (new FunilResource($funil->load('etapas')))->response()->setStatusCode(201);
    }

    public function update(FunilRequest $request, string $id, AtualizarFunil $atualizar): FunilResource
    {
        $funil = Funil::query()->findOrFail($id);

        return new FunilResource($atualizar->executar($this->autor($request), $funil, $request->validated())->load('etapas'));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

`EtapasController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarEtapa;
use App\Modules\Crm\Application\Actions\CriarEtapa;
use App\Modules\Crm\Application\Actions\ReordenarEtapas;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Http\Requests\EtapaRequest;
use App\Modules\Crm\Http\Requests\OrdemDasEtapasRequest;
use App\Modules\Crm\Http\Resources\EtapaResource;
use App\Modules\Crm\Http\Resources\FunilResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EtapasController
{
    public function store(EtapaRequest $request, string $funilId, CriarEtapa $criar): JsonResponse
    {
        $funil = Funil::query()->findOrFail($funilId);
        $etapa = $criar->executar(
            $this->autor($request),
            $funil,
            $request->string('nome')->toString(),
            $request->integer('probabilidade'),
        );

        return (new EtapaResource($etapa))->response()->setStatusCode(201);
    }

    public function update(EtapaRequest $request, string $id, AtualizarEtapa $atualizar): EtapaResource
    {
        $etapa = Etapa::query()->findOrFail($id);

        return new EtapaResource($atualizar->executar($this->autor($request), $etapa, $request->validated()));
    }

    public function ordenar(OrdemDasEtapasRequest $request, string $funilId, ReordenarEtapas $reordenar): FunilResource
    {
        $funil = Funil::query()->findOrFail($funilId);
        /** @var list<string> $ids */
        $ids = $request->validated('etapas');
        $reordenar->executar($this->autor($request), $funil, $ids);

        return new FunilResource($funil->load('etapas'));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

`MotivosDePerdaController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarMotivoDePerda;
use App\Modules\Crm\Application\Actions\CriarMotivoDePerda;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Http\Requests\MotivoDePerdaRequest;
use App\Modules\Crm\Http\Resources\MotivoDePerdaResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MotivosDePerdaController
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly PreparadorDoCrm $preparador,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);
        $this->preparador->garantir($autor);

        return MotivoDePerdaResource::collection(MotivoDePerda::query()->orderBy('nome')->get());
    }

    public function store(MotivoDePerdaRequest $request, CriarMotivoDePerda $criar): JsonResponse
    {
        $motivo = $criar->executar($this->autor($request), $request->string('nome')->toString());

        return (new MotivoDePerdaResource($motivo))->response()->setStatusCode(201);
    }

    public function update(MotivoDePerdaRequest $request, string $id, AtualizarMotivoDePerda $atualizar): MotivoDePerdaResource
    {
        $motivo = MotivoDePerda::query()->findOrFail($id);

        return new MotivoDePerdaResource($atualizar->executar($this->autor($request), $motivo, $request->validated()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

`ConfiguracaoController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarConfiguracao;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Http\Requests\ConfiguracaoRequest;
use App\Modules\Crm\Http\Resources\ConfiguracaoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfiguracaoController
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $this->politica->garantirPodeLer($autor);

        return response()->json(['data' => ['vendedor_ve_so_os_seus' => ConfiguracaoDoCrm::vendedorVeSoOsSeus()]]);
    }

    public function update(ConfiguracaoRequest $request, AtualizarConfiguracao $atualizar): ConfiguracaoResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return new ConfiguracaoResource($atualizar->executar($autor, $request->boolean('vendedor_ve_so_os_seus')));
    }
}
```

`routes-app.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Crm\Http\Controllers\ConfiguracaoController;
use App\Modules\Crm\Http\Controllers\EtapasController;
use App\Modules\Crm\Http\Controllers\FunisController;
use App\Modules\Crm\Http\Controllers\MotivosDePerdaController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('funis', [FunisController::class, 'index'])->name('app.crm.funis.index');
    Route::post('funis', [FunisController::class, 'store'])->name('app.crm.funis.store');
    Route::put('funis/{id}', [FunisController::class, 'update'])->name('app.crm.funis.update');
    Route::post('funis/{funilId}/etapas', [EtapasController::class, 'store'])->name('app.crm.etapas.store');
    Route::put('funis/{funilId}/etapas/ordem', [EtapasController::class, 'ordenar'])->name('app.crm.etapas.ordenar');
    Route::put('etapas/{id}', [EtapasController::class, 'update'])->name('app.crm.etapas.update');

    Route::get('motivos-de-perda', [MotivosDePerdaController::class, 'index'])->name('app.crm.motivos.index');
    Route::post('motivos-de-perda', [MotivosDePerdaController::class, 'store'])->name('app.crm.motivos.store');
    Route::put('motivos-de-perda/{id}', [MotivosDePerdaController::class, 'update'])->name('app.crm.motivos.update');

    Route::get('configuracao', [ConfiguracaoController::class, 'show'])->name('app.crm.configuracao.show');
    Route::put('configuracao', [ConfiguracaoController::class, 'update'])->name('app.crm.configuracao.update');
});
```

- [ ] **Step 6: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=ConfiguracaoApiTest`
Expected: PASS (7 testes). Se a rota `etapas/ordem` for capturada por `etapas/{id}`, o `PUT funis/{funilId}/etapas/ordem` é distinto e não conflita.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm
git commit -m "feat(crm): API de funis, etapas, motivos de perda e configuração"
```

---

### Task 5: Eventos e Actions de criação e edição de negócio

**Files:**
- Create: `backend/app/Modules/Crm/Domain/Events/NegocioCriado.php`, `NegocioMovido.php`, `NegocioGanho.php`, `NegocioPerdido.php`
- Create: `backend/app/Modules/Crm/Application/Actions/DefinirItensDoNegocio.php`, `CriarNegocio.php`, `AtualizarNegocio.php`
- Test: `backend/tests/Feature/Crm/NegociosActionsTest.php`

**Interfaces:**
- Consumes: Task 1 a 3.
- Produces:
  - Eventos (todos `readonly`, `public` props): `NegocioCriado(tenantId, negocioId, clienteId, autorId)`, `NegocioMovido(tenantId, negocioId, clienteId, autorId, etapaOrigemId, etapaDestinoId)`, `NegocioGanho(tenantId, negocioId, clienteId, autorId)`, `NegocioPerdido(tenantId, negocioId, clienteId, autorId, motivoId)`
  - `DefinirItensDoNegocio::executar(Usuario, Negocio, array $itens): Negocio` e `aplicar(Negocio, array $itens): void` (sem autorização; reutilizado por `CriarNegocio`); item = `array{tipo: string, item_id: string, quantidade: int}`
  - `CriarNegocio::executar(Usuario, array $dados): Negocio` com `dados`: `titulo`, `funil_id`, `etapa_id`, `cliente_id?`, `novo_lead?: {nome, telefone?, email?}`, `responsavel_id?`, `previsao_fechamento?`, `valor_centavos?`, `itens?`
  - `AtualizarNegocio::executar(Usuario, Negocio, array $dados): Negocio` com `titulo?`, `previsao_fechamento?`, `responsavel_id?`, `valor_centavos?`

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Crm\Application\Actions\AtualizarNegocio;
use App\Modules\Crm\Application\Actions\CriarNegocio;
use App\Modules\Crm\Application\Actions\DefinirItensDoNegocio;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioCriado;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\ComCrm;
use Tests\TestCase;

class NegociosActionsTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Funil $funil;

    private Etapa $etapa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->funil = Funil::factory()->padrao()->create();
        $this->etapa = Etapa::factory()->create(['funil_id' => $this->funil->id]);
    }

    /** @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        return array_merge([
            'titulo' => 'Reforma',
            'funil_id' => $this->funil->id,
            'etapa_id' => $this->etapa->id,
            'cliente_id' => Cliente::factory()->create()->id,
        ], $extra);
    }

    public function test_cria_negocio_com_cliente_existente_e_responsavel_padrao(): void
    {
        Event::fake([NegocioCriado::class]);

        $negocio = app(CriarNegocio::class)->executar($this->admin, $this->dados(['valor_centavos' => 0]));

        $this->assertSame($this->admin->id, $negocio->responsavel_id);
        $this->assertSame(StatusDoNegocio::Aberto, $negocio->status);
        $this->assertSame(0, $negocio->valor_centavos);
        Event::assertDispatched(NegocioCriado::class, fn (NegocioCriado $e): bool => $e->negocioId === $negocio->id);
    }

    public function test_cria_lead_na_hora_e_posicoes_sequenciais(): void
    {
        $a = app(CriarNegocio::class)->executar($this->admin, $this->dados(['cliente_id' => null, 'novo_lead' => ['nome' => 'Ana', 'telefone' => '11999990000']]));
        $b = app(CriarNegocio::class)->executar($this->admin, $this->dados());

        $this->assertSame('Ana', Cliente::query()->findOrFail($a->cliente_id)->nome);
        $this->assertSame([0, 1], [$a->posicao_na_etapa, $b->posicao_na_etapa]);
    }

    public function test_cliente_de_outra_empresa_e_recusado_e_nada_e_criado(): void
    {
        $outra = Tenant::factory()->create();
        app(TenantContext::class)->set($outra->id);
        $alheio = Cliente::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        try {
            app(CriarNegocio::class)->executar($this->admin, $this->dados(['cliente_id' => $alheio->id]));
            $this->fail('Deveria recusar.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('CLIENTE_NAO_ENCONTRADO', $e->codigo());
        }
        $this->assertSame(0, Negocio::query()->count());
    }

    public function test_falha_depois_do_lead_nao_deixa_cliente_orfao(): void
    {
        $antes = Cliente::query()->count();

        try {
            app(CriarNegocio::class)->executar($this->admin, $this->dados([
                'cliente_id' => null,
                'novo_lead' => ['nome' => 'Orfao', 'telefone' => '11999990000'],
                'itens' => [['tipo' => 'PRODUTO', 'item_id' => (string) \Illuminate\Support\Str::uuid(), 'quantidade' => 1]],
            ]));
            $this->fail('Deveria recusar.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('ITEM_NAO_ENCONTRADO', $e->codigo());
        }

        $this->assertSame($antes, Cliente::query()->count());
    }

    public function test_etapa_de_outro_funil_e_recusada(): void
    {
        $outraEtapa = Etapa::factory()->create();

        $this->expectException(RegraDoCrmException::class);

        app(CriarNegocio::class)->executar($this->admin, $this->dados(['etapa_id' => $outraEtapa->id]));
    }

    public function test_valor_vira_soma_dos_itens_e_ignora_o_enviado(): void
    {
        $caneca = Produto::factory()->create(['preco_centavos' => 2500]);

        $negocio = app(CriarNegocio::class)->executar($this->admin, $this->dados([
            'valor_centavos' => 999999,
            'itens' => [['tipo' => 'PRODUTO', 'item_id' => $caneca->id, 'quantidade' => 3]],
        ]));

        $this->assertSame(7500, $negocio->valor_centavos);
        $this->assertSame(2500, $negocio->itens->first()->valor_unitario_centavos);
    }

    public function test_item_de_outra_empresa_e_recusado(): void
    {
        $outra = Tenant::factory()->create();
        app(TenantContext::class)->set($outra->id);
        $alheio = Produto::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);
        $negocio = Negocio::factory()->create(['funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id, 'responsavel_id' => $this->admin->id]);

        $this->expectException(RegraDoCrmException::class);

        app(DefinirItensDoNegocio::class)->executar($this->admin, $negocio, [['tipo' => 'PRODUTO', 'item_id' => $alheio->id, 'quantidade' => 1]]);
    }

    public function test_vendedor_nao_atribui_outro_responsavel(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $outro = $this->usuarioDoPapel(Papel::Vendedor);

        $this->expectException(AcessoNegadoException::class);

        app(CriarNegocio::class)->executar($vendedor, $this->dados(['responsavel_id' => $outro->id]));
    }

    public function test_admin_atribui_responsavel_da_empresa_mas_nao_de_fora(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $negocio = app(CriarNegocio::class)->executar($this->admin, $this->dados(['responsavel_id' => $vendedor->id]));
        $this->assertSame($vendedor->id, $negocio->responsavel_id);

        $alheio = $this->usuarioDoPapel(Papel::Vendedor, Tenant::factory()->create());
        $this->expectException(RegraDoCrmException::class);
        app(CriarNegocio::class)->executar($this->admin, $this->dados(['responsavel_id' => $alheio->id]));
    }

    public function test_valor_digitado_so_vale_sem_itens(): void
    {
        $caneca = Produto::factory()->create(['preco_centavos' => 1000]);
        $negocio = app(CriarNegocio::class)->executar($this->admin, $this->dados(['itens' => [['tipo' => 'PRODUTO', 'item_id' => $caneca->id, 'quantidade' => 1]]]));

        $this->expectException(RegraDoCrmException::class);

        app(AtualizarNegocio::class)->executar($this->admin, $negocio, ['valor_centavos' => 5]);
    }

    public function test_negocio_fechado_nao_aceita_alteracao(): void
    {
        $negocio = Negocio::factory()->create([
            'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id,
            'responsavel_id' => $this->admin->id, 'status' => StatusDoNegocio::Ganho,
        ]);

        $this->expectException(RegraDoCrmException::class);

        app(AtualizarNegocio::class)->executar($this->admin, $negocio, ['titulo' => 'Novo']);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=NegociosActionsTest`
Expected: FAIL (classes não existem).

- [ ] **Step 3: Eventos**

`NegocioCriado.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Events;

final class NegocioCriado
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $negocioId,
        public readonly string $clienteId,
        public readonly ?string $autorId,
    ) {}
}
```

`NegocioMovido.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Events;

final class NegocioMovido
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $negocioId,
        public readonly string $clienteId,
        public readonly ?string $autorId,
        public readonly string $etapaOrigemId,
        public readonly string $etapaDestinoId,
    ) {}
}
```

`NegocioGanho.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Events;

final class NegocioGanho
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $negocioId,
        public readonly string $clienteId,
        public readonly ?string $autorId,
    ) {}
}
```

`NegocioPerdido.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Events;

final class NegocioPerdido
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $negocioId,
        public readonly string $clienteId,
        public readonly ?string $autorId,
        public readonly string $motivoId,
    ) {}
}
```

- [ ] **Step 4: Actions**

`DefinirItensDoNegocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\CatalogoDeItens;
use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class DefinirItensDoNegocio
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly CatalogoDeItens $catalogo,
    ) {}

    /** @param list<array{tipo: string, item_id: string, quantidade: int}> $itens */
    public function executar(Usuario $autor, Negocio $negocio, array $itens): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($negocio, $itens): Negocio {
            $negocio->garantirAberto();
            $this->aplicar($negocio, $itens);

            return $negocio->refresh()->load('itens');
        });
    }

    /**
     * Sem autorização: quem chama já autorizou. Substitui os itens com snapshot do catálogo e,
     * havendo itens, o valor do negócio passa a ser a soma deles.
     *
     * @param  list<array{tipo: string, item_id: string, quantidade: int}>  $itens
     */
    public function aplicar(Negocio $negocio, array $itens): void
    {
        $linhas = [];
        foreach ($itens as $item) {
            $descrito = $this->catalogo->descrever(TipoDeItem::from($item['tipo']), $item['item_id']);
            if ($descrito === null) {
                throw new RegraDoCrmException('Item não encontrado no catálogo.', 'ITEM_NAO_ENCONTRADO');
            }

            $linhas[] = [
                'tipo' => $descrito->tipo,
                'item_id' => $descrito->itemId,
                'descricao' => $descrito->descricao,
                'quantidade' => $item['quantidade'],
                'valor_unitario_centavos' => $descrito->valorUnitarioCentavos,
            ];
        }

        $negocio->itens()->delete();
        foreach ($linhas as $linha) {
            $negocio->itens()->create($linha);
        }

        if ($linhas !== []) {
            $negocio->update(['valor_centavos' => array_sum(array_map(
                fn (array $l): int => $l['quantidade'] * $l['valor_unitario_centavos'],
                $linhas,
            ))]);
        }
    }
}
```

`CriarNegocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioCriado;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Support\Facades\DB;

final class CriarNegocio
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly PreparadorDoCrm $preparador,
        private readonly ConsultaDeClientes $clientes,
        private readonly DiretorioDeUsuarios $usuarios,
        private readonly DefinirItensDoNegocio $itens,
    ) {}

    /**
     * @param  array{
     *     titulo: string, funil_id: string, etapa_id: string, cliente_id?: ?string,
     *     novo_lead?: ?array{nome: string, telefone?: ?string, email?: ?string},
     *     responsavel_id?: ?string, previsao_fechamento?: ?string, valor_centavos?: ?int,
     *     itens?: list<array{tipo: string, item_id: string, quantidade: int}>
     * }  $dados
     */
    public function executar(Usuario $autor, array $dados): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $dados): Negocio {
            $this->preparador->garantir($autor);

            $funil = Funil::query()->where('ativo', true)->find($dados['funil_id'])
                ?? throw new RegraDoCrmException('Funil não encontrado ou inativo.', 'FUNIL_INVALIDO');
            $etapa = Etapa::query()->where('funil_id', $funil->id)->where('ativa', true)->find($dados['etapa_id'])
                ?? throw new RegraDoCrmException('Etapa não encontrada neste funil.', 'ETAPA_INVALIDA');

            $responsavelId = $this->responsavel($autor, $dados['responsavel_id'] ?? null);
            $clienteId = $this->cliente($autor, $dados);

            $ultima = Negocio::query()->where('etapa_id', $etapa->id)->where('status', StatusDoNegocio::Aberto)->max('posicao_na_etapa');

            $negocio = Negocio::create([
                'titulo' => trim($dados['titulo']),
                'cliente_id' => $clienteId,
                'funil_id' => $funil->id,
                'etapa_id' => $etapa->id,
                'responsavel_id' => $responsavelId,
                'status' => StatusDoNegocio::Aberto,
                'valor_centavos' => $dados['valor_centavos'] ?? 0,
                'previsao_fechamento' => $dados['previsao_fechamento'] ?? null,
                'posicao_na_etapa' => $ultima === null ? 0 : (int) $ultima + 1,
            ]);

            if (($dados['itens'] ?? []) !== []) {
                $this->itens->aplicar($negocio, $dados['itens']);
            }

            DB::afterCommit(fn () => event(new NegocioCriado($autor->tenant_id ?? '', $negocio->id, $clienteId, $autor->id)));

            return $negocio->refresh()->load('itens');
        });
    }

    private function responsavel(Usuario $autor, ?string $pedido): string
    {
        if ($pedido === null || $pedido === $autor->id) {
            return $autor->id;
        }
        if (! $this->politica->podeReatribuir($autor)) {
            throw new AcessoNegadoException('Só proprietário e administrador atribuem outro responsável.');
        }
        if (! $this->usuarios->ehDaEmpresa($pedido)) {
            throw new RegraDoCrmException('Responsável não encontrado.', 'RESPONSAVEL_INVALIDO');
        }

        return $pedido;
    }

    /** @param array<string, mixed> $dados */
    private function cliente(Usuario $autor, array $dados): string
    {
        $id = $dados['cliente_id'] ?? null;
        if ($id !== null) {
            if ($this->clientes->resumo($id) === null) {
                throw new RegraDoCrmException('Cliente não encontrado.', 'CLIENTE_NAO_ENCONTRADO');
            }

            return $id;
        }

        $lead = $dados['novo_lead'] ?? null;
        if (! is_array($lead) || ($lead['nome'] ?? '') === '') {
            throw new RegraDoCrmException('Informe um cliente ou os dados do novo lead.', 'CLIENTE_OBRIGATORIO');
        }

        return $this->clientes->criarLead($autor, trim((string) $lead['nome']), $lead['telefone'] ?? null, $lead['email'] ?? null);
    }
}
```

`AtualizarNegocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Support\Facades\DB;

final class AtualizarNegocio
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly DiretorioDeUsuarios $usuarios,
    ) {}

    /** @param array{titulo?: string, previsao_fechamento?: ?string, responsavel_id?: ?string, valor_centavos?: ?int} $dados */
    public function executar(Usuario $autor, Negocio $negocio, array $dados): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $negocio, $dados): Negocio {
            $negocio->garantirAberto();
            $alteracoes = [];

            if (array_key_exists('titulo', $dados) && $dados['titulo'] !== null) {
                $alteracoes['titulo'] = trim($dados['titulo']);
            }
            if (array_key_exists('previsao_fechamento', $dados)) {
                $alteracoes['previsao_fechamento'] = $dados['previsao_fechamento'];
            }
            if (($dados['responsavel_id'] ?? null) !== null && $dados['responsavel_id'] !== $negocio->responsavel_id) {
                if (! $this->politica->podeReatribuir($autor)) {
                    throw new AcessoNegadoException('Só proprietário e administrador atribuem outro responsável.');
                }
                if (! $this->usuarios->ehDaEmpresa($dados['responsavel_id'])) {
                    throw new RegraDoCrmException('Responsável não encontrado.', 'RESPONSAVEL_INVALIDO');
                }
                $alteracoes['responsavel_id'] = $dados['responsavel_id'];
            }
            if (($dados['valor_centavos'] ?? null) !== null) {
                if ($negocio->itens()->exists()) {
                    throw new RegraDoCrmException('O valor é a soma dos itens. Altere os itens.', 'VALOR_DERIVADO_DOS_ITENS');
                }
                $alteracoes['valor_centavos'] = $dados['valor_centavos'];
            }

            $negocio->update($alteracoes);

            return $negocio->refresh()->load('itens');
        });
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=NegociosActionsTest`
Expected: PASS (10 testes).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm
git commit -m "feat(crm): criação, edição e itens do negócio, com eventos"
```

---

### Task 6: Mover, ganhar e perder negócio

**Files:**
- Create: `backend/app/Modules/Crm/Application/Actions/MoverNegocio.php`, `GanharNegocio.php`, `PerderNegocio.php`
- Test: `backend/tests/Feature/Crm/MoverGanharPerderTest.php`

**Interfaces:**
- Consumes: eventos e Models das tasks anteriores, `ConsultaDeClientes`.
- Produces: `MoverNegocio::executar(Usuario, Negocio, string $etapaId, int $posicao): Negocio` (posição baseada em 0, limitada ao tamanho da coluna); `GanharNegocio::executar(Usuario, Negocio): Negocio`; `PerderNegocio::executar(Usuario, Negocio, string $motivoId, ?string $observacao): Negocio`.

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\GanharNegocio;
use App\Modules\Crm\Application\Actions\MoverNegocio;
use App\Modules\Crm\Application\Actions\PerderNegocio;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioGanho;
use App\Modules\Crm\Domain\Events\NegocioMovido;
use App\Modules\Crm\Domain\Events\NegocioPerdido;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\ComCrm;
use Tests\TestCase;

class MoverGanharPerderTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Funil $funil;

    private Etapa $origem;

    private Etapa $destino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->funil = Funil::factory()->padrao()->create();
        $this->origem = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 0]);
        $this->destino = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 1]);
    }

    private function negocio(Etapa $etapa, int $posicao, array $extra = []): Negocio
    {
        return Negocio::factory()->create(array_merge([
            'funil_id' => $this->funil->id, 'etapa_id' => $etapa->id,
            'responsavel_id' => $this->admin->id, 'posicao_na_etapa' => $posicao,
        ], $extra));
    }

    /** @return list<string> */
    private function ordem(Etapa $etapa): array
    {
        return Negocio::query()->where('etapa_id', $etapa->id)->orderBy('posicao_na_etapa')->pluck('id')->all();
    }

    public function test_mover_entre_etapas_reindexa_origem_e_destino(): void
    {
        Event::fake([NegocioMovido::class]);
        $o0 = $this->negocio($this->origem, 0);
        $o1 = $this->negocio($this->origem, 1);
        $o2 = $this->negocio($this->origem, 2);
        $d0 = $this->negocio($this->destino, 0);
        $d1 = $this->negocio($this->destino, 1);

        app(MoverNegocio::class)->executar($this->admin, $o1, $this->destino->id, 1);

        $this->assertSame([$o0->id, $o2->id], $this->ordem($this->origem));
        $this->assertSame([$d0->id, $o1->id, $d1->id], $this->ordem($this->destino));
        $this->assertSame([0, 1], Negocio::query()->where('etapa_id', $this->origem->id)->orderBy('posicao_na_etapa')->pluck('posicao_na_etapa')->all());
        $this->assertSame([0, 1, 2], Negocio::query()->where('etapa_id', $this->destino->id)->orderBy('posicao_na_etapa')->pluck('posicao_na_etapa')->all());
        Event::assertDispatched(NegocioMovido::class, fn (NegocioMovido $e): bool => $e->etapaOrigemId === $this->origem->id && $e->etapaDestinoId === $this->destino->id);
    }

    public function test_reordenar_na_mesma_etapa_nao_dispara_evento_de_movimento(): void
    {
        Event::fake([NegocioMovido::class]);
        $a = $this->negocio($this->origem, 0);
        $b = $this->negocio($this->origem, 1);

        app(MoverNegocio::class)->executar($this->admin, $b, $this->origem->id, 0);

        $this->assertSame([$b->id, $a->id], $this->ordem($this->origem));
        Event::assertNotDispatched(NegocioMovido::class);
    }

    public function test_posicao_acima_do_tamanho_vai_para_o_fim(): void
    {
        $a = $this->negocio($this->origem, 0);
        $d0 = $this->negocio($this->destino, 0);

        app(MoverNegocio::class)->executar($this->admin, $a, $this->destino->id, 99);

        $this->assertSame([$d0->id, $a->id], $this->ordem($this->destino));
    }

    public function test_etapa_de_outro_funil_e_recusada(): void
    {
        $alheia = Etapa::factory()->create();
        $negocio = $this->negocio($this->origem, 0);

        $this->expectException(RegraDoCrmException::class);

        app(MoverNegocio::class)->executar($this->admin, $negocio, $alheia->id, 0);
    }

    public function test_ganhar_converte_lead_e_dispara_evento(): void
    {
        Event::fake([NegocioGanho::class]);
        $lead = Cliente::factory()->create();
        $negocio = $this->negocio($this->origem, 0, ['cliente_id' => $lead->id]);

        $ganho = app(GanharNegocio::class)->executar($this->admin, $negocio);

        $this->assertSame(StatusDoNegocio::Ganho, $ganho->status);
        $this->assertNotNull($ganho->fechado_em);
        $this->assertSame(EstagioCliente::Cliente, $lead->fresh()->estagio);
        Event::assertDispatched(NegocioGanho::class);
    }

    public function test_perder_exige_motivo_ativo_e_nao_converte_lead(): void
    {
        Event::fake([NegocioPerdido::class]);
        $lead = Cliente::factory()->create();
        $negocio = $this->negocio($this->origem, 0, ['cliente_id' => $lead->id]);
        $inativo = MotivoDePerda::factory()->create(['ativo' => false]);
        $motivo = MotivoDePerda::factory()->create();

        try {
            app(PerderNegocio::class)->executar($this->admin, $negocio, $inativo->id, null);
            $this->fail('Deveria recusar.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('MOTIVO_INVALIDO', $e->codigo());
        }

        $perdido = app(PerderNegocio::class)->executar($this->admin, $negocio, $motivo->id, 'Foi para o concorrente');

        $this->assertSame(StatusDoNegocio::Perdido, $perdido->status);
        $this->assertSame($motivo->id, $perdido->motivo_de_perda_id);
        $this->assertSame(EstagioCliente::Lead, $lead->fresh()->estagio);
        Event::assertDispatched(NegocioPerdido::class);
    }

    public function test_negocio_fechado_nao_move_nem_fecha_de_novo(): void
    {
        $fechado = $this->negocio($this->origem, 0, ['status' => StatusDoNegocio::Ganho]);

        $this->expectException(RegraDoCrmException::class);

        app(MoverNegocio::class)->executar($this->admin, $fechado, $this->destino->id, 0);
    }

    public function test_ganhar_negocio_ja_fechado_e_recusado(): void
    {
        $fechado = $this->negocio($this->origem, 0, ['status' => StatusDoNegocio::Perdido]);

        $this->expectException(RegraDoCrmException::class);

        app(GanharNegocio::class)->executar($this->admin, $fechado);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=MoverGanharPerderTest`
Expected: FAIL (classes não existem).

- [ ] **Step 3: Actions**

`MoverNegocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioMovido;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class MoverNegocio
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, Negocio $negocio, string $etapaId, int $posicao): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $negocio, $etapaId, $posicao): Negocio {
            // Serializa movimentos concorrentes do mesmo negócio.
            $negocio = Negocio::query()->lockForUpdate()->findOrFail($negocio->id);
            $negocio->garantirAberto();

            $destino = Etapa::query()
                ->where('funil_id', $negocio->funil_id)->where('ativa', true)->find($etapaId)
                ?? throw new RegraDoCrmException('Etapa não encontrada neste funil.', 'ETAPA_INVALIDA');

            $origemId = $negocio->etapa_id;

            $ids = Negocio::query()
                ->where('etapa_id', $destino->id)->where('status', StatusDoNegocio::Aberto)->where('id', '!=', $negocio->id)
                ->orderBy('posicao_na_etapa')->orderBy('id')->pluck('id')->all();
            array_splice($ids, max(0, min($posicao, count($ids))), 0, [$negocio->id]);

            $negocio->update(['etapa_id' => $destino->id]);
            $this->reindexar($ids);

            if ($origemId !== $destino->id) {
                $this->reindexar(Negocio::query()
                    ->where('etapa_id', $origemId)->where('status', StatusDoNegocio::Aberto)
                    ->orderBy('posicao_na_etapa')->orderBy('id')->pluck('id')->all());

                DB::afterCommit(fn () => event(new NegocioMovido(
                    $autor->tenant_id ?? '', $negocio->id, $negocio->cliente_id, $autor->id, $origemId, $destino->id,
                )));
            }

            return $negocio->refresh()->load('itens');
        });
    }

    /** @param list<string> $ids */
    private function reindexar(array $ids): void
    {
        foreach ($ids as $posicao => $id) {
            Negocio::query()->whereKey($id)->update(['posicao_na_etapa' => $posicao]);
        }
    }
}
```

`GanharNegocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioGanho;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class GanharNegocio
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultaDeClientes $clientes,
    ) {}

    public function executar(Usuario $autor, Negocio $negocio): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $negocio): Negocio {
            $negocio = Negocio::query()->lockForUpdate()->findOrFail($negocio->id);
            $negocio->garantirAberto();

            $negocio->update(['status' => StatusDoNegocio::Ganho, 'fechado_em' => now()]);

            if ($this->clientes->resumo($negocio->cliente_id)?->ehLead === true) {
                $this->clientes->converterEmCliente($negocio->cliente_id);
            }

            DB::afterCommit(fn () => event(new NegocioGanho($autor->tenant_id ?? '', $negocio->id, $negocio->cliente_id, $autor->id)));

            return $negocio->refresh()->load('itens');
        });
    }
}
```

`PerderNegocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioPerdido;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class PerderNegocio
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, Negocio $negocio, string $motivoId, ?string $observacao): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $negocio, $motivoId, $observacao): Negocio {
            $negocio = Negocio::query()->lockForUpdate()->findOrFail($negocio->id);
            $negocio->garantirAberto();

            $motivo = MotivoDePerda::query()->where('ativo', true)->find($motivoId)
                ?? throw new RegraDoCrmException('Escolha um motivo de perda válido.', 'MOTIVO_INVALIDO');

            $negocio->update([
                'status' => StatusDoNegocio::Perdido,
                'motivo_de_perda_id' => $motivo->id,
                'observacao_da_perda' => $observacao === null || trim($observacao) === '' ? null : trim($observacao),
                'fechado_em' => now(),
            ]);

            DB::afterCommit(fn () => event(new NegocioPerdido(
                $autor->tenant_id ?? '', $negocio->id, $negocio->cliente_id, $autor->id, $motivo->id,
            )));

            return $negocio->refresh()->load('itens');
        });
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=MoverGanharPerderTest`
Expected: PASS (8 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm
git commit -m "feat(crm): mover, ganhar e perder negócio"
```

---

### Task 7: API de negócios, visibilidade e isolamento

**Files:**
- Create: `backend/app/Modules/Crm/Application/ApresentadorDeNegocios.php`
- Create: `backend/app/Modules/Crm/Http/Controllers/NegociosController.php`
- Create: `backend/app/Modules/Crm/Http/Requests/CriarNegocioRequest.php`, `AtualizarNegocioRequest.php`, `ItensDoNegocioRequest.php`, `MoverNegocioRequest.php`, `PerderNegocioRequest.php`
- Modify: `backend/app/Modules/Crm/Http/routes-app.php`
- Test: `backend/tests/Feature/Crm/NegociosApiTest.php`

**Interfaces:**
- Consumes: Actions das Tasks 5 e 6, `ConsultaDeClientes::resumos`, `DiretorioDeUsuarios::nomes`.
- Produces (JSON de negócio): `{id, titulo, cliente: {id, nome, telefone, email, estagio: 'LEAD'|'CLIENTE'} | null, funil_id, etapa_id, responsavel: {id, nome} | null, status, valor_centavos, previsao_fechamento (Y-m-d|null), posicao_na_etapa, motivo_de_perda_id, observacao_da_perda, fechado_em (ISO|null), itens: [{id, tipo, item_id, descricao, quantidade, valor_unitario_centavos}]}`. Rotas: `GET negocios?funil_id&status&responsavel_id&cliente_id`, `POST negocios`, `GET negocios/{id}`, `PUT negocios/{id}`, `PUT negocios/{id}/itens`, `POST negocios/{id}/mover` (`{etapa_id, posicao}`), `POST negocios/{id}/ganhar`, `POST negocios/{id}/perder` (`{motivo_de_perda_id, observacao?}`).

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class NegociosApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Funil $funil;

    private Etapa $etapa;

    private Etapa $segunda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->funil = Funil::factory()->padrao()->create();
        $this->etapa = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 0]);
        $this->segunda = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 1]);
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
    }

    private function negocioDe(Usuario $responsavel, array $extra = []): Negocio
    {
        return Negocio::factory()->create(array_merge([
            'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id, 'responsavel_id' => $responsavel->id,
        ], $extra));
    }

    public function test_cria_negocio_com_lead_na_hora_e_lista_para_o_kanban(): void
    {
        $caneca = Produto::factory()->create(['nome' => 'Caneca', 'preco_centavos' => 2500]);

        $this->spaComo($this->admin)->postJson('/api/app/crm/negocios', [
            'titulo' => 'Brindes', 'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id,
            'novo_lead' => ['nome' => 'Ana', 'telefone' => '11999990000'],
            'itens' => [['tipo' => 'PRODUTO', 'item_id' => $caneca->id, 'quantidade' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.cliente.nome', 'Ana')
            ->assertJsonPath('data.cliente.estagio', 'LEAD')
            ->assertJsonPath('data.valor_centavos', 5000)
            ->assertJsonPath('data.responsavel.id', $this->admin->id)
            ->assertJsonPath('data.itens.0.descricao', 'Caneca');

        $this->spaComo($this->admin)->getJson("/api/app/crm/negocios?funil_id={$this->funil->id}&status=ABERTO")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_lead_sem_telefone_nem_email_devolve_422(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/crm/negocios', [
            'titulo' => 'X', 'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id,
            'novo_lead' => ['nome' => 'Ana'],
        ])->assertStatus(422)->assertJsonValidationErrors('novo_lead.telefone');
    }

    public function test_fluxo_mover_ganhar(): void
    {
        $negocio = $this->negocioDe($this->admin);

        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/mover", ['etapa_id' => $this->segunda->id, 'posicao' => 0])
            ->assertOk()->assertJsonPath('data.etapa_id', $this->segunda->id);
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/ganhar")
            ->assertOk()->assertJsonPath('data.status', 'GANHO');
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/mover", ['etapa_id' => $this->etapa->id, 'posicao' => 0])
            ->assertStatus(422)->assertJsonPath('codigo', 'NEGOCIO_FECHADO');
    }

    public function test_perder_exige_motivo(): void
    {
        $negocio = $this->negocioDe($this->admin);
        $motivo = MotivoDePerda::factory()->create();

        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/perder", [])->assertStatus(422);
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$negocio->id}/perder", ['motivo_de_perda_id' => $motivo->id])
            ->assertOk()->assertJsonPath('data.status', 'PERDIDO');
    }

    public function test_vendedor_so_ve_e_mexe_nos_proprios_negocios_com_interruptor_ligado(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $deJoao = $this->negocioDe($joao);
        $deMaria = $this->negocioDe($maria);

        $this->spaComo($joao)->getJson('/api/app/crm/negocios')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deJoao->id);
        $this->spaComo($joao)->getJson("/api/app/crm/negocios/{$deMaria->id}")->assertNotFound();
        $this->spaComo($joao)->putJson("/api/app/crm/negocios/{$deMaria->id}", ['titulo' => 'x'])->assertNotFound();
        $this->spaComo($joao)->putJson("/api/app/crm/negocios/{$deMaria->id}/itens", ['itens' => []])->assertNotFound();
        $this->spaComo($joao)->postJson("/api/app/crm/negocios/{$deMaria->id}/mover", ['etapa_id' => $this->segunda->id, 'posicao' => 0])->assertNotFound();
        $this->spaComo($joao)->postJson("/api/app/crm/negocios/{$deMaria->id}/ganhar")->assertNotFound();
        $this->spaComo($joao)->postJson("/api/app/crm/negocios/{$deMaria->id}/perder", ['motivo_de_perda_id' => MotivoDePerda::factory()->create()->id])->assertNotFound();
    }

    public function test_com_interruptor_desligado_vendedor_ve_todos(): void
    {
        ConfiguracaoDoCrm::query()->update(['vendedor_ve_so_os_seus' => false]);
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $this->negocioDe($this->admin);
        $this->negocioDe($joao);

        $this->spaComo($joao)->getJson('/api/app/crm/negocios')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_leitura_ve_todos_mas_nao_escreve(): void
    {
        $leitor = $this->usuarioDoPapel(Papel::Leitura);
        $negocio = $this->negocioDe($this->admin);

        $this->spaComo($leitor)->getJson('/api/app/crm/negocios')->assertOk()->assertJsonCount(1, 'data');
        $this->spaComo($leitor)->postJson("/api/app/crm/negocios/{$negocio->id}/ganhar")->assertForbidden();
    }

    public function test_fiscal_e_empresa_sem_modulo_sao_barrados(): void
    {
        $this->spaComo($this->usuarioDoPapel(Papel::Fiscal))->getJson('/api/app/crm/negocios')->assertForbidden();

        $semModulo = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($semModulo)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($semModulo->id);
        $this->spaComo($admin)->getJson('/api/app/crm/negocios')->assertForbidden()->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_isolamento_negocio_de_outra_empresa_da_404_em_todos_os_endpoints(): void
    {
        $outra = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Crm))->create();
        $donoAlheio = Usuario::factory()->for($outra)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($outra->id);
        $alheio = Negocio::factory()->create(['responsavel_id' => $donoAlheio->id]);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->getJson("/api/app/crm/negocios/{$alheio->id}")->assertNotFound();
        $this->spaComo($this->admin)->putJson("/api/app/crm/negocios/{$alheio->id}", ['titulo' => 'x'])->assertNotFound();
        $this->spaComo($this->admin)->putJson("/api/app/crm/negocios/{$alheio->id}/itens", ['itens' => []])->assertNotFound();
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$alheio->id}/mover", ['etapa_id' => $this->segunda->id, 'posicao' => 0])->assertNotFound();
        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$alheio->id}/ganhar")->assertNotFound();
        $this->spaComo($this->admin)->getJson('/api/app/crm/negocios')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cliente_de_outra_empresa_no_post_devolve_422(): void
    {
        $outra = Tenant::factory()->create();
        app(TenantContext::class)->set($outra->id);
        $alheio = Cliente::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->postJson('/api/app/crm/negocios', [
            'titulo' => 'X', 'funil_id' => $this->funil->id, 'etapa_id' => $this->etapa->id, 'cliente_id' => $alheio->id,
        ])->assertStatus(422)->assertJsonPath('codigo', 'CLIENTE_NAO_ENCONTRADO');
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=NegociosApiTest`
Expected: FAIL (rotas 404).

- [ ] **Step 3: Apresentador**

`ApresentadorDeNegocios.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Models\Negocio;

/** Monta o JSON do negócio buscando clientes e responsáveis em lote, pelos contratos. */
final class ApresentadorDeNegocios
{
    public function __construct(
        private readonly ConsultaDeClientes $clientes,
        private readonly DiretorioDeUsuarios $usuarios,
    ) {}

    /**
     * @param  iterable<Negocio>  $negocios
     * @return list<array<string, mixed>>
     */
    public function apresentar(iterable $negocios): array
    {
        $lista = [...$negocios];
        $clientes = $this->clientes->resumos(array_values(array_unique(array_map(fn (Negocio $n): string => $n->cliente_id, $lista))));
        $nomes = $this->usuarios->nomes(array_values(array_unique(array_map(fn (Negocio $n): string => $n->responsavel_id, $lista))));

        return array_map(function (Negocio $n) use ($clientes, $nomes): array {
            $cliente = $clientes[$n->cliente_id] ?? null;

            return [
                'id' => $n->id,
                'titulo' => $n->titulo,
                'cliente' => $cliente === null ? null : [
                    'id' => $cliente->id,
                    'nome' => $cliente->nome,
                    'telefone' => $cliente->telefone,
                    'email' => $cliente->email,
                    'estagio' => $cliente->ehLead ? 'LEAD' : 'CLIENTE',
                ],
                'funil_id' => $n->funil_id,
                'etapa_id' => $n->etapa_id,
                'responsavel' => isset($nomes[$n->responsavel_id]) ? ['id' => $n->responsavel_id, 'nome' => $nomes[$n->responsavel_id]] : null,
                'status' => $n->status->value,
                'valor_centavos' => $n->valor_centavos,
                'previsao_fechamento' => $n->previsao_fechamento?->format('Y-m-d'),
                'posicao_na_etapa' => $n->posicao_na_etapa,
                'motivo_de_perda_id' => $n->motivo_de_perda_id,
                'observacao_da_perda' => $n->observacao_da_perda,
                'fechado_em' => $n->fechado_em?->toIso8601String(),
                'itens' => $n->itens->map(fn ($i): array => [
                    'id' => $i->id,
                    'tipo' => $i->tipo->value,
                    'item_id' => $i->item_id,
                    'descricao' => $i->descricao,
                    'quantidade' => $i->quantidade,
                    'valor_unitario_centavos' => $i->valor_unitario_centavos,
                ])->values()->all(),
            ];
        }, $lista);
    }
}
```

- [ ] **Step 4: Requests**

`CriarNegocioRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use App\Modules\Crm\Domain\Enums\TipoDeItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'min:1', 'max:150'],
            'funil_id' => ['required', 'uuid'],
            'etapa_id' => ['required', 'uuid'],
            'cliente_id' => ['nullable', 'uuid', 'required_without:novo_lead'],
            'novo_lead' => ['nullable', 'array', 'required_without:cliente_id'],
            'novo_lead.nome' => ['required_with:novo_lead', 'string', 'max:150'],
            'novo_lead.telefone' => ['nullable', 'string', 'max:20'],
            'novo_lead.email' => ['nullable', 'string', 'email', 'max:150'],
            'responsavel_id' => ['nullable', 'uuid'],
            'previsao_fechamento' => ['nullable', 'date_format:Y-m-d'],
            'valor_centavos' => ['nullable', 'integer', 'min:0'],
            'itens' => ['nullable', 'array'],
            'itens.*.tipo' => ['required', Rule::enum(TipoDeItem::class)],
            'itens.*.item_id' => ['required', 'uuid'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $lead = $this->input('novo_lead');
            if (is_array($lead) && ($lead['telefone'] ?? '') === '' && ($lead['email'] ?? '') === '') {
                $v->errors()->add('novo_lead.telefone', 'Informe telefone ou e-mail do lead.');
            }
        });
    }
}
```

`AtualizarNegocioRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'titulo' => ['sometimes', 'string', 'min:1', 'max:150'],
            'previsao_fechamento' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'responsavel_id' => ['sometimes', 'nullable', 'uuid'],
            'valor_centavos' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
```

`ItensDoNegocioRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use App\Modules\Crm\Domain\Enums\TipoDeItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ItensDoNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'itens' => ['present', 'array'],
            'itens.*.tipo' => ['required', Rule::enum(TipoDeItem::class)],
            'itens.*.item_id' => ['required', 'uuid'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
```

`MoverNegocioRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MoverNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['etapa_id' => ['required', 'uuid'], 'posicao' => ['required', 'integer', 'min:0']];
    }
}
```

`PerderNegocioRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PerderNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['motivo_de_perda_id' => ['required', 'uuid'], 'observacao' => ['nullable', 'string', 'max:1000']];
    }
}
```

- [ ] **Step 5: Controller e rotas**

`NegociosController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarNegocio;
use App\Modules\Crm\Application\Actions\CriarNegocio;
use App\Modules\Crm\Application\Actions\DefinirItensDoNegocio;
use App\Modules\Crm\Application\Actions\GanharNegocio;
use App\Modules\Crm\Application\Actions\MoverNegocio;
use App\Modules\Crm\Application\Actions\PerderNegocio;
use App\Modules\Crm\Application\ApresentadorDeNegocios;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Http\Requests\AtualizarNegocioRequest;
use App\Modules\Crm\Http\Requests\CriarNegocioRequest;
use App\Modules\Crm\Http\Requests\ItensDoNegocioRequest;
use App\Modules\Crm\Http\Requests\MoverNegocioRequest;
use App\Modules\Crm\Http\Requests\PerderNegocioRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NegociosController
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ApresentadorDeNegocios $apresentador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);

        $status = StatusDoNegocio::tryFrom($request->string('status', 'ABERTO')->toString()) ?? StatusDoNegocio::Aberto;
        $consulta = Negocio::query()->visiveisPara($autor)->with('itens')->where('status', $status);

        foreach (['funil_id', 'responsavel_id', 'cliente_id'] as $filtro) {
            if ($request->filled($filtro)) {
                $consulta->where($filtro, $request->string($filtro)->toString());
            }
        }

        $consulta = $status === StatusDoNegocio::Aberto
            ? $consulta->orderBy('posicao_na_etapa')->orderBy('id')
            : $consulta->orderByDesc('fechado_em')->orderBy('id');

        return response()->json(['data' => $this->apresentador->apresentar($consulta->get())]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);

        return $this->um($this->doUsuario($autor, $id));
    }

    public function store(CriarNegocioRequest $request, CriarNegocio $criar): JsonResponse
    {
        /** @var array<string, mixed> $dados */
        $dados = $request->validated();

        return $this->um($criar->executar($this->autor($request), $dados), 201);
    }

    public function update(AtualizarNegocioRequest $request, string $id, AtualizarNegocio $atualizar): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($atualizar->executar($autor, $this->doUsuario($autor, $id), $request->validated()));
    }

    public function itens(ItensDoNegocioRequest $request, string $id, DefinirItensDoNegocio $definir): JsonResponse
    {
        $autor = $this->autor($request);
        /** @var list<array{tipo: string, item_id: string, quantidade: int}> $itens */
        $itens = $request->validated('itens');

        return $this->um($definir->executar($autor, $this->doUsuario($autor, $id), $itens));
    }

    public function mover(MoverNegocioRequest $request, string $id, MoverNegocio $mover): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($mover->executar(
            $autor,
            $this->doUsuario($autor, $id),
            $request->string('etapa_id')->toString(),
            $request->integer('posicao'),
        ));
    }

    public function ganhar(Request $request, string $id, GanharNegocio $ganhar): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($ganhar->executar($autor, $this->doUsuario($autor, $id)));
    }

    public function perder(PerderNegocioRequest $request, string $id, PerderNegocio $perder): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->um($perder->executar(
            $autor,
            $this->doUsuario($autor, $id),
            $request->string('motivo_de_perda_id')->toString(),
            $request->filled('observacao') ? $request->string('observacao')->toString() : null,
        ));
    }

    /** 404 (e não 403) para negócio fora da visibilidade do usuário. */
    private function doUsuario(Usuario $autor, string $id): Negocio
    {
        return Negocio::query()->visiveisPara($autor)->with('itens')->findOrFail($id);
    }

    private function um(Negocio $negocio, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->apresentador->apresentar([$negocio])[0]], $status);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

Acrescentar a `routes-app.php`, dentro do grupo `empresa`, e o `use` do controller:

```php
    Route::get('negocios', [NegociosController::class, 'index'])->name('app.crm.negocios.index');
    Route::post('negocios', [NegociosController::class, 'store'])->name('app.crm.negocios.store');
    Route::get('negocios/{id}', [NegociosController::class, 'show'])->name('app.crm.negocios.show');
    Route::put('negocios/{id}', [NegociosController::class, 'update'])->name('app.crm.negocios.update');
    Route::put('negocios/{id}/itens', [NegociosController::class, 'itens'])->name('app.crm.negocios.itens');
    Route::post('negocios/{id}/mover', [NegociosController::class, 'mover'])->name('app.crm.negocios.mover');
    Route::post('negocios/{id}/ganhar', [NegociosController::class, 'ganhar'])->name('app.crm.negocios.ganhar');
    Route::post('negocios/{id}/perder', [NegociosController::class, 'perder'])->name('app.crm.negocios.perder');
```

- [ ] **Step 6: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=NegociosApiTest`
Expected: PASS (10 testes).

- [ ] **Step 7: Rodar a suíte completa do backend**

Run: `cd backend && php artisan test`
Expected: tudo verde, sem regressão em Customers, Catalog e Identity (os binds novos não alteram comportamento existente).

- [ ] **Step 8: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm
git commit -m "feat(crm): API de negócios com visibilidade por vendedor"
```

---

### Task 8: Frontend base (dependência, tipos, API, hooks, menu)

**Files:**
- Modify: `frontend/package.json` (via `npm i`), `frontend/components/layout/nav-items.ts`, `frontend/components/layout/MenuDeNavegacao.tsx`, `frontend/components/layout/nav-items.test.ts`
- Create: `frontend/features/crm/types.ts`, `api.ts`, `movimento.ts`, `movimento.test.ts`
- Create: `frontend/features/crm/hooks/useFunis.ts`, `useNegocios.ts`, `useMotivosDePerda.ts`, `useConfiguracaoCrm.ts`
- Test: `frontend/features/crm/movimento.test.ts`, `frontend/components/layout/nav-items.test.ts`

**Interfaces:**
- Consumes: `api` de `@/lib/api` (`api<T>(path, init)` com `ApiError`), `useAssinatura` (`data.plano?.modulos`), `Papel`.
- Produces:
  - Tipos: `StatusDoNegocio`, `Etapa`, `Funil`, `MotivoDePerda`, `ConfiguracaoDoCrm`, `ItemDoNegocio`, `ClienteDoNegocio`, `Negocio`, `NovoNegocioPayload`.
  - `crmApi` (métodos nas rotas da Task 4 e 7).
  - `QUERY_KEY_CRM = ['crm'] as const`; `aplicarMovimento(negocios: Negocio[], id: string, etapaId: string, posicao: number): Negocio[]`; `destinoDoArraste(overId: string, negocios: Negocio[], movidoId: string): { etapaId: string; posicao: number } | null` (ids de coluna têm prefixo `etapa:`).
  - Hooks: `useFunis()`, `useNegocios(funilId, status)`, `useMoverNegocio(funilId)`, `useMotivosDePerda()`, `useConfiguracaoCrm()`.
  - `NavItem.modulo?: Modulo`; `itensVisiveis(itens, papel, modulos?)`.

- [ ] **Step 1: Instalar a dependência**

Run: `cd frontend && npm i @dnd-kit/core @dnd-kit/sortable @dnd-kit/utilities`
Expected: instala sem erro; `package.json` ganha as três entradas.

- [ ] **Step 2: Escrever os testes que falham**

`features/crm/movimento.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { aplicarMovimento, destinoDoArraste } from './movimento';
import type { Negocio } from './types';

function n(id: string, etapa: string, posicao: number): Negocio {
  return {
    id, titulo: id, cliente: null, funil_id: 'f', etapa_id: etapa, responsavel: null, status: 'ABERTO',
    valor_centavos: 0, previsao_fechamento: null, posicao_na_etapa: posicao, motivo_de_perda_id: null,
    observacao_da_perda: null, fechado_em: null, itens: [],
  };
}

describe('aplicarMovimento', () => {
  it('move para outra etapa e reindexa as duas colunas', () => {
    const lista = [n('a', 'e1', 0), n('b', 'e1', 1), n('c', 'e2', 0)];
    const r = aplicarMovimento(lista, 'a', 'e2', 0);

    expect(r.filter((x) => x.etapa_id === 'e1').map((x) => [x.id, x.posicao_na_etapa])).toEqual([['b', 0]]);
    expect(r.filter((x) => x.etapa_id === 'e2').sort((x, y) => x.posicao_na_etapa - y.posicao_na_etapa).map((x) => x.id)).toEqual(['a', 'c']);
  });

  it('posição acima do tamanho vai para o fim', () => {
    const r = aplicarMovimento([n('a', 'e1', 0), n('c', 'e2', 0)], 'a', 'e2', 99);
    expect(r.find((x) => x.id === 'a')?.posicao_na_etapa).toBe(1);
  });
});

describe('destinoDoArraste', () => {
  const lista = [n('a', 'e1', 0), n('b', 'e1', 1), n('c', 'e2', 0)];

  it('soltar na coluna vai para o fim dela', () => {
    expect(destinoDoArraste('etapa:e2', lista, 'a')).toEqual({ etapaId: 'e2', posicao: 1 });
  });

  it('soltar sobre um cartão entra na posição dele', () => {
    expect(destinoDoArraste('c', lista, 'a')).toEqual({ etapaId: 'e2', posicao: 0 });
  });

  it('soltar sobre si mesmo não move', () => {
    expect(destinoDoArraste('a', lista, 'a')).toBeNull();
  });
});
```

Em `components/layout/nav-items.test.ts`, acrescentar:

```ts
import { Briefcase } from 'lucide-react';

describe('itensVisiveis com módulo', () => {
  const crm = { href: '/crm/negocios', label: 'CRM', icon: Briefcase, papeis: ['VENDEDOR' as const], modulo: 'CRM' as const };

  it('esconde item de módulo ausente do plano', () => {
    expect(itensVisiveis([crm], 'VENDEDOR', ['FISCAL_NFE'])).toEqual([]);
  });

  it('mostra com o módulo no plano e papel permitido', () => {
    expect(itensVisiveis([crm], 'VENDEDOR', ['CRM'])).toEqual([crm]);
  });

  it('esconde enquanto a lista de módulos não carregou', () => {
    expect(itensVisiveis([crm], 'VENDEDOR', undefined)).toEqual([]);
  });
});
```

(ajustar o import de `itensVisiveis` e `describe` conforme o topo do arquivo existente.)

- [ ] **Step 3: Rodar e ver falhar**

Run: `cd frontend && npx vitest run features/crm/movimento.test.ts components/layout/nav-items.test.ts`
Expected: FAIL (`./movimento` e `modulo` não existem).

- [ ] **Step 4: Tipos**

`features/crm/types.ts`:

```ts
export type StatusDoNegocio = 'ABERTO' | 'GANHO' | 'PERDIDO';
export type TipoDeItem = 'PRODUTO' | 'SERVICO';

export interface Etapa {
  id: string;
  funil_id: string;
  nome: string;
  posicao: number;
  probabilidade: number;
  ativa: boolean;
}

export interface Funil {
  id: string;
  nome: string;
  padrao: boolean;
  ativo: boolean;
  posicao: number;
  etapas: Etapa[];
}

export interface MotivoDePerda {
  id: string;
  nome: string;
  ativo: boolean;
}

export interface ConfiguracaoDoCrm {
  vendedor_ve_so_os_seus: boolean;
}

export interface ItemDoNegocio {
  id: string;
  tipo: TipoDeItem;
  item_id: string;
  descricao: string;
  quantidade: number;
  valor_unitario_centavos: number;
}

export interface ClienteDoNegocio {
  id: string;
  nome: string;
  telefone: string | null;
  email: string | null;
  estagio: 'LEAD' | 'CLIENTE';
}

export interface Negocio {
  id: string;
  titulo: string;
  cliente: ClienteDoNegocio | null;
  funil_id: string;
  etapa_id: string;
  responsavel: { id: string; nome: string } | null;
  status: StatusDoNegocio;
  valor_centavos: number;
  previsao_fechamento: string | null;
  posicao_na_etapa: number;
  motivo_de_perda_id: string | null;
  observacao_da_perda: string | null;
  fechado_em: string | null;
  itens: ItemDoNegocio[];
}

export interface ItemEntrada {
  tipo: TipoDeItem;
  item_id: string;
  quantidade: number;
}

export interface NovoNegocioPayload {
  titulo: string;
  funil_id: string;
  etapa_id: string;
  cliente_id?: string;
  novo_lead?: { nome: string; telefone?: string; email?: string };
  previsao_fechamento?: string;
  valor_centavos?: number;
  itens?: ItemEntrada[];
}
```

- [ ] **Step 5: Movimento (lógica pura)**

`features/crm/movimento.ts`:

```ts
import type { Negocio } from './types';

export const PREFIXO_COLUNA = 'etapa:';

function reindexar(lista: Negocio[]): Negocio[] {
  return lista.map((n, i) => ({ ...n, posicao_na_etapa: i }));
}

/** Espelha a Action `MoverNegocio` do backend para a atualização otimista. */
export function aplicarMovimento(negocios: Negocio[], id: string, etapaId: string, posicao: number): Negocio[] {
  const movido = negocios.find((n) => n.id === id);
  if (!movido) return negocios;

  const porPosicao = (a: Negocio, b: Negocio) => a.posicao_na_etapa - b.posicao_na_etapa;
  const origemId = movido.etapa_id;
  const destino = negocios.filter((n) => n.etapa_id === etapaId && n.id !== id).sort(porPosicao);
  destino.splice(Math.max(0, Math.min(posicao, destino.length)), 0, { ...movido, etapa_id: etapaId });

  const resto = negocios.filter((n) => n.id !== id && n.etapa_id !== etapaId);
  const origem = origemId === etapaId ? [] : reindexar(resto.filter((n) => n.etapa_id === origemId).sort(porPosicao));
  const outros = resto.filter((n) => n.etapa_id !== origemId || origemId === etapaId);

  return [...outros, ...origem, ...reindexar(destino)];
}

export interface Alvo {
  etapaId: string;
  posicao: number;
}

/** Converte o alvo de um arraste (coluna `etapa:<id>` ou cartão) em etapa e posição. */
export function destinoDoArraste(overId: string, negocios: Negocio[], movidoId: string): Alvo | null {
  if (overId.startsWith(PREFIXO_COLUNA)) {
    const etapaId = overId.slice(PREFIXO_COLUNA.length);
    return { etapaId, posicao: negocios.filter((n) => n.etapa_id === etapaId && n.id !== movidoId).length };
  }

  const alvo = negocios.find((n) => n.id === overId);
  if (!alvo || alvo.id === movidoId) return null;

  const daEtapa = negocios
    .filter((n) => n.etapa_id === alvo.etapa_id && n.id !== movidoId)
    .sort((a, b) => a.posicao_na_etapa - b.posicao_na_etapa);

  return { etapaId: alvo.etapa_id, posicao: daEtapa.findIndex((n) => n.id === alvo.id) };
}
```

- [ ] **Step 6: API e hooks**

`features/crm/api.ts`:

```ts
import { api } from '@/lib/api';
import type {
  ConfiguracaoDoCrm, Etapa, Funil, ItemEntrada, MotivoDePerda, Negocio, NovoNegocioPayload, StatusDoNegocio,
} from './types';

export const QUERY_KEY_CRM = ['crm'] as const;

const BASE = '/api/app/crm';
const json = (corpo: unknown) => JSON.stringify(corpo);

export const crmApi = {
  funis: () => api<{ data: Funil[] }>(`${BASE}/funis`),
  criarFunil: (nome: string) => api<{ data: Funil }>(`${BASE}/funis`, { method: 'POST', body: json({ nome }) }),
  editarFunil: (id: string, dados: { nome?: string; ativo?: boolean }) =>
    api<{ data: Funil }>(`${BASE}/funis/${id}`, { method: 'PUT', body: json(dados) }),
  criarEtapa: (funilId: string, dados: { nome: string; probabilidade: number }) =>
    api<{ data: Etapa }>(`${BASE}/funis/${funilId}/etapas`, { method: 'POST', body: json(dados) }),
  editarEtapa: (id: string, dados: { nome?: string; probabilidade?: number; ativa?: boolean }) =>
    api<{ data: Etapa }>(`${BASE}/etapas/${id}`, { method: 'PUT', body: json(dados) }),
  ordenarEtapas: (funilId: string, etapas: string[]) =>
    api<{ data: Funil }>(`${BASE}/funis/${funilId}/etapas/ordem`, { method: 'PUT', body: json({ etapas }) }),

  motivos: () => api<{ data: MotivoDePerda[] }>(`${BASE}/motivos-de-perda`),
  criarMotivo: (nome: string) => api<{ data: MotivoDePerda }>(`${BASE}/motivos-de-perda`, { method: 'POST', body: json({ nome }) }),
  editarMotivo: (id: string, dados: { nome?: string; ativo?: boolean }) =>
    api<{ data: MotivoDePerda }>(`${BASE}/motivos-de-perda/${id}`, { method: 'PUT', body: json(dados) }),

  configuracao: () => api<{ data: ConfiguracaoDoCrm }>(`${BASE}/configuracao`),
  salvarConfiguracao: (vendedor_ve_so_os_seus: boolean) =>
    api<{ data: ConfiguracaoDoCrm }>(`${BASE}/configuracao`, { method: 'PUT', body: json({ vendedor_ve_so_os_seus }) }),

  negocios: (funilId: string, status: StatusDoNegocio) =>
    api<{ data: Negocio[] }>(`${BASE}/negocios?funil_id=${funilId}&status=${status}`),
  negocio: (id: string) => api<{ data: Negocio }>(`${BASE}/negocios/${id}`),
  criarNegocio: (dados: NovoNegocioPayload) => api<{ data: Negocio }>(`${BASE}/negocios`, { method: 'POST', body: json(dados) }),
  editarNegocio: (id: string, dados: { titulo?: string; previsao_fechamento?: string | null; valor_centavos?: number; responsavel_id?: string }) =>
    api<{ data: Negocio }>(`${BASE}/negocios/${id}`, { method: 'PUT', body: json(dados) }),
  definirItens: (id: string, itens: ItemEntrada[]) =>
    api<{ data: Negocio }>(`${BASE}/negocios/${id}/itens`, { method: 'PUT', body: json({ itens }) }),
  mover: (id: string, etapaId: string, posicao: number) =>
    api<{ data: Negocio }>(`${BASE}/negocios/${id}/mover`, { method: 'POST', body: json({ etapa_id: etapaId, posicao }) }),
  ganhar: (id: string) => api<{ data: Negocio }>(`${BASE}/negocios/${id}/ganhar`, { method: 'POST' }),
  perder: (id: string, motivoId: string, observacao?: string) =>
    api<{ data: Negocio }>(`${BASE}/negocios/${id}/perder`, {
      method: 'POST',
      body: json({ motivo_de_perda_id: motivoId, observacao: observacao || undefined }),
    }),
};
```

`features/crm/hooks/useFunis.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { Funil } from '../types';

export const QUERY_KEY_FUNIS = [...QUERY_KEY_CRM, 'funis'] as const;

export function useFunis() {
  return useQuery<Funil[]>({ queryKey: QUERY_KEY_FUNIS, queryFn: async () => (await crmApi.funis()).data });
}
```

`features/crm/hooks/useNegocios.ts`:

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';
import { aplicarMovimento } from '../movimento';
import type { Negocio, StatusDoNegocio } from '../types';

export const chaveDosNegocios = (funilId: string, status: StatusDoNegocio) =>
  [...QUERY_KEY_CRM, 'negocios', funilId, status] as const;

export function useNegocios(funilId: string | undefined, status: StatusDoNegocio) {
  return useQuery<Negocio[]>({
    queryKey: chaveDosNegocios(funilId ?? '', status),
    queryFn: async () => (await crmApi.negocios(funilId ?? '', status)).data,
    enabled: funilId !== undefined,
  });
}

/** Move com atualização otimista; em erro volta ao estado anterior e avisa. */
export function useMoverNegocio(funilId: string) {
  const queryClient = useQueryClient();
  const chave = chaveDosNegocios(funilId, 'ABERTO');

  return useMutation({
    mutationFn: ({ id, etapaId, posicao }: { id: string; etapaId: string; posicao: number }) =>
      crmApi.mover(id, etapaId, posicao),
    onMutate: async ({ id, etapaId, posicao }) => {
      await queryClient.cancelQueries({ queryKey: chave });
      const anterior = queryClient.getQueryData<Negocio[]>(chave);
      if (anterior) queryClient.setQueryData(chave, aplicarMovimento(anterior, id, etapaId, posicao));
      return { anterior };
    },
    onError: (erro, _vars, contexto) => {
      if (contexto?.anterior) queryClient.setQueryData(chave, contexto.anterior);
      toast.error(erro instanceof ApiError ? erro.message : 'Não foi possível mover o negócio.');
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: chave }),
  });
}
```

`features/crm/hooks/useMotivosDePerda.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { MotivoDePerda } from '../types';

export const QUERY_KEY_MOTIVOS = [...QUERY_KEY_CRM, 'motivos'] as const;

export function useMotivosDePerda() {
  return useQuery<MotivoDePerda[]>({ queryKey: QUERY_KEY_MOTIVOS, queryFn: async () => (await crmApi.motivos()).data });
}
```

`features/crm/hooks/useConfiguracaoCrm.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { ConfiguracaoDoCrm } from '../types';

export const QUERY_KEY_CONFIGURACAO_CRM = [...QUERY_KEY_CRM, 'configuracao'] as const;

export function useConfiguracaoCrm() {
  return useQuery<ConfiguracaoDoCrm>({
    queryKey: QUERY_KEY_CONFIGURACAO_CRM,
    queryFn: async () => (await crmApi.configuracao()).data,
  });
}
```

- [ ] **Step 7: Menu com gate de módulo**

Em `components/layout/nav-items.ts`: importar `Briefcase` de `lucide-react` e `type { Modulo } from '@/features/assinatura/types'`; acrescentar `modulo?: Modulo;` ao `NavItem` (com o comentário `/** Só aparece se o plano da empresa tiver o módulo. */`); incluir em `NAV_ITEMS`, depois de `Clientes`:

```ts
  { href: '/crm/negocios', label: 'CRM', icon: Briefcase, papeis: ['PROPRIETARIO', 'ADMIN', 'VENDEDOR', 'LEITURA'], modulo: 'CRM' },
  { href: '/configuracoes/crm', label: 'Config. do CRM', icon: Settings2, papeis: ['PROPRIETARIO', 'ADMIN'], modulo: 'CRM' },
```

(importar também `Settings2`) e trocar `itensVisiveis` por:

```ts
export function itensVisiveis(itens: NavItem[], papel: Papel | undefined, modulos?: Modulo[]): NavItem[] {
  return itens.filter((item) => {
    if (item.papeis && (papel === undefined || !item.papeis.includes(papel))) return false;
    if (item.modulo && !(modulos ?? []).includes(item.modulo)) return false;
    return true;
  });
}
```

Em `components/layout/MenuDeNavegacao.tsx`: importar `useAssinatura` de `@/features/assinatura/hooks/useAssinatura`, chamar `const { data: assinatura } = useAssinatura();` e trocar a linha de `visiveis` por `const visiveis = itensVisiveis(itens, usuario?.papel, assinatura?.plano?.modulos);`. Itens do admin da plataforma não têm `modulo`, então não são afetados.

- [ ] **Step 8: Rodar e ver passar**

Run: `cd frontend && npx vitest run features/crm components/layout && npx tsc --noEmit && npx eslint`
Expected: PASS, sem erros de tipo nem de lint. Se algum teste de layout existente montar o `MenuDeNavegacao` sem `QueryClientProvider` para assinatura, mockar `useAssinatura` como os testes vizinhos já fazem com `useUsuario`.

- [ ] **Step 9: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/features/crm frontend/components/layout
git commit -m "feat(crm): base do frontend (tipos, API, hooks e menu por módulo)"
```

---

### Task 9: Configurações do CRM (funis, etapas, motivos, interruptor)

**Files:**
- Create: `frontend/features/crm/components/ConfiguracoesDoCrm.tsx`, `EditorDeFunis.tsx`, `EditorDeMotivos.tsx`, `InterruptorDeVisibilidade.tsx`
- Create: `frontend/app/(app)/configuracoes/crm/page.tsx`
- Test: `frontend/features/crm/components/ConfiguracoesDoCrm.test.tsx`

**Interfaces:**
- Consumes: `crmApi`, hooks da Task 8, `useUsuario` (`data.papel`), `Banner`, `Button`, `Input`, `Card`.
- Produces: `<ConfiguracoesDoCrm />` (usa `useUsuario` internamente; só Proprietário e Admin editam, os demais veem somente leitura).

- [ ] **Step 1: Escrever o teste que falha**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { Funil } from '../types';
import { ConfiguracoesDoCrm } from './ConfiguracoesDoCrm';

vi.mock('../api', () => ({
  QUERY_KEY_CRM: ['crm'],
  crmApi: {
    funis: vi.fn(), motivos: vi.fn(), configuracao: vi.fn(), criarFunil: vi.fn(), editarFunil: vi.fn(),
    criarEtapa: vi.fn(), editarEtapa: vi.fn(), ordenarEtapas: vi.fn(), criarMotivo: vi.fn(), editarMotivo: vi.fn(),
    salvarConfiguracao: vi.fn(),
  },
}));

const papel = vi.hoisted(() => ({ atual: 'ADMIN' }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel: papel.atual } }) }));

const funil: Funil = {
  id: 'f1', nome: 'Vendas', padrao: true, ativo: true, posicao: 0,
  etapas: [
    { id: 'e1', funil_id: 'f1', nome: 'Qualificação', posicao: 0, probabilidade: 10, ativa: true },
    { id: 'e2', funil_id: 'f1', nome: 'Proposta', posicao: 1, probabilidade: 40, ativa: true },
  ],
};

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  papel.atual = 'ADMIN';
  vi.mocked(crmApi.funis).mockResolvedValue({ data: [funil] });
  vi.mocked(crmApi.motivos).mockResolvedValue({ data: [{ id: 'm1', nome: 'Preço', ativo: true }] });
  vi.mocked(crmApi.configuracao).mockResolvedValue({ data: { vendedor_ve_so_os_seus: true } });
});

describe('ConfiguracoesDoCrm', () => {
  it('reordena etapas com o botão descer', async () => {
    vi.mocked(crmApi.ordenarEtapas).mockResolvedValue({ data: funil });
    renderizar(<ConfiguracoesDoCrm />);

    await userEvent.click(await screen.findByRole('button', { name: 'Descer Qualificação' }));

    await waitFor(() => expect(crmApi.ordenarEtapas).toHaveBeenCalledWith('f1', ['e2', 'e1']));
  });

  it('cria motivo de perda', async () => {
    vi.mocked(crmApi.criarMotivo).mockResolvedValue({ data: { id: 'm2', nome: 'Prazo', ativo: true } });
    renderizar(<ConfiguracoesDoCrm />);

    await userEvent.type(await screen.findByLabelText('Novo motivo'), 'Prazo');
    await userEvent.click(screen.getByRole('button', { name: 'Adicionar motivo' }));

    await waitFor(() => expect(crmApi.criarMotivo).toHaveBeenCalledWith('Prazo'));
  });

  it('altera o interruptor de visibilidade', async () => {
    vi.mocked(crmApi.salvarConfiguracao).mockResolvedValue({ data: { vendedor_ve_so_os_seus: false } });
    renderizar(<ConfiguracoesDoCrm />);

    await userEvent.click(await screen.findByLabelText('Vendedor vê só os próprios negócios'));

    await waitFor(() => expect(crmApi.salvarConfiguracao).toHaveBeenCalledWith(false));
  });

  it('vendedor vê a configuração sem controles de edição', async () => {
    papel.atual = 'VENDEDOR';
    renderizar(<ConfiguracoesDoCrm />);

    expect(await screen.findByText('Qualificação')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Adicionar motivo' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Descer Qualificação' })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd frontend && npx vitest run features/crm/components/ConfiguracoesDoCrm.test.tsx`
Expected: FAIL (componente não existe).

- [ ] **Step 3: Implementar os componentes**

Ler `frontend/node_modules/next/dist/docs/` (trecho de rotas e Client Components) e `features/clientes/components/ListaDeClientes.tsx` para manter o estilo (`'use client'`, `useMutation` + `toast` + `invalidateQueries`). Escrever:

`InterruptorDeVisibilidade.tsx`: `useConfiguracaoCrm()`; `<input type="checkbox" aria-label="Vendedor vê só os próprios negócios" checked={data.vendedor_ve_so_os_seus} disabled={!podeEditar || mutation.isPending} onChange={(e) => mutation.mutate(e.target.checked)} />` com `useMutation({ mutationFn: (v: boolean) => crmApi.salvarConfiguracao(v), onSuccess: invalida QUERY_KEY_CONFIGURACAO_CRM + toast.success('Configuração salva.'), onError: toast.error(mensagem da ApiError) })`. Props: `{ podeEditar: boolean }`.

`EditorDeMotivos.tsx`: props `{ podeEditar: boolean }`. Lista de `useMotivosDePerda()` com botão "Desativar/Ativar {nome}" (`crmApi.editarMotivo(id, { ativo })`). Se `podeEditar`: campo `Input` com `<Label htmlFor="novo-motivo">Novo motivo</Label>` e botão `Adicionar motivo` (`crmApi.criarMotivo(nome.trim())`, limpa o campo, invalida `QUERY_KEY_MOTIVOS`). Não envia nome vazio.

`EditorDeFunis.tsx`: props `{ podeEditar: boolean }`. `useFunis()`; para cada funil: título com selo "padrão"; lista de etapas ordenadas por `posicao` mostrando `nome`, `probabilidade%` e `ativa`. Se `podeEditar`: botões `aria-label={`Subir ${etapa.nome}`}` e `aria-label={`Descer ${etapa.nome}`}` (desabilitados no limite) que chamam `crmApi.ordenarEtapas(funil.id, idsNaNovaOrdem)`; botão "Desativar/Ativar {etapa}" (`editarEtapa(id, { ativa })`); campo de probabilidade `type="number" min=0 max=100` com `onBlur` salvando (`editarEtapa(id, { probabilidade })`); formulário "Nova etapa" (nome + probabilidade) por funil e formulário "Novo funil" no fim; botão "Desativar funil" (some no padrão). Erros da API (`ApiError`) viram `toast.error(erro.message)`; todo sucesso invalida `QUERY_KEY_FUNIS`.

`ConfiguracoesDoCrm.tsx`:

```tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { EditorDeFunis } from './EditorDeFunis';
import { EditorDeMotivos } from './EditorDeMotivos';
import { InterruptorDeVisibilidade } from './InterruptorDeVisibilidade';

const PAPEIS_QUE_CONFIGURAM = ['PROPRIETARIO', 'ADMIN'];

export function ConfiguracoesDoCrm() {
  const { data: usuario } = useUsuario();
  const podeEditar = Boolean(usuario && PAPEIS_QUE_CONFIGURAM.includes(usuario.papel));

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader><CardTitle>Visibilidade</CardTitle></CardHeader>
        <CardContent><InterruptorDeVisibilidade podeEditar={podeEditar} /></CardContent>
      </Card>
      <Card>
        <CardHeader><CardTitle>Funis e etapas</CardTitle></CardHeader>
        <CardContent><EditorDeFunis podeEditar={podeEditar} /></CardContent>
      </Card>
      <Card>
        <CardHeader><CardTitle>Motivos de perda</CardTitle></CardHeader>
        <CardContent><EditorDeMotivos podeEditar={podeEditar} /></CardContent>
      </Card>
    </div>
  );
}
```

`app/(app)/configuracoes/crm/page.tsx`:

```tsx
'use client';

import { ConfiguracoesDoCrm } from '@/features/crm/components/ConfiguracoesDoCrm';

export default function ConfiguracoesDoCrmPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Configurações do CRM</h1>
      <ConfiguracoesDoCrm />
    </div>
  );
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd frontend && npx vitest run features/crm && npx tsc --noEmit && npx eslint`
Expected: PASS, sem erros. Cada arquivo com até 350 linhas.

- [ ] **Step 5: Commit**

```bash
git add frontend/features/crm frontend/app
git commit -m "feat(crm): tela de configurações do CRM"
```

---

### Task 10: Kanban e novo negócio

**Files:**
- Create: `frontend/features/crm/components/KanbanDeNegocios.tsx`, `ColunaDaEtapa.tsx`, `CartaoDoNegocio.tsx`, `TabelaDeFechados.tsx`, `NovoNegocioForm.tsx`, `QuadroDeNegocios.tsx`
- Create: `frontend/features/crm/schemas.ts`
- Create: `frontend/app/(app)/crm/negocios/page.tsx`
- Test: `frontend/features/crm/components/Kanban.test.tsx`

**Interfaces:**
- Consumes: `useFunis`, `useNegocios`, `useMoverNegocio`, `aplicarMovimento`, `destinoDoArraste`, `PREFIXO_COLUNA`, `crmApi.criarNegocio`, `clientesApi.listar` (+ `QUERY_KEY_CLIENTES`), `useUsuario`.
- Produces: `<QuadroDeNegocios />` (página inteira do kanban), `<NovoNegocioForm funil={Funil} onCriado={() => void} />`; `negocioSchema` (zod) e `NegocioFormDados`.

- [ ] **Step 1: Escrever o teste que falha**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { crmApi } from '../api';
import type { Funil, Negocio } from '../types';
import { QuadroDeNegocios } from './QuadroDeNegocios';

vi.mock('../api', () => ({
  QUERY_KEY_CRM: ['crm'],
  crmApi: { funis: vi.fn(), negocios: vi.fn(), mover: vi.fn(), criarNegocio: vi.fn() },
}));
vi.mock('@/features/clientes/api', () => ({
  QUERY_KEY_CLIENTES: ['clientes'],
  clientesApi: { listar: vi.fn().mockResolvedValue({ data: [] }) },
}));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel: 'VENDEDOR' } }) }));

const funil: Funil = {
  id: 'f1', nome: 'Vendas', padrao: true, ativo: true, posicao: 0,
  etapas: [
    { id: 'e1', funil_id: 'f1', nome: 'Qualificação', posicao: 0, probabilidade: 10, ativa: true },
    { id: 'e2', funil_id: 'f1', nome: 'Proposta', posicao: 1, probabilidade: 40, ativa: true },
  ],
};

function negocio(extra: Partial<Negocio> = {}): Negocio {
  return {
    id: 'n1', titulo: 'Reforma', cliente: { id: 'c1', nome: 'Maria', telefone: null, email: null, estagio: 'LEAD' },
    funil_id: 'f1', etapa_id: 'e1', responsavel: { id: 'u1', nome: 'João' }, status: 'ABERTO', valor_centavos: 150000,
    previsao_fechamento: null, posicao_na_etapa: 0, motivo_de_perda_id: null, observacao_da_perda: null,
    fechado_em: null, itens: [], ...extra,
  };
}

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  vi.mocked(crmApi.funis).mockResolvedValue({ data: [funil] });
  vi.mocked(crmApi.negocios).mockResolvedValue({ data: [negocio()] });
});

describe('kanban', () => {
  it('mostra uma coluna por etapa com a soma do valor', async () => {
    renderizar(<QuadroDeNegocios />);

    const coluna = await screen.findByRole('region', { name: 'Qualificação' });
    expect(within(coluna).getByText('Reforma')).toBeInTheDocument();
    expect(within(coluna).getByText(/R\$\s*1\.500,00/)).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Proposta' })).toBeInTheDocument();
  });

  it('move pelo menu "Mover para" e chama a API', async () => {
    vi.mocked(crmApi.mover).mockResolvedValue({ data: negocio({ etapa_id: 'e2' }) });
    renderizar(<QuadroDeNegocios />);

    await userEvent.selectOptions(await screen.findByLabelText('Mover Reforma para'), 'e2');

    await waitFor(() => expect(crmApi.mover).toHaveBeenCalledWith('n1', 'e2', 0));
    const proposta = screen.getByRole('region', { name: 'Proposta' });
    await waitFor(() => expect(within(proposta).getByText('Reforma')).toBeInTheDocument());
  });

  it('volta o cartão e avisa quando a API recusa (422)', async () => {
    vi.mocked(crmApi.mover).mockRejectedValue(new ApiError(422, 'Etapa inválida.', {}));
    renderizar(<QuadroDeNegocios />);

    await userEvent.selectOptions(await screen.findByLabelText('Mover Reforma para'), 'e2');

    await waitFor(() => expect(crmApi.mover).toHaveBeenCalled());
    const origem = screen.getByRole('region', { name: 'Qualificação' });
    await waitFor(() => expect(within(origem).getByText('Reforma')).toBeInTheDocument());
  });

  it('aba Ganhos pede os negócios ganhos', async () => {
    renderizar(<QuadroDeNegocios />);

    await userEvent.click(await screen.findByRole('tab', { name: 'Ganhos' }));

    await waitFor(() => expect(crmApi.negocios).toHaveBeenCalledWith('f1', 'GANHO'));
  });

  it('novo negócio cria lead na hora', async () => {
    vi.mocked(crmApi.criarNegocio).mockResolvedValue({ data: negocio({ id: 'n2' }) });
    renderizar(<QuadroDeNegocios />);

    await userEvent.click(await screen.findByRole('button', { name: 'Novo negócio' }));
    await userEvent.type(screen.getByLabelText('Título'), 'Brindes');
    await userEvent.type(screen.getByLabelText('Nome do lead'), 'Ana');
    await userEvent.type(screen.getByLabelText('Telefone do lead'), '11999990000');
    await userEvent.click(screen.getByRole('button', { name: 'Criar negócio' }));

    await waitFor(() =>
      expect(crmApi.criarNegocio).toHaveBeenCalledWith(
        expect.objectContaining({ titulo: 'Brindes', funil_id: 'f1', etapa_id: 'e1', novo_lead: { nome: 'Ana', telefone: '11999990000' } }),
      ),
    );
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd frontend && npx vitest run features/crm/components/Kanban.test.tsx`
Expected: FAIL (componentes não existem).

- [ ] **Step 3: Schema do formulário**

`features/crm/schemas.ts`:

```ts
import { z } from 'zod';

export const negocioSchema = z
  .object({
    titulo: z.string().trim().min(1, 'Informe o título.').max(150),
    modo: z.enum(['EXISTENTE', 'NOVO']),
    cliente_id: z.string(),
    lead_nome: z.string().trim().max(150),
    lead_telefone: z.string().trim().max(20),
    lead_email: z.string().trim().max(150),
    previsao_fechamento: z.string(),
    valor_reais: z.string(),
  })
  .superRefine((d, ctx) => {
    if (d.modo === 'EXISTENTE' && d.cliente_id === '') {
      ctx.addIssue({ code: 'custom', path: ['cliente_id'], message: 'Escolha o cliente.' });
    }
    if (d.modo === 'NOVO') {
      if (d.lead_nome === '') ctx.addIssue({ code: 'custom', path: ['lead_nome'], message: 'Informe o nome do lead.' });
      if (d.lead_telefone === '' && d.lead_email === '') {
        ctx.addIssue({ code: 'custom', path: ['lead_telefone'], message: 'Informe telefone ou e-mail.' });
      }
    }
  });

export type NegocioFormDados = z.infer<typeof negocioSchema>;
```

- [ ] **Step 4: Componentes do quadro**

Ler `frontend/node_modules/next/dist/docs/` e `components/form/Campo.tsx`, `lib/formatos.ts` (`formatarCentavos`, e `mascararReais`/equivalente se existir) antes de escrever.

`CartaoDoNegocio.tsx`: props `{ negocio: Negocio; etapas: Etapa[]; podeEscrever: boolean; aoMover: (etapaId: string) => void }`. Usa `useSortable({ id: negocio.id })` de `@dnd-kit/sortable` e `CSS.Transform.toString` de `@dnd-kit/utilities` para o estilo; área de arraste só no ícone `GripVertical` (props `attributes` e `listeners` no botão, `aria-label={`Arrastar ${negocio.titulo}`}`), para o resto do cartão (link e `select`) ser clicável. Conteúdo: `<Link href={`/crm/negocios/${negocio.id}`}>` com o título; cliente; `formatarCentavos(valor_centavos)`; responsável; previsão com classe `text-danger` se a data for anterior a hoje. Se `podeEscrever`: `<select aria-label={`Mover ${negocio.titulo} para`} value={negocio.etapa_id} onChange={(e) => aoMover(e.target.value)}>` com uma `option` por etapa ativa (`value=etapa.id`, texto `etapa.nome`).

`ColunaDaEtapa.tsx`: props `{ etapa: Etapa; negocios: Negocio[]; etapas: Etapa[]; podeEscrever: boolean; aoMover: (negocioId: string, etapaId: string, posicao: number) => void }`. `<section role="region" aria-label={etapa.nome}>` (use `<section aria-label>`; o teste busca `region`), `useDroppable({ id: `${PREFIXO_COLUNA}${etapa.id}` })`, `SortableContext` com `verticalListSortingStrategy` e os ids dos negócios ordenados por `posicao_na_etapa`. Cabeçalho: nome, `etapa.probabilidade%`, contagem e `formatarCentavos(soma)`. Cartões com `aoMover={(destino) => aoMover(n.id, destino, destino === n.etapa_id ? n.posicao_na_etapa : posicaoFinal(destino))}`; pelo menu, a posição é o fim da coluna de destino (`negocios` daquela etapa, sem o próprio, `.length`). Para isso `QuadroDeNegocios` passa `aoMover` já com a lista completa e calcula o fim: a coluna só repassa `(negocioId, etapaId)` e o quadro resolve a posição com `destinoDoArraste(`${PREFIXO_COLUNA}${etapaId}`, negocios, negocioId)`.

`KanbanDeNegocios.tsx`: props `{ funil: Funil; negocios: Negocio[]; podeEscrever: boolean }`. `DndContext` com `sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }), useSensor(KeyboardSensor))` e `collisionDetection={closestCorners}`. `onDragEnd={({ active, over }) => { if (!over) return; const alvo = destinoDoArraste(String(over.id), negocios, String(active.id)); if (alvo) mover.mutate({ id: String(active.id), ...alvo }); }}`. `mover = useMoverNegocio(funil.id)`. Contêiner `flex gap-3 overflow-x-auto snap-x snap-mandatory`, cada coluna `snap-start min-w-[16rem]`. Passa `aoMover={(id, etapaId) => { const alvo = destinoDoArraste(`${PREFIXO_COLUNA}${etapaId}`, negocios, id); if (alvo) mover.mutate({ id, ...alvo }); }}`. Só colunas de etapas `ativa`.

`TabelaDeFechados.tsx`: props `{ negocios: Negocio[]; status: 'GANHO' | 'PERDIDO' }`; tabela simples (título com link, cliente, valor, `fechado_em` formatado) e texto "Nenhum negócio {ganho|perdido}." quando vazia.

`NovoNegocioForm.tsx`: props `{ funil: Funil; onCriado: () => void }`. `useForm<NegocioFormDados>({ resolver: zodResolver(negocioSchema), defaultValues: { titulo: '', modo: 'NOVO', cliente_id: '', lead_nome: '', lead_telefone: '', lead_email: '', previsao_fechamento: '', valor_reais: '' } })`; `modo = useWatch({ control, name: 'modo' })`. Campos com `Campo` (labels exatamente: "Título", "Nome do lead", "Telefone do lead", "E-mail do lead", "Cliente", "Previsão de fechamento", "Valor (R$)"); radio "Cliente existente" / "Novo lead"; o select "Cliente" lista `clientesApi.listar()`. Envio com `useEnvioUnico`; monta `NovoNegocioPayload`: `etapa_id` = primeira etapa ativa do funil por `posicao`; `novo_lead` sem chaves vazias (`{ nome, telefone }` quando só há telefone); `valor_centavos` = `Math.round(parseFloat(valor.replace(',', '.')) * 100)` só se o campo estiver preenchido e for número; `previsao_fechamento` só se preenchido. Sucesso: `toast.success('Negócio criado.')`, invalida `[...QUERY_KEY_CRM, 'negocios']`, chama `onCriado()`. Erros: `ApiError` com `errors` mapeados aos campos como em `CadastroForm`, resto em `Banner` (`erros.root`). Botão "Criar negócio".

`QuadroDeNegocios.tsx`: `useFunis()` (usa o primeiro funil ativo; `<select aria-label="Funil">` se houver mais de um), abas `role="tablist"` com três `role="tab"` (Abertos, Ganhos, Perdidos; `aria-selected`), `useNegocios(funil?.id, status)`; `useUsuario` define `podeEscrever` (`PROPRIETARIO`, `ADMIN`, `VENDEDOR`); botão "Novo negócio" (só se `podeEscrever`) alterna `NovoNegocioForm`; estados de carregando/erro com `Banner tom="perigo"` ("Não foi possível carregar o CRM." e, para 403 do módulo, a mensagem da API). Aba Abertos renderiza `KanbanDeNegocios`; as outras, `TabelaDeFechados`.

`app/(app)/crm/negocios/page.tsx`:

```tsx
'use client';

import { QuadroDeNegocios } from '@/features/crm/components/QuadroDeNegocios';

export default function NegociosPage() {
  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">CRM</h1>
      <QuadroDeNegocios />
    </div>
  );
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `cd frontend && npx vitest run features/crm && npx tsc --noEmit && npx eslint`
Expected: PASS. Se o `PointerSensor` do dnd-kit reclamar em jsdom, os testes não arrastam (usam o menu), então não deve ocorrer; qualquer arquivo acima de 350 linhas deve ser dividido.

- [ ] **Step 6: Commit**

```bash
git add frontend/features/crm frontend/app
git commit -m "feat(crm): kanban de negócios e novo negócio"
```

---

### Task 11: Detalhe do negócio (itens, ganhar, perder)

**Files:**
- Create: `frontend/features/crm/components/DetalheDoNegocio.tsx`, `EditorDeItens.tsx`, `PainelDePerda.tsx`
- Create: `frontend/app/(app)/crm/negocios/[id]/page.tsx`
- Test: `frontend/features/crm/components/DetalheDoNegocio.test.tsx`

**Interfaces:**
- Consumes: `crmApi.negocio/definirItens/ganhar/perder/editarNegocio`, `useMotivosDePerda`, APIs de listagem de produtos e serviços existentes (ler `features/produtos/api.ts` e `features/servicos/api.ts` para os nomes exatos e os tipos `Produto`/`Servico`; ambos têm `id`, `nome`, `preco_centavos`).
- Produces: `<DetalheDoNegocio id={string} />`.

- [ ] **Step 1: Escrever o teste que falha**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { Negocio } from '../types';
import { DetalheDoNegocio } from './DetalheDoNegocio';

vi.mock('../api', () => ({
  QUERY_KEY_CRM: ['crm'],
  crmApi: { negocio: vi.fn(), motivos: vi.fn(), ganhar: vi.fn(), perder: vi.fn(), definirItens: vi.fn(), editarNegocio: vi.fn() },
}));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel: 'VENDEDOR' } }) }));
vi.mock('@/features/produtos/api', () => ({ QUERY_KEY_PRODUTOS: ['produtos'], produtosApi: { listar: vi.fn().mockResolvedValue({ data: [] }) } }));
vi.mock('@/features/servicos/api', () => ({ QUERY_KEY_SERVICOS: ['servicos'], servicosApi: { listar: vi.fn().mockResolvedValue({ data: [] }) } }));

function negocio(extra: Partial<Negocio> = {}): Negocio {
  return {
    id: 'n1', titulo: 'Reforma', cliente: { id: 'c1', nome: 'Maria', telefone: null, email: null, estagio: 'LEAD' },
    funil_id: 'f1', etapa_id: 'e1', responsavel: { id: 'u1', nome: 'João' }, status: 'ABERTO', valor_centavos: 5000,
    previsao_fechamento: null, posicao_na_etapa: 0, motivo_de_perda_id: null, observacao_da_perda: null, fechado_em: null,
    itens: [{ id: 'i1', tipo: 'PRODUTO', item_id: 'p1', descricao: 'Caneca', quantidade: 2, valor_unitario_centavos: 2500 }],
    ...extra,
  };
}

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  vi.mocked(crmApi.negocio).mockResolvedValue({ data: negocio() });
  vi.mocked(crmApi.motivos).mockResolvedValue({ data: [{ id: 'm1', nome: 'Preço', ativo: true }, { id: 'm2', nome: 'Antigo', ativo: false }] });
});

describe('DetalheDoNegocio', () => {
  it('mostra itens e valor do negócio', async () => {
    renderizar(<DetalheDoNegocio id="n1" />);

    expect(await screen.findByText('Caneca')).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*50,00/)).toBeInTheDocument();
  });

  it('ganha o negócio', async () => {
    vi.mocked(crmApi.ganhar).mockResolvedValue({ data: negocio({ status: 'GANHO' }) });
    renderizar(<DetalheDoNegocio id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Ganhar' }));

    await waitFor(() => expect(crmApi.ganhar).toHaveBeenCalledWith('n1'));
  });

  it('perder exige motivo e só oferece motivos ativos', async () => {
    vi.mocked(crmApi.perder).mockResolvedValue({ data: negocio({ status: 'PERDIDO' }) });
    renderizar(<DetalheDoNegocio id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Perder' }));
    expect(screen.queryByRole('option', { name: 'Antigo' })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Confirmar perda' }));
    expect(await screen.findByText('Escolha o motivo da perda.')).toBeInTheDocument();
    expect(crmApi.perder).not.toHaveBeenCalled();

    await userEvent.selectOptions(screen.getByLabelText('Motivo da perda'), 'm1');
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar perda' }));

    await waitFor(() => expect(crmApi.perder).toHaveBeenCalledWith('n1', 'm1', undefined));
  });

  it('negócio fechado não mostra ações', async () => {
    vi.mocked(crmApi.negocio).mockResolvedValue({ data: negocio({ status: 'GANHO' }) });
    renderizar(<DetalheDoNegocio id="n1" />);

    expect(await screen.findByText('Ganho')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ganhar' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Perder' })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd frontend && npx vitest run features/crm/components/DetalheDoNegocio.test.tsx`
Expected: FAIL (componente não existe).

- [ ] **Step 3: Implementar**

Antes, ler `features/produtos/api.ts` e `features/servicos/api.ts` e ajustar nomes de import e do mock do teste se divergirem de `produtosApi.listar`/`servicosApi.listar`/`QUERY_KEY_PRODUTOS`/`QUERY_KEY_SERVICOS`.

`DetalheDoNegocio.tsx`: `useQuery({ queryKey: [...QUERY_KEY_CRM, 'negocio', id], queryFn: async () => (await crmApi.negocio(id)).data })`. Cabeçalho com título, selo de status (`Aberto`/`Ganho`/`Perdido`), cliente (nome, telefone, e-mail), responsável, previsão, `formatarCentavos(valor_centavos)`. Seção "Itens" com `EditorDeItens`. Se `status === 'ABERTO'` e o papel é `PROPRIETARIO`, `ADMIN` ou `VENDEDOR`: botões "Ganhar" (`useMutation` com `crmApi.ganhar`, `toast.success('Negócio ganho.')`) e "Perder" (alterna `PainelDePerda`). Fechado: mostra data de fechamento e, se perdido, o motivo (`motivo_de_perda_id` resolvido na lista de `useMotivosDePerda()`) e a observação; sem botões. Mutations invalidam a query do negócio e `[...QUERY_KEY_CRM, 'negocios']`; erros viram `toast.error` com a mensagem da `ApiError`.

`PainelDePerda.tsx`: props `{ negocioId: string; onConcluido: () => void }`. `select` com `<Label>Motivo da perda</Label>` listando só `useMotivosDePerda()` ativos, `textarea` "Observação" e botão "Confirmar perda". Sem motivo escolhido, mostra "Escolha o motivo da perda." e não chama a API. Com motivo: `crmApi.perder(negocioId, motivoId, observacao.trim() || undefined)`.

`EditorDeItens.tsx`: props `{ negocio: Negocio; podeEditar: boolean }`. Tabela com descrição, quantidade, valor unitário e subtotal (`quantidade * valor_unitario_centavos`), botão "Remover {descricao}". Para adicionar: `select` "Item do catálogo" reunindo produtos e serviços (prefixo "Produto: " e "Serviço: "), campo "Quantidade" (inteiro ≥ 1) e botão "Adicionar item". Cada mudança chama `crmApi.definirItens(negocio.id, listaCompletaNova)` com os itens atuais (mapeados para `{ tipo, item_id, quantidade }`) mais ou menos o item. Quando há itens, mostra a nota "O valor do negócio é a soma dos itens." Sem itens e `podeEditar`, permite editar o valor em R$ (`crmApi.editarNegocio(id, { valor_centavos })`) no `onBlur`. Só com `status === 'ABERTO'` e `podeEditar`.

`app/(app)/crm/negocios/[id]/page.tsx`: ler `frontend/node_modules/next/dist/docs/` sobre `params` em rotas dinâmicas desta versão do Next (em versões recentes `params` é uma Promise; usar `use(params)` em Client Component) e escrever:

```tsx
'use client';

import { use } from 'react';
import { DetalheDoNegocio } from '@/features/crm/components/DetalheDoNegocio';

export default function NegocioPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);

  return <DetalheDoNegocio id={id} />;
}
```

Confirmar na documentação instalada que essa é a assinatura correta e ajustar se a versão do Next pedir outra.

- [ ] **Step 4: Rodar e ver passar**

Run: `cd frontend && npx vitest run features/crm && npx tsc --noEmit && npx eslint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/features/crm frontend/app
git commit -m "feat(crm): detalhe do negócio com itens, ganho e perda"
```

---

### Task 12: Verificação final e documentação

**Files:**
- Modify: `PROGRESSO.md`, `TAREFAS.md`
- Modify: `docs/superpowers/specs/2026-10-13-crm-f7a-funis-e-negocios-design.md` (marcar concluída)

- [ ] **Step 1: Suíte completa e gates**

Run, na ordem:
- `cd backend && php artisan test`
- `cd backend && vendor/bin/pint --test` (se o projeto usar Pint) e a análise estática que o projeto já roda (conferir em `composer.json`/`PROGRESSO.md`)
- `cd frontend && npx eslint && npx tsc --noEmit && npx vitest run && npm run build`

Expected: tudo verde. Qualquer falha é corrigida na task de origem, com commit próprio.

- [ ] **Step 2: Fumaça manual com curl ou navegador**

Subir o backend com SQLite descartável (receita em `PROGRESSO.md`: host `localhost`, login em `/api/app/auth/login`), criar uma empresa com plano que tenha o módulo `CRM`, e conferir: `GET /api/app/crm/funis` semeia o funil; criar negócio com lead novo; mover; ganhar (o cliente vira `CLIENTE`). Se houver navegador disponível, abrir `/crm/negocios` e arrastar um cartão. Registrar no `PROGRESSO.md` o que foi e o que não foi verificado.

- [ ] **Step 3: Atualizar PROGRESSO.md e TAREFAS.md**

Ler os dois arquivos. Em `PROGRESSO.md`: registrar a F7a concluída (1 a 3 linhas: módulo `Crm`, contratos, API e kanban; decisões não óbvias: `Crm` usa contratos inclusive para usuários, Fiscal sem acesso, quantidade de item inteira, eventos sem listeners), reescrever "Próxima tarefa" para a F7b (atividades e agenda, timeline, alerta de parado, WhatsApp e e-mail em 1 clique; brainstorming primeiro, arquitetural) e a "Contexto necessário" só com o que a F7b usa (spec mestre §9, spec e código da F7a, eventos `Negocio*`). Em `TAREFAS.md`: marcar a F7a e deixar F7b a F7d como pendentes.

- [ ] **Step 4: Commit**

```bash
git add PROGRESSO.md TAREFAS.md docs/superpowers/specs
git commit -m "docs: F7a concluída (CRM, funis e negócios)"
```

- [ ] **Step 5: Recomendar `/compact` ou `/clear`** (regra de gestão de contexto do `CLAUDE.md`) e informar que o push aguarda confirmação do usuário.

---

## Self-review

**Cobertura da spec:** §3 modelo de dados → Task 1; §4 contratos → Task 2, Actions e eventos → Tasks 3, 5, 6; §5 API e permissões → Tasks 4 e 7; §6 frontend → Tasks 8 a 11; §7 testes → distribuídos em cada task (isolamento, matriz de papéis, módulo ausente, 404 de vendedor, reindexação, valor derivado, lead órfão, eventos após commit); §8 ordem → Tasks 1 a 12 na mesma sequência; D8 dependência `@dnd-kit` → Task 8. Terceiro contrato (`DiretorioDeUsuarios`) registrado na spec na Task 2.

**Placeholders:** nenhum "TBD". Pontos deliberadamente delegados ao executor, com instrução objetiva: nomes exatos de `produtosApi`/`servicosApi` (Task 11, ler os arquivos), forma de `params` no Next 16 (Task 11, ler `node_modules/next/dist/docs/`), comando de análise estática do backend (Task 12), corpo dos componentes de configuração e do quadro, descritos campo a campo nas Tasks 9 e 10.

**Consistência de tipos:** `RegraDoCrmException(string $mensagem, string $codigoDoErro)` com `codigo()`; `Negocio::garantirAberto()`; `ConsultaDeClientes::resumo/resumos/criarLead/converterEmCliente`; `DiretorioDeUsuarios::ehDaEmpresa/nomes`; `PoliticaDoCrm::garantirPodeLer/OperarNegocios/Configurar` e `podeReatribuir`; `DefinirItensDoNegocio::executar/aplicar`; `MoverNegocio::executar(Usuario, Negocio, string, int)`; frontend `aplicarMovimento`, `destinoDoArraste`, `PREFIXO_COLUNA`, `crmApi.*` e `QUERY_KEY_*` usados com os mesmos nomes entre tasks.

**Limites assumidos (registrar com `shortcut:` no código se aparecerem):** quantidade de item é inteira (sem fracionado); `lockForUpdate` é no-op no SQLite dos testes, então a concorrência real só é exercitada em PostgreSQL; kanban carrega todos os negócios abertos do funil, sem paginação.
