# F7d: relatórios, metas e dashboard comercial do CRM — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar relatórios comerciais, metas mensais por vendedor e o dashboard do CRM, lendo os dados que a F7a–F7c já produzem.

**Architecture:** Uma tabela nova (`crm_metas`) e Actions de leitura agregada em SQL no módulo `Crm`. O faturamento vem por um contrato Crm→Fiscal (mesmo padrão de `GeradorDeRascunhoDeNota`). Frontend: página `/crm/relatorios` e bloco do painel ampliado, usando as mesmas rotas autenticadas `api/app/crm/*`.

**Tech Stack:** Laravel 12 / PHP 8.4 (Pest-less PHPUnit, SQLite em teste), Next.js (App Router), TanStack Query, Recharts, Vitest + Testing Library.

**Spec:** `docs/superpowers/specs/2026-10-13-crm-f7d-relatorios-design.md`

## Global Constraints

- `declare(strict_types=1)` em todo PHP; TypeScript strict, sem `any`.
- Zero é valor válido: usar `=== null`, nunca `empty()` nem `?? 0` em número de negócio. Taxa sem denominador é `null`, nunca 0%.
- Dinheiro em centavos inteiros; sem float para valor.
- Todo Model de tenant usa `BelongsToTenant`; todo endpoint novo ganha teste de isolamento.
- Schema só por migration. Frontend e API chamam as mesmas Actions; sem regra no controller.
- Vendedor com o interruptor "só os seus" ligado vê só os próprios números e nunca o ranking.
- Textos ao usuário em pt-BR. Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u`.
- Working tree tem arquivos alheios que **não** podem entrar em commit: `frontend/eslint.config.mjs`, `frontend/features/cadastro/components/CadastroForm.tsx`, `frontend/eslint-rules/`. Usar sempre `git add` com caminhos.
- Arquivos usam CRLF (autocrlf): editar com Edit/Write.
- Frontend Next.js tem breaking changes: seguir o padrão de `frontend/app/(app)/crm/negocios/[id]/page.tsx` (`params` é `Promise`) e ler `frontend/node_modules/next/dist/docs/` antes de criar rota nova.

## Review Focus

- Período que cruza a virada do mês ou do dia no fuso `America/Sao_Paulo` (ganho às 23h30 do último dia): entra no mês certo, não no seguinte em UTC.
- Vendedor restrito passando `vendedor_id` de outro: ignorado, nunca vaza número alheio.
- Relatório de empresa sem nenhum negócio fechado: `taxa_de_conversao` `null`, totais `0`, nenhuma divisão por zero.
- Meta com realizado acima do alvo: percentual passa de 100 sem erro.
- Plano sem Fiscal: `faturado_centavos` vem `null`; plano com Fiscal e sem nota autorizada: vem `0`.

## Rulings (decididos ao planejar)

1. `faturado` da spec vira o campo `faturado_centavos` na resposta (mesmo significado).
2. `vendedor_id` enviado por quem não pode reatribuir (`PoliticaDoCrm::podeReatribuir`) é ignorado, não rejeitado: o resultado já é restrito pelo próprio papel.
3. Período omitido na API = mês corrente no fuso do negócio.
4. `funil_id` só afeta o bloco `funil`; `resumo`, `previsao` e `motivos_de_perda` somam todos os funis.
5. Propostas aceitas são carregadas como linhas escalares (`withSum` do subtotal), nunca com itens hidratados.
6. Sem componente de abas nem diálogo no design system: abas são botões segmentados e as metas ficam num cartão inline na própria página.
7. Percentual de meta é inteiro (`intdiv(realizado * 100, alvo)`); a taxa de conversão e de aceite é fração de 0 a 1 com 4 casas.

## File Structure

Backend (`backend/`):

| Arquivo | Responsabilidade |
|---|---|
| `database/migrations/2026_10_18_000001_create_crm_metas.php` | `crm_metas` + índice de fechamento em `crm_negocios` |
| `app/Modules/Crm/Domain/Enums/UnidadeDaMeta.php` | `VALOR` / `QUANTIDADE` |
| `app/Modules/Crm/Domain/Models/MetaDoVendedor.php` | Model da meta |
| `database/factories/MetaDoVendedorFactory.php` | Factory |
| `app/Modules/Crm/Domain/Contracts/ConsultorDeValorFaturado.php` | Contrato Crm→Fiscal |
| `app/Modules/Fiscal/Application/ConsultorDeValorFaturadoDoFiscal.php` | Implementação no Fiscal |
| `app/Modules/Crm/Application/Relatorio/PeriodoDoRelatorio.php` | Período validado, no fuso do negócio |
| `app/Modules/Crm/Application/Relatorio/RelatorioDeNegocios.php` | Agregados de negócios e metas |
| `app/Modules/Crm/Application/Relatorio/RelatorioDePropostas.php` | Agregados de propostas e faturado |
| `app/Modules/Crm/Application/Actions/RelatorioComercial.php` | Orquestra o relatório |
| `app/Modules/Crm/Application/Actions/{DefinirMeta,RemoverMeta,ListarMetas}.php` | Metas |
| `app/Modules/Crm/Http/{Controllers,Requests}/…` | `MetasController`, `RelatoriosController`, `MetaRequest`, `RelatorioRequest` |

Frontend (`frontend/`): `features/crm/{types.ts,api.ts,periodos.ts}`, `hooks/{useRelatorio,useMetas}.ts`, `components/{RelatoriosDoCrm,VisaoGeral,TabelaDeVendedores,PerdasDoPeriodo,MetasDoMes,FiltroDePeriodo,BlocoCrmNoPainel}.tsx`, rota `app/(app)/crm/relatorios/page.tsx`, `components/layout/nav-items.ts`.

---

### Task 1: Tabela de metas, Model e índice

**Files:**
- Create: `backend/database/migrations/2026_10_18_000001_create_crm_metas.php`
- Create: `backend/app/Modules/Crm/Domain/Enums/UnidadeDaMeta.php`
- Create: `backend/app/Modules/Crm/Domain/Models/MetaDoVendedor.php`
- Create: `backend/database/factories/MetaDoVendedorFactory.php`
- Modify: `backend/app/Modules/Crm/Domain/Exceptions/RegraDoCrmException.php`
- Test: `backend/tests/Feature/Crm/MetasModeloTest.php`

**Interfaces:**
- Produces: `UnidadeDaMeta::{Valor,Quantidade}` (`'VALOR'`, `'QUANTIDADE'`); `MetaDoVendedor` com `usuario_id`, `mes` (date `Y-m-d`, dia 1), `unidade`, `alvo`; `MetaDoVendedor::factory()`; `RegraDoCrmException::metaInvalida(string)` (`META_INVALIDA`) e `::periodoInvalido(string)` (`PERIODO_INVALIDO`), ambos 422.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class MetasModeloTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_guarda_mes_como_data_e_unidade_como_enum(): void
    {
        $meta = MetaDoVendedor::factory()->create([
            'usuario_id' => $this->admin->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Valor, 'alvo' => 500000,
        ])->refresh();

        $this->assertSame('2026-10-01', $meta->mes->toDateString());
        $this->assertSame(UnidadeDaMeta::Valor, $meta->unidade);
        $this->assertSame(500000, $meta->alvo);
    }

    public function test_so_enxerga_metas_do_proprio_tenant(): void
    {
        MetaDoVendedor::factory()->create(['usuario_id' => $this->admin->id]);
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);

        $this->assertSame(0, MetaDoVendedor::query()->count());
    }

    public function test_uma_meta_por_vendedor_e_mes(): void
    {
        MetaDoVendedor::factory()->create(['usuario_id' => $this->admin->id, 'mes' => '2026-10-01']);

        $this->expectException(QueryException::class);
        MetaDoVendedor::factory()->create(['usuario_id' => $this->admin->id, 'mes' => '2026-10-01']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=MetasModeloTest`
Expected: FAIL (`Class "App\Modules\Crm\Domain\Models\MetaDoVendedor" not found`).

- [ ] **Step 3: Write minimal implementation**

`UnidadeDaMeta.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

enum UnidadeDaMeta: string
{
    case Valor = 'VALOR';
    case Quantidade = 'QUANTIDADE';
}
```

Migration:

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
        Schema::create('crm_metas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->uuid('usuario_id');
            $table->date('mes');
            $table->string('unidade', 10);
            $table->unsignedBigInteger('alvo');
            $table->timestamps();
            $table->unique(['tenant_id', 'usuario_id', 'mes']);
        });

        Schema::table('crm_negocios', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status', 'fechado_em'], 'crm_negocios_fechamento');
        });
    }

    public function down(): void
    {
        Schema::table('crm_negocios', function (Blueprint $table): void {
            $table->dropIndex('crm_negocios_fechamento');
        });
        Schema::dropIfExists('crm_metas');
    }
};
```

`MetaDoVendedor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\MetaDoVendedorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $usuario_id
 * @property CarbonInterface $mes
 * @property UnidadeDaMeta $unidade
 * @property int $alvo
 */
class MetaDoVendedor extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MetaDoVendedorFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_metas';

    protected $fillable = ['usuario_id', 'mes', 'unidade', 'alvo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // `date:Y-m-d` grava só a data, para a igualdade por texto funcionar no SQLite e no PostgreSQL.
        return ['mes' => 'date:Y-m-d', 'unidade' => UnidadeDaMeta::class, 'alvo' => 'integer'];
    }

    protected static function newFactory(): MetaDoVendedorFactory
    {
        return MetaDoVendedorFactory::new();
    }
}
```

`MetaDoVendedorFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MetaDoVendedor> */
class MetaDoVendedorFactory extends Factory
{
    protected $model = MetaDoVendedor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => fn (): string => Usuario::factory()->create(['tenant_id' => app(TenantContext::class)->require()])->id,
            'mes' => now()->startOfMonth()->toDateString(),
            'unidade' => UnidadeDaMeta::Valor,
            'alvo' => 100000,
        ];
    }
}
```

Em `RegraDoCrmException.php`, junto de `negocioFechado()`:

```php
    public static function metaInvalida(string $mensagem): self
    {
        return new self($mensagem, 'META_INVALIDA');
    }

    public static function periodoInvalido(string $mensagem): self
    {
        return new self($mensagem, 'PERIODO_INVALIDO');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=MetasModeloTest`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
cd /c/Users/dougl/workspace/crm
git add backend/database/migrations/2026_10_18_000001_create_crm_metas.php backend/app/Modules/Crm/Domain/Enums/UnidadeDaMeta.php backend/app/Modules/Crm/Domain/Models/MetaDoVendedor.php backend/database/factories/MetaDoVendedorFactory.php backend/app/Modules/Crm/Domain/Exceptions/RegraDoCrmException.php backend/tests/Feature/Crm/MetasModeloTest.php
git commit -m "feat(crm): tabela e modelo de metas do vendedor

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 2: Actions e API de metas

**Files:**
- Create: `backend/app/Modules/Crm/Application/Actions/DefinirMeta.php`
- Create: `backend/app/Modules/Crm/Application/Actions/RemoverMeta.php`
- Create: `backend/app/Modules/Crm/Application/Actions/ListarMetas.php`
- Create: `backend/app/Modules/Crm/Http/Controllers/MetasController.php`
- Create: `backend/app/Modules/Crm/Http/Requests/MetaRequest.php`
- Modify: `backend/app/Modules/Crm/Http/routes-app.php`
- Test: `backend/tests/Feature/Crm/MetasApiTest.php`

**Interfaces:**
- Consumes: `MetaDoVendedor`, `UnidadeDaMeta`, `RegraDoCrmException::metaInvalida`, `PoliticaDoCrm`, `DiretorioDeUsuarios::ehDaEmpresa/nomes`.
- Produces: `GET api/app/crm/metas?mes=AAAA-MM`, `PUT api/app/crm/metas` (`usuario_id`, `mes`, `unidade`, `alvo`), `DELETE api/app/crm/metas/{id}`. Item: `{id, usuario_id, usuario_nome, mes: "AAAA-MM", unidade, alvo}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class MetasApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_admin_define_e_atualiza_a_meta_do_mes(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $corpo = ['usuario_id' => $vendedor->id, 'mes' => '2026-10', 'unidade' => 'VALOR', 'alvo' => 500000];

        $this->spaComo($this->admin)->putJson('/api/app/crm/metas', $corpo)
            ->assertOk()
            ->assertJsonPath('data.mes', '2026-10')
            ->assertJsonPath('data.alvo', 500000)
            ->assertJsonPath('data.usuario_nome', $vendedor->nome);

        $this->spaComo($this->admin)->putJson('/api/app/crm/metas', [...$corpo, 'unidade' => 'QUANTIDADE', 'alvo' => 8])
            ->assertOk()->assertJsonPath('data.unidade', 'QUANTIDADE');

        $this->assertSame(1, MetaDoVendedor::query()->count());
    }

    public function test_lista_por_mes_e_vendedor_so_ve_a_propria(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $outro = $this->usuarioDoPapel(Papel::Vendedor);
        MetaDoVendedor::factory()->create(['usuario_id' => $vendedor->id, 'mes' => '2026-10-01']);
        MetaDoVendedor::factory()->create(['usuario_id' => $outro->id, 'mes' => '2026-10-01']);
        MetaDoVendedor::factory()->create(['usuario_id' => $vendedor->id, 'mes' => '2026-09-01']);

        $this->spaComo($this->admin)->getJson('/api/app/crm/metas?mes=2026-10')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->spaComo($vendedor)->getJson('/api/app/crm/metas?mes=2026-10')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.usuario_id', $vendedor->id);
    }

    public function test_alvo_zero_ou_negativo_devolve_422(): void
    {
        $this->spaComo($this->admin)->putJson('/api/app/crm/metas', [
            'usuario_id' => $this->admin->id, 'mes' => '2026-10', 'unidade' => 'VALOR', 'alvo' => 0,
        ])->assertStatus(422);
    }

    public function test_usuario_de_outra_empresa_devolve_422(): void
    {
        $outro = Tenant::factory()->create();
        $alheio = $this->usuarioDoPapel(Papel::Vendedor, $outro);

        $this->spaComo($this->admin)->putJson('/api/app/crm/metas', [
            'usuario_id' => $alheio->id, 'mes' => '2026-10', 'unidade' => 'VALOR', 'alvo' => 100,
        ])->assertStatus(422)->assertJsonPath('codigo', 'META_INVALIDA');
    }

    public function test_vendedor_nao_define_nem_remove(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $meta = MetaDoVendedor::factory()->create(['usuario_id' => $vendedor->id]);

        $this->spaComo($vendedor)->putJson('/api/app/crm/metas', [
            'usuario_id' => $vendedor->id, 'mes' => '2026-10', 'unidade' => 'VALOR', 'alvo' => 100,
        ])->assertForbidden();
        $this->spaComo($vendedor)->deleteJson("/api/app/crm/metas/{$meta->id}")->assertForbidden();
    }

    public function test_remove_e_nao_alcanca_meta_de_outro_tenant(): void
    {
        $meta = MetaDoVendedor::factory()->create(['usuario_id' => $this->admin->id]);
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheia = MetaDoVendedor::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->deleteJson("/api/app/crm/metas/{$alheia->id}")->assertNotFound();
        $this->spaComo($this->admin)->deleteJson("/api/app/crm/metas/{$meta->id}")->assertNoContent();
        $this->assertSame(0, MetaDoVendedor::query()->count());
    }

    public function test_listagem_so_mostra_o_proprio_tenant(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        MetaDoVendedor::factory()->create(['mes' => '2026-10-01']);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->getJson('/api/app/crm/metas?mes=2026-10')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=MetasApiTest`
Expected: FAIL (404 nas rotas).

- [ ] **Step 3: Write minimal implementation**

`DefinirMeta.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\CarbonInterface;

final class DefinirMeta
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly DiretorioDeUsuarios $usuarios,
    ) {}

    public function executar(Usuario $autor, string $usuarioId, CarbonInterface $mes, UnidadeDaMeta $unidade, int $alvo): MetaDoVendedor
    {
        $this->politica->garantirPodeConfigurar($autor);

        if ($alvo <= 0) {
            throw RegraDoCrmException::metaInvalida('A meta precisa ser maior que zero.');
        }

        if (! $this->usuarios->ehDaEmpresa($usuarioId)) {
            throw RegraDoCrmException::metaInvalida('Escolha um usuário ativo da empresa.');
        }

        return MetaDoVendedor::query()->updateOrCreate(
            ['usuario_id' => $usuarioId, 'mes' => $mes->copy()->startOfMonth()->toDateString()],
            ['unidade' => $unidade, 'alvo' => $alvo],
        );
    }
}
```

`RemoverMeta.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Models\Usuario;

final class RemoverMeta
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, MetaDoVendedor $meta): void
    {
        $this->politica->garantirPodeConfigurar($autor);
        $meta->delete();
    }
}
```

`ListarMetas.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ListarMetas
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @return Collection<int, MetaDoVendedor> */
    public function executar(Usuario $autor, CarbonInterface $mes): Collection
    {
        $this->politica->garantirPodeLer($autor);

        $consulta = MetaDoVendedor::query()->where('mes', $mes->copy()->startOfMonth()->toDateString());

        if (! $this->politica->podeReatribuir($autor)) {
            $consulta->where('usuario_id', $autor->id);
        }

        return $consulta->orderBy('id')->get();
    }
}
```

`MetaRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MetaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'usuario_id' => ['required', 'uuid'],
            'mes' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'unidade' => ['required', 'in:VALOR,QUANTIDADE'],
            'alvo' => ['required', 'integer', 'min:1'],
        ];
    }
}
```

`MetasController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\DefinirMeta;
use App\Modules\Crm\Application\Actions\ListarMetas;
use App\Modules\Crm\Application\Actions\RemoverMeta;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Crm\Http\Requests\MetaRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MetasController
{
    public function __construct(private readonly DiretorioDeUsuarios $usuarios) {}

    public function index(Request $request, ListarMetas $listar): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $mes = $this->mes((string) $request->query('mes', now((string) config('app.fuso_negocio'))->format('Y-m')));

        return response()->json(['data' => $this->apresentar($listar->executar($autor, $mes)->all())]);
    }

    public function update(MetaRequest $request, DefinirMeta $definir): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        /** @var array{usuario_id: string, mes: string, unidade: string, alvo: int} $dados */
        $dados = $request->validated();

        $meta = $definir->executar($autor, $dados['usuario_id'], $this->mes($dados['mes']), UnidadeDaMeta::from($dados['unidade']), (int) $dados['alvo']);

        return response()->json(['data' => $this->apresentar([$meta])[0]]);
    }

    public function destroy(Request $request, string $id, RemoverMeta $remover): Response
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $remover->executar($autor, MetaDoVendedor::query()->findOrFail($id));

        return response()->noContent();
    }

    private function mes(string $texto): Carbon
    {
        return Carbon::parse($texto.'-01')->startOfDay();
    }

    /**
     * @param  list<MetaDoVendedor>  $metas
     * @return list<array<string, mixed>>
     */
    private function apresentar(array $metas): array
    {
        $nomes = $this->usuarios->nomes(array_map(fn (MetaDoVendedor $m): string => $m->usuario_id, $metas));

        return array_map(fn (MetaDoVendedor $m): array => [
            'id' => $m->id,
            'usuario_id' => $m->usuario_id,
            'usuario_nome' => $nomes[$m->usuario_id] ?? null,
            'mes' => $m->mes->format('Y-m'),
            'unidade' => $m->unidade->value,
            'alvo' => $m->alvo,
        ], $metas);
    }
}
```

`GET metas?mes=` mal formado: `Carbon::parse` lança; validar no controller antes: no `index`, se `preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $texto) !== 1` lançar `RegraDoCrmException::periodoInvalido('Informe o mês no formato AAAA-MM.')`. Incluir essa verificação no método `mes()` (único ponto de parse) e importar `RegraDoCrmException`.

Em `routes-app.php`, importar `MetasController` e acrescentar dentro do grupo:

```php
    Route::get('metas', [MetasController::class, 'index'])->name('app.crm.metas.index');
    Route::put('metas', [MetasController::class, 'update'])->name('app.crm.metas.update');
    Route::delete('metas/{id}', [MetasController::class, 'destroy'])->name('app.crm.metas.destroy');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=MetasApiTest`
Expected: PASS (6 testes).

- [ ] **Step 5: Commit**

```bash
cd /c/Users/dougl/workspace/crm
git add backend/app/Modules/Crm/Application/Actions/DefinirMeta.php backend/app/Modules/Crm/Application/Actions/RemoverMeta.php backend/app/Modules/Crm/Application/Actions/ListarMetas.php backend/app/Modules/Crm/Http/Controllers/MetasController.php backend/app/Modules/Crm/Http/Requests/MetaRequest.php backend/app/Modules/Crm/Http/routes-app.php backend/tests/Feature/Crm/MetasApiTest.php
git commit -m "feat(crm): API de metas mensais por vendedor

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 3: Período e agregados de negócios

**Files:**
- Create: `backend/app/Modules/Crm/Application/Relatorio/PeriodoDoRelatorio.php`
- Create: `backend/app/Modules/Crm/Application/Relatorio/RelatorioDeNegocios.php`
- Test: `backend/tests/Feature/Crm/RelatorioDeNegociosTest.php`

**Interfaces:**
- Consumes: `Negocio::visiveisPara`, `Funil`, `Etapa`, `MotivoDePerda`, `MetaDoVendedor`, `DiretorioDeUsuarios::nomes`, `RegraDoCrmException::periodoInvalido`.
- Produces:
  - `PeriodoDoRelatorio::entre(string $de, string $ate): self`, `::doMes(CarbonInterface $referencia): self`; props `de`, `ate` (`CarbonImmutable` no fuso do negócio); `inicioUtc()`, `fimUtc()`: `CarbonImmutable`; `inicioData()`, `fimData()`, `mes()`: `string` `Y-m-d`; `ehMesInteiro(): bool`.
  - `RelatorioDeNegocios`: `negocios(Usuario, ?string $vendedorId): Builder<Negocio>`; `resumo(Usuario, PeriodoDoRelatorio, ?string): array`; `funil(Usuario, ?string $vendedorId, ?string $funilId): ?array`; `previsao(Usuario, PeriodoDoRelatorio, ?string): array`; `motivosDePerda(Usuario, PeriodoDoRelatorio, ?string): array`; `vendedores(Usuario, PeriodoDoRelatorio, ?string): ?array`; `metaDoUsuario(Usuario, PeriodoDoRelatorio): ?array`.
  - Formas: total `{quantidade:int, valor_centavos:int}`; meta `{unidade, alvo, realizado, percentual}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Relatorio\PeriodoDoRelatorio;
use App\Modules\Crm\Application\Relatorio\RelatorioDeNegocios;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Enums\Papel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class RelatorioDeNegociosTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private RelatorioDeNegocios $relatorio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->relatorio = app(RelatorioDeNegocios::class);
    }

    private function periodoDeOutubro(): PeriodoDoRelatorio
    {
        return PeriodoDoRelatorio::entre('2026-10-01', '2026-10-31');
    }

    /** @param array<string, mixed> $extra */
    private function fechado(StatusDoNegocio $status, string $quando, int $valor, array $extra = []): Negocio
    {
        return Negocio::factory()->create([
            'status' => $status, 'fechado_em' => $quando, 'valor_centavos' => $valor, 'responsavel_id' => $this->admin->id, ...$extra,
        ]);
    }

    public function test_periodo_valida_formato_ordem_e_tamanho(): void
    {
        foreach ([['2026-10-31', '2026-10-01'], ['2026-02-31', '2026-03-01'], ['x', '2026-10-01'], ['2025-01-01', '2026-01-02']] as [$de, $ate]) {
            try {
                PeriodoDoRelatorio::entre($de, $ate);
                $this->fail("Aceitou {$de} a {$ate}");
            } catch (RegraDoCrmException $e) {
                $this->assertSame('PERIODO_INVALIDO', $e->codigo());
            }
        }

        $this->assertTrue(PeriodoDoRelatorio::entre('2026-01-01', '2026-12-31')->ate->isSameDay('2026-12-31'));
    }

    public function test_periodo_usa_o_fuso_do_negocio_nas_bordas(): void
    {
        $periodo = $this->periodoDeOutubro();

        $this->assertSame('2026-10-01 03:00:00', $periodo->inicioUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 02:59:59', $periodo->fimUtc()->format('Y-m-d H:i:s'));
        $this->assertTrue($periodo->ehMesInteiro());
        $this->assertFalse(PeriodoDoRelatorio::entre('2026-10-02', '2026-10-31')->ehMesInteiro());
    }

    public function test_resumo_conta_ganhos_perdidos_e_abertos_e_calcula_conversao(): void
    {
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 10000);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-11 15:00:00', 5000);
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-12 15:00:00', 7000);
        $this->fechado(StatusDoNegocio::Ganho, '2026-09-30 15:00:00', 99999); // fora do período
        Negocio::factory()->create(['valor_centavos' => 3000, 'responsavel_id' => $this->admin->id]);

        $r = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), null);

        $this->assertSame(['quantidade' => 2, 'valor_centavos' => 15000], $r['ganhos']);
        $this->assertSame(['quantidade' => 1, 'valor_centavos' => 7000], $r['perdidos']);
        $this->assertSame(['quantidade' => 1, 'valor_centavos' => 3000], $r['abertos']);
        $this->assertSame(0.6667, $r['taxa_de_conversao']);
    }

    public function test_sem_negocio_fechado_a_taxa_e_nula_e_os_totais_sao_zero(): void
    {
        $r = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), null);

        $this->assertNull($r['taxa_de_conversao']);
        $this->assertSame(['quantidade' => 0, 'valor_centavos' => 0], $r['ganhos']);
    }

    public function test_so_perdidos_da_taxa_zero_e_nao_nula(): void
    {
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-12 15:00:00', 100);

        $r = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), null);

        $this->assertSame(0.0, $r['taxa_de_conversao']);
    }

    public function test_fechamento_as_23h30_do_ultimo_dia_cai_no_mes_certo(): void
    {
        // 23h30 de 31/10 em São Paulo = 02h30 de 01/11 em UTC.
        $this->fechado(StatusDoNegocio::Ganho, '2026-11-01 02:30:00', 1000);

        $outubro = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), null);
        $novembro = $this->relatorio->resumo($this->admin, PeriodoDoRelatorio::entre('2026-11-01', '2026-11-30'), null);

        $this->assertSame(1, $outubro['ganhos']['quantidade']);
        $this->assertSame(0, $novembro['ganhos']['quantidade']);
    }

    public function test_filtro_por_vendedor_e_visibilidade_do_vendedor_restrito(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 1000);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 4000, ['responsavel_id' => $vendedor->id]);

        $porAdmin = $this->relatorio->resumo($this->admin, $this->periodoDeOutubro(), $vendedor->id);
        $restrito = $this->relatorio->resumo($vendedor, $this->periodoDeOutubro(), null);

        $this->assertSame(4000, $porAdmin['ganhos']['valor_centavos']);
        $this->assertSame(4000, $restrito['ganhos']['valor_centavos']);
        $this->assertNull($this->relatorio->vendedores($vendedor, $this->periodoDeOutubro(), null));
    }

    public function test_funil_mostra_so_etapas_ativas_do_funil_padrao_com_negocios_abertos(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $a = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 0, 'probabilidade' => 10, 'nome' => 'A']);
        $b = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 1, 'probabilidade' => 50, 'nome' => 'B']);
        Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 2, 'ativa' => false, 'nome' => 'Inativa']);
        Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $a->id, 'valor_centavos' => 1000, 'responsavel_id' => $this->admin->id]);
        Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $a->id, 'valor_centavos' => 2000, 'responsavel_id' => $this->admin->id]);

        $f = $this->relatorio->funil($this->admin, null, null);

        $this->assertSame($funil->id, $f['id']);
        $this->assertSame(['A', 'B'], array_column($f['etapas'], 'nome'));
        $this->assertSame(2, $f['etapas'][0]['quantidade']);
        $this->assertSame(3000, $f['etapas'][0]['valor_centavos']);
        $this->assertSame(0, $f['etapas'][1]['quantidade']);
        $this->assertSame($b->id, $f['etapas'][1]['id']);
    }

    public function test_sem_funil_padrao_o_bloco_e_nulo(): void
    {
        $this->assertNull($this->relatorio->funil($this->admin, null, null));
    }

    public function test_previsao_pondera_pela_probabilidade_e_separa_os_sem_data(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'probabilidade' => 50]);
        $base = ['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id];
        Negocio::factory()->create([...$base, 'valor_centavos' => 10000, 'previsao_fechamento' => '2026-10-31']);
        Negocio::factory()->create([...$base, 'valor_centavos' => 4001, 'previsao_fechamento' => '2026-10-01']);
        Negocio::factory()->create([...$base, 'valor_centavos' => 9999, 'previsao_fechamento' => '2026-11-01']);
        Negocio::factory()->create([...$base, 'valor_centavos' => 700, 'previsao_fechamento' => null]);

        $p = $this->relatorio->previsao($this->admin, $this->periodoDeOutubro(), null);

        $this->assertSame(2, $p['no_periodo']['quantidade']);
        $this->assertSame(14001, $p['no_periodo']['valor_centavos']);
        $this->assertSame(7000, $p['no_periodo']['ponderada_centavos']);
        $this->assertSame(['quantidade' => 1, 'valor_centavos' => 700], $p['sem_previsao']);
    }

    public function test_motivos_de_perda_agrupa_e_nomeia_o_sem_motivo(): void
    {
        $preco = MotivoDePerda::query()->create(['nome' => 'Preço', 'ativo' => true]);
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-10 15:00:00', 1000, ['motivo_de_perda_id' => $preco->id]);
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-11 15:00:00', 3000, ['motivo_de_perda_id' => $preco->id]);
        $this->fechado(StatusDoNegocio::Perdido, '2026-10-12 15:00:00', 500, ['motivo_de_perda_id' => null]);

        $m = $this->relatorio->motivosDePerda($this->admin, $this->periodoDeOutubro(), null);

        $this->assertSame('Preço', $m[0]['nome']);
        $this->assertSame(2, $m[0]['quantidade']);
        $this->assertSame(4000, $m[0]['valor_centavos']);
        $this->assertSame('Sem motivo', $m[1]['nome']);
        $this->assertNull($m[1]['motivo_id']);
    }

    public function test_vendedores_trazem_meta_so_quando_o_periodo_e_um_mes_inteiro(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 25000, ['responsavel_id' => $vendedor->id]);
        MetaDoVendedor::factory()->create([
            'usuario_id' => $vendedor->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Valor, 'alvo' => 100000,
        ]);
        MetaDoVendedor::factory()->create([
            'usuario_id' => $this->admin->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Quantidade, 'alvo' => 5,
        ]);

        $mes = collect($this->relatorio->vendedores($this->admin, $this->periodoDeOutubro(), null))->keyBy('usuario_id');
        $parcial = collect($this->relatorio->vendedores($this->admin, PeriodoDoRelatorio::entre('2026-10-01', '2026-10-15'), null))->keyBy('usuario_id');

        $this->assertSame(['unidade' => 'VALOR', 'alvo' => 100000, 'realizado' => 25000, 'percentual' => 25], $mes[$vendedor->id]['meta']);
        $this->assertSame(0, $mes[$this->admin->id]['meta']['realizado']);
        $this->assertNull($parcial[$vendedor->id]['meta']);
    }

    public function test_meta_batida_passa_de_cem_por_cento(): void
    {
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-10 15:00:00', 1000);
        $this->fechado(StatusDoNegocio::Ganho, '2026-10-11 15:00:00', 1000);
        MetaDoVendedor::factory()->create([
            'usuario_id' => $this->admin->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Quantidade, 'alvo' => 1,
        ]);

        $meta = $this->relatorio->metaDoUsuario($this->admin, $this->periodoDeOutubro());

        $this->assertSame(200, $meta['percentual']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=RelatorioDeNegociosTest`
Expected: FAIL (`Class "...PeriodoDoRelatorio" not found`).

- [ ] **Step 3: Write minimal implementation**

`PeriodoDoRelatorio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Relatorio;

use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Intervalo de datas do relatório, sempre no fuso do negócio; as bordas viram UTC só na consulta. */
final class PeriodoDoRelatorio
{
    private const MAXIMO_DE_DIAS = 366;

    private function __construct(
        public readonly CarbonImmutable $de,
        public readonly CarbonImmutable $ate,
    ) {}

    public static function entre(string $de, string $ate): self
    {
        $inicio = self::dia($de);
        $fim = self::dia($ate);

        if ($fim->lt($inicio)) {
            throw RegraDoCrmException::periodoInvalido('A data final não pode ser anterior à inicial.');
        }

        if ($inicio->diffInDays($fim) > self::MAXIMO_DE_DIAS - 1) {
            throw RegraDoCrmException::periodoInvalido('O período máximo é de 366 dias.');
        }

        return new self($inicio, $fim->endOfDay());
    }

    public static function doMes(CarbonInterface $referencia): self
    {
        $dia = CarbonImmutable::instance($referencia)->setTimezone((string) config('app.fuso_negocio'));

        return new self($dia->startOfMonth(), $dia->endOfMonth());
    }

    public function inicioUtc(): CarbonImmutable
    {
        return $this->de->utc();
    }

    public function fimUtc(): CarbonImmutable
    {
        return $this->ate->utc();
    }

    public function inicioData(): string
    {
        return $this->de->toDateString();
    }

    public function fimData(): string
    {
        return $this->ate->toDateString();
    }

    /** Dia 1 do mês, chave da meta. */
    public function mes(): string
    {
        return $this->de->startOfMonth()->toDateString();
    }

    public function ehMesInteiro(): bool
    {
        return $this->de->day === 1 && $this->ate->toDateString() === $this->de->endOfMonth()->toDateString();
    }

    private static function dia(string $texto): CarbonImmutable
    {
        $mensagem = 'Informe as datas do período no formato AAAA-MM-DD.';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) !== 1) {
            throw RegraDoCrmException::periodoInvalido($mensagem);
        }

        try {
            $dia = CarbonImmutable::createFromFormat('!Y-m-d', $texto, (string) config('app.fuso_negocio'));
        } catch (\Throwable) {
            throw RegraDoCrmException::periodoInvalido($mensagem);
        }

        // `2026-02-31` não falha na criação: vira 3 de março. A comparação pega o estouro.
        if ($dia === false || $dia->format('Y-m-d') !== $texto) {
            throw RegraDoCrmException::periodoInvalido($mensagem);
        }

        return $dia;
    }
}
```

`RelatorioDeNegocios.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Relatorio;

use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

/** Agregados de negócios e metas. Tudo em SQL (`GROUP BY`); respeita a visibilidade do vendedor. */
final class RelatorioDeNegocios
{
    public function __construct(private readonly DiretorioDeUsuarios $usuarios) {}

    /** @return Builder<Negocio> */
    public function negocios(Usuario $autor, ?string $vendedorId): Builder
    {
        $consulta = Negocio::query()->visiveisPara($autor);

        if ($vendedorId !== null) {
            $consulta->where('responsavel_id', $vendedorId);
        }

        return $consulta;
    }

    /** @return array<string, mixed> */
    public function resumo(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): array
    {
        $fechados = fn (StatusDoNegocio $status): array => $this->totais(
            $this->negocios($autor, $vendedorId)->where('status', $status)
                ->whereBetween('fechado_em', [$periodo->inicioUtc(), $periodo->fimUtc()]),
        );

        $ganhos = $fechados(StatusDoNegocio::Ganho);
        $perdidos = $fechados(StatusDoNegocio::Perdido);
        $abertos = $this->totais($this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Aberto));
        $fechadosNoPeriodo = $ganhos['quantidade'] + $perdidos['quantidade'];

        return [
            'abertos' => $abertos,
            'ganhos' => $ganhos,
            'perdidos' => $perdidos,
            'taxa_de_conversao' => $fechadosNoPeriodo === 0 ? null : round($ganhos['quantidade'] / $fechadosNoPeriodo, 4),
        ];
    }

    /** @return array{id: string, nome: string, etapas: list<array<string, mixed>>}|null */
    public function funil(Usuario $autor, ?string $vendedorId, ?string $funilId): ?array
    {
        $funil = $funilId !== null
            ? Funil::query()->findOrFail($funilId)
            : Funil::query()->where('padrao', true)->first();

        if ($funil === null) {
            return null;
        }

        $linhas = $this->negocios($autor, $vendedorId)
            ->where('status', StatusDoNegocio::Aberto)->where('funil_id', $funil->id)
            ->selectRaw('etapa_id, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('etapa_id')->get()->keyBy('etapa_id');

        $etapas = Etapa::query()->where('funil_id', $funil->id)->where('ativa', true)->orderBy('posicao')->get()
            ->map(fn (Etapa $e): array => [
                'id' => $e->id,
                'nome' => $e->nome,
                'probabilidade' => $e->probabilidade,
                'quantidade' => (int) $linhas->get($e->id)?->getAttribute('quantidade'),
                'valor_centavos' => (int) $linhas->get($e->id)?->getAttribute('valor'),
            ])->values()->all();

        return ['id' => $funil->id, 'nome' => $funil->nome, 'etapas' => $etapas];
    }

    /** @return array{no_periodo: array<string, int>, sem_previsao: array<string, int>} */
    public function previsao(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): array
    {
        $linhas = $this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Aberto)
            ->whereDate('previsao_fechamento', '>=', $periodo->inicioData())
            ->whereDate('previsao_fechamento', '<=', $periodo->fimData())
            ->selectRaw('etapa_id, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('etapa_id')->get();

        $probabilidades = Etapa::query()->whereIn('id', $linhas->pluck('etapa_id')->all())->pluck('probabilidade', 'id');

        $quantidade = 0;
        $valor = 0;
        $ponderado = 0;
        foreach ($linhas as $linha) {
            $quantidade += (int) $linha->getAttribute('quantidade');
            $valor += (int) $linha->getAttribute('valor');
            // A etapa existe (chave estrangeira): a probabilidade é sempre encontrada.
            $ponderado += (int) $linha->getAttribute('valor') * (int) $probabilidades[$linha->etapa_id];
        }

        return [
            'no_periodo' => ['quantidade' => $quantidade, 'valor_centavos' => $valor, 'ponderada_centavos' => intdiv($ponderado, 100)],
            'sem_previsao' => $this->totais(
                $this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Aberto)->whereNull('previsao_fechamento'),
            ),
        ];
    }

    /** @return list<array{motivo_id: string|null, nome: string, quantidade: int, valor_centavos: int}> */
    public function motivosDePerda(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): array
    {
        $linhas = $this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Perdido)
            ->whereBetween('fechado_em', [$periodo->inicioUtc(), $periodo->fimUtc()])
            ->selectRaw('motivo_de_perda_id, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('motivo_de_perda_id')->get();

        $nomes = MotivoDePerda::query()->whereIn('id', $linhas->pluck('motivo_de_perda_id')->filter()->all())->pluck('nome', 'id');

        return $linhas->map(fn (Negocio $l): array => [
            'motivo_id' => $l->motivo_de_perda_id,
            'nome' => $l->motivo_de_perda_id === null ? 'Sem motivo' : (string) $nomes->get($l->motivo_de_perda_id, 'Motivo removido'),
            'quantidade' => (int) $l->getAttribute('quantidade'),
            'valor_centavos' => (int) $l->getAttribute('valor'),
        ])->sortByDesc('valor_centavos')->values()->all();
    }

    /**
     * Ranking por vendedor. Vendedor restrito não vê ranking: devolve `null`.
     *
     * @return list<array<string, mixed>>|null
     */
    public function vendedores(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): ?array
    {
        if (Negocio::restritoParaVendedor($autor)) {
            return null;
        }

        $fechados = $this->negocios($autor, $vendedorId)->whereIn('status', [StatusDoNegocio::Ganho, StatusDoNegocio::Perdido])
            ->whereBetween('fechado_em', [$periodo->inicioUtc(), $periodo->fimUtc()])
            ->selectRaw('responsavel_id, status, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('responsavel_id', 'status')->get();
        $abertos = $this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Aberto)
            ->selectRaw('responsavel_id, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('responsavel_id')->get();

        $metas = collect();
        if ($periodo->ehMesInteiro()) {
            $metas = MetaDoVendedor::query()->where('mes', $periodo->mes())
                ->when($vendedorId !== null, fn ($q) => $q->where('usuario_id', $vendedorId))
                ->get()->keyBy('usuario_id');
        }

        $ids = collect([...$fechados->pluck('responsavel_id'), ...$abertos->pluck('responsavel_id'), ...$metas->keys()])->unique()->values()->all();
        $nomes = $this->usuarios->nomes($ids);
        $zero = ['quantidade' => 0, 'valor_centavos' => 0];

        $linhas = array_map(function (string $id) use ($fechados, $abertos, $metas, $nomes, $zero): array {
            $doVendedor = fn (StatusDoNegocio $s): array => ($l = $fechados->first(fn (Negocio $f): bool => $f->responsavel_id === $id && $f->status === $s)) === null
                ? $zero
                : ['quantidade' => (int) $l->getAttribute('quantidade'), 'valor_centavos' => (int) $l->getAttribute('valor')];
            $abertosDele = $abertos->first(fn (Negocio $a): bool => $a->responsavel_id === $id);
            $ganhos = $doVendedor(StatusDoNegocio::Ganho);
            $meta = $metas->get($id);

            return [
                'usuario_id' => $id,
                'nome' => $nomes[$id] ?? 'Usuário removido',
                'abertos' => $abertosDele === null ? $zero : ['quantidade' => (int) $abertosDele->getAttribute('quantidade'), 'valor_centavos' => (int) $abertosDele->getAttribute('valor')],
                'ganhos' => $ganhos,
                'perdidos' => $doVendedor(StatusDoNegocio::Perdido),
                'meta' => $meta === null ? null : self::progresso($meta, $ganhos),
            ];
        }, $ids);

        usort($linhas, fn (array $a, array $b): int => $b['ganhos']['valor_centavos'] <=> $a['ganhos']['valor_centavos']);

        return $linhas;
    }

    /** Meta do próprio usuário no mês do período, com o realizado. Só vale para mês inteiro. */
    public function metaDoUsuario(Usuario $usuario, PeriodoDoRelatorio $periodo): ?array
    {
        if (! $periodo->ehMesInteiro()) {
            return null;
        }

        $meta = MetaDoVendedor::query()->where('usuario_id', $usuario->id)->where('mes', $periodo->mes())->first();

        if ($meta === null) {
            return null;
        }

        $ganhos = $this->totais(
            Negocio::query()->where('responsavel_id', $usuario->id)->where('status', StatusDoNegocio::Ganho)
                ->whereBetween('fechado_em', [$periodo->inicioUtc(), $periodo->fimUtc()]),
        );

        return self::progresso($meta, $ganhos);
    }

    /**
     * @param  array{quantidade: int, valor_centavos: int}  $ganhos
     * @return array{unidade: string, alvo: int, realizado: int, percentual: int}
     */
    private static function progresso(MetaDoVendedor $meta, array $ganhos): array
    {
        $realizado = $meta->unidade === UnidadeDaMeta::Valor ? $ganhos['valor_centavos'] : $ganhos['quantidade'];

        // `alvo` é sempre maior que zero (validado em DefinirMeta).
        return [
            'unidade' => $meta->unidade->value,
            'alvo' => $meta->alvo,
            'realizado' => $realizado,
            'percentual' => intdiv($realizado * 100, $meta->alvo),
        ];
    }

    /**
     * @param  Builder<Negocio>  $consulta
     * @return array{quantidade: int, valor_centavos: int}
     */
    private function totais(Builder $consulta): array
    {
        $linha = $consulta->selectRaw('COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')->first();

        return [
            'quantidade' => (int) $linha?->getAttribute('quantidade'),
            'valor_centavos' => (int) $linha?->getAttribute('valor'),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=RelatorioDeNegociosTest`
Expected: PASS (13 testes). Se `ehMesInteiro` falhar por `endOfMonth` com hora, comparar só `toDateString()` (já é o caso).

- [ ] **Step 5: Commit**

```bash
cd /c/Users/dougl/workspace/crm
git add backend/app/Modules/Crm/Application/Relatorio backend/tests/Feature/Crm/RelatorioDeNegociosTest.php
git commit -m "feat(crm): período e agregados de negócios para o relatório

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 4: Contrato de faturado e agregados de propostas

**Files:**
- Create: `backend/app/Modules/Crm/Domain/Contracts/ConsultorDeValorFaturado.php`
- Create: `backend/app/Modules/Fiscal/Application/ConsultorDeValorFaturadoDoFiscal.php`
- Create: `backend/app/Modules/Crm/Application/Relatorio/RelatorioDePropostas.php`
- Modify: `backend/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (linha do bind de `GeradorDeRascunhoDeNota`)
- Test: `backend/tests/Feature/Crm/RelatorioDePropostasTest.php`

**Interfaces:**
- Consumes: `RelatorioDeNegocios::negocios`, `Proposta`, `StatusDaProposta`, `PeriodoDoRelatorio`, `Nota`, `StatusNota`.
- Produces: `ConsultorDeValorFaturado::disponivel(Usuario): bool` e `::totalAutorizadoCentavos(list<string> $notasIds): int`; `RelatorioDePropostas::propostas(Usuario, PeriodoDoRelatorio, ?string $vendedorId): array{enviadas:int, aceitas:int, recusadas:int, valor_aceito_centavos:int, taxa_de_aceite: float|null}` e `::faturado(Usuario, PeriodoDoRelatorio, ?string): ?int`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Relatorio\PeriodoDoRelatorio;
use App\Modules\Crm\Application\Relatorio\RelatorioDePropostas;
use App\Modules\Crm\Domain\Contracts\ConsultorDeValorFaturado;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\Support\ComPropostas;
use Tests\TestCase;

class RelatorioDePropostasTest extends TestCase
{
    use ComCrm;
    use ComPropostas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    private function periodo(): PeriodoDoRelatorio
    {
        return PeriodoDoRelatorio::entre('2026-10-01', '2026-10-31');
    }

    /** @param array<string, mixed> $extra */
    private function aceita(string $quando, array $extra = []): Proposta
    {
        $proposta = $this->propostaEnviada($this->negocioComItens(2, 1500));
        $proposta->forceFill(['status' => StatusDaProposta::Aceita, 'respondida_em' => $quando, ...$extra])->save();

        return $proposta;
    }

    public function test_conta_enviadas_aceitas_recusadas_e_soma_o_valor_aceito_com_desconto(): void
    {
        $this->aceita('2026-10-10 15:00:00', ['desconto_centavos' => 500]);
        $recusada = $this->propostaEnviada();
        $recusada->forceFill(['status' => StatusDaProposta::Recusada, 'respondida_em' => '2026-10-12 15:00:00'])->save();
        $this->aceita('2026-09-30 15:00:00'); // fora do período

        Proposta::query()->update(['enviada_em' => '2026-10-05 12:00:00']);

        $r = app(RelatorioDePropostas::class)->propostas($this->admin, $this->periodo(), null);

        $this->assertSame(3, $r['enviadas']);
        $this->assertSame(1, $r['aceitas']);
        $this->assertSame(1, $r['recusadas']);
        $this->assertSame(2500, $r['valor_aceito_centavos']); // 2 × 1500 − 500
        $this->assertSame(0.5, $r['taxa_de_aceite']);
    }

    public function test_sem_propostas_respondidas_a_taxa_e_nula(): void
    {
        $r = app(RelatorioDePropostas::class)->propostas($this->admin, $this->periodo(), null);

        $this->assertNull($r['taxa_de_aceite']);
        $this->assertSame(0, $r['valor_aceito_centavos']);
    }

    public function test_vendedor_restrito_so_conta_propostas_dos_proprios_negocios(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->aceita('2026-10-10 15:00:00'); // negócio do admin

        $r = app(RelatorioDePropostas::class)->propostas($vendedor, $this->periodo(), null);

        $this->assertSame(0, $r['aceitas']);
    }

    public function test_faturado_soma_so_notas_autorizadas_das_propostas_aceitas_no_periodo(): void
    {
        $autorizada = Nota::factory()->create(['status' => StatusNota::Autorizada, 'total_centavos' => 2500]);
        $rascunho = Nota::factory()->create(['status' => StatusNota::Rascunho, 'total_centavos' => 9000]);
        $this->aceita('2026-10-10 15:00:00', ['notas_ids' => [$autorizada->id, $rascunho->id]]);
        $foraDoPeriodo = Nota::factory()->create(['status' => StatusNota::Autorizada, 'total_centavos' => 7000]);
        $this->aceita('2026-09-10 15:00:00', ['notas_ids' => [$foraDoPeriodo->id]]);

        $this->assertSame(2500, app(RelatorioDePropostas::class)->faturado($this->admin, $this->periodo(), null));
    }

    public function test_faturado_e_zero_com_fiscal_e_sem_nota_autorizada(): void
    {
        $this->aceita('2026-10-10 15:00:00');

        $this->assertSame(0, app(RelatorioDePropostas::class)->faturado($this->admin, $this->periodo(), null));
    }

    public function test_faturado_e_nulo_sem_fiscal_no_plano(): void
    {
        $plano = Plano::factory()->comModulos(Modulo::Crm);
        $tenant = Tenant::factory()->for($plano)->create();
        $semFiscal = \App\Modules\Identity\Domain\Models\Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->assertFalse(app(ConsultorDeValorFaturado::class)->disponivel($semFiscal));
        $this->assertNull(app(RelatorioDePropostas::class)->faturado($semFiscal, $this->periodo(), null));
    }
}
```

> O teste `faturado_e_zero_com_fiscal` e `soma_so_notas_autorizadas` exigem o módulo Fiscal no plano de teste. Em `prepararCrm()` o plano só tem `Modulo::Crm`: no `setUp` desta classe, depois de `prepararCrm()`, acrescentar o módulo ao plano do tenant. Descobrir como: `grep -n "comModulos" backend/tests -r | head`; usar o mesmo padrão dos testes de `RascunhoDeNotaDaPropostaTest` (que já precisam de NF-e no plano) e copiá-lo para o `setUp`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=RelatorioDePropostasTest`
Expected: FAIL (`Target class [...ConsultorDeValorFaturado] does not exist` / classe não encontrada).

- [ ] **Step 3: Write minimal implementation**

`ConsultorDeValorFaturado.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Contracts;

use App\Modules\Identity\Domain\Models\Usuario;

/** Implementado por Fiscal. Só leitura, sempre dentro do tenant do contexto atual. */
interface ConsultorDeValorFaturado
{
    /** O plano do tenant inclui NF-e ou NFS-e? Sem isso o faturado não existe. */
    public function disponivel(Usuario $autor): bool;

    /**
     * Soma o total das notas **autorizadas** entre os ids informados. Outros status não contam.
     *
     * @param  list<string>  $notasIds
     */
    public function totalAutorizadoCentavos(array $notasIds): int;
}
```

`ConsultorDeValorFaturadoDoFiscal.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Crm\Domain\Contracts\ConsultorDeValorFaturado;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;

/** Ponte Fiscal → CRM de leitura: o CRM nunca importa o Model da nota. */
final class ConsultorDeValorFaturadoDoFiscal implements ConsultorDeValorFaturado
{
    public function __construct(private readonly EntitlementService $planos) {}

    public function disponivel(Usuario $autor): bool
    {
        return $autor->tenant !== null
            && ($this->planos->temModulo($autor->tenant, Modulo::FiscalNfe) || $this->planos->temModulo($autor->tenant, Modulo::FiscalNfse));
    }

    public function totalAutorizadoCentavos(array $notasIds): int
    {
        if ($notasIds === []) {
            return 0;
        }

        return (int) Nota::query()->whereIn('id', $notasIds)->where('status', StatusNota::Autorizada)->sum('total_centavos');
    }
}
```

`FiscalServiceProvider.php`, ao lado do bind existente (`use` incluído no topo):

```php
        $this->app->bind(ConsultorDeValorFaturado::class, ConsultorDeValorFaturadoDoFiscal::class);
```

`RelatorioDePropostas.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Relatorio;

use App\Modules\Crm\Domain\Contracts\ConsultorDeValorFaturado;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Agregados de propostas e do valor faturado a partir delas. A visibilidade vem dos negócios. */
final class RelatorioDePropostas
{
    public function __construct(
        private readonly RelatorioDeNegocios $negocios,
        private readonly ConsultorDeValorFaturado $faturamento,
    ) {}

    /** @return array{enviadas: int, aceitas: int, recusadas: int, valor_aceito_centavos: int, taxa_de_aceite: float|null} */
    public function propostas(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): array
    {
        $entre = [$periodo->inicioUtc(), $periodo->fimUtc()];

        $enviadas = $this->visiveis($autor, $vendedorId)->whereBetween('enviada_em', $entre)->count();
        $recusadas = $this->visiveis($autor, $vendedorId)->where('status', StatusDaProposta::Recusada)->whereBetween('respondida_em', $entre)->count();
        $aceitas = $this->aceitasNoPeriodo($autor, $periodo, $vendedorId);
        $respondidas = $aceitas->count() + $recusadas;

        return [
            'enviadas' => $enviadas,
            'aceitas' => $aceitas->count(),
            'recusadas' => $recusadas,
            'valor_aceito_centavos' => (int) $aceitas->sum(
                fn (Proposta $p): int => (int) $p->getAttribute('subtotal_centavos') - $p->desconto_centavos,
            ),
            'taxa_de_aceite' => $respondidas === 0 ? null : round($aceitas->count() / $respondidas, 4),
        ];
    }

    /** `null` quando o plano não inclui Fiscal; `0` quando inclui e nenhuma nota foi autorizada. */
    public function faturado(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): ?int
    {
        if (! $this->faturamento->disponivel($autor)) {
            return null;
        }

        /** @var list<string> $ids */
        $ids = $this->aceitasNoPeriodo($autor, $periodo, $vendedorId)
            ->flatMap(fn (Proposta $p): array => $p->notas_ids ?? [])->values()->all();

        return $this->faturamento->totalAutorizadoCentavos($ids);
    }

    /** @return Builder<Proposta> */
    private function visiveis(Usuario $autor, ?string $vendedorId): Builder
    {
        return Proposta::query()->whereIn('negocio_id', $this->negocios->negocios($autor, $vendedorId)->select('id'));
    }

    /**
     * Linhas escalares: o subtotal vem de um `SUM` por proposta, sem hidratar itens.
     *
     * @return Collection<int, Proposta>
     */
    private function aceitasNoPeriodo(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): Collection
    {
        return $this->visiveis($autor, $vendedorId)
            ->where('status', StatusDaProposta::Aceita)
            ->whereBetween('respondida_em', [$periodo->inicioUtc(), $periodo->fimUtc()])
            ->withSum('itens as subtotal_centavos', DB::raw('quantidade * valor_unitario_centavos'))
            ->get();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=RelatorioDePropostasTest`
Expected: PASS (6 testes). Se `withSum` com `DB::raw` não aceitar a expressão, trocar por `->addSelect(['subtotal_centavos' => ItemDaProposta::query()->selectRaw('COALESCE(SUM(quantidade * valor_unitario_centavos), 0)')->whereColumn('proposta_id', 'crm_propostas.id')])` e ler o mesmo atributo.

- [ ] **Step 5: Commit**

```bash
cd /c/Users/dougl/workspace/crm
git add backend/app/Modules/Crm/Domain/Contracts/ConsultorDeValorFaturado.php backend/app/Modules/Fiscal/Application/ConsultorDeValorFaturadoDoFiscal.php backend/app/Modules/Fiscal/Providers/FiscalServiceProvider.php backend/app/Modules/Crm/Application/Relatorio/RelatorioDePropostas.php backend/tests/Feature/Crm/RelatorioDePropostasTest.php
git commit -m "feat(crm): agregados de propostas e valor faturado via contrato Crm→Fiscal

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 5: Action e API do relatório; resumo do painel ampliado

**Files:**
- Create: `backend/app/Modules/Crm/Application/Actions/RelatorioComercial.php`
- Create: `backend/app/Modules/Crm/Http/Requests/RelatorioRequest.php`
- Create: `backend/app/Modules/Crm/Http/Controllers/RelatoriosController.php`
- Modify: `backend/app/Modules/Crm/Application/Actions/ResumoDoCrm.php`
- Modify: `backend/app/Modules/Crm/Http/routes-app.php`
- Test: `backend/tests/Feature/Crm/RelatoriosApiTest.php`

**Interfaces:**
- Consumes: `RelatorioDeNegocios`, `RelatorioDePropostas`, `PeriodoDoRelatorio`, `PoliticaDoCrm`.
- Produces: `GET api/app/crm/relatorios?de&ate&vendedor_id&funil_id` → `{data: {periodo:{de,ate}, resumo, funil, previsao, motivos_de_perda, vendedores, propostas, faturado_centavos}}`. `GET api/app/crm/resumo` ganha `funil`, `previsao_do_mes`, `meta_do_mes`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ComCrm;
use Tests\TestCase;

class RelatoriosApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        Carbon::setTestNow('2026-10-20 15:00:00');
    }

    private function ganho(int $valor, ?string $responsavel = null, string $quando = '2026-10-10 15:00:00'): Negocio
    {
        return Negocio::factory()->create([
            'status' => StatusDoNegocio::Ganho, 'fechado_em' => $quando, 'valor_centavos' => $valor,
            'responsavel_id' => $responsavel ?? $this->admin->id,
        ]);
    }

    public function test_devolve_o_relatorio_do_periodo(): void
    {
        $this->ganho(10000);

        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?de=2026-10-01&ate=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.periodo.de', '2026-10-01')
            ->assertJsonPath('data.resumo.ganhos.quantidade', 1)
            ->assertJsonPath('data.resumo.ganhos.valor_centavos', 10000)
            ->assertJsonPath('data.resumo.taxa_de_conversao', 1)
            ->assertJsonPath('data.propostas.enviadas', 0)
            ->assertJsonPath('data.faturado_centavos', null)
            ->assertJsonStructure(['data' => ['resumo', 'funil', 'previsao' => ['no_periodo', 'sem_previsao'], 'motivos_de_perda', 'vendedores']]);
    }

    public function test_sem_periodo_usa_o_mes_corrente(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios')
            ->assertOk()
            ->assertJsonPath('data.periodo.de', '2026-10-01')
            ->assertJsonPath('data.periodo.ate', '2026-10-31');
    }

    public function test_periodo_invalido_devolve_422_com_codigo(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?de=2026-10-31&ate=2026-10-01')
            ->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INVALIDO');
    }

    public function test_vendedor_restrito_ve_so_o_proprio_e_sem_ranking(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->ganho(1000);
        $this->ganho(4000, $vendedor->id);

        $this->spaComo($vendedor)->getJson('/api/app/crm/relatorios?de=2026-10-01&ate=2026-10-31&vendedor_id='.$this->admin->id)
            ->assertOk()
            ->assertJsonPath('data.resumo.ganhos.valor_centavos', 4000)
            ->assertJsonPath('data.vendedores', null);
    }

    public function test_admin_filtra_por_vendedor(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->ganho(1000);
        $this->ganho(4000, $vendedor->id);

        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?de=2026-10-01&ate=2026-10-31&vendedor_id='.$vendedor->id)
            ->assertOk()->assertJsonPath('data.resumo.ganhos.valor_centavos', 4000);
    }

    public function test_isolamento_nao_mostra_numeros_de_outra_empresa(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        Negocio::factory()->create(['status' => StatusDoNegocio::Ganho, 'fechado_em' => '2026-10-10 15:00:00', 'valor_centavos' => 99999]);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?de=2026-10-01&ate=2026-10-31')
            ->assertOk()->assertJsonPath('data.resumo.ganhos.quantidade', 0);
    }

    public function test_funil_de_outro_tenant_devolve_404(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheio = Funil::factory()->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->getJson('/api/app/crm/relatorios?funil_id='.$alheio->id)->assertNotFound();
    }

    public function test_fiscal_e_barrado(): void
    {
        $this->spaComo($this->usuarioDoPapel(Papel::Fiscal))->getJson('/api/app/crm/relatorios')->assertForbidden();
    }

    public function test_resumo_do_painel_traz_funil_previsao_e_meta_do_mes(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'probabilidade' => 40]);
        Negocio::factory()->create([
            'funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id,
            'valor_centavos' => 10000, 'previsao_fechamento' => '2026-10-25',
        ]);
        $this->ganho(3000);
        MetaDoVendedor::factory()->create([
            'usuario_id' => $this->admin->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Valor, 'alvo' => 6000,
        ]);

        $this->spaComo($this->admin)->getJson('/api/app/crm/resumo')
            ->assertOk()
            ->assertJsonPath('data.funil.etapas.0.quantidade', 1)
            ->assertJsonPath('data.previsao_do_mes.ponderada_centavos', 4000)
            ->assertJsonPath('data.meta_do_mes.percentual', 50)
            ->assertJsonPath('data.hoje', 0);
    }

    public function test_resumo_sem_meta_devolve_meta_nula(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/resumo')
            ->assertOk()->assertJsonPath('data.meta_do_mes', null)->assertJsonPath('data.funil', null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=RelatoriosApiTest`
Expected: FAIL (404 em `/relatorios`; chaves novas ausentes do resumo).

- [ ] **Step 3: Write minimal implementation**

`RelatorioComercial.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\Relatorio\PeriodoDoRelatorio;
use App\Modules\Crm\Application\Relatorio\RelatorioDeNegocios;
use App\Modules\Crm\Application\Relatorio\RelatorioDePropostas;
use App\Modules\Identity\Domain\Models\Usuario;

final class RelatorioComercial
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly RelatorioDeNegocios $negocios,
        private readonly RelatorioDePropostas $propostas,
    ) {}

    /** @return array<string, mixed> */
    public function executar(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId, ?string $funilId): array
    {
        $this->politica->garantirPodeLer($autor);

        // Quem não reatribui negócio não escolhe vendedor: o resultado já é o próprio recorte dele.
        $vendedorId = $this->politica->podeReatribuir($autor) ? $vendedorId : null;

        return [
            'periodo' => ['de' => $periodo->inicioData(), 'ate' => $periodo->fimData()],
            'resumo' => $this->negocios->resumo($autor, $periodo, $vendedorId),
            'funil' => $this->negocios->funil($autor, $vendedorId, $funilId),
            'previsao' => $this->negocios->previsao($autor, $periodo, $vendedorId),
            'motivos_de_perda' => $this->negocios->motivosDePerda($autor, $periodo, $vendedorId),
            'vendedores' => $this->negocios->vendedores($autor, $periodo, $vendedorId),
            'propostas' => $this->propostas->propostas($autor, $periodo, $vendedorId),
            'faturado_centavos' => $this->propostas->faturado($autor, $periodo, $vendedorId),
        ];
    }
}
```

`RelatorioRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RelatorioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // O formato da data é validado em PeriodoDoRelatorio (devolve PERIODO_INVALIDO).
        return [
            'de' => ['nullable', 'string'],
            'ate' => ['nullable', 'string'],
            'vendedor_id' => ['nullable', 'uuid'],
            'funil_id' => ['nullable', 'uuid'],
        ];
    }
}
```

`RelatoriosController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\RelatorioComercial;
use App\Modules\Crm\Application\Relatorio\PeriodoDoRelatorio;
use App\Modules\Crm\Http\Requests\RelatorioRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;

final class RelatoriosController
{
    public function index(RelatorioRequest $request, RelatorioComercial $relatorio): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        /** @var array{de?: string|null, ate?: string|null, vendedor_id?: string|null, funil_id?: string|null} $dados */
        $dados = $request->validated();

        $de = $dados['de'] ?? null;
        $ate = $dados['ate'] ?? null;
        $periodo = $de === null || $ate === null ? PeriodoDoRelatorio::doMes(now()) : PeriodoDoRelatorio::entre($de, $ate);

        return response()->json(['data' => $relatorio->executar($autor, $periodo, $dados['vendedor_id'] ?? null, $dados['funil_id'] ?? null)]);
    }
}
```

`ResumoDoCrm.php`: injetar `RelatorioDeNegocios` e acrescentar ao retorno (manter os três campos atuais):

```php
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultorDeParados $parados,
        private readonly RelatorioDeNegocios $relatorio,
    ) {}

    /** @return array<string, mixed> */
    public function executar(Usuario $autor): array
    {
        // ... (corpo atual até $situacao)
        $mes = PeriodoDoRelatorio::doMes($agora);

        return [
            'hoje' => /* igual */,
            'atrasadas' => /* igual */,
            'parados' => /* igual */,
            'funil' => $this->relatorio->funil($autor, null, null),
            'previsao_do_mes' => $this->relatorio->previsao($autor, $mes, null)['no_periodo'],
            'meta_do_mes' => $this->relatorio->metaDoUsuario($autor, $mes),
        ];
    }
```

Atualizar o docblock de retorno de `@return array{hoje: int, atrasadas: int, parados: int}` para `@return array<string, mixed>` e importar `PeriodoDoRelatorio` e `RelatorioDeNegocios`.

`routes-app.php`: importar `RelatoriosController` e acrescentar `Route::get('relatorios', [RelatoriosController::class, 'index'])->name('app.crm.relatorios.index');`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter="RelatoriosApiTest|MetasApiTest|RelatorioDe"`
Expected: PASS. Rodar também `php artisan test --filter=Crm` para pegar regressão em `AtividadesApiTest` (resumo).

- [ ] **Step 5: Commit**

```bash
cd /c/Users/dougl/workspace/crm
git add backend/app/Modules/Crm/Application/Actions/RelatorioComercial.php backend/app/Modules/Crm/Application/Actions/ResumoDoCrm.php backend/app/Modules/Crm/Http/Requests/RelatorioRequest.php backend/app/Modules/Crm/Http/Controllers/RelatoriosController.php backend/app/Modules/Crm/Http/routes-app.php backend/tests/Feature/Crm/RelatoriosApiTest.php
git commit -m "feat(crm): API do relatório comercial e resumo do painel com funil, previsão e meta

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 6: Portões do backend (Pint, PHPStan, suíte)

**Files:** nenhum novo; corrigir o que os portões acusarem.

- [ ] **Step 1: Rodar os portões**

```bash
cd /c/Users/dougl/workspace/crm/backend
vendor/bin/pint --test 2>&1 | tail -15
vendor/bin/phpstan analyse --memory-limit=1G 2>&1 | tail -25
php artisan test 2>&1 | tail -15
```

Expected: Pint sem diferenças, PHPStan `[OK] No errors`, suíte toda verde (1476 anteriores + os novos).

- [ ] **Step 2: Corrigir**

Se o Pint acusar: `vendor/bin/pint app/Modules/Crm app/Modules/Fiscal tests/Feature/Crm database/factories`. Se o PHPStan acusar tipos de `selectRaw`/`getAttribute`, anotar com `@var` local ou `(int)`, nunca `@phpstan-ignore`. Rodar os três comandos de novo até ficarem limpos.

- [ ] **Step 3: Commit (só se houve correção)**

```bash
cd /c/Users/dougl/workspace/crm
git add backend/app backend/tests backend/database
git commit -m "chore(crm): ajustes de estilo e tipos da F7d

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 7: Frontend: tipos, API, hooks e períodos

**Files:**
- Modify: `frontend/features/crm/types.ts`
- Modify: `frontend/features/crm/api.ts`
- Create: `frontend/features/crm/periodos.ts`
- Create: `frontend/features/crm/periodos.test.ts`
- Create: `frontend/features/crm/hooks/useRelatorio.ts`
- Create: `frontend/features/crm/hooks/useMetas.ts`

**Interfaces:**
- Produces (tipos em `types.ts`): `UnidadeDaMeta`, `ProgressoDeMeta`, `TotalDeNegocios`, `EtapaDoFunil`, `FunilDoRelatorio`, `PrevisaoDoRelatorio`, `MotivoDoRelatorio`, `VendedorDoRelatorio`, `PropostasDoRelatorio`, `RelatorioComercial`, `MetaDoVendedor`, `ResumoDoCrm` (ampliado).
- Produces (`periodos.ts`): `type Periodo = {de: string; ate: string}`; `mesAtual(hoje?: Date): Periodo`; `mesAnterior(hoje?: Date): Periodo`; `ultimosDias(n: number, hoje?: Date): Periodo`; `mesDoPeriodo(p: Periodo): string` (`AAAA-MM`).
- Produces (api): `crmApi.relatorio(p: {de; ate; vendedor_id?; funil_id?})`, `crmApi.metas(mes)`, `crmApi.definirMeta(dados)`, `crmApi.removerMeta(id)`.
- Produces (hooks): `useRelatorio(periodo, vendedorId?, funilId?)`, `useMetas(mes)`, `useDefinirMeta()`, `useRemoverMeta()`.

- [ ] **Step 1: Write the failing test** (`periodos.test.ts`)

```ts
import { describe, expect, it } from 'vitest';
import { mesAnterior, mesAtual, mesDoPeriodo, ultimosDias } from './periodos';

const hoje = new Date(2026, 9, 20); // 20/10/2026 (mês 9 = outubro)

describe('periodos', () => {
  it('mês atual vai do dia 1 ao último dia', () => {
    expect(mesAtual(hoje)).toEqual({ de: '2026-10-01', ate: '2026-10-31' });
  });

  it('mês anterior cruza o ano em janeiro', () => {
    expect(mesAnterior(new Date(2026, 0, 15))).toEqual({ de: '2025-12-01', ate: '2025-12-31' });
    expect(mesAnterior(hoje)).toEqual({ de: '2026-09-01', ate: '2026-09-30' });
  });

  it('últimos N dias inclui hoje', () => {
    expect(ultimosDias(90, hoje)).toEqual({ de: '2026-07-23', ate: '2026-10-20' });
  });

  it('mês do período só existe quando cobre um mês inteiro', () => {
    expect(mesDoPeriodo({ de: '2026-10-01', ate: '2026-10-31' })).toBe('2026-10');
    expect(mesDoPeriodo({ de: '2026-10-02', ate: '2026-10-31' })).toBeNull();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run features/crm/periodos.test.ts`
Expected: FAIL (módulo `./periodos` não existe).

- [ ] **Step 3: Write minimal implementation**

`periodos.ts`:

```ts
export interface Periodo {
  de: string;
  ate: string;
}

const dois = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${dois(d.getMonth() + 1)}-${dois(d.getDate())}`;

/** Datas de calendário no relógio do navegador; o backend interpreta no fuso do negócio. */
export function mesAtual(hoje: Date = new Date()): Periodo {
  return {
    de: iso(new Date(hoje.getFullYear(), hoje.getMonth(), 1)),
    ate: iso(new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0)),
  };
}

export function mesAnterior(hoje: Date = new Date()): Periodo {
  return {
    de: iso(new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1)),
    ate: iso(new Date(hoje.getFullYear(), hoje.getMonth(), 0)),
  };
}

export function ultimosDias(n: number, hoje: Date = new Date()): Periodo {
  return { de: iso(new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - (n - 1))), ate: iso(hoje) };
}

/** `AAAA-MM` quando o período é exatamente um mês (a meta só vale nesse caso); senão `null`. */
export function mesDoPeriodo(p: Periodo): string | null {
  const [ano, mes, dia] = p.de.split('-').map(Number);
  if (dia !== 1) return null;
  const ultimo = iso(new Date(ano, mes, 0));
  return p.ate === ultimo ? `${ano}-${dois(mes)}` : null;
}
```

Em `types.ts`, substituir `ResumoDoCrm` e acrescentar:

```ts
export type UnidadeDaMeta = 'VALOR' | 'QUANTIDADE';

export interface ProgressoDeMeta {
  unidade: UnidadeDaMeta;
  alvo: number;
  realizado: number;
  percentual: number;
}

export interface TotalDeNegocios {
  quantidade: number;
  valor_centavos: number;
}

export interface EtapaDoFunil {
  id: string;
  nome: string;
  probabilidade: number;
  quantidade: number;
  valor_centavos: number;
}

export interface FunilDoRelatorio {
  id: string;
  nome: string;
  etapas: EtapaDoFunil[];
}

export interface PrevisaoDoPeriodo extends TotalDeNegocios {
  ponderada_centavos: number;
}

export interface MotivoDoRelatorio extends TotalDeNegocios {
  motivo_id: string | null;
  nome: string;
}

export interface VendedorDoRelatorio {
  usuario_id: string;
  nome: string;
  abertos: TotalDeNegocios;
  ganhos: TotalDeNegocios;
  perdidos: TotalDeNegocios;
  meta: ProgressoDeMeta | null;
}

export interface PropostasDoRelatorio {
  enviadas: number;
  aceitas: number;
  recusadas: number;
  valor_aceito_centavos: number;
  taxa_de_aceite: number | null;
}

export interface RelatorioComercial {
  periodo: { de: string; ate: string };
  resumo: { abertos: TotalDeNegocios; ganhos: TotalDeNegocios; perdidos: TotalDeNegocios; taxa_de_conversao: number | null };
  funil: FunilDoRelatorio | null;
  previsao: { no_periodo: PrevisaoDoPeriodo; sem_previsao: TotalDeNegocios };
  motivos_de_perda: MotivoDoRelatorio[];
  vendedores: VendedorDoRelatorio[] | null;
  propostas: PropostasDoRelatorio;
  faturado_centavos: number | null;
}

export interface MetaDoVendedor {
  id: string;
  usuario_id: string;
  usuario_nome: string | null;
  mes: string;
  unidade: UnidadeDaMeta;
  alvo: number;
}

export interface ResumoDoCrm {
  hoje: number;
  atrasadas: number;
  parados: number;
  funil: FunilDoRelatorio | null;
  previsao_do_mes: PrevisaoDoPeriodo;
  meta_do_mes: ProgressoDeMeta | null;
}
```

Em `api.ts`: importar `MetaDoVendedor`, `RelatorioComercial`, `UnidadeDaMeta` e acrescentar no `crmApi`:

```ts
  relatorio: (p: { de: string; ate: string; vendedor_id?: string; funil_id?: string }) => {
    const q = new URLSearchParams({ de: p.de, ate: p.ate });
    if (p.vendedor_id) q.set('vendedor_id', p.vendedor_id);
    if (p.funil_id) q.set('funil_id', p.funil_id);
    return api<{ data: RelatorioComercial }>(`${BASE}/relatorios?${q.toString()}`);
  },
  metas: (mes: string) => api<{ data: MetaDoVendedor[] }>(`${BASE}/metas?mes=${mes}`),
  definirMeta: (dados: { usuario_id: string; mes: string; unidade: UnidadeDaMeta; alvo: number }) =>
    api<{ data: MetaDoVendedor }>(`${BASE}/metas`, { method: 'PUT', body: json(dados) }),
  removerMeta: (id: string) => api<void>(`${BASE}/metas/${id}`, { method: 'DELETE' }),
```

`hooks/useRelatorio.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { Periodo } from '../periodos';
import type { RelatorioComercial } from '../types';

export function useRelatorio(periodo: Periodo, vendedorId?: string, funilId?: string) {
  return useQuery<RelatorioComercial>({
    queryKey: [...QUERY_KEY_CRM, 'relatorio', periodo.de, periodo.ate, vendedorId ?? '', funilId ?? ''],
    queryFn: async () => (await crmApi.relatorio({ ...periodo, vendedor_id: vendedorId, funil_id: funilId })).data,
  });
}
```

`hooks/useMetas.ts`:

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { MetaDoVendedor, UnidadeDaMeta } from '../types';

const mensagem = (erro: unknown, padrao: string) => (erro instanceof ApiError ? erro.message : padrao);

export function useMetas(mes: string | null) {
  return useQuery<MetaDoVendedor[]>({
    queryKey: [...QUERY_KEY_CRM, 'metas', mes ?? ''],
    queryFn: async () => (await crmApi.metas(mes as string)).data,
    enabled: mes !== null,
  });
}

/** Meta muda o progresso nos relatórios e no painel: invalida o CRM inteiro. */
function useInvalidarCrm() {
  const client = useQueryClient();
  return () => client.invalidateQueries({ queryKey: QUERY_KEY_CRM });
}

export function useDefinirMeta() {
  const invalidar = useInvalidarCrm();
  return useMutation({
    mutationFn: (dados: { usuario_id: string; mes: string; unidade: UnidadeDaMeta; alvo: number }) => crmApi.definirMeta(dados),
    onSuccess: () => {
      toast.success('Meta salva.');
      return invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível salvar a meta.')),
  });
}

export function useRemoverMeta() {
  const invalidar = useInvalidarCrm();
  return useMutation({
    mutationFn: (id: string) => crmApi.removerMeta(id),
    onSuccess: () => {
      toast.success('Meta removida.');
      return invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível remover a meta.')),
  });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run features/crm/periodos.test.ts && npx tsc --noEmit`
Expected: 4 testes PASS; `tsc` sem erro. Se `tsc` acusar `BlocoCrmNoPainel.test.tsx` por faltar `funil`/`previsao_do_mes`/`meta_do_mes` no mock de `resumo`, corrigir o mock na Task 9 (o teste do bloco é reescrito lá); até lá, acrescentar `funil: null, previsao_do_mes: { quantidade: 0, valor_centavos: 0, ponderada_centavos: 0 }, meta_do_mes: null` ao mock existente para manter o `tsc` limpo.

- [ ] **Step 5: Commit**

```bash
cd /c/Users/dougl/workspace/crm
git add frontend/features/crm/types.ts frontend/features/crm/api.ts frontend/features/crm/periodos.ts frontend/features/crm/periodos.test.ts frontend/features/crm/hooks/useRelatorio.ts frontend/features/crm/hooks/useMetas.ts frontend/features/crm/components/BlocoCrmNoPainel.test.tsx
git commit -m "feat(crm): tipos, API e hooks de relatórios e metas no frontend

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 8: Frontend: página `/crm/relatorios`

**Files:**
- Create: `frontend/features/crm/components/RelatoriosDoCrm.tsx`
- Create: `frontend/features/crm/components/FiltroDePeriodo.tsx`
- Create: `frontend/features/crm/components/VisaoGeralDoRelatorio.tsx`
- Create: `frontend/features/crm/components/TabelaDeVendedores.tsx`
- Create: `frontend/features/crm/components/PerdasDoPeriodo.tsx`
- Create: `frontend/features/crm/components/MetasDoMes.tsx`
- Create: `frontend/app/(app)/crm/relatorios/page.tsx`
- Modify: `frontend/components/layout/nav-items.ts`
- Test: `frontend/features/crm/components/RelatoriosDoCrm.test.tsx`

**Interfaces:**
- Consumes: `useRelatorio`, `useMetas`, `useDefinirMeta`, `useRemoverMeta`, `periodos.ts`, `usuariosApi.listar`, `useUsuario` (`@/features/auth/hooks/useUsuario`), `formatarCentavos`, `StatCard`, `Card*`, `Button`, `Input`, `Label`, `SelectNativo`, `Banner`.
- Produces: componente `RelatoriosDoCrm` (sem props); rota `/crm/relatorios`; item de menu "Relatórios".

- [ ] **Step 1: Write the failing test**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { RelatorioComercial } from '../types';
import { RelatoriosDoCrm } from './RelatoriosDoCrm';

const papel = vi.hoisted(() => ({ atual: 'ADMIN' }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel: papel.atual } }) }));
vi.mock('@/features/usuarios/api', () => ({
  QUERY_KEY_USUARIOS: ['usuarios'],
  usuariosApi: { listar: vi.fn().mockResolvedValue({ data: [{ id: 'u1', nome: 'Ana', email: 'a@x.com', papel: 'VENDEDOR', ativo: true }] }) },
}));
vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return { ...real, crmApi: { ...real.crmApi, relatorio: vi.fn(), metas: vi.fn(), definirMeta: vi.fn(), removerMeta: vi.fn() } };
});

const total = (quantidade: number, valor_centavos: number) => ({ quantidade, valor_centavos });

function relatorio(extra: Partial<RelatorioComercial> = {}): RelatorioComercial {
  return {
    periodo: { de: '2026-10-01', ate: '2026-10-31' },
    resumo: { abertos: total(4, 40000), ganhos: total(2, 15000), perdidos: total(1, 7000), taxa_de_conversao: 0.6667 },
    funil: { id: 'f1', nome: 'Vendas', etapas: [{ id: 'e1', nome: 'Proposta', probabilidade: 40, quantidade: 3, valor_centavos: 30000 }] },
    previsao: { no_periodo: { quantidade: 2, valor_centavos: 20000, ponderada_centavos: 8000 }, sem_previsao: total(1, 500) },
    motivos_de_perda: [{ motivo_id: 'm1', nome: 'Preço', quantidade: 1, valor_centavos: 7000 }],
    vendedores: [{
      usuario_id: 'u1', nome: 'Ana', abertos: total(1, 1000), ganhos: total(2, 15000), perdidos: total(0, 0),
      meta: { unidade: 'VALOR', alvo: 30000, realizado: 15000, percentual: 50 },
    }],
    propostas: { enviadas: 5, aceitas: 2, recusadas: 1, valor_aceito_centavos: 15000, taxa_de_aceite: 0.6667 },
    faturado_centavos: 9000,
    ...extra,
  };
}

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><RelatoriosDoCrm /></QueryClientProvider>);
}

describe('RelatoriosDoCrm', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    papel.atual = 'ADMIN';
    vi.mocked(crmApi.metas).mockResolvedValue({ data: [] });
  });

  it('visão geral mostra ganhos, conversão, previsão e faturado', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio() });
    renderizar();

    expect(await screen.findByText(/66,7\s?%/)).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*150,00/)).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*80,00/)).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*90,00/)).toBeInTheDocument();
    expect(screen.getByText('Proposta')).toBeInTheDocument();
  });

  it('conversão sem negócio fechado aparece como traço, não 0%', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({
      data: relatorio({ resumo: { abertos: total(0, 0), ganhos: total(0, 0), perdidos: total(0, 0), taxa_de_conversao: null } }),
    });
    renderizar();

    expect(await screen.findByText('—')).toBeInTheDocument();
    expect(screen.queryByText(/0,0\s?%/)).not.toBeInTheDocument();
  });

  it('faturado nulo (plano sem Fiscal) não mostra o cartão', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio({ faturado_centavos: null }) });
    renderizar();

    await screen.findByText('Proposta');
    expect(screen.queryByText(/faturado/i)).not.toBeInTheDocument();
  });

  it('aba Vendedores mostra o progresso da meta', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio() });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Vendedores' }));

    expect(screen.getByText('Ana')).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: /meta de ana/i })).toHaveAttribute('aria-valuenow', '50');
  });

  it('vendedor restrito não vê a aba Vendedores', async () => {
    papel.atual = 'VENDEDOR';
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio({ vendedores: null }) });
    renderizar();

    await screen.findByText('Proposta');
    expect(screen.queryByRole('button', { name: 'Vendedores' })).not.toBeInTheDocument();
  });

  it('aba Perdas lista os motivos', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio() });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Perdas' }));

    expect(screen.getByText('Preço')).toBeInTheDocument();
  });

  it('admin define a meta de um vendedor no mês inteiro', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio() });
    vi.mocked(crmApi.definirMeta).mockResolvedValue({
      data: { id: 'm1', usuario_id: 'u1', usuario_nome: 'Ana', mes: '2026-10', unidade: 'VALOR', alvo: 500000 },
    });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Metas' }));
    await userEvent.selectOptions(await screen.findByLabelText(/vendedor/i), 'u1');
    await userEvent.type(screen.getByLabelText(/alvo/i), '5000');
    await userEvent.click(screen.getByRole('button', { name: /salvar meta/i }));

    expect(crmApi.definirMeta).toHaveBeenCalledWith(expect.objectContaining({ usuario_id: 'u1', unidade: 'VALOR', alvo: 500000 }));
  });

  it('erro da API mostra mensagem na página', async () => {
    vi.mocked(crmApi.relatorio).mockRejectedValue(new Error('falhou'));
    renderizar();

    expect(await screen.findByText(/não foi possível carregar o relatório/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run features/crm/components/RelatoriosDoCrm.test.tsx`
Expected: FAIL (módulo `./RelatoriosDoCrm` não existe).

- [ ] **Step 3: Write minimal implementation**

Antes de escrever, ler `frontend/app/(app)/crm/negocios/page.tsx` e `frontend/features/crm/components/AgendaDoCrm.tsx` (padrão de página, `Card`, estados de carga) e `frontend/components/ui/campos.test.tsx` para as props de `SelectNativo`/`Input`; seguir o mesmo estilo (tokens do design system, `font-display` no título, `sr-only` nas tabelas dos gráficos).

Regras de formatação (um helper local `percentual(taxa: number | null) => taxa === null ? '—' : \`${(taxa * 100).toFixed(1).replace('.', ',')} %\``; `formatarCentavos` para dinheiro; `—` para `null`; zero exibido como `0`/`R$ 0,00`).

Estrutura:

- `RelatoriosDoCrm.tsx` (`'use client'`): estado `periodo` (padrão `mesAtual()`), `vendedorId`, `aba` (`'geral' | 'vendedores' | 'perdas' | 'metas'`). Usa `useUsuario()` para o papel: `podeConfigurar = ['PROPRIETARIO','ADMIN'].includes(papel)`. Chama `useRelatorio(periodo, vendedorId || undefined)`. Estados: carregando (`<p>Carregando...</p>`), erro (`<Banner tom="perigo">Não foi possível carregar o relatório.</Banner>`). Cabeçalho `<h1 className="font-display ...">Relatórios do CRM</h1>`, `FiltroDePeriodo`, filtro de vendedor (`SelectNativo` com `usuariosApi.listar` via `useQuery`, só para `podeConfigurar`), e os botões segmentados de aba: **Visão geral**, **Vendedores** (só quando `data.vendedores !== null`), **Perdas**, **Metas** (só `podeConfigurar`). Botões com `aria-pressed`.
- `FiltroDePeriodo.tsx`: botões "Este mês", "Mês anterior", "Últimos 90 dias" e dois `<input type="date">` rotulados "De" e "Até" (`Label` + `Input`) que chamam `onChange({de, ate})`.
- `VisaoGeralDoRelatorio.tsx`: grade de `StatCard`: Ganhos (quantidade + valor), Perdidos, Abertos, Conversão (`percentual(taxa)`), Previsão ponderada (`no_periodo.ponderada_centavos`; legenda com `sem_previsao.quantidade` "sem data de previsão"), Propostas aceitas (valor + taxa de aceite) e **Faturado** (cartão só quando `faturado_centavos !== null`). Abaixo, o funil: Recharts `BarChart` horizontal com `valor_centavos` por etapa (mesmo padrão de `GraficoDeFaturamento`: gráfico `aria-hidden` + `<table className="sr-only">` com etapa, quantidade e valor). Sem `funil`: texto "Nenhum funil configurado.". Nota fixa sob o funil: "O funil mostra a situação de agora; o período vale para ganhos, perdas, previsão e propostas."
- `TabelaDeVendedores.tsx`: no desktop uma `<table>`; no celular cartões (`md:hidden` / `hidden md:table`). Cada vendedor: nome, ganhos (quantidade e valor), perdidos, abertos e, quando `meta !== null`, uma barra `role="progressbar"` com `aria-label={\`Meta de ${nome}\`}`, `aria-valuenow={percentual}`, `aria-valuemin={0}`, `aria-valuemax={100}` (largura limitada a 100%; o texto mostra o percentual real, inclusive acima de 100) e o texto `realizado / alvo` (dinheiro ou quantidade conforme `unidade`). Sem meta: "Sem meta no mês." (ou, quando o período não é um mês inteiro, "Meta só aparece para mês inteiro."). Não reutilizar `BarraDeProgresso` (suas cores significam consumo, não meta).
- `PerdasDoPeriodo.tsx`: lista de motivos com quantidade e valor; vazio: "Nenhum negócio perdido no período.".
- `MetasDoMes.tsx`: recebe `mes: string | null`. Quando `mes === null`: `<Banner tom="info">Escolha um mês inteiro no filtro para definir metas.</Banner>`. Senão, lista `useMetas(mes)` (nome, unidade, alvo, botão "Remover" com `useRemoverMeta`) e formulário: `Label` "Vendedor" + `SelectNativo` (usuários ativos), `Label` "Unidade" + `SelectNativo` (`VALOR` "Valor (R$)", `QUANTIDADE` "Quantidade"), `Label` "Alvo" + `Input`, botão "Salvar meta". O alvo em `VALOR` passa por `reaisParaCentavos` (de `@/lib/formatos`); em `QUANTIDADE` é inteiro. Alvo vazio ou zero: botão desabilitado.

`app/(app)/crm/relatorios/page.tsx`:

```tsx
import { RelatoriosDoCrm } from '@/features/crm/components/RelatoriosDoCrm';

export default function RelatoriosPage() {
  return <RelatoriosDoCrm />;
}
```

`nav-items.ts`: após o item "Agenda", acrescentar (ícone `BarChart3` de `lucide-react`, importar junto dos demais):

```ts
  { href: '/crm/relatorios', label: 'Relatórios', icon: BarChart3, papeis: ['PROPRIETARIO', 'ADMIN', 'VENDEDOR', 'LEITURA'], modulo: 'CRM' },
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
cd /c/Users/dougl/workspace/crm/frontend
npx vitest run features/crm/components/RelatoriosDoCrm.test.tsx components/layout
npx tsc --noEmit
npx eslint features/crm "app/(app)/crm" components/layout
```
Expected: PASS; sem erro de tipo nem de lint. Há teste de `nav-items`/sidebar que conta itens? Se `components/layout` acusar contagem, atualizar a expectativa para incluir "Relatórios".

- [ ] **Step 5: Commit**

```bash
cd /c/Users/dougl/workspace/crm
git add frontend/features/crm/components/RelatoriosDoCrm.tsx frontend/features/crm/components/RelatoriosDoCrm.test.tsx frontend/features/crm/components/FiltroDePeriodo.tsx frontend/features/crm/components/VisaoGeralDoRelatorio.tsx frontend/features/crm/components/TabelaDeVendedores.tsx frontend/features/crm/components/PerdasDoPeriodo.tsx frontend/features/crm/components/MetasDoMes.tsx "frontend/app/(app)/crm/relatorios/page.tsx" frontend/components/layout/nav-items.ts
git commit -m "feat(crm): página de relatórios com visão geral, vendedores, perdas e metas

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 9: Frontend: bloco do CRM no painel

**Files:**
- Modify: `frontend/features/crm/components/BlocoCrmNoPainel.tsx`
- Modify: `frontend/features/crm/components/BlocoCrmNoPainel.test.tsx`

**Interfaces:**
- Consumes: `useResumoDoCrm` (agora com `funil`, `previsao_do_mes`, `meta_do_mes`), `formatarCentavos`, `StatCard`.

- [ ] **Step 1: Write the failing test** (acrescentar ao arquivo existente; ajustar o mock existente para o novo formato)

Trocar o mock `{ hoje: 2, atrasadas: 1, parados: 3 }` por um helper `resumo(extra)` com `funil: null, previsao_do_mes: { quantidade: 0, valor_centavos: 0, ponderada_centavos: 0 }, meta_do_mes: null`, e acrescentar:

```tsx
  it('mostra previsão do mês e progresso da meta com link para os relatórios', async () => {
    vi.mocked(crmApi.resumo).mockResolvedValue({
      data: resumo({
        previsao_do_mes: { quantidade: 2, valor_centavos: 20000, ponderada_centavos: 8000 },
        meta_do_mes: { unidade: 'VALOR', alvo: 30000, realizado: 15000, percentual: 50 },
      }),
    });
    renderizar();

    expect(await screen.findByRole('link', { name: /previsão do mês/i })).toHaveAttribute('href', '/crm/relatorios');
    expect(screen.getByRole('link', { name: /previsão do mês/i })).toHaveTextContent(/R\$\s*80,00/);
    expect(screen.getByRole('link', { name: /meta do mês/i })).toHaveTextContent('50');
  });

  it('sem meta definida não mostra o cartão de meta', async () => {
    vi.mocked(crmApi.resumo).mockResolvedValue({ data: resumo({}) });
    renderizar();

    await screen.findByRole('link', { name: /hoje/i });
    expect(screen.queryByRole('link', { name: /meta do mês/i })).not.toBeInTheDocument();
  });
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run features/crm/components/BlocoCrmNoPainel.test.tsx`
Expected: FAIL (cartões novos ausentes).

- [ ] **Step 3: Write minimal implementation**

Em `BlocoCrmNoPainel.tsx`, importar `formatarCentavos` e, dentro da `grid`, depois do cartão "Parados":

```tsx
        <StatCard
          titulo="Previsão do mês"
          valor={formatarCentavos(data.previsao_do_mes.ponderada_centavos)}
          legenda={`${data.previsao_do_mes.quantidade} negócios com fechamento previsto`}
          href="/crm/relatorios"
        />
        {data.meta_do_mes ? (
          <StatCard
            titulo="Meta do mês"
            valor={`${data.meta_do_mes.percentual}%`}
            legenda={
              data.meta_do_mes.unidade === 'VALOR'
                ? `${formatarCentavos(data.meta_do_mes.realizado)} de ${formatarCentavos(data.meta_do_mes.alvo)}`
                : `${data.meta_do_mes.realizado} de ${data.meta_do_mes.alvo} negócios`
            }
            tom={data.meta_do_mes.percentual >= 100 ? 'sucesso' : 'primario'}
            href="/crm/relatorios"
          />
        ) : null}
```

Trocar `sm:grid-cols-3` por `sm:grid-cols-2 lg:grid-cols-3` para a grade acomodar até cinco cartões.

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
cd /c/Users/dougl/workspace/crm/frontend
npx vitest run features/crm features/dashboard-fiscal
npx tsc --noEmit
```
Expected: PASS (inclui `DashboardPage.test.tsx`, que monta o bloco; corrigir seu mock de `resumo` se falhar por campo ausente).

- [ ] **Step 5: Commit**

```bash
cd /c/Users/dougl/workspace/crm
git add frontend/features/crm/components/BlocoCrmNoPainel.tsx frontend/features/crm/components/BlocoCrmNoPainel.test.tsx frontend/features/dashboard-fiscal/components/DashboardPage.test.tsx
git commit -m "feat(crm): previsão e meta do mês no bloco do painel

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 10: Verificação, documentação e fechamento

**Files:**
- Modify: `PROGRESSO.md`, `TAREFAS.md`
- Modify (se houver): `docs/` da API interna do CRM, caso exista lista de rotas autenticadas (`grep -rn "crm/resumo" docs | head`).

- [ ] **Step 1: Portões completos**

```bash
cd /c/Users/dougl/workspace/crm/backend
vendor/bin/pint --test 2>&1 | tail -5
vendor/bin/phpstan analyse --memory-limit=1G 2>&1 | tail -8
php artisan test 2>&1 | tail -8
cd ../frontend
npx tsc --noEmit
npx eslint features app components 2>&1 | tail -10
npx vitest run 2>&1 | tail -8
npx next build 2>&1 | tail -15
```

Expected: tudo limpo; backend com 1476 + novos testes; frontend com 425 + novos. Registrar os números reais.

- [ ] **Step 2: Fumaça (SQLite descartável, `curl`)**

Subir o backend com banco descartável (padrão do "Fumaça com curl" em `PROGRESSO.md`), criar empresa, funil, dois negócios (um ganho e um perdido com motivo), definir meta via `PUT /api/app/crm/metas` e conferir `GET /api/app/crm/relatorios` e `GET /api/app/crm/resumo`. Conferir `taxa_de_conversao`, `vendedores[].meta.percentual`, `faturado_centavos` e o 422 de período invertido. Anotar o resultado; não repetir se falhar sem entender.

- [ ] **Step 3: Atualizar `PROGRESSO.md` e `TAREFAS.md`**

- `TAREFAS.md`: marcar F7d como concluída (`[x] **F7d Relatórios e metas** (10 de 10, concluída em 2026-10-13): ...`) e a F7 como concluída; adicionar "Pendências da F7d" com: histórico de etapas (conversão etapa a etapa e tempo por etapa), CSV do CRM, meta de atividades, exportação dos relatórios, cache dos agregados, conferência no navegador de `/crm/relatorios` e do bloco do painel, concorrência não testada em PostgreSQL.
- `PROGRESSO.md`: seção "Fechamento da F7d" (commits, rulings do plano, números de testes, o que não foi verificado) e reescrever "Próxima tarefa" para a **F8 Go-live** (auditoria fiscal, carga, backup/restauração, piloto), com a "Contexto necessário" só do que a F8 usa (spec mestre, `docs/referencia-fiscal/00-INDICE.md`, TAREFAS F8).

- [ ] **Step 4: Commit e recomendação**

```bash
cd /c/Users/dougl/workspace/crm
git add PROGRESSO.md TAREFAS.md
git commit -m "docs: F7d concluída (relatórios, metas e dashboard do CRM)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

Recomendar `/compact` e perguntar (múltipla escolha) se o usuário quer o push (`git push origin master` só com confirmação).

---

## Self-review (feita ao escrever)

- **Cobertura da spec:** §4 modelo → T1; §5.1 Actions de meta → T2, relatório → T3–T5, `ResumoDoCrm` → T5; §5.2 contrato → T4; §5.3 regras (SQL agregado, fuso, zero/null, visibilidade, plano, 422) → T3–T5; §5.4 API → T2 e T5; §6 frontend → T7–T9; §7 testes → distribuídos; §8 riscos → T10.
- **Placeholders:** nenhum; passos de frontend descrevem estrutura e dão código só onde a forma é não óbvia, porque o padrão visual vem dos componentes existentes que a tarefa manda ler.
- **Consistência de tipos:** `PeriodoDoRelatorio` (T3) é consumido por T4/T5; `RelatorioDeNegocios::negocios` (T3) por `RelatorioDePropostas` (T4); `faturado_centavos` (ruling 1) coincide em T5 e T7; `taxa_*` é fração 0–1 em backend e frontend; `percentual` é inteiro.
