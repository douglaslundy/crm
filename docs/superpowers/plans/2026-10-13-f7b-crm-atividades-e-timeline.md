# F7b: CRM, atividades, agenda, timeline e negócio parado: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** o vendedor agenda e registra contatos, vê a agenda, a timeline do cliente e os negócios parados, e contata por WhatsApp e e-mail em 1 clique.

**Architecture:** tabelas `crm_atividades` e `crm_timeline` no módulo `Crm`. Listeners síncronos dos eventos `Negocio*` da F7a e Actions gravam a timeline. "Parado" é calculado na leitura (`ConsultorDeParados`), sem job. Frontend e API chamam as mesmas Actions.

**Tech Stack:** Laravel 12 + PHPUnit, SQLite nos testes (PostgreSQL em staging), Next.js (App Router) + TanStack Query + RHF/Zod, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-13-crm-f7b-atividades-e-timeline-design.md` (ler antes de qualquer tarefa).

## Global Constraints

- `declare(strict_types=1)` em todo PHP. TypeScript strict, sem `any`.
- Todo Model de tenant usa `BelongsToTenant` (Global Scope). Todo endpoint novo ganha teste de isolamento de tenant.
- Mudança de schema só por migration. Nenhum dado real em fixtures.
- Nunca chutar valor: dado ausente bloqueia com mensagem acionável (telefone e e-mail do contato). Zero é válido: usar `=== null`, nunca `empty()` nem `?? 0`.
- `Crm` só fala com Customers e Identity pelos contratos `ConsultaDeClientes` e `DiretorioDeUsuarios` (`Domain/Contracts`). Não importar Model de cliente.
- Fora da visibilidade do usuário a resposta é 404, nunca 403.
- Fuso do negócio: `config('app.fuso_negocio')` (`America/Sao_Paulo`). O banco guarda UTC e o Eloquent não converte.
- IDs em rota com `whereUuid`; filtros de id validados como `uuid` (PostgreSQL daria 500).
- Testes de HTTP: `$this->spaComo($usuario)->getJson(...)`; fixture `Tests\Support\ComCrm` (`prepararCrm()`, `usuarioDoPapel()`, `$this->admin`, `$this->tenant`).
- Arquivos voltam do checkout com CRLF: editar com Edit/Write, não com regex de `\n`. O shell come barras invertidas: usar Write/Edit para PHP.
- `status()` é reservado do PHPUnit; esquecer `use` em teste é falha comum.
- Next.js deste repo tem breaking changes: antes de criar página, ler o guia relevante em `frontend/node_modules/next/dist/docs/` e copiar o padrão de `frontend/app/(app)/crm/negocios/[id]/page.tsx`.
- Comandos de verificação (backend, em `backend/`): `php artisan test --filter=<Classe>`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`. Frontend (em `frontend/`): `npx vitest run <arquivo>`, `npx tsc --noEmit`, `npx eslint .`, `npm run build`.
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u`.

## Esclarecimentos do spec (mudanças em relação ao texto aprovado)

1. **`timeline_registros.atividade_id` (nulável):** necessário para "Reabrir remove o registro ATIVIDADE_CONCLUIDA correspondente". O nome físico da tabela é `crm_timeline` (prefixo `crm_` como as demais).
2. **Parado = `dias_parado >= dias_para_parado`** (o spec §5 diz "anterior a hoje − X dias", que deixaria o dia X fora; "sem atividade há X dias" pede `>=`). Task 1 corrige o texto do spec.
3. **Sem página de detalhe do cliente hoje:** a timeline do cliente vive em `/crm/clientes/[id]` (nova), ligada a partir do detalhe do negócio e do cartão do kanban.

## Review Focus

- Telefone com máscara, com `+55`, só com 8 dígitos ou vazio no contato em 1 clique: normaliza ou bloqueia 422; nunca monta link com número chutado (Task 4).
- Clique duplicado em WhatsApp/E-mail: cada clique cria uma atividade; a resposta é idempotente em estrutura, sem erro (Task 4).
- Negócio exatamente no limite de `dias_para_parado`, etapa sem alerta, negócio ganho/perdido, virada de dia às 21h no fuso SP (Task 5).
- Reabrir atividade concluída duas vezes e concluir duas vezes: sem erro, sem registro duplicado (Task 3).
- Vendedor restrito pedindo atividade, timeline ou negócio de colega: 404 (Tasks 3, 6).
- Atividade apontando para negócio de outro cliente ou de outro tenant: 422 `NEGOCIO_INVALIDO` (Task 3).
- Listener da timeline falhando (negócio apagado, etapa inexistente): ação do vendedor não cai (Task 2).

---

## File Structure

**Backend (criar):**
- `database/migrations/2026_10_16_000001_create_crm_atividades_e_timeline.php`
- `Crm/Domain/Enums/TipoDeAtividade.php`, `Crm/Domain/Enums/TipoDeRegistro.php`
- `Crm/Domain/Models/Atividade.php`, `Crm/Domain/Models/RegistroDaTimeline.php`
- `database/factories/AtividadeFactory.php`
- `Crm/Application/RegistradorDaTimeline.php`, `Crm/Application/GravarTimelineDoNegocio.php` (listener)
- `Crm/Application/ValidadorDeAtividade.php`, `Crm/Application/LinkDeContato.php`, `Crm/Application/ConsultorDeParados.php`, `Crm/Application/ApresentadorDeAtividades.php`
- Actions: `CriarAtividade`, `AtualizarAtividade`, `ConcluirAtividade`, `ReabrirAtividade`, `ExcluirAtividade`, `RegistrarContato`, `CriarNotaDoCliente`, `ListarAgenda`, `ListarTimelineDoCliente`, `ResumoDoCrm`
- `Crm/Http/Controllers/{AtividadesController,TimelineController,ContatosController}.php`
- `Crm/Http/Requests/{AtividadeRequest,ContatoRequest,NotaRequest}.php`

**Backend (modificar):** `Etapa.php`, `EtapaRequest.php`, `EtapaResource.php`, `AtualizarEtapa.php`, `Negocio.php`, `ApresentadorDeNegocios.php`, `NegociosController.php`, `CrmServiceProvider.php`, `routes-app.php`.

**Frontend (criar):** `features/crm/{atividades.ts (api/types), hooks/useAtividades.ts, hooks/useTimeline.ts, components/{AgendaDoCrm,FormularioDeAtividade,AtividadesDoNegocio,BotoesDeContato,TimelineDoCliente,SeloDeParado,BlocoCrmNoPainel}.tsx}`, `app/(app)/crm/agenda/page.tsx`, `app/(app)/crm/clientes/[id]/page.tsx`.

**Frontend (modificar):** `features/crm/types.ts`, `api.ts`, `CartaoDoNegocio.tsx`, `DetalheDoNegocio.tsx`, `EditorDeFunis.tsx` (campo dias), `components/layout/nav-items.ts`, `app/(app)/dashboard/page.tsx`.

(`Crm/` = `backend/app/Modules/Crm/`.)

---

### Task 1: Schema, enums, models, factory, `dias_para_parado` da etapa

**Files:**
- Create: `backend/database/migrations/2026_10_16_000001_create_crm_atividades_e_timeline.php`
- Create: `backend/app/Modules/Crm/Domain/Enums/TipoDeAtividade.php`, `TipoDeRegistro.php`
- Create: `backend/app/Modules/Crm/Domain/Models/Atividade.php`, `RegistroDaTimeline.php`
- Create: `backend/database/factories/AtividadeFactory.php`
- Modify: `backend/app/Modules/Crm/Domain/Models/{Etapa,Negocio}.php`, `Http/Requests/EtapaRequest.php`, `Http/Resources/EtapaResource.php`, `Application/Actions/AtualizarEtapa.php`
- Modify: `docs/superpowers/specs/2026-10-13-crm-f7b-atividades-e-timeline-design.md` (§3 `atividade_id`, §5 `>=`)
- Test: `backend/tests/Feature/Crm/AtividadesModelosTest.php`

**Interfaces:**
- Produces: `Atividade` (table `crm_atividades`; props `id, cliente_id, negocio_id, tipo: TipoDeAtividade, titulo, descricao, agendada_para: CarbonInterface, concluida_em: ?CarbonInterface, responsavel_id, criado_por`; `scopeVisiveisPara(Builder, Usuario)`; `estaConcluida(): bool`).
- Produces: `RegistroDaTimeline` (table `crm_timeline`, sem timestamps; `id, cliente_id, negocio_id, tipo: TipoDeRegistro, texto, dados: array, autor_id, atividade_id, ocorreu_em`; `scopeVisiveisPara(Builder, Usuario)`).
- Produces: `Negocio::restritoParaVendedor(Usuario): bool`, `Etapa->dias_para_parado: ?int`.
- Produces: `AtividadeFactory` com `concluida()`.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Crm/AtividadesModelosTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Enums\Papel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class AtividadesModelosTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
    }

    public function test_atividade_e_criada_aberta_com_tipo_e_responsavel(): void
    {
        $atividade = Atividade::factory()->create(['tipo' => TipoDeAtividade::Ligacao]);

        $this->assertSame(TipoDeAtividade::Ligacao, $atividade->refresh()->tipo);
        $this->assertFalse($atividade->estaConcluida());
        $this->assertSame($this->tenant->id, $atividade->tenant_id);
    }

    public function test_atividade_concluida_pela_factory(): void
    {
        $this->assertTrue(Atividade::factory()->concluida()->create()->estaConcluida());
    }

    public function test_vendedor_restrito_so_enxerga_as_suas_ou_de_negocios_dele(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id]);
        $negocioDoJoao = Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $joao->id]);

        $propria = Atividade::factory()->create(['responsavel_id' => $joao->id]);
        $doNegocio = Atividade::factory()->create(['responsavel_id' => $maria->id, 'negocio_id' => $negocioDoJoao->id, 'cliente_id' => $negocioDoJoao->cliente_id]);
        $alheia = Atividade::factory()->create(['responsavel_id' => $maria->id]);

        $ids = Atividade::query()->visiveisPara($joao)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$propria->id, $doNegocio->id], $ids);
        $this->assertNotContains($alheia->id, $ids);
        $this->assertCount(3, Atividade::query()->visiveisPara($this->admin)->get());
    }

    public function test_timeline_do_vendedor_restrito_traz_negocios_dele_e_o_que_ele_escreveu(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id]);
        $dele = Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $joao->id]);
        $dela = Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $maria->id, 'cliente_id' => $dele->cliente_id]);

        $base = ['cliente_id' => $dele->cliente_id, 'tipo' => TipoDeRegistro::NegocioCriado, 'texto' => 'x', 'ocorreu_em' => now()];
        $r1 = RegistroDaTimeline::create($base + ['negocio_id' => $dele->id]);
        RegistroDaTimeline::create($base + ['negocio_id' => $dela->id]);
        $nota = RegistroDaTimeline::create(['tipo' => TipoDeRegistro::Nota, 'autor_id' => $joao->id] + $base);
        RegistroDaTimeline::create(['tipo' => TipoDeRegistro::Nota, 'autor_id' => $maria->id] + $base);

        $ids = RegistroDaTimeline::query()->visiveisPara($joao)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$r1->id, $nota->id], $ids);
        $this->assertCount(4, RegistroDaTimeline::query()->visiveisPara($this->admin)->get());
    }

    public function test_etapa_guarda_dias_para_parado_nulavel(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'dias_para_parado' => 7]);

        $this->assertSame(7, $etapa->refresh()->dias_para_parado);
        $this->assertNull(Etapa::factory()->create(['funil_id' => $funil->id])->refresh()->dias_para_parado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (em `backend/`): `php artisan test --filter=AtividadesModelosTest`
Expected: FAIL (`Class "App\Modules\Crm\Domain\Models\Atividade" not found`).

- [ ] **Step 3: Write minimal implementation**

`backend/database/migrations/2026_10_16_000001_create_crm_atividades_e_timeline.php`:

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
        Schema::create('crm_atividades', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Sem chave estrangeira para cliente e usuário: pertencem a outros módulos.
            $table->uuid('cliente_id');
            $table->foreignUuid('negocio_id')->nullable()->constrained('crm_negocios');
            $table->string('tipo', 12);
            $table->string('titulo', 150);
            $table->text('descricao')->nullable();
            $table->timestamp('agendada_para');
            $table->timestamp('concluida_em')->nullable();
            $table->uuid('responsavel_id');
            $table->uuid('criado_por');
            $table->timestamps();
            $table->index(['tenant_id', 'responsavel_id', 'concluida_em', 'agendada_para'], 'crm_atividades_agenda');
            $table->index(['tenant_id', 'cliente_id']);
            $table->index(['tenant_id', 'negocio_id']);
        });

        Schema::create('crm_timeline', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->uuid('cliente_id');
            $table->foreignUuid('negocio_id')->nullable()->constrained('crm_negocios');
            $table->uuid('atividade_id')->nullable();
            $table->string('tipo', 24);
            $table->text('texto');
            $table->json('dados')->nullable();
            $table->uuid('autor_id')->nullable();
            $table->timestamp('ocorreu_em');
            $table->index(['tenant_id', 'cliente_id', 'ocorreu_em', 'id'], 'crm_timeline_cliente');
            $table->index(['tenant_id', 'negocio_id', 'tipo']);
            $table->index(['tenant_id', 'atividade_id']);
        });

        Schema::table('crm_etapas', function (Blueprint $table): void {
            $table->unsignedSmallInteger('dias_para_parado')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_etapas', function (Blueprint $table): void {
            $table->dropColumn('dias_para_parado');
        });
        Schema::dropIfExists('crm_timeline');
        Schema::dropIfExists('crm_atividades');
    }
};
```

`backend/app/Modules/Crm/Domain/Enums/TipoDeAtividade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

enum TipoDeAtividade: string
{
    case Ligacao = 'LIGACAO';
    case Reuniao = 'REUNIAO';
    case Email = 'EMAIL';
    case Whatsapp = 'WHATSAPP';
    case Tarefa = 'TAREFA';
}
```

`backend/app/Modules/Crm/Domain/Enums/TipoDeRegistro.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

enum TipoDeRegistro: string
{
    case Nota = 'NOTA';
    case AtividadeConcluida = 'ATIVIDADE_CONCLUIDA';
    case NegocioCriado = 'NEGOCIO_CRIADO';
    case NegocioMovido = 'NEGOCIO_MOVIDO';
    case NegocioGanho = 'NEGOCIO_GANHO';
    case NegocioPerdido = 'NEGOCIO_PERDIDO';
}
```

Em `Negocio.php`, adicionar o método estático e usá-lo no escopo (substituir o corpo de `scopeVisiveisPara`):

```php
    /** Vendedor com o interruptor "vê só os seus" ligado enxerga só o que é dele (spec F7a §5). */
    public static function restritoParaVendedor(Usuario $usuario): bool
    {
        return $usuario->papel === Papel::Vendedor && ConfiguracaoDoCrm::vendedorVeSoOsSeus();
    }

    public function scopeVisiveisPara(Builder $query, Usuario $usuario): Builder
    {
        if (self::restritoParaVendedor($usuario)) {
            $query->where('responsavel_id', $usuario->id);
        }

        return $query;
    }
```

`backend/app/Modules/Crm/Domain/Models/Atividade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\AtividadeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $cliente_id
 * @property ?string $negocio_id
 * @property TipoDeAtividade $tipo
 * @property string $titulo
 * @property ?string $descricao
 * @property CarbonInterface $agendada_para
 * @property ?CarbonInterface $concluida_em
 * @property string $responsavel_id
 * @property string $criado_por
 */
class Atividade extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AtividadeFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'crm_atividades';

    protected $fillable = [
        'cliente_id', 'negocio_id', 'tipo', 'titulo', 'descricao', 'agendada_para', 'concluida_em',
        'responsavel_id', 'criado_por',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoDeAtividade::class,
            'agendada_para' => 'datetime',
            'concluida_em' => 'datetime',
        ];
    }

    public function estaConcluida(): bool
    {
        return $this->concluida_em !== null;
    }

    /**
     * Vendedor restrito vê as atividades em que é responsável e as de negócios que enxerga.
     *
     * @param  Builder<Atividade>  $query
     * @return Builder<Atividade>
     */
    public function scopeVisiveisPara(Builder $query, Usuario $usuario): Builder
    {
        if (! Negocio::restritoParaVendedor($usuario)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($usuario): void {
            $q->where('responsavel_id', $usuario->id)
                ->orWhereIn('negocio_id', Negocio::query()->visiveisPara($usuario)->select('id'));
        });
    }

    protected static function newFactory(): AtividadeFactory
    {
        return AtividadeFactory::new();
    }
}
```

`backend/app/Modules/Crm/Domain/Models/RegistroDaTimeline.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Models;

use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $cliente_id
 * @property ?string $negocio_id
 * @property ?string $atividade_id
 * @property TipoDeRegistro $tipo
 * @property string $texto
 * @property array<string, mixed> $dados
 * @property ?string $autor_id
 * @property CarbonInterface $ocorreu_em
 */
class RegistroDaTimeline extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $table = 'crm_timeline';

    protected $fillable = [
        'cliente_id', 'negocio_id', 'atividade_id', 'tipo', 'texto', 'dados', 'autor_id', 'ocorreu_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tipo' => TipoDeRegistro::class, 'dados' => 'array', 'ocorreu_em' => 'datetime'];
    }

    /**
     * Vendedor restrito vê registros de negócios que enxerga e o que ele mesmo escreveu.
     *
     * @param  Builder<RegistroDaTimeline>  $query
     * @return Builder<RegistroDaTimeline>
     */
    public function scopeVisiveisPara(Builder $query, Usuario $usuario): Builder
    {
        if (! Negocio::restritoParaVendedor($usuario)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($usuario): void {
            $q->whereIn('negocio_id', Negocio::query()->visiveisPara($usuario)->select('id'))
                ->orWhere('autor_id', $usuario->id);
        });
    }
}
```

`backend/database/factories/AtividadeFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Atividade> */
class AtividadeFactory extends Factory
{
    protected $model = Atividade::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cliente_id' => fn (): string => Cliente::factory()->create()->id,
            'tipo' => TipoDeAtividade::Tarefa,
            'titulo' => fake()->sentence(3),
            'agendada_para' => now()->addDay(),
            'responsavel_id' => fn (): string => Usuario::factory()
                ->create(['tenant_id' => app(TenantContext::class)->require()])->id,
            'criado_por' => fn (array $atributos): string => $atributos['responsavel_id'],
        ];
    }

    public function concluida(): static
    {
        return $this->state(fn (): array => ['concluida_em' => now()]);
    }
}
```

Em `Etapa.php`: acrescentar `@property ?int $dias_para_parado`, incluir `'dias_para_parado'` em `$fillable` e `'dias_para_parado' => 'integer'` em `casts()`. Em `EtapaFactory` nada muda (coluna nulável).

Em `EtapaRequest::rules()` acrescentar: `'dias_para_parado' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],`.
Em `EtapaResource::toArray()` acrescentar: `'dias_para_parado' => $this->dias_para_parado,`.
Em `AtualizarEtapa::executar()`: o docblock vira `array{nome?: string, probabilidade?: int, ativa?: bool, dias_para_parado?: ?int}` e o `array_intersect_key` passa a incluir `'dias_para_parado' => 1`.

Em `docs/superpowers/specs/2026-10-13-crm-f7b-atividades-e-timeline-design.md`: na lista de colunas de `timeline_registros` (§3) acrescentar "`atividade_id` (nulável; liga o registro `ATIVIDADE_CONCLUIDA` à atividade, para a reabertura)" e, no §5, trocar "é anterior a `hoje (SP) − dias_para_parado`" por "tem `dias_parado >= dias_para_parado`, com `dias_parado` contado em dias de calendário no fuso SP".

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AtividadesModelosTest` e depois `php artisan test --filter=Crm`
Expected: PASS (nenhum teste da F7a quebra).

- [ ] **Step 5: Commit**

```bash
git add backend/database backend/app/Modules/Crm backend/tests/Feature/Crm/AtividadesModelosTest.php docs/superpowers/specs/2026-10-13-crm-f7b-atividades-e-timeline-design.md
git commit -m "feat(crm): schema e modelos de atividades e timeline, dias para parado na etapa"
```

---

### Task 2: Registrador e listeners da timeline

**Files:**
- Create: `backend/app/Modules/Crm/Application/RegistradorDaTimeline.php`, `GravarTimelineDoNegocio.php`
- Modify: `backend/app/Modules/Crm/Providers/CrmServiceProvider.php`
- Test: `backend/tests/Feature/Crm/TimelineDoNegocioTest.php`

**Interfaces:**
- Consumes: `RegistroDaTimeline`, `TipoDeRegistro`, eventos `NegocioCriado(tenantId, negocioId, clienteId, autorId)`, `NegocioMovido(tenantId, negocioId, clienteId, autorId, etapaOrigemId, etapaDestinoId)`, `NegocioGanho(tenantId, negocioId, clienteId, autorId)`, `NegocioPerdido(tenantId, negocioId, clienteId, autorId, motivoId)`.
- Produces: `RegistradorDaTimeline::registrar(TipoDeRegistro $tipo, string $clienteId, ?string $negocioId, ?string $autorId, string $texto, array $dados = [], ?string $atividadeId = null, ?CarbonInterface $ocorreuEm = null): RegistroDaTimeline`.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Crm/TimelineDoNegocioTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\MoverNegocio;
use App\Modules\Crm\Application\Actions\PerderNegocio;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Events\NegocioCriado;
use App\Modules\Crm\Domain\Events\NegocioMovido;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class TimelineDoNegocioTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Negocio $negocio;

    private Etapa $origem;

    private Etapa $destino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => false]);
        $funil = Funil::factory()->padrao()->create();
        $this->origem = Etapa::factory()->create(['funil_id' => $funil->id, 'nome' => 'Contato', 'posicao' => 0]);
        $this->destino = Etapa::factory()->create(['funil_id' => $funil->id, 'nome' => 'Proposta', 'posicao' => 1]);
        $this->negocio = Negocio::factory()->create([
            'titulo' => 'Brindes', 'funil_id' => $funil->id, 'etapa_id' => $this->origem->id, 'responsavel_id' => $this->admin->id,
        ]);
    }

    public function test_negocio_criado_grava_registro(): void
    {
        event(new NegocioCriado($this->tenant->id, $this->negocio->id, $this->negocio->cliente_id, $this->admin->id));

        $r = RegistroDaTimeline::query()->sole();
        $this->assertSame(TipoDeRegistro::NegocioCriado, $r->tipo);
        $this->assertSame($this->negocio->cliente_id, $r->cliente_id);
        $this->assertSame($this->negocio->id, $r->negocio_id);
        $this->assertSame($this->admin->id, $r->autor_id);
        $this->assertStringContainsString('Brindes', $r->texto);
    }

    public function test_mover_pela_action_grava_etapas_de_e_para_por_nome(): void
    {
        app(MoverNegocio::class)->executar($this->admin, $this->negocio, $this->destino->id, 0);

        $r = RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::NegocioMovido)->sole();
        $this->assertSame('Contato', $r->dados['de']);
        $this->assertSame('Proposta', $r->dados['para']);
        $this->assertSame('Negócio movido de Contato para Proposta: Brindes', $r->texto);
    }

    public function test_perder_pela_action_grava_o_motivo(): void
    {
        $motivo = MotivoDePerda::factory()->create(['nome' => 'Preço']);

        app(PerderNegocio::class)->executar($this->admin, $this->negocio, $motivo->id, null);

        $r = RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::NegocioPerdido)->sole();
        $this->assertSame('Preço', $r->dados['motivo']);
    }

    public function test_falha_do_listener_nao_derruba_o_evento(): void
    {
        // Negócio inexistente: o listener não acha o título e não pode propagar exceção.
        event(new NegocioMovido($this->tenant->id, '00000000-0000-4000-8000-000000000000', $this->negocio->cliente_id, null, $this->origem->id, $this->destino->id));

        $this->assertSame(0, RegistroDaTimeline::query()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TimelineDoNegocioTest`
Expected: FAIL (`MotivoDePerda::factory` pode já existir; as asserções de registro falham com 0 linhas).

- [ ] **Step 3: Write minimal implementation**

`backend/app/Modules/Crm/Application/RegistradorDaTimeline.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use Carbon\CarbonInterface;

/** Único ponto que escreve na timeline do cliente. A F7c só adiciona listeners que chamam isto. */
final class RegistradorDaTimeline
{
    /** @param array<string, mixed> $dados */
    public function registrar(
        TipoDeRegistro $tipo,
        string $clienteId,
        ?string $negocioId,
        ?string $autorId,
        string $texto,
        array $dados = [],
        ?string $atividadeId = null,
        ?CarbonInterface $ocorreuEm = null,
    ): RegistroDaTimeline {
        return RegistroDaTimeline::create([
            'cliente_id' => $clienteId,
            'negocio_id' => $negocioId,
            'atividade_id' => $atividadeId,
            'tipo' => $tipo,
            'texto' => $texto,
            'dados' => $dados,
            'autor_id' => $autorId,
            'ocorreu_em' => $ocorreuEm ?? now(),
        ]);
    }
}
```

`backend/app/Modules/Crm/Application/GravarTimelineDoNegocio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Events\NegocioCriado;
use App\Modules\Crm\Domain\Events\NegocioGanho;
use App\Modules\Crm\Domain\Events\NegocioMovido;
use App\Modules\Crm\Domain\Events\NegocioPerdido;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use Throwable;

/**
 * Listeners síncronos dos eventos do negócio. Os eventos saem depois do commit: uma falha aqui é
 * logada e nunca derruba a ação do vendedor (risco assumido na spec F7b §4).
 */
final class GravarTimelineDoNegocio
{
    public function __construct(private readonly RegistradorDaTimeline $registrador) {}

    public function criado(NegocioCriado $e): void
    {
        $this->proteger(function () use ($e): void {
            $titulo = $this->titulo($e->negocioId);
            $this->registrador->registrar(
                TipoDeRegistro::NegocioCriado, $e->clienteId, $e->negocioId, $e->autorId,
                "Negócio criado: {$titulo}", ['titulo' => $titulo],
            );
        });
    }

    public function movido(NegocioMovido $e): void
    {
        $this->proteger(function () use ($e): void {
            $titulo = $this->titulo($e->negocioId);
            $nomes = Etapa::query()->whereIn('id', [$e->etapaOrigemId, $e->etapaDestinoId])->pluck('nome', 'id');
            $de = (string) ($nomes[$e->etapaOrigemId] ?? '—');
            $para = (string) ($nomes[$e->etapaDestinoId] ?? '—');
            $this->registrador->registrar(
                TipoDeRegistro::NegocioMovido, $e->clienteId, $e->negocioId, $e->autorId,
                "Negócio movido de {$de} para {$para}: {$titulo}", ['titulo' => $titulo, 'de' => $de, 'para' => $para],
            );
        });
    }

    public function ganho(NegocioGanho $e): void
    {
        $this->proteger(function () use ($e): void {
            $titulo = $this->titulo($e->negocioId);
            $this->registrador->registrar(
                TipoDeRegistro::NegocioGanho, $e->clienteId, $e->negocioId, $e->autorId,
                "Negócio ganho: {$titulo}", ['titulo' => $titulo],
            );
        });
    }

    public function perdido(NegocioPerdido $e): void
    {
        $this->proteger(function () use ($e): void {
            $titulo = $this->titulo($e->negocioId);
            $motivo = (string) (MotivoDePerda::query()->whereKey($e->motivoId)->value('nome') ?? '—');
            $this->registrador->registrar(
                TipoDeRegistro::NegocioPerdido, $e->clienteId, $e->negocioId, $e->autorId,
                "Negócio perdido: {$titulo} ({$motivo})", ['titulo' => $titulo, 'motivo' => $motivo],
            );
        });
    }

    private function titulo(string $negocioId): string
    {
        return Negocio::query()->findOrFail($negocioId)->titulo;
    }

    private function proteger(callable $gravar): void
    {
        try {
            $gravar();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
```

Em `CrmServiceProvider::boot()`, antes do bloco de rotas, registrar os listeners (e os imports `Event` e as classes):

```php
        Event::listen(NegocioCriado::class, [GravarTimelineDoNegocio::class, 'criado']);
        Event::listen(NegocioMovido::class, [GravarTimelineDoNegocio::class, 'movido']);
        Event::listen(NegocioGanho::class, [GravarTimelineDoNegocio::class, 'ganho']);
        Event::listen(NegocioPerdido::class, [GravarTimelineDoNegocio::class, 'perdido']);
```

Imports: `Illuminate\Support\Facades\Event`, `App\Modules\Crm\Application\GravarTimelineDoNegocio`, e os quatro eventos de `App\Modules\Crm\Domain\Events`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TimelineDoNegocioTest`
Expected: PASS. Se o teste de integração (mover/perder) falhar porque `afterCommit` não dispara dentro da transação do teste, não troque o teste por `Event::fake`: verifique `DB::afterCommit` com `RefreshDatabase` (os testes da F7a que contam eventos mostram o padrão) e corrija o setup.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm/TimelineDoNegocioTest.php
git commit -m "feat(crm): timeline do negócio gravada por listeners dos eventos Negocio*"
```

---

### Task 3: Actions de atividade (criar, atualizar, concluir, reabrir, excluir)

**Files:**
- Create: `backend/app/Modules/Crm/Application/ValidadorDeAtividade.php`
- Create: `backend/app/Modules/Crm/Application/Actions/{CriarAtividade,AtualizarAtividade,ConcluirAtividade,ReabrirAtividade,ExcluirAtividade}.php`
- Test: `backend/tests/Feature/Crm/AtividadesActionsTest.php`

**Interfaces:**
- Consumes: `PoliticaDoCrm::garantirPodeOperarNegocios/podeReatribuir`, `ConsultaDeClientes::resumo(string): ?ResumoDoCliente`, `DiretorioDeUsuarios::ehDaEmpresa`, `RegistradorDaTimeline::registrar`, `Atividade`, `Negocio::visiveisPara`.
- Produces:
  - `ValidadorDeAtividade::alvo(Usuario $autor, ?string $clienteId, ?string $negocioId): array{0: string, 1: ?string}` (cliente, negócio; derivado do negócio quando informado).
  - `ValidadorDeAtividade::responsavel(Usuario $autor, ?string $responsavelId): string`.
  - `ValidadorDeAtividade::instante(string $iso): CarbonInterface` (UTC).
  - `CriarAtividade::executar(Usuario $autor, array $dados): Atividade` com `$dados = ['tipo'=>string,'titulo'=>string,'descricao'=>?string,'agendada_para'=>string,'cliente_id'=>?string,'negocio_id'=>?string,'responsavel_id'=>?string]`.
  - `AtualizarAtividade::executar(Usuario, Atividade, array $dados): Atividade` (`tipo, titulo, descricao, agendada_para, responsavel_id`).
  - `ConcluirAtividade::executar(Usuario, Atividade): Atividade`, `ReabrirAtividade::executar(Usuario, Atividade): Atividade`, `ExcluirAtividade::executar(Usuario, Atividade): void`.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Crm/AtividadesActionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\AtualizarAtividade;
use App\Modules\Crm\Application\Actions\ConcluirAtividade;
use App\Modules\Crm\Application\Actions\CriarAtividade;
use App\Modules\Crm\Application\Actions\ExcluirAtividade;
use App\Modules\Crm\Application\Actions\ReabrirAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class AtividadesActionsTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
        $this->cliente = Cliente::factory()->create();
    }

    /** @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        return array_merge([
            'tipo' => 'LIGACAO', 'titulo' => 'Ligar para Ana', 'agendada_para' => '2026-10-14T12:00:00-03:00',
            'cliente_id' => $this->cliente->id,
        ], $extra);
    }

    private function negocioDe(string $responsavelId, ?string $clienteId = null): Negocio
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id]);

        return Negocio::factory()->create([
            'funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $responsavelId,
            'cliente_id' => $clienteId ?? $this->cliente->id,
        ]);
    }

    public function test_cria_atividade_com_o_autor_como_responsavel_e_data_em_utc(): void
    {
        $a = app(CriarAtividade::class)->executar($this->admin, $this->dados());

        $this->assertSame($this->admin->id, $a->responsavel_id);
        $this->assertSame($this->admin->id, $a->criado_por);
        $this->assertSame(TipoDeAtividade::Ligacao, $a->tipo);
        $this->assertSame('2026-10-14 15:00:00', $a->agendada_para->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($a->negocio_id);
    }

    public function test_negocio_informado_define_o_cliente(): void
    {
        $negocio = $this->negocioDe($this->admin->id);

        $a = app(CriarAtividade::class)->executar($this->admin, $this->dados(['cliente_id' => null, 'negocio_id' => $negocio->id]));

        $this->assertSame($negocio->cliente_id, $a->cliente_id);
        $this->assertSame($negocio->id, $a->negocio_id);
    }

    public function test_negocio_de_outro_cliente_e_recusado(): void
    {
        $negocio = $this->negocioDe($this->admin->id);
        $outro = Cliente::factory()->create();

        $this->expectException(RegraDoCrmException::class);
        app(CriarAtividade::class)->executar($this->admin, $this->dados(['cliente_id' => $outro->id, 'negocio_id' => $negocio->id]));
    }

    public function test_cliente_inexistente_e_recusado(): void
    {
        $this->expectException(RegraDoCrmException::class);
        app(CriarAtividade::class)->executar($this->admin, $this->dados(['cliente_id' => '00000000-0000-4000-8000-000000000000']));
    }

    public function test_vendedor_nao_atribui_a_outro_usuario(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);

        $this->expectException(AcessoNegadoException::class);
        app(CriarAtividade::class)->executar($joao, $this->dados(['responsavel_id' => $maria->id]));
    }

    public function test_admin_atribui_a_usuario_da_empresa_e_recusa_de_fora(): void
    {
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $a = app(CriarAtividade::class)->executar($this->admin, $this->dados(['responsavel_id' => $maria->id]));
        $this->assertSame($maria->id, $a->responsavel_id);

        $this->expectException(RegraDoCrmException::class);
        app(CriarAtividade::class)->executar($this->admin, $this->dados(['responsavel_id' => '00000000-0000-4000-8000-000000000000']));
    }

    public function test_leitura_nao_cria(): void
    {
        $this->expectException(AcessoNegadoException::class);
        app(CriarAtividade::class)->executar($this->usuarioDoPapel(Papel::Leitura), $this->dados());
    }

    public function test_concluir_grava_registro_na_timeline_uma_unica_vez(): void
    {
        $a = Atividade::factory()->create(['cliente_id' => $this->cliente->id, 'titulo' => 'Enviar proposta', 'tipo' => TipoDeAtividade::Email]);

        app(ConcluirAtividade::class)->executar($this->admin, $a);
        app(ConcluirAtividade::class)->executar($this->admin, $a->refresh());

        $this->assertTrue($a->refresh()->estaConcluida());
        $r = RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::AtividadeConcluida)->sole();
        $this->assertSame($a->id, $r->atividade_id);
        $this->assertSame($this->cliente->id, $r->cliente_id);
        $this->assertSame('EMAIL', $r->dados['tipo']);
    }

    public function test_reabrir_remove_o_registro_e_e_idempotente(): void
    {
        $a = Atividade::factory()->create(['cliente_id' => $this->cliente->id]);
        app(ConcluirAtividade::class)->executar($this->admin, $a);

        app(ReabrirAtividade::class)->executar($this->admin, $a->refresh());
        app(ReabrirAtividade::class)->executar($this->admin, $a->refresh());

        $this->assertFalse($a->refresh()->estaConcluida());
        $this->assertSame(0, RegistroDaTimeline::query()->count());
    }

    public function test_excluir_nao_apaga_registro_ja_gravado(): void
    {
        $a = Atividade::factory()->create(['cliente_id' => $this->cliente->id]);
        app(ConcluirAtividade::class)->executar($this->admin, $a);

        app(ExcluirAtividade::class)->executar($this->admin, $a->refresh());

        $this->assertSame(0, Atividade::query()->count());
        $this->assertSame(1, RegistroDaTimeline::query()->count());
    }

    public function test_atualizar_muda_titulo_data_e_responsavel(): void
    {
        $a = Atividade::factory()->create(['cliente_id' => $this->cliente->id]);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);

        $a = app(AtualizarAtividade::class)->executar($this->admin, $a, [
            'titulo' => 'Novo título', 'agendada_para' => '2026-10-20T09:00:00Z', 'responsavel_id' => $maria->id,
        ]);

        $this->assertSame('Novo título', $a->titulo);
        $this->assertSame('2026-10-20 09:00:00', $a->agendada_para->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($maria->id, $a->responsavel_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AtividadesActionsTest`
Expected: FAIL (`Class ... CriarAtividade not found`).

- [ ] **Step 3: Write minimal implementation**

`backend/app/Modules/Crm/Application/ValidadorDeAtividade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/** Regras comuns de criar e atualizar atividade: alvo (cliente e negócio), responsável e instante. */
final class ValidadorDeAtividade
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultaDeClientes $clientes,
        private readonly DiretorioDeUsuarios $usuarios,
    ) {}

    /** @return array{0: string, 1: ?string} cliente_id e negocio_id */
    public function alvo(Usuario $autor, ?string $clienteId, ?string $negocioId): array
    {
        if ($negocioId !== null) {
            $negocio = Negocio::query()->visiveisPara($autor)->find($negocioId)
                ?? throw new RegraDoCrmException('Negócio não encontrado.', 'NEGOCIO_INVALIDO');

            if ($clienteId !== null && $clienteId !== $negocio->cliente_id) {
                throw new RegraDoCrmException('O negócio não pertence a este cliente.', 'NEGOCIO_INVALIDO');
            }

            return [$negocio->cliente_id, $negocio->id];
        }

        if ($clienteId === null) {
            throw new RegraDoCrmException('Informe o cliente ou o negócio.', 'CLIENTE_INVALIDO');
        }

        if ($this->clientes->resumo($clienteId) === null) {
            throw new RegraDoCrmException('Cliente não encontrado.', 'CLIENTE_INVALIDO');
        }

        return [$clienteId, null];
    }

    public function responsavel(Usuario $autor, ?string $responsavelId): string
    {
        if ($responsavelId === null || $responsavelId === $autor->id) {
            return $autor->id;
        }

        if (! $this->politica->podeReatribuir($autor)) {
            throw new AcessoNegadoException('Só proprietário e administrador atribuem atividades a outros usuários.');
        }

        if (! $this->usuarios->ehDaEmpresa($responsavelId)) {
            throw new RegraDoCrmException('Responsável não encontrado nesta empresa.', 'RESPONSAVEL_INVALIDO');
        }

        return $responsavelId;
    }

    public function instante(string $iso): CarbonInterface
    {
        return Carbon::parse($iso)->utc();
    }
}
```

`CriarAtividade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\ValidadorDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarAtividade
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ValidadorDeAtividade $validador,
    ) {}

    /**
     * @param  array{tipo: string, titulo: string, descricao?: ?string, agendada_para: string, cliente_id?: ?string, negocio_id?: ?string, responsavel_id?: ?string}  $dados
     */
    public function executar(Usuario $autor, array $dados): Atividade
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        [$clienteId, $negocioId] = $this->validador->alvo($autor, $dados['cliente_id'] ?? null, $dados['negocio_id'] ?? null);
        $descricao = isset($dados['descricao']) ? trim($dados['descricao']) : null;

        return Atividade::create([
            'cliente_id' => $clienteId,
            'negocio_id' => $negocioId,
            'tipo' => TipoDeAtividade::from($dados['tipo']),
            'titulo' => trim($dados['titulo']),
            'descricao' => $descricao === '' ? null : $descricao,
            'agendada_para' => $this->validador->instante($dados['agendada_para']),
            'responsavel_id' => $this->validador->responsavel($autor, $dados['responsavel_id'] ?? null),
            'criado_por' => $autor->id,
        ]);
    }
}
```

`AtualizarAtividade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\ValidadorDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class AtualizarAtividade
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ValidadorDeAtividade $validador,
    ) {}

    /** @param array{tipo?: string, titulo?: string, descricao?: ?string, agendada_para?: string, responsavel_id?: ?string} $dados */
    public function executar(Usuario $autor, Atividade $atividade, array $dados): Atividade
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $atividade, $dados): Atividade {
            $atividade = Atividade::query()->lockForUpdate()->findOrFail($atividade->id);
            $mudancas = [];

            if (isset($dados['tipo'])) {
                $mudancas['tipo'] = TipoDeAtividade::from($dados['tipo']);
            }
            if (isset($dados['titulo'])) {
                $mudancas['titulo'] = trim($dados['titulo']);
            }
            if (array_key_exists('descricao', $dados)) {
                $descricao = $dados['descricao'] === null ? null : trim($dados['descricao']);
                $mudancas['descricao'] = $descricao === '' ? null : $descricao;
            }
            if (isset($dados['agendada_para'])) {
                $mudancas['agendada_para'] = $this->validador->instante($dados['agendada_para']);
            }
            if (isset($dados['responsavel_id'])) {
                $mudancas['responsavel_id'] = $this->validador->responsavel($autor, $dados['responsavel_id']);
            }

            $atividade->update($mudancas);

            return $atividade->refresh();
        });
    }
}
```

`ConcluirAtividade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ConcluirAtividade
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    public function executar(Usuario $autor, Atividade $atividade): Atividade
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $atividade): Atividade {
            $atividade = Atividade::query()->lockForUpdate()->findOrFail($atividade->id);

            if ($atividade->estaConcluida()) {
                return $atividade;
            }

            $agora = now();
            $atividade->update(['concluida_em' => $agora]);

            $this->timeline->registrar(
                TipoDeRegistro::AtividadeConcluida,
                $atividade->cliente_id,
                $atividade->negocio_id,
                $autor->id,
                $atividade->titulo,
                ['tipo' => $atividade->tipo->value, 'titulo' => $atividade->titulo],
                $atividade->id,
                $agora,
            );

            return $atividade->refresh();
        });
    }
}
```

`ReabrirAtividade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ReabrirAtividade
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, Atividade $atividade): Atividade
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($atividade): Atividade {
            $atividade = Atividade::query()->lockForUpdate()->findOrFail($atividade->id);

            if ($atividade->estaConcluida()) {
                $atividade->update(['concluida_em' => null]);
            }

            // A timeline não conta uma conclusão desfeita.
            RegistroDaTimeline::query()
                ->where('atividade_id', $atividade->id)
                ->where('tipo', TipoDeRegistro::AtividadeConcluida)
                ->delete();

            return $atividade->refresh();
        });
    }
}
```

`ExcluirAtividade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;

final class ExcluirAtividade
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** Registros já gravados na timeline ficam: a história não some com a atividade. */
    public function executar(Usuario $autor, Atividade $atividade): void
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        $atividade->delete();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AtividadesActionsTest`
Expected: PASS (10 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm/AtividadesActionsTest.php
git commit -m "feat(crm): actions de atividade (criar, atualizar, concluir, reabrir, excluir)"
```

---

### Task 4: Contato em 1 clique e nota do cliente

**Files:**
- Create: `backend/app/Modules/Crm/Application/LinkDeContato.php`
- Create: `backend/app/Modules/Crm/Application/Actions/{RegistrarContato,CriarNotaDoCliente}.php`
- Test: `backend/tests/Feature/Crm/ContatoEmUmCliqueTest.php`

**Interfaces:**
- Consumes: `ConsultaDeClientes::resumo` (`ResumoDoCliente->telefone/email/nome`), `ValidadorDeAtividade::alvo`, `RegistradorDaTimeline::registrar`.
- Produces:
  - `LinkDeContato::whatsapp(?string $telefone): string` (URL `https://wa.me/55<DDD+número>`), `LinkDeContato::email(?string $email): string` (`mailto:<email>`); ambos lançam `RegraDoCrmException` (`TELEFONE_INVALIDO`, `EMAIL_INVALIDO`) com mensagem acionável.
  - `RegistrarContato::executar(Usuario $autor, string $canal, ?string $clienteId, ?string $negocioId): array{atividade: Atividade, url: string}`; `$canal` é `WHATSAPP` ou `EMAIL`.
  - `CriarNotaDoCliente::executar(Usuario $autor, string $clienteId, string $texto): RegistroDaTimeline`.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Crm/ContatoEmUmCliqueTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\CriarNotaDoCliente;
use App\Modules\Crm\Application\Actions\RegistrarContato;
use App\Modules\Crm\Application\LinkDeContato;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ComCrm;
use Tests\TestCase;

class ContatoEmUmCliqueTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function telefonesValidos(): array
    {
        return [
            'celular com máscara' => ['(11) 99999-0000', 'https://wa.me/5511999990000'],
            'fixo com DDD' => ['1133334444', 'https://wa.me/551133334444'],
            'com +55' => ['+55 11 99999-0000', 'https://wa.me/5511999990000'],
            'com 55 e sem sinal' => ['5511999990000', 'https://wa.me/5511999990000'],
        ];
    }

    #[DataProvider('telefonesValidos')]
    public function test_normaliza_telefone(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, app(LinkDeContato::class)->whatsapp($entrada));
    }

    /** @return array<string, array{0: ?string}> */
    public static function telefonesInvalidos(): array
    {
        return ['nulo' => [null], 'vazio' => [''], 'sem DDD' => ['99999-0000'], 'oito dígitos' => ['33334444'], 'letras' => ['abc']];
    }

    #[DataProvider('telefonesInvalidos')]
    public function test_telefone_invalido_bloqueia_com_mensagem(?string $entrada): void
    {
        try {
            app(LinkDeContato::class)->whatsapp($entrada);
            $this->fail('Deveria bloquear.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('TELEFONE_INVALIDO', $e->codigo());
            $this->assertStringContainsString('DDD', $e->getMessage());
        }
    }

    public function test_email_valido_e_invalido(): void
    {
        $this->assertSame('mailto:ana@empresa.com', app(LinkDeContato::class)->email(' ana@empresa.com '));

        $this->expectException(RegraDoCrmException::class);
        app(LinkDeContato::class)->email('sem-arroba');
    }

    public function test_whatsapp_cria_atividade_concluida_e_registro_na_timeline(): void
    {
        $cliente = Cliente::factory()->create(['nome' => 'Ana', 'telefone' => '(11) 99999-0000']);

        $r = app(RegistrarContato::class)->executar($this->admin, 'WHATSAPP', $cliente->id, null);

        $this->assertSame('https://wa.me/5511999990000', $r['url']);
        $this->assertSame(TipoDeAtividade::Whatsapp, $r['atividade']->tipo);
        $this->assertTrue($r['atividade']->estaConcluida());
        $this->assertSame('WhatsApp para Ana', $r['atividade']->titulo);
        $this->assertSame(1, RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::AtividadeConcluida)->count());
    }

    public function test_telefone_invalido_nao_cria_nada(): void
    {
        $cliente = Cliente::factory()->create(['telefone' => null]);

        try {
            app(RegistrarContato::class)->executar($this->admin, 'WHATSAPP', $cliente->id, null);
            $this->fail('Deveria bloquear.');
        } catch (RegraDoCrmException) {
            $this->assertSame(0, Atividade::query()->count());
            $this->assertSame(0, RegistroDaTimeline::query()->count());
        }
    }

    public function test_cada_clique_cria_uma_atividade(): void
    {
        $cliente = Cliente::factory()->create(['telefone' => '11999990000']);

        app(RegistrarContato::class)->executar($this->admin, 'WHATSAPP', $cliente->id, null);
        app(RegistrarContato::class)->executar($this->admin, 'WHATSAPP', $cliente->id, null);

        $this->assertSame(2, Atividade::query()->count());
    }

    public function test_canal_desconhecido_e_recusado(): void
    {
        $cliente = Cliente::factory()->create();

        $this->expectException(RegraDoCrmException::class);
        app(RegistrarContato::class)->executar($this->admin, 'TELEGRAM', $cliente->id, null);
    }

    public function test_nota_do_cliente(): void
    {
        $cliente = Cliente::factory()->create();

        $r = app(CriarNotaDoCliente::class)->executar($this->admin, $cliente->id, '  Prefere contato à tarde.  ');

        $this->assertSame(TipoDeRegistro::Nota, $r->tipo);
        $this->assertSame('Prefere contato à tarde.', $r->texto);
        $this->assertSame($this->admin->id, $r->autor_id);
    }

    public function test_nota_vazia_ou_longa_e_recusada(): void
    {
        $cliente = Cliente::factory()->create();

        $this->expectException(RegraDoCrmException::class);
        app(CriarNotaDoCliente::class)->executar($this->admin, $cliente->id, '   ');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ContatoEmUmCliqueTest`
Expected: FAIL (`Class ... LinkDeContato not found`).

- [ ] **Step 3: Write minimal implementation**

`backend/app/Modules/Crm/Application/LinkDeContato.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;

/** Monta `wa.me` e `mailto`. Dado ausente ou fora do padrão bloqueia: nunca se chuta número. */
final class LinkDeContato
{
    public function whatsapp(?string $telefone): string
    {
        $digitos = preg_replace('/\D+/', '', $telefone ?? '') ?? '';

        if (in_array(strlen($digitos), [12, 13], true) && str_starts_with($digitos, '55')) {
            $numero = substr($digitos, 2);
        } else {
            $numero = $digitos;
        }

        // Nacional: DDD de 2 dígitos (sem zero à esquerda) + 8 ou 9 dígitos do número.
        if (! in_array(strlen($numero), [10, 11], true) || $numero[0] === '0') {
            throw new RegraDoCrmException(
                'Cadastre o telefone do cliente com DDD (10 ou 11 dígitos) para abrir o WhatsApp.',
                'TELEFONE_INVALIDO',
            );
        }

        return "https://wa.me/55{$numero}";
    }

    public function email(?string $email): string
    {
        $limpo = trim($email ?? '');

        if ($limpo === '' || filter_var($limpo, FILTER_VALIDATE_EMAIL) === false) {
            throw new RegraDoCrmException('Cadastre um e-mail válido para o cliente antes de enviar.', 'EMAIL_INVALIDO');
        }

        return "mailto:{$limpo}";
    }
}
```

`backend/app/Modules/Crm/Application/Actions/RegistrarContato.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\LinkDeContato;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Application\ValidadorDeAtividade;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class RegistrarContato
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ValidadorDeAtividade $validador,
        private readonly ConsultaDeClientes $clientes,
        private readonly LinkDeContato $links,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    /** @return array{atividade: Atividade, url: string} */
    public function executar(Usuario $autor, string $canal, ?string $clienteId, ?string $negocioId): array
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        $tipo = match ($canal) {
            'WHATSAPP' => TipoDeAtividade::Whatsapp,
            'EMAIL' => TipoDeAtividade::Email,
            default => throw new RegraDoCrmException('Canal de contato inválido.', 'CANAL_INVALIDO'),
        };

        [$clienteId, $negocioId] = $this->validador->alvo($autor, $clienteId, $negocioId);
        $cliente = $this->clientes->resumo($clienteId)
            ?? throw new RegraDoCrmException('Cliente não encontrado.', 'CLIENTE_INVALIDO');

        // O link é montado antes de gravar: dado inválido não deixa atividade nem registro para trás.
        $url = $tipo === TipoDeAtividade::Whatsapp
            ? $this->links->whatsapp($cliente->telefone)
            : $this->links->email($cliente->email);

        $titulo = ($tipo === TipoDeAtividade::Whatsapp ? 'WhatsApp para ' : 'E-mail para ').$cliente->nome;

        $atividade = DB::transaction(function () use ($autor, $tipo, $clienteId, $negocioId, $titulo): Atividade {
            $agora = now();
            $atividade = Atividade::create([
                'cliente_id' => $clienteId, 'negocio_id' => $negocioId, 'tipo' => $tipo, 'titulo' => $titulo,
                'agendada_para' => $agora, 'concluida_em' => $agora,
                'responsavel_id' => $autor->id, 'criado_por' => $autor->id,
            ]);

            $this->timeline->registrar(
                TipoDeRegistro::AtividadeConcluida, $clienteId, $negocioId, $autor->id, $titulo,
                ['tipo' => $tipo->value, 'titulo' => $titulo], $atividade->id, $agora,
            );

            return $atividade;
        });

        return ['atividade' => $atividade, 'url' => $url];
    }
}
```

`backend/app/Modules/Crm/Application/Actions/CriarNotaDoCliente.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Models\Usuario;

final class CriarNotaDoCliente
{
    public const LIMITE = 2000;

    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultaDeClientes $clientes,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    public function executar(Usuario $autor, string $clienteId, string $texto): RegistroDaTimeline
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) > self::LIMITE) {
            throw new RegraDoCrmException('A nota precisa ter de 1 a '.self::LIMITE.' caracteres.', 'NOTA_INVALIDA');
        }

        if ($this->clientes->resumo($clienteId) === null) {
            throw new RegraDoCrmException('Cliente não encontrado.', 'CLIENTE_INVALIDO');
        }

        return $this->timeline->registrar(TipoDeRegistro::Nota, $clienteId, null, $autor->id, $texto);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ContatoEmUmCliqueTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm/ContatoEmUmCliqueTest.php
git commit -m "feat(crm): contato em 1 clique (WhatsApp e e-mail) e nota do cliente"
```

---

### Task 5: Negócio parado (calculado na leitura)

**Files:**
- Create: `backend/app/Modules/Crm/Application/ConsultorDeParados.php`
- Modify: `backend/app/Modules/Crm/Application/ApresentadorDeNegocios.php`, `backend/app/Modules/Crm/Http/Controllers/NegociosController.php`
- Test: `backend/tests/Feature/Crm/NegocioParadoTest.php`

**Interfaces:**
- Consumes: `Atividade`, `RegistroDaTimeline`, `TipoDeRegistro::NegocioMovido`, `Etapa->dias_para_parado`.
- Produces:
  - `ConsultorDeParados::situacao(iterable $negocios): array<string, array{parado: bool, dias: int}>` (só negócios abertos; id => situação).
  - `ApresentadorDeNegocios::apresentar()` passa a incluir `parado` (bool) e `dias_parado` (int|null) em cada item. Negócio fechado: `false` e `null`.
  - `GET /api/app/crm/negocios?parado=1` filtra.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Crm/NegocioParadoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\ConsultorDeParados;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class NegocioParadoTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Funil $funil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => false]);
        $this->funil = Funil::factory()->padrao()->create();
        // 2026-10-14 12:00 UTC = 09:00 em São Paulo.
        $this->travelTo('2026-10-14 12:00:00');
    }

    /** @param array<string, mixed> $extra */
    private function negocio(?int $diasParaParado, string $criadoEm, array $extra = []): Negocio
    {
        $etapa = Etapa::factory()->create(['funil_id' => $this->funil->id, 'dias_para_parado' => $diasParaParado]);
        $n = Negocio::factory()->create(array_merge([
            'funil_id' => $this->funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id,
        ], $extra));
        $n->forceFill(['created_at' => $criadoEm])->saveQuietly();

        return $n->refresh();
    }

    public function test_exatamente_no_limite_ja_esta_parado(): void
    {
        $n = $this->negocio(3, '2026-10-11 15:00:00'); // 11/10 (SP) -> 3 dias de calendário

        $s = app(ConsultorDeParados::class)->situacao([$n]);

        $this->assertTrue($s[$n->id]['parado']);
        $this->assertSame(3, $s[$n->id]['dias']);
    }

    public function test_um_dia_antes_do_limite_nao_esta_parado(): void
    {
        $n = $this->negocio(3, '2026-10-12 15:00:00');

        $this->assertFalse(app(ConsultorDeParados::class)->situacao([$n])[$n->id]['parado']);
    }

    public function test_etapa_sem_alerta_nunca_para(): void
    {
        $n = $this->negocio(null, '2026-01-01 12:00:00');

        $this->assertFalse(app(ConsultorDeParados::class)->situacao([$n])[$n->id]['parado']);
    }

    public function test_atividade_concluida_zera_o_contador(): void
    {
        $n = $this->negocio(3, '2026-09-01 12:00:00');
        Atividade::factory()->create(['negocio_id' => $n->id, 'cliente_id' => $n->cliente_id, 'concluida_em' => '2026-10-13 12:00:00']);

        $s = app(ConsultorDeParados::class)->situacao([$n])[$n->id];

        $this->assertFalse($s['parado']);
        $this->assertSame(1, $s['dias']);
    }

    public function test_atividade_aberta_nao_conta(): void
    {
        $n = $this->negocio(3, '2026-09-01 12:00:00');
        Atividade::factory()->create(['negocio_id' => $n->id, 'cliente_id' => $n->cliente_id]);

        $this->assertTrue(app(ConsultorDeParados::class)->situacao([$n])[$n->id]['parado']);
    }

    public function test_movimentacao_de_etapa_conta(): void
    {
        $n = $this->negocio(3, '2026-09-01 12:00:00');
        RegistroDaTimeline::create([
            'cliente_id' => $n->cliente_id, 'negocio_id' => $n->id, 'tipo' => TipoDeRegistro::NegocioMovido,
            'texto' => 'x', 'ocorreu_em' => '2026-10-14 10:00:00',
        ]);

        $this->assertFalse(app(ConsultorDeParados::class)->situacao([$n])[$n->id]['parado']);
    }

    public function test_virada_de_dia_usa_o_fuso_de_sao_paulo(): void
    {
        // 14/10 02:00 UTC = 13/10 23:00 em SP. Agora (14/10 09:00 SP): 1 dia, não 0.
        $n = $this->negocio(1, '2026-10-14 02:00:00');

        $s = app(ConsultorDeParados::class)->situacao([$n])[$n->id];

        $this->assertSame(1, $s['dias']);
        $this->assertTrue($s['parado']);
    }

    public function test_ganho_e_perdido_ficam_de_fora(): void
    {
        $n = $this->negocio(1, '2026-01-01 12:00:00', ['status' => StatusDoNegocio::Ganho]);

        $this->assertArrayNotHasKey($n->id, app(ConsultorDeParados::class)->situacao([$n]));
    }

    public function test_api_devolve_parado_e_filtra(): void
    {
        $parado = $this->negocio(3, '2026-10-01 12:00:00');
        $this->negocio(3, '2026-10-14 11:00:00');

        $this->spaComo($this->admin)->getJson("/api/app/crm/negocios?funil_id={$this->funil->id}")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $parado->id, 'parado' => true, 'dias_parado' => 13]);

        $this->spaComo($this->admin)->getJson("/api/app/crm/negocios?funil_id={$this->funil->id}&parado=1")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $parado->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=NegocioParadoTest`
Expected: FAIL (`Class ... ConsultorDeParados not found`).

- [ ] **Step 3: Write minimal implementation**

`backend/app/Modules/Crm/Application/ConsultorDeParados.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use Carbon\Carbon;

/**
 * Parado = negócio aberto sem interação há `dias_para_parado` dias de calendário (fuso SP) ou mais.
 * Interação = criação, última movimentação de etapa ou última atividade concluída do negócio.
 */
final class ConsultorDeParados
{
    /**
     * @param  iterable<Negocio>  $negocios
     * @return array<string, array{parado: bool, dias: int}> só os negócios abertos, por id
     */
    public function situacao(iterable $negocios): array
    {
        $abertos = [];
        foreach ($negocios as $negocio) {
            if ($negocio->status === StatusDoNegocio::Aberto) {
                $abertos[$negocio->id] = $negocio;
            }
        }

        if ($abertos === []) {
            return [];
        }

        $ids = array_keys($abertos);
        $fuso = (string) config('app.fuso_negocio');

        /** @var array<string, string> $atividades */
        $atividades = Atividade::query()->whereIn('negocio_id', $ids)->whereNotNull('concluida_em')
            ->groupBy('negocio_id')->selectRaw('negocio_id, max(concluida_em) as ultima')
            ->pluck('ultima', 'negocio_id')->all();

        /** @var array<string, string> $movimentos */
        $movimentos = RegistroDaTimeline::query()->whereIn('negocio_id', $ids)
            ->where('tipo', TipoDeRegistro::NegocioMovido->value)
            ->groupBy('negocio_id')->selectRaw('negocio_id, max(ocorreu_em) as ultima')
            ->pluck('ultima', 'negocio_id')->all();

        $etapas = array_unique(array_map(fn (Negocio $n): string => $n->etapa_id, $abertos));
        /** @var array<string, ?int> $limites */
        $limites = Etapa::query()->whereIn('id', $etapas)->pluck('dias_para_parado', 'id')->all();

        $hoje = now($fuso)->startOfDay();
        $situacao = [];

        foreach ($abertos as $id => $negocio) {
            $momentos = [Carbon::parse($negocio->created_at, 'UTC')];
            foreach ([$atividades[$id] ?? null, $movimentos[$id] ?? null] as $bruto) {
                if ($bruto !== null) {
                    $momentos[] = Carbon::parse($bruto, 'UTC');
                }
            }

            $ultima = collect($momentos)->max()->copy()->setTimezone($fuso)->startOfDay();
            $dias = (int) abs($ultima->diffInDays($hoje));
            $limite = $limites[$negocio->etapa_id] ?? null;

            $situacao[$id] = ['parado' => $limite !== null && $dias >= (int) $limite, 'dias' => $dias];
        }

        return $situacao;
    }
}
```

`created_at` é cast para Carbon em app timezone; `Carbon::parse($carbon, 'UTC')` mantém o instante. Conferir que `config('app.timezone')` é `UTC`; se não for, use `$negocio->created_at->copy()` em vez de `parse` para esse momento.

Em `ApresentadorDeNegocios`: injetar `private readonly ConsultorDeParados $parados` no construtor, calcular `$situacao = $this->parados->situacao($lista);` antes do `array_map`, passar `$situacao` no `use (...)` e acrescentar ao array do item:

```php
                'parado' => $situacao[$n->id]['parado'] ?? false,
                'dias_parado' => $situacao[$n->id]['dias'] ?? null,
```

Em `NegociosController::index()`: validar `'parado' => ['nullable', 'boolean']` e trocar o `return` final por:

```php
        $lista = $this->apresentador->apresentar($consulta->get());
        if ($request->boolean('parado')) {
            $lista = array_values(array_filter($lista, fn (array $n): bool => $n['parado'] === true));
        }

        return response()->json(['data' => $lista]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=NegocioParadoTest` e `php artisan test --filter=Crm`
Expected: PASS. Testes de F7a que comparam o JSON do negócio inteiro precisam ganhar `parado` e `dias_parado`; ajustar só esses asserts.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm
git commit -m "feat(crm): negócio parado calculado na leitura, com filtro"
```

---

### Task 6: API de atividades, agenda, resumo, contatos e timeline

**Files:**
- Create: `Crm/Application/ApresentadorDeAtividades.php`
- Create: `Crm/Application/Actions/{ListarAgenda,ListarTimelineDoCliente,ResumoDoCrm}.php`
- Create: `Crm/Http/Controllers/{AtividadesController,TimelineController,ContatosController}.php`
- Create: `Crm/Http/Requests/{AtividadeRequest,ContatoRequest,NotaRequest}.php`
- Modify: `Crm/Http/routes-app.php`
- Test: `backend/tests/Feature/Crm/AtividadesApiTest.php`

**Interfaces:**
- Consumes: Actions das Tasks 3 e 4, `ConsultorDeParados::situacao`, `Atividade::visiveisPara`, `RegistroDaTimeline::visiveisPara`.
- Produces (JSON; `data` no envelope):
  - Atividade: `{id, tipo, titulo, descricao, agendada_para, concluida_em, atrasada, cliente:{id,nome,telefone,email}|null, negocio:{id,titulo}|null, responsavel:{id,nome}|null}`.
  - Timeline: `{data:[{id,tipo,texto,dados,autor:{id,nome}|null,negocio_id,atividade_id,ocorreu_em}], next_cursor: string|null}`.
  - Resumo: `{data:{hoje:int, atrasadas:int, parados:int}}`.
  - Contato: `{data:{atividade:<Atividade>, url:string}}` (201).
- Rotas: ver Step 3.

- [ ] **Step 1: Write the failing test**

Criar `backend/tests/Feature/Crm/AtividadesApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class AtividadesApiTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
        $this->cliente = Cliente::factory()->create(['nome' => 'Ana', 'telefone' => '11999990000', 'email' => 'ana@x.com']);
        $this->travelTo('2026-10-14 12:00:00'); // 09:00 em São Paulo
    }

    public function test_cria_lista_conclui_reabre_e_exclui(): void
    {
        $id = $this->spaComo($this->admin)->postJson('/api/app/crm/atividades', [
            'tipo' => 'LIGACAO', 'titulo' => 'Ligar', 'agendada_para' => '2026-10-14T15:00:00Z', 'cliente_id' => $this->cliente->id,
        ])->assertCreated()->assertJsonPath('data.cliente.nome', 'Ana')->assertJsonPath('data.atrasada', false)->json('data.id');

        $this->spaComo($this->admin)->getJson("/api/app/crm/atividades?cliente_id={$this->cliente->id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->spaComo($this->admin)->postJson("/api/app/crm/atividades/{$id}/concluir")
            ->assertOk()->assertJsonPath('data.concluida_em', fn ($v) => $v !== null);
        $this->spaComo($this->admin)->postJson("/api/app/crm/atividades/{$id}/reabrir")
            ->assertOk()->assertJsonPath('data.concluida_em', null);
        $this->spaComo($this->admin)->putJson("/api/app/crm/atividades/{$id}", ['titulo' => 'Ligar de novo'])
            ->assertOk()->assertJsonPath('data.titulo', 'Ligar de novo');
        $this->spaComo($this->admin)->deleteJson("/api/app/crm/atividades/{$id}")->assertNoContent();
    }

    public function test_validacao_do_corpo(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/crm/atividades', ['tipo' => 'X', 'titulo' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['tipo', 'titulo', 'agendada_para']);
    }

    public function test_agenda_traz_atrasadas_e_o_intervalo(): void
    {
        $atrasada = Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-10 12:00:00']);
        $hoje = Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-14 18:00:00']);
        Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-30 12:00:00']);
        Atividade::factory()->concluida()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-14 13:00:00']);

        $r = $this->spaComo($this->admin)->getJson('/api/app/crm/agenda?de=2026-10-14&ate=2026-10-20')->assertOk();

        $this->assertSame([$atrasada->id, $hoje->id], array_column($r->json('data'), 'id'));
        $r->assertJsonPath('data.0.atrasada', true)->assertJsonPath('data.1.atrasada', false);
    }

    public function test_resumo(): void
    {
        Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-10 12:00:00']);
        Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-14 18:00:00']);
        Atividade::factory()->create(['responsavel_id' => $this->admin->id, 'cliente_id' => $this->cliente->id, 'agendada_para' => '2026-10-15 18:00:00']);

        $this->spaComo($this->admin)->getJson('/api/app/crm/resumo')
            ->assertOk()->assertJsonPath('data.hoje', 1)->assertJsonPath('data.atrasadas', 1)->assertJsonPath('data.parados', 0);
    }

    public function test_contato_em_um_clique(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/crm/contatos', ['canal' => 'WHATSAPP', 'cliente_id' => $this->cliente->id])
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://wa.me/5511999990000')
            ->assertJsonPath('data.atividade.tipo', 'WHATSAPP');
    }

    public function test_contato_sem_telefone_devolve_422_com_codigo(): void
    {
        $semTelefone = Cliente::factory()->create(['telefone' => null]);

        $this->spaComo($this->admin)->postJson('/api/app/crm/contatos', ['canal' => 'WHATSAPP', 'cliente_id' => $semTelefone->id])
            ->assertStatus(422)->assertJsonPath('code', 'TELEFONE_INVALIDO');
    }

    public function test_nota_e_timeline_com_cursor(): void
    {
        foreach (['primeira', 'segunda'] as $texto) {
            $this->spaComo($this->admin)->postJson("/api/app/crm/clientes/{$this->cliente->id}/notas", ['texto' => $texto])
                ->assertCreated();
            $this->travelTo(now()->addMinute());
        }

        $r = $this->spaComo($this->admin)->getJson("/api/app/crm/clientes/{$this->cliente->id}/timeline")->assertOk();

        $this->assertSame(['segunda', 'primeira'], array_column($r->json('data'), 'texto'));
        $r->assertJsonPath('data.0.autor.id', $this->admin->id)->assertJsonPath('next_cursor', null);
    }

    public function test_vendedor_restrito_recebe_404_de_atividade_e_timeline_de_colega(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $alheia = Atividade::factory()->create(['responsavel_id' => $maria->id, 'cliente_id' => $this->cliente->id]);

        $this->spaComo($joao)->postJson("/api/app/crm/atividades/{$alheia->id}/concluir")->assertNotFound();
        $this->spaComo($joao)->putJson("/api/app/crm/atividades/{$alheia->id}", ['titulo' => 'x'])->assertNotFound();
        $this->spaComo($joao)->deleteJson("/api/app/crm/atividades/{$alheia->id}")->assertNotFound();

        RegistroDaTimeline::create([
            'cliente_id' => $this->cliente->id, 'tipo' => TipoDeRegistro::Nota, 'texto' => 'da Maria',
            'autor_id' => $maria->id, 'ocorreu_em' => now(),
        ]);
        $this->spaComo($joao)->getJson("/api/app/crm/clientes/{$this->cliente->id}/timeline")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_papel_fiscal_nao_acessa_e_leitura_nao_escreve(): void
    {
        $this->spaComo($this->usuarioDoPapel(Papel::Fiscal))->getJson('/api/app/crm/agenda')->assertForbidden();
        $this->spaComo($this->usuarioDoPapel(Papel::Leitura))->postJson('/api/app/crm/atividades', [
            'tipo' => 'TAREFA', 'titulo' => 'x', 'agendada_para' => '2026-10-15T12:00:00Z', 'cliente_id' => $this->cliente->id,
        ])->assertForbidden();
    }

    public function test_isolamento_de_tenant(): void
    {
        $plano = Plano::factory()->comModulos(Modulo::Crm);
        $outroTenant = Tenant::factory()->for($plano)->create();
        app(TenantContext::class)->set($outroTenant->id);
        $clienteDeFora = Cliente::factory()->create();
        $atividadeDeFora = Atividade::factory()->create(['cliente_id' => $clienteDeFora->id]);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->postJson("/api/app/crm/atividades/{$atividadeDeFora->id}/concluir")->assertNotFound();
        $this->spaComo($this->admin)->getJson("/api/app/crm/clientes/{$clienteDeFora->id}/timeline")->assertNotFound();
        $this->spaComo($this->admin)->postJson('/api/app/crm/atividades', [
            'tipo' => 'TAREFA', 'titulo' => 'x', 'agendada_para' => '2026-10-15T12:00:00Z', 'cliente_id' => $clienteDeFora->id,
        ])->assertStatus(422)->assertJsonPath('code', 'CLIENTE_INVALIDO');
        $this->spaComo($this->admin)->getJson('/api/app/crm/atividades')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filtro_com_id_invalido_devolve_422(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/atividades?cliente_id=nao-e-uuid')->assertStatus(422);
        $this->spaComo($this->admin)->putJson('/api/app/crm/atividades/nao-e-uuid', [])->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AtividadesApiTest`
Expected: FAIL (404 nas rotas novas).

- [ ] **Step 3: Write minimal implementation**

`Crm/Application/ApresentadorDeAtividades.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\Negocio;

/** Monta o JSON da atividade com cliente, negócio e responsável em lote. */
final class ApresentadorDeAtividades
{
    public function __construct(
        private readonly ConsultaDeClientes $clientes,
        private readonly DiretorioDeUsuarios $usuarios,
    ) {}

    /**
     * @param  iterable<Atividade>  $atividades
     * @return list<array<string, mixed>>
     */
    public function apresentar(iterable $atividades): array
    {
        $lista = [...$atividades];
        $clientes = $this->clientes->resumos(array_values(array_unique(array_map(fn (Atividade $a): string => $a->cliente_id, $lista))));
        $nomes = $this->usuarios->nomes(array_values(array_unique(array_map(fn (Atividade $a): string => $a->responsavel_id, $lista))));
        $negocioIds = array_values(array_unique(array_filter(array_map(fn (Atividade $a): ?string => $a->negocio_id, $lista))));
        /** @var array<string, string> $titulos */
        $titulos = $negocioIds === [] ? [] : Negocio::query()->whereIn('id', $negocioIds)->pluck('titulo', 'id')->all();
        $agora = now();

        return array_map(function (Atividade $a) use ($clientes, $nomes, $titulos, $agora): array {
            $cliente = $clientes[$a->cliente_id] ?? null;

            return [
                'id' => $a->id,
                'tipo' => $a->tipo->value,
                'titulo' => $a->titulo,
                'descricao' => $a->descricao,
                'agendada_para' => $a->agendada_para->toIso8601String(),
                'concluida_em' => $a->concluida_em?->toIso8601String(),
                'atrasada' => ! $a->estaConcluida() && $a->agendada_para->lt($agora),
                'cliente' => $cliente === null ? null : [
                    'id' => $cliente->id, 'nome' => $cliente->nome, 'telefone' => $cliente->telefone, 'email' => $cliente->email,
                ],
                'negocio' => $a->negocio_id !== null && isset($titulos[$a->negocio_id])
                    ? ['id' => $a->negocio_id, 'titulo' => $titulos[$a->negocio_id]] : null,
                'responsavel' => isset($nomes[$a->responsavel_id]) ? ['id' => $a->responsavel_id, 'nome' => $nomes[$a->responsavel_id]] : null,
            ];
        }, $lista);
    }
}
```

`Actions/ListarAgenda.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ListarAgenda
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /**
     * Abertas até o fim de `$ate`: as do intervalo e todas as atrasadas (agendadas antes de agora).
     *
     * @return Collection<int, Atividade>
     */
    public function executar(Usuario $autor, CarbonInterface $de, CarbonInterface $ate, ?string $responsavelId): Collection
    {
        $this->politica->garantirPodeLer($autor);

        $consulta = Atividade::query()->visiveisPara($autor)
            ->whereNull('concluida_em')
            ->where('agendada_para', '<=', $ate->copy()->utc())
            ->where(function ($q) use ($de): void {
                $q->where('agendada_para', '>=', $de->copy()->utc())->orWhere('agendada_para', '<', now());
            });

        if ($responsavelId !== null) {
            $consulta->where('responsavel_id', $responsavelId);
        }

        return $consulta->orderBy('agendada_para')->orderBy('id')->get();
    }
}
```

`Actions/ResumoDoCrm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\ConsultorDeParados;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;

final class ResumoDoCrm
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultorDeParados $parados,
    ) {}

    /** @return array{hoje: int, atrasadas: int, parados: int} */
    public function executar(Usuario $autor): array
    {
        $this->politica->garantirPodeLer($autor);

        $agora = now();
        $fimDoDia = now((string) config('app.fuso_negocio'))->endOfDay()->utc();
        $abertas = fn () => Atividade::query()->visiveisPara($autor)->where('responsavel_id', $autor->id)->whereNull('concluida_em');

        $negocios = Negocio::query()->visiveisPara($autor)->where('status', StatusDoNegocio::Aberto)->get();
        $situacao = $this->parados->situacao($negocios);

        return [
            'hoje' => $abertas()->where('agendada_para', '>=', $agora)->where('agendada_para', '<=', $fimDoDia)->count(),
            'atrasadas' => $abertas()->where('agendada_para', '<', $agora)->count(),
            'parados' => count(array_filter($situacao, fn (array $s): bool => $s['parado'])),
        ];
    }
}
```

`Actions/ListarTimelineDoCliente.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ListarTimelineDoCliente
{
    public const POR_PAGINA = 30;

    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultaDeClientes $clientes,
    ) {}

    /** @return CursorPaginator<int, RegistroDaTimeline> */
    public function executar(Usuario $autor, string $clienteId): CursorPaginator
    {
        $this->politica->garantirPodeLer($autor);

        if ($this->clientes->resumo($clienteId) === null) {
            throw new ModelNotFoundException;
        }

        return RegistroDaTimeline::query()->visiveisPara($autor)
            ->where('cliente_id', $clienteId)
            ->orderByDesc('ocorreu_em')->orderByDesc('id')
            ->cursorPaginate(self::POR_PAGINA);
    }
}
```

Requests:

`Http/Requests/AtividadeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AtividadeRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $criando = $this->isMethod('POST');
        $obrigatorio = $criando ? 'required' : 'sometimes';

        return [
            'tipo' => [$obrigatorio, Rule::enum(TipoDeAtividade::class)],
            'titulo' => [$obrigatorio, 'string', 'min:1', 'max:150'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'agendada_para' => [$obrigatorio, 'date'],
            'responsavel_id' => ['nullable', 'uuid'],
            'cliente_id' => $criando ? ['required_without:negocio_id', 'nullable', 'uuid'] : ['prohibited'],
            'negocio_id' => $criando ? ['nullable', 'uuid'] : ['prohibited'],
        ];
    }
}
```

`Http/Requests/ContatoRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ContatoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'canal' => ['required', 'in:WHATSAPP,EMAIL'],
            'cliente_id' => ['required_without:negocio_id', 'nullable', 'uuid'],
            'negocio_id' => ['nullable', 'uuid'],
        ];
    }
}
```

`Http/Requests/NotaRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class NotaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['texto' => ['required', 'string', 'min:1', 'max:2000']];
    }
}
```

`Http/Controllers/AtividadesController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AtualizarAtividade;
use App\Modules\Crm\Application\Actions\ConcluirAtividade;
use App\Modules\Crm\Application\Actions\CriarAtividade;
use App\Modules\Crm\Application\Actions\ExcluirAtividade;
use App\Modules\Crm\Application\Actions\ListarAgenda;
use App\Modules\Crm\Application\Actions\ReabrirAtividade;
use App\Modules\Crm\Application\Actions\ResumoDoCrm;
use App\Modules\Crm\Application\ApresentadorDeAtividades;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Http\Requests\AtividadeRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AtividadesController
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ApresentadorDeAtividades $apresentador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $autor = $this->autor($request);
        $this->politica->garantirPodeLer($autor);

        $request->validate([
            'cliente_id' => ['nullable', 'uuid'],
            'negocio_id' => ['nullable', 'uuid'],
            'responsavel_id' => ['nullable', 'uuid'],
            'concluida' => ['nullable', 'boolean'],
        ]);

        $consulta = Atividade::query()->visiveisPara($autor);
        foreach (['cliente_id', 'negocio_id', 'responsavel_id'] as $filtro) {
            if ($request->filled($filtro)) {
                $consulta->where($filtro, $request->string($filtro)->toString());
            }
        }
        if ($request->filled('concluida')) {
            $request->boolean('concluida') ? $consulta->whereNotNull('concluida_em') : $consulta->whereNull('concluida_em');
        }

        $lista = $consulta->orderBy('agendada_para')->orderBy('id')->limit(200)->get();

        return response()->json(['data' => $this->apresentador->apresentar($lista)]);
    }

    public function agenda(Request $request, ListarAgenda $listar): JsonResponse
    {
        $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'responsavel_id' => ['nullable', 'uuid'],
        ]);

        $fuso = (string) config('app.fuso_negocio');
        $de = $request->filled('de') ? Carbon::createFromFormat('Y-m-d', $request->string('de')->toString(), $fuso) : now($fuso);
        $ate = $request->filled('ate') ? Carbon::createFromFormat('Y-m-d', $request->string('ate')->toString(), $fuso) : now($fuso)->addDays(6);

        $lista = $listar->executar(
            $this->autor($request),
            $de->startOfDay(),
            $ate->endOfDay(),
            $request->filled('responsavel_id') ? $request->string('responsavel_id')->toString() : null,
        );

        return response()->json(['data' => $this->apresentador->apresentar($lista)]);
    }

    public function resumo(Request $request, ResumoDoCrm $resumo): JsonResponse
    {
        return response()->json(['data' => $resumo->executar($this->autor($request))]);
    }

    public function store(AtividadeRequest $request, CriarAtividade $criar): JsonResponse
    {
        /** @var array{tipo: string, titulo: string, agendada_para: string} $dados */
        $dados = $request->validated();

        return $this->uma($criar->executar($this->autor($request), $dados), 201);
    }

    public function update(AtividadeRequest $request, string $id, AtualizarAtividade $atualizar): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->uma($atualizar->executar($autor, $this->doUsuario($autor, $id), $request->validated()));
    }

    public function destroy(Request $request, string $id, ExcluirAtividade $excluir): Response
    {
        $autor = $this->autor($request);
        $excluir->executar($autor, $this->doUsuario($autor, $id));

        return response()->noContent();
    }

    public function concluir(Request $request, string $id, ConcluirAtividade $concluir): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->uma($concluir->executar($autor, $this->doUsuario($autor, $id)));
    }

    public function reabrir(Request $request, string $id, ReabrirAtividade $reabrir): JsonResponse
    {
        $autor = $this->autor($request);

        return $this->uma($reabrir->executar($autor, $this->doUsuario($autor, $id)));
    }

    /** 404 (e não 403) para atividade fora da visibilidade do usuário. */
    private function doUsuario(Usuario $autor, string $id): Atividade
    {
        return Atividade::query()->visiveisPara($autor)->findOrFail($id);
    }

    private function uma(Atividade $atividade, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->apresentador->apresentar([$atividade])[0]], $status);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

Ressalva: as Actions já chamam `garantirPodeOperarNegocios`, mas `doUsuario` roda antes da Action; Fiscal/Leitura chegam a `doUsuario` primeiro. Isso é aceitável porque a Action recusa em seguida com 403. Para o papel Fiscal no GET `agenda`, `ListarAgenda` recusa (403).

`Http/Controllers/TimelineController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\CriarNotaDoCliente;
use App\Modules\Crm\Application\Actions\ListarTimelineDoCliente;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Crm\Http\Requests\NotaRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TimelineController
{
    public function __construct(private readonly DiretorioDeUsuarios $usuarios) {}

    public function index(Request $request, string $id, ListarTimelineDoCliente $listar): JsonResponse
    {
        $pagina = $listar->executar($this->autor($request), $id);
        $registros = $pagina->items();

        return response()->json([
            'data' => $this->apresentar($registros),
            'next_cursor' => $pagina->nextCursor()?->encode(),
        ]);
    }

    public function nota(NotaRequest $request, string $id, CriarNotaDoCliente $criar): JsonResponse
    {
        $registro = $criar->executar($this->autor($request), $id, $request->string('texto')->toString());

        return response()->json(['data' => $this->apresentar([$registro])[0]], 201);
    }

    /**
     * @param  iterable<RegistroDaTimeline>  $registros
     * @return list<array<string, mixed>>
     */
    private function apresentar(iterable $registros): array
    {
        $lista = [...$registros];
        $nomes = $this->usuarios->nomes(array_values(array_unique(array_filter(array_map(fn (RegistroDaTimeline $r): ?string => $r->autor_id, $lista)))));

        return array_map(fn (RegistroDaTimeline $r): array => [
            'id' => $r->id,
            'tipo' => $r->tipo->value,
            'texto' => $r->texto,
            'dados' => $r->dados,
            'autor' => $r->autor_id !== null && isset($nomes[$r->autor_id]) ? ['id' => $r->autor_id, 'nome' => $nomes[$r->autor_id]] : null,
            'negocio_id' => $r->negocio_id,
            'atividade_id' => $r->atividade_id,
            'ocorreu_em' => $r->ocorreu_em->toIso8601String(),
        ], $lista);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

`Http/Controllers/ContatosController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\RegistrarContato;
use App\Modules\Crm\Application\ApresentadorDeAtividades;
use App\Modules\Crm\Http\Requests\ContatoRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;

final class ContatosController
{
    public function store(ContatoRequest $request, RegistrarContato $registrar, ApresentadorDeAtividades $apresentador): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        $resultado = $registrar->executar(
            $autor,
            $request->string('canal')->toString(),
            $request->filled('cliente_id') ? $request->string('cliente_id')->toString() : null,
            $request->filled('negocio_id') ? $request->string('negocio_id')->toString() : null,
        );

        return response()->json(['data' => [
            'atividade' => $apresentador->apresentar([$resultado['atividade']])[0],
            'url' => $resultado['url'],
        ]], 201);
    }
}
```

Em `routes-app.php` (dentro do grupo existente) acrescentar os imports e:

```php
    Route::get('atividades', [AtividadesController::class, 'index'])->name('app.crm.atividades.index');
    Route::post('atividades', [AtividadesController::class, 'store'])->name('app.crm.atividades.store');
    Route::put('atividades/{id}', [AtividadesController::class, 'update'])->name('app.crm.atividades.update');
    Route::delete('atividades/{id}', [AtividadesController::class, 'destroy'])->name('app.crm.atividades.destroy');
    Route::post('atividades/{id}/concluir', [AtividadesController::class, 'concluir'])->name('app.crm.atividades.concluir');
    Route::post('atividades/{id}/reabrir', [AtividadesController::class, 'reabrir'])->name('app.crm.atividades.reabrir');
    Route::get('agenda', [AtividadesController::class, 'agenda'])->name('app.crm.agenda');
    Route::get('resumo', [AtividadesController::class, 'resumo'])->name('app.crm.resumo');
    Route::post('contatos', [ContatosController::class, 'store'])->name('app.crm.contatos.store');
    Route::get('clientes/{id}/timeline', [TimelineController::class, 'index'])->name('app.crm.timeline.index');
    Route::post('clientes/{id}/notas', [TimelineController::class, 'nota'])->name('app.crm.timeline.nota');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AtividadesApiTest`; depois `php artisan test --filter=Crm`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`.
Expected: PASS. Ajustar o assert de `code` se o tratador de erros da F7a usar outra chave para o código (conferir o 422 de `RegraDoCrmException` em `NegociosApiTest`).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Crm backend/tests/Feature/Crm/AtividadesApiTest.php
git commit -m "feat(crm): API de atividades, agenda, resumo, contatos e timeline do cliente"
```

---

### Task 7: Frontend, camada de dados (tipos, api, hooks) e campo da etapa

**Files:**
- Modify: `frontend/features/crm/types.ts`, `frontend/features/crm/api.ts`, `frontend/features/crm/components/EditorDeFunis.tsx`
- Create: `frontend/features/crm/hooks/useAtividades.ts`, `frontend/features/crm/hooks/useTimeline.ts`
- Test: `frontend/features/crm/hooks/useAtividades.test.tsx`

**Interfaces:**
- Produces (types): `TipoDeAtividade`, `Atividade`, `RegistroDaTimeline`, `ResumoDoCrm`, `ContatoResultado`; `Etapa.dias_para_parado: number | null`; `Negocio.parado: boolean`, `Negocio.dias_parado: number | null`.
- Produces (api): `crmApi.atividades(filtros)`, `.agenda(de, ate, responsavelId?)`, `.resumo()`, `.criarAtividade(dados)`, `.editarAtividade(id, dados)`, `.concluirAtividade(id)`, `.reabrirAtividade(id)`, `.excluirAtividade(id)`, `.registrarContato(canal, ids)`, `.timeline(clienteId, cursor?)`, `.criarNota(clienteId, texto)`; `crmApi.editarEtapa` aceita `dias_para_parado`.
- Produces (hooks): `useAgenda(de, ate)`, `useAtividadesDoNegocio(negocioId)`, `useResumoDoCrm()`, `useConcluirAtividade()`, `useReabrirAtividade()`, `useCriarAtividade()`, `useTimelineDoCliente(clienteId)`, `useContato()`, `useCriarNota(clienteId)`; chaves em `QUERY_KEY_ATIVIDADES`.

- [ ] **Step 1: Write the failing test**

Criar `frontend/features/crm/hooks/useAtividades.test.tsx` seguindo o padrão de `useNegocios`/`Kanban.test.tsx` (wrapper com `QueryClientProvider`; mock de `crmApi` com `vi.mock('../api')`):

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import { useContato, useResumoDoCrm } from './useAtividades';

vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return {
    ...real,
    crmApi: {
      ...real.crmApi,
      resumo: vi.fn(async () => ({ data: { hoje: 2, atrasadas: 1, parados: 3 } })),
      registrarContato: vi.fn(async () => ({
        data: { url: 'https://wa.me/5511999990000', atividade: { id: 'a1' } },
      })),
    },
  };
});

function envoltorio() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

describe('hooks de atividades', () => {
  it('carrega o resumo do CRM', async () => {
    const { result } = renderHook(() => useResumoDoCrm(), { wrapper: envoltorio() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toEqual({ hoje: 2, atrasadas: 1, parados: 3 });
  });

  it('abre o canal só depois do registro e devolve a url', async () => {
    const abrir = vi.fn();
    vi.stubGlobal('open', abrir);
    const { result } = renderHook(() => useContato(), { wrapper: envoltorio() });

    result.current.mutate({ canal: 'WHATSAPP', clienteId: 'c1' });

    await waitFor(() => expect(abrir).toHaveBeenCalledWith('https://wa.me/5511999990000', '_blank', 'noopener,noreferrer'));
    expect(crmApi.registrarContato).toHaveBeenCalledWith('WHATSAPP', { cliente_id: 'c1', negocio_id: undefined });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (em `frontend/`): `npx vitest run features/crm/hooks/useAtividades.test.tsx`
Expected: FAIL (módulo `./useAtividades` não existe).

- [ ] **Step 3: Write minimal implementation**

Em `types.ts` acrescentar `dias_para_parado: number | null;` a `Etapa`, `parado: boolean; dias_parado: number | null;` a `Negocio` e, ao final:

```ts
export type TipoDeAtividade = 'LIGACAO' | 'REUNIAO' | 'EMAIL' | 'WHATSAPP' | 'TAREFA';

export interface Atividade {
  id: string;
  tipo: TipoDeAtividade;
  titulo: string;
  descricao: string | null;
  agendada_para: string;
  concluida_em: string | null;
  atrasada: boolean;
  cliente: { id: string; nome: string; telefone: string | null; email: string | null } | null;
  negocio: { id: string; titulo: string } | null;
  responsavel: { id: string; nome: string } | null;
}

export type TipoDeRegistro =
  | 'NOTA' | 'ATIVIDADE_CONCLUIDA' | 'NEGOCIO_CRIADO' | 'NEGOCIO_MOVIDO' | 'NEGOCIO_GANHO' | 'NEGOCIO_PERDIDO';

export interface RegistroDaTimeline {
  id: string;
  tipo: TipoDeRegistro;
  texto: string;
  dados: Record<string, unknown>;
  autor: { id: string; nome: string } | null;
  negocio_id: string | null;
  atividade_id: string | null;
  ocorreu_em: string;
}

export interface ResumoDoCrm {
  hoje: number;
  atrasadas: number;
  parados: number;
}

export interface NovaAtividadePayload {
  tipo: TipoDeAtividade;
  titulo: string;
  descricao?: string;
  agendada_para: string;
  cliente_id?: string;
  negocio_id?: string;
  responsavel_id?: string;
}
```

Em `api.ts`: importar os tipos novos, trocar o tipo de `editarEtapa` para `{ nome?: string; probabilidade?: number; ativa?: boolean; dias_para_parado?: number | null }` e acrescentar ao objeto `crmApi`:

```ts
  atividades: (filtros: { cliente_id?: string; negocio_id?: string; concluida?: boolean }) => {
    const q = new URLSearchParams();
    if (filtros.cliente_id) q.set('cliente_id', filtros.cliente_id);
    if (filtros.negocio_id) q.set('negocio_id', filtros.negocio_id);
    if (filtros.concluida !== undefined) q.set('concluida', filtros.concluida ? '1' : '0');
    return api<{ data: Atividade[] }>(`${BASE}/atividades?${q.toString()}`);
  },
  agenda: (de: string, ate: string, responsavelId?: string) => {
    const q = new URLSearchParams({ de, ate });
    if (responsavelId) q.set('responsavel_id', responsavelId);
    return api<{ data: Atividade[] }>(`${BASE}/agenda?${q.toString()}`);
  },
  resumo: () => api<{ data: ResumoDoCrm }>(`${BASE}/resumo`),
  criarAtividade: (dados: NovaAtividadePayload) =>
    api<{ data: Atividade }>(`${BASE}/atividades`, { method: 'POST', body: json(dados) }),
  editarAtividade: (id: string, dados: Partial<Omit<NovaAtividadePayload, 'cliente_id' | 'negocio_id'>>) =>
    api<{ data: Atividade }>(`${BASE}/atividades/${id}`, { method: 'PUT', body: json(dados) }),
  concluirAtividade: (id: string) => api<{ data: Atividade }>(`${BASE}/atividades/${id}/concluir`, { method: 'POST' }),
  reabrirAtividade: (id: string) => api<{ data: Atividade }>(`${BASE}/atividades/${id}/reabrir`, { method: 'POST' }),
  excluirAtividade: (id: string) => api<void>(`${BASE}/atividades/${id}`, { method: 'DELETE' }),
  registrarContato: (canal: 'WHATSAPP' | 'EMAIL', alvo: { cliente_id?: string; negocio_id?: string }) =>
    api<{ data: { atividade: Atividade; url: string } }>(`${BASE}/contatos`, { method: 'POST', body: json({ canal, ...alvo }) }),
  timeline: (clienteId: string, cursor?: string) =>
    api<{ data: RegistroDaTimeline[]; next_cursor: string | null }>(
      `${BASE}/clientes/${clienteId}/timeline${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`,
    ),
  criarNota: (clienteId: string, texto: string) =>
    api<{ data: RegistroDaTimeline }>(`${BASE}/clientes/${clienteId}/notas`, { method: 'POST', body: json({ texto }) }),
```

Antes de usar `api<void>` no DELETE, conferir em `frontend/lib/api.ts` como respostas 204 são tratadas; se `api` sempre faz `.json()`, tratar o 204 lá (retornar `undefined` quando `status === 204`) com teste.

`frontend/features/crm/hooks/useAtividades.ts`:

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { Atividade, NovaAtividadePayload, ResumoDoCrm } from '../types';

export const QUERY_KEY_ATIVIDADES = [...QUERY_KEY_CRM, 'atividades'] as const;

const mensagem = (erro: unknown, padrao: string) => (erro instanceof ApiError ? erro.message : padrao);

export function useAgenda(de: string, ate: string, responsavelId?: string) {
  return useQuery<Atividade[]>({
    queryKey: [...QUERY_KEY_ATIVIDADES, 'agenda', de, ate, responsavelId ?? ''],
    queryFn: async () => (await crmApi.agenda(de, ate, responsavelId)).data,
  });
}

export function useAtividadesDoNegocio(negocioId: string) {
  return useQuery<Atividade[]>({
    queryKey: [...QUERY_KEY_ATIVIDADES, 'negocio', negocioId],
    queryFn: async () => (await crmApi.atividades({ negocio_id: negocioId })).data,
  });
}

export function useResumoDoCrm() {
  return useQuery<ResumoDoCrm>({
    queryKey: [...QUERY_KEY_ATIVIDADES, 'resumo'],
    queryFn: async () => (await crmApi.resumo()).data,
    refetchInterval: 60_000,
  });
}

function useInvalidarAtividades() {
  const queryClient = useQueryClient();
  // Atividade mexe na agenda, no resumo, na timeline e no "parado" dos negócios.
  return () => queryClient.invalidateQueries({ queryKey: QUERY_KEY_CRM });
}

export function useCriarAtividade() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: (dados: NovaAtividadePayload) => crmApi.criarAtividade(dados),
    onSuccess: () => {
      toast.success('Atividade criada.');
      void invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível criar a atividade.')),
  });
}

export function useConcluirAtividade() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: (id: string) => crmApi.concluirAtividade(id),
    onSuccess: () => {
      toast.success('Atividade concluída.');
      void invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível concluir.')),
  });
}

export function useReabrirAtividade() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: (id: string) => crmApi.reabrirAtividade(id),
    onSuccess: () => void invalidar(),
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível reabrir.')),
  });
}

export function useExcluirAtividade() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: (id: string) => crmApi.excluirAtividade(id),
    onSuccess: () => void invalidar(),
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível excluir.')),
  });
}

/** Registra o contato e só então abre o canal; o servidor bloqueia dado inválido com mensagem acionável. */
export function useContato() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: ({ canal, clienteId, negocioId }: { canal: 'WHATSAPP' | 'EMAIL'; clienteId?: string; negocioId?: string }) =>
      crmApi.registrarContato(canal, { cliente_id: clienteId, negocio_id: negocioId }),
    onSuccess: ({ data }) => {
      window.open(data.url, '_blank', 'noopener,noreferrer');
      void invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível registrar o contato.')),
  });
}
```

`frontend/features/crm/hooks/useTimeline.ts`:

```ts
import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';

export function useTimelineDoCliente(clienteId: string) {
  return useInfiniteQuery({
    queryKey: [...QUERY_KEY_CRM, 'timeline', clienteId],
    queryFn: ({ pageParam }) => crmApi.timeline(clienteId, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (ultima) => ultima.next_cursor ?? undefined,
  });
}

export function useCriarNota(clienteId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (texto: string) => crmApi.criarNota(clienteId, texto),
    onSuccess: () => {
      toast.success('Nota registrada.');
      void queryClient.invalidateQueries({ queryKey: [...QUERY_KEY_CRM, 'timeline', clienteId] });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Não foi possível salvar a nota.'),
  });
}
```

Em `EditorDeFunis.tsx`: na linha de edição de cada etapa, ao lado da probabilidade, acrescentar um campo numérico "Dias para parado" (vazio = sem alerta, 1 a 365) que chama `crmApi.editarEtapa(id, { dias_para_parado })` com o mesmo mecanismo de salvar que a probabilidade já usa (ler o componente e copiar o padrão; não criar mecanismo novo). Teste: em `ConfiguracoesDoCrm.test.tsx`, preencher 7 e verificar a chamada `editarEtapa('<id>', { dias_para_parado: 7 })` e que vazio envia `null`.

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run features/crm` e `npx tsc --noEmit`
Expected: PASS, sem erro de tipo (`Negocio` e `Etapa` ganharam campos: ajustar fixtures de teste da F7a que montam esses objetos).

- [ ] **Step 5: Commit**

```bash
git add frontend/features/crm
git commit -m "feat(crm): tipos, api e hooks de atividades e timeline; dias para parado na etapa"
```

---

### Task 8: Frontend, agenda e contador no menu

**Files:**
- Create: `frontend/features/crm/components/AgendaDoCrm.tsx`, `FormularioDeAtividade.tsx`, `ItemDeAtividade.tsx`
- Create: `frontend/app/(app)/crm/agenda/page.tsx`
- Modify: `frontend/components/layout/nav-items.ts` (+ teste `nav-items.test.ts`)
- Test: `frontend/features/crm/components/AgendaDoCrm.test.tsx`

**Interfaces:**
- Consumes: `useAgenda`, `useResumoDoCrm`, `useConcluirAtividade`, `useReabrirAtividade`, `useCriarAtividade`, tipos da Task 7, `formatarData` de `@/lib/formatos`.
- Produces: `AgendaDoCrm` (página inteira), `FormularioDeAtividade({ clienteId?, negocioId?, aoCriar })`, `ItemDeAtividade({ atividade, podeEscrever })`.

- [ ] **Step 1: Write the failing test**

Criar `AgendaDoCrm.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { Atividade } from '../types';
import { AgendaDoCrm } from './AgendaDoCrm';

let papel = 'ADMIN';
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel } }) }));
vi.mock('next/link', () => ({
  default: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
}));
vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return {
    ...real,
    crmApi: {
      ...real.crmApi,
      agenda: vi.fn(),
      concluirAtividade: vi.fn(async () => ({ data: {} })),
      criarAtividade: vi.fn(),
    },
  };
});

function atividade(extra: Partial<Atividade>): Atividade {
  return {
    id: 'a1', tipo: 'LIGACAO', titulo: 'Ligar', descricao: null, agendada_para: '2026-10-14T15:00:00Z',
    concluida_em: null, atrasada: false, cliente: { id: 'c1', nome: 'Ana', telefone: null, email: null },
    negocio: null, responsavel: { id: 'u1', nome: 'Dono' }, ...extra,
  };
}

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><AgendaDoCrm /></QueryClientProvider>);
}

describe('AgendaDoCrm', () => {
  beforeEach(() => {
    papel = 'ADMIN';
    vi.clearAllMocks();
  });

  it('separa atrasadas das do período', async () => {
    vi.mocked(crmApi.agenda).mockResolvedValue({
      data: [atividade({ id: 'a1', titulo: 'Atrasada X', atrasada: true }), atividade({ id: 'a2', titulo: 'De hoje Y' })],
    });
    renderizar();

    expect(await screen.findByText('Atrasadas (1)')).toBeInTheDocument();
    expect(screen.getByText('Atrasada X')).toBeInTheDocument();
    expect(screen.getByText('De hoje Y')).toBeInTheDocument();
  });

  it('conclui a atividade pelo botão', async () => {
    vi.mocked(crmApi.agenda).mockResolvedValue({ data: [atividade({ id: 'a1', titulo: 'Ligar' })] });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: /concluir/i }));

    expect(crmApi.concluirAtividade).toHaveBeenCalledWith('a1');
  });

  it('papel de leitura não vê concluir nem nova atividade', async () => {
    papel = 'LEITURA';
    vi.mocked(crmApi.agenda).mockResolvedValue({ data: [atividade({})] });
    renderizar();

    await screen.findByText('Ligar');
    expect(screen.queryByRole('button', { name: /concluir/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /nova atividade/i })).not.toBeInTheDocument();
  });

  it('mostra estado vazio', async () => {
    vi.mocked(crmApi.agenda).mockResolvedValue({ data: [] });
    renderizar();

    expect(await screen.findByText('Nenhuma atividade neste período.')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run features/crm/components/AgendaDoCrm.test.tsx`
Expected: FAIL (componente inexistente).

- [ ] **Step 3: Write minimal implementation**

Antes: ler `frontend/node_modules/next/dist/docs/` (convenções de página) e `frontend/app/(app)/crm/negocios/page.tsx`; a página nova segue o mesmo formato.

`frontend/app/(app)/crm/agenda/page.tsx`:

```tsx
import { AgendaDoCrm } from '@/features/crm/components/AgendaDoCrm';

export default function AgendaPage() {
  return <AgendaDoCrm />;
}
```

`ItemDeAtividade.tsx`:

```tsx
'use client';

import { Check, RotateCcw } from 'lucide-react';
import Link from 'next/link';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useConcluirAtividade, useReabrirAtividade } from '../hooks/useAtividades';
import type { Atividade, TipoDeAtividade } from '../types';

const ROTULO: Record<TipoDeAtividade, string> = {
  LIGACAO: 'Ligação', REUNIAO: 'Reunião', EMAIL: 'E-mail', WHATSAPP: 'WhatsApp', TAREFA: 'Tarefa',
};

function quando(iso: string): string {
  return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}

export function ItemDeAtividade({ atividade, podeEscrever }: { atividade: Atividade; podeEscrever: boolean }) {
  const concluir = useConcluirAtividade();
  const reabrir = useReabrirAtividade();
  const concluida = atividade.concluida_em !== null;

  return (
    <li className="flex items-start justify-between gap-3 rounded-lg border border-border bg-card p-3 text-sm">
      <div className="min-w-0 space-y-1">
        <p className={cn('font-semibold', concluida && 'line-through text-muted-foreground')}>
          <span className="mr-2 rounded bg-muted px-1.5 py-0.5 text-xs font-medium">{ROTULO[atividade.tipo]}</span>
          {atividade.titulo}
        </p>
        <p className={cn('text-xs', atividade.atrasada ? 'text-danger' : 'text-muted-foreground')}>
          {quando(atividade.agendada_para)}
          {atividade.atrasada ? ' · atrasada' : ''}
          {atividade.responsavel ? ` · ${atividade.responsavel.nome}` : ''}
        </p>
        <p className="truncate text-xs text-muted-foreground">
          {atividade.cliente ? (
            <Link href={`/crm/clientes/${atividade.cliente.id}`} className="underline">{atividade.cliente.nome}</Link>
          ) : null}
          {atividade.negocio ? (
            <>
              {' · '}
              <Link href={`/crm/negocios/${atividade.negocio.id}`} className="underline">{atividade.negocio.titulo}</Link>
            </>
          ) : null}
        </p>
      </div>
      {podeEscrever ? (
        concluida ? (
          <Button variant="outline" size="sm" disabled={reabrir.isPending} onClick={() => reabrir.mutate(atividade.id)}>
            <RotateCcw className="size-4" aria-hidden /> Reabrir
          </Button>
        ) : (
          <Button size="sm" disabled={concluir.isPending} onClick={() => concluir.mutate(atividade.id)}>
            <Check className="size-4" aria-hidden /> Concluir
          </Button>
        )
      ) : null}
    </li>
  );
}
```

`FormularioDeAtividade.tsx`: formulário RHF + Zod (copiar o padrão de `NovoNegocioForm.tsx`, incluindo `useEnvioUnico`): campos `tipo` (select com os 5 tipos), `titulo`, `data` e `hora` (inputs `date` e `time`, hora padrão 09:00), `descricao` opcional. Ao enviar monta `agendada_para` com o fuso do navegador (`new Date(\`${data}T${hora}\`).toISOString()`) e chama `useCriarAtividade().mutate({ tipo, titulo, descricao, agendada_para, cliente_id: clienteId, negocio_id: negocioId }, { onSuccess: aoCriar })`. Esquema Zod: `titulo` 1 a 150, `data` obrigatória, `hora` obrigatória, `descricao` até 2000. Botão com `disabled={isPending}`.

`AgendaDoCrm.tsx`:

```tsx
'use client';

import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { useAgenda } from '../hooks/useAtividades';
import { FormularioDeAtividade } from './FormularioDeAtividade';
import { ItemDeAtividade } from './ItemDeAtividade';

const PAPEIS_QUE_ESCREVEM = ['PROPRIETARIO', 'ADMIN', 'VENDEDOR'];

function dataLocal(dias: number): string {
  const d = new Date();
  d.setDate(d.getDate() + dias);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function AgendaDoCrm() {
  const { data: usuario } = useUsuario();
  const podeEscrever = Boolean(usuario && PAPEIS_QUE_ESCREVEM.includes(usuario.papel));
  const [semana, setSemana] = useState(false);
  const [criando, setCriando] = useState(false);
  const hoje = dataLocal(0);
  const ate = dataLocal(semana ? 6 : 0);
  const agenda = useAgenda(hoje, ate);

  if (agenda.isError) return <Banner tom="perigo">Não foi possível carregar a agenda.</Banner>;

  const atividades = agenda.data ?? [];
  const atrasadas = atividades.filter((a) => a.atrasada);
  const doPeriodo = atividades.filter((a) => !a.atrasada);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="font-display text-[28px] leading-tight font-extrabold">Agenda</h1>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setSemana((v) => !v)}>{semana ? 'Ver só hoje' : 'Ver a semana'}</Button>
          {podeEscrever ? <Button onClick={() => setCriando((v) => !v)}>Nova atividade</Button> : null}
        </div>
      </div>

      {criando ? (
        <Card><CardContent className="pt-4"><FormularioDeAtividade aoCriar={() => setCriando(false)} /></CardContent></Card>
      ) : null}

      {agenda.isPending ? <p className="text-muted-foreground">Carregando...</p> : null}

      {atrasadas.length > 0 ? (
        <Card>
          <CardHeader><CardTitle>Atrasadas ({atrasadas.length})</CardTitle></CardHeader>
          <CardContent>
            <ul className="space-y-2">
              {atrasadas.map((a) => <ItemDeAtividade key={a.id} atividade={a} podeEscrever={podeEscrever} />)}
            </ul>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader><CardTitle>{semana ? 'Próximos 7 dias' : 'Hoje'}</CardTitle></CardHeader>
        <CardContent>
          {doPeriodo.length === 0 && !agenda.isPending ? (
            <p className="text-sm text-muted-foreground">Nenhuma atividade neste período.</p>
          ) : (
            <ul className="space-y-2">
              {doPeriodo.map((a) => <ItemDeAtividade key={a.id} atividade={a} podeEscrever={podeEscrever} />)}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
```

Em `nav-items.ts`: adicionar, logo depois do item `/crm/negocios`, `{ href: '/crm/agenda', label: 'Agenda', icon: CalendarDays, papeis: ['PROPRIETARIO', 'ADMIN', 'VENDEDOR', 'LEITURA'], modulo: 'CRM' }` (importar `CalendarDays` de `lucide-react`). Contador de atrasadas: o item de menu ganha `emblema?: 'crm-atrasadas'`; no componente que renderiza o menu (`MenuDeNavegacao`), quando `emblema === 'crm-atrasadas'`, usar `useResumoDoCrm()` e mostrar `data.atrasadas` numa pílula se maior que 0. Ler `MenuDeNavegacao.tsx` e seu teste antes e seguir o padrão existente de renderização; atualizar `nav-items.test.ts` com o novo item.

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run features/crm components/layout`, `npx tsc --noEmit`, `npx eslint features/crm components/layout`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(crm): agenda do CRM e contador de atrasadas no menu"
```

---

### Task 9: Frontend, atividades no negócio, contato em 1 clique, timeline do cliente e selo de parado

**Files:**
- Create: `frontend/features/crm/components/{AtividadesDoNegocio,BotoesDeContato,TimelineDoCliente,SeloDeParado}.tsx`
- Create: `frontend/app/(app)/crm/clientes/[id]/page.tsx`
- Modify: `frontend/features/crm/components/{DetalheDoNegocio,CartaoDoNegocio}.tsx`
- Test: `frontend/features/crm/components/{BotoesDeContato,TimelineDoCliente,SeloDeParado}.test.tsx`

**Interfaces:**
- Consumes: hooks da Task 7, `ItemDeAtividade` e `FormularioDeAtividade` da Task 8.
- Produces: `BotoesDeContato({ clienteId?, negocioId?, podeEscrever })`, `AtividadesDoNegocio({ negocio, podeEscrever })`, `TimelineDoCliente({ clienteId, podeEscrever })`, `SeloDeParado({ dias })`.

- [ ] **Step 1: Write the failing tests**

`SeloDeParado.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SeloDeParado } from './SeloDeParado';

describe('SeloDeParado', () => {
  it('mostra os dias parado', () => {
    render(<SeloDeParado dias={5} />);
    expect(screen.getByText('Parado há 5 dias')).toBeInTheDocument();
  });

  it('usa singular com 1 dia', () => {
    render(<SeloDeParado dias={1} />);
    expect(screen.getByText('Parado há 1 dia')).toBeInTheDocument();
  });
});
```

`BotoesDeContato.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { crmApi } from '../api';
import { BotoesDeContato } from './BotoesDeContato';

const erroToast = vi.fn();
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: (m: string) => erroToast(m) } }));
vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return { ...real, crmApi: { ...real.crmApi, registrarContato: vi.fn() } };
});

function renderizar(podeEscrever: boolean) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}><BotoesDeContato clienteId="c1" podeEscrever={podeEscrever} /></QueryClientProvider>,
  );
}

describe('BotoesDeContato', () => {
  beforeEach(() => vi.clearAllMocks());

  it('registra o contato e abre o canal', async () => {
    const abrir = vi.fn();
    vi.stubGlobal('open', abrir);
    vi.mocked(crmApi.registrarContato).mockResolvedValue({ data: { url: 'https://wa.me/5511999990000', atividade: {} as never } });
    renderizar(true);

    await userEvent.click(screen.getByRole('button', { name: /whatsapp/i }));

    expect(crmApi.registrarContato).toHaveBeenCalledWith('WHATSAPP', { cliente_id: 'c1', negocio_id: undefined });
    expect(abrir).toHaveBeenCalledWith('https://wa.me/5511999990000', '_blank', 'noopener,noreferrer');
  });

  it('mostra a mensagem do servidor quando o cadastro está incompleto e não abre o canal', async () => {
    const abrir = vi.fn();
    vi.stubGlobal('open', abrir);
    vi.mocked(crmApi.registrarContato).mockRejectedValue(
      new ApiError(422, 'Cadastre o telefone do cliente com DDD (10 ou 11 dígitos) para abrir o WhatsApp.'),
    );
    renderizar(true);

    await userEvent.click(screen.getByRole('button', { name: /whatsapp/i }));

    await vi.waitFor(() => expect(erroToast).toHaveBeenCalledWith(expect.stringContaining('DDD')));
    expect(abrir).not.toHaveBeenCalled();
  });

  it('sem permissão de escrita não renderiza os botões', () => {
    renderizar(false);

    expect(screen.queryByRole('button', { name: /whatsapp/i })).not.toBeInTheDocument();
  });
});
```

Conferir a assinatura do construtor de `ApiError` em `frontend/lib/api.ts` e ajustar a chamada acima se for diferente.

`TimelineDoCliente.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { RegistroDaTimeline } from '../types';
import { TimelineDoCliente } from './TimelineDoCliente';

vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return { ...real, crmApi: { ...real.crmApi, timeline: vi.fn(), criarNota: vi.fn(async () => ({ data: {} })) } };
});

function registro(extra: Partial<RegistroDaTimeline>): RegistroDaTimeline {
  return {
    id: 'r1', tipo: 'NOTA', texto: 'Prefere à tarde', dados: {}, autor: { id: 'u1', nome: 'Dono' },
    negocio_id: null, atividade_id: null, ocorreu_em: '2026-10-14T15:00:00Z', ...extra,
  };
}

function renderizar(podeEscrever = true) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}><TimelineDoCliente clienteId="c1" podeEscrever={podeEscrever} /></QueryClientProvider>,
  );
}

describe('TimelineDoCliente', () => {
  beforeEach(() => vi.clearAllMocks());

  it('lista os registros com autor', async () => {
    vi.mocked(crmApi.timeline).mockResolvedValue({
      data: [registro({}), registro({ id: 'r2', tipo: 'NEGOCIO_MOVIDO', texto: 'Negócio movido de A para B: Brindes' })],
      next_cursor: null,
    });
    renderizar();

    expect(await screen.findByText('Prefere à tarde')).toBeInTheDocument();
    expect(screen.getByText('Negócio movido de A para B: Brindes')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /carregar mais/i })).not.toBeInTheDocument();
  });

  it('registra nota e limpa o campo', async () => {
    vi.mocked(crmApi.timeline).mockResolvedValue({ data: [], next_cursor: null });
    renderizar();

    const campo = await screen.findByLabelText('Nova nota');
    await userEvent.type(campo, 'Ligou pedindo desconto');
    await userEvent.click(screen.getByRole('button', { name: /registrar nota/i }));

    expect(crmApi.criarNota).toHaveBeenCalledWith('c1', 'Ligou pedindo desconto');
    await vi.waitFor(() => expect(campo).toHaveValue(''));
  });

  it('carrega mais pelo cursor', async () => {
    vi.mocked(crmApi.timeline)
      .mockResolvedValueOnce({ data: [registro({})], next_cursor: 'CUR' })
      .mockResolvedValueOnce({ data: [registro({ id: 'r9', texto: 'Antiga' })], next_cursor: null });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: /carregar mais/i }));

    expect(crmApi.timeline).toHaveBeenLastCalledWith('c1', 'CUR');
    expect(await screen.findByText('Antiga')).toBeInTheDocument();
  });

  it('sem permissão de escrita não mostra o formulário de nota', async () => {
    vi.mocked(crmApi.timeline).mockResolvedValue({ data: [], next_cursor: null });
    renderizar(false);

    await screen.findByText('Nada registrado para este cliente ainda.');
    expect(screen.queryByLabelText('Nova nota')).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run features/crm/components/SeloDeParado.test.tsx features/crm/components/BotoesDeContato.test.tsx features/crm/components/TimelineDoCliente.test.tsx`
Expected: FAIL (componentes inexistentes).

- [ ] **Step 3: Write minimal implementation**

`SeloDeParado.tsx`:

```tsx
export function SeloDeParado({ dias }: { dias: number }) {
  return (
    <span className="inline-block rounded-full border border-warning/40 bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">
      Parado há {dias} {dias === 1 ? 'dia' : 'dias'}
    </span>
  );
}
```

Conferir em `frontend/docs/design-system/` (ou `docs/design-system/` na raiz) os tokens `warning` e usar o componente `Pilula` existente se ele cobrir o caso; o markup acima é o mínimo e deve usar os tokens que o design system tiver.

`BotoesDeContato.tsx`:

```tsx
'use client';

import { Mail, MessageCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useContato } from '../hooks/useAtividades';

interface Props {
  clienteId?: string;
  negocioId?: string;
  podeEscrever: boolean;
}

export function BotoesDeContato({ clienteId, negocioId, podeEscrever }: Props) {
  const contato = useContato();
  if (!podeEscrever) return null;

  return (
    <div className="flex gap-2">
      <Button
        variant="outline"
        size="sm"
        disabled={contato.isPending}
        onClick={() => contato.mutate({ canal: 'WHATSAPP', clienteId, negocioId })}
      >
        <MessageCircle className="size-4" aria-hidden /> WhatsApp
      </Button>
      <Button
        variant="outline"
        size="sm"
        disabled={contato.isPending}
        onClick={() => contato.mutate({ canal: 'EMAIL', clienteId, negocioId })}
      >
        <Mail className="size-4" aria-hidden /> E-mail
      </Button>
    </div>
  );
}
```

`TimelineDoCliente.tsx`: usa `useTimelineDoCliente(clienteId)` e `useCriarNota(clienteId)`. Estrutura: `<form>` com `<textarea aria-label="Nova nota" maxLength={2000}>` e botão "Registrar nota" (`disabled` com texto vazio ou `isPending`; limpa o campo em `onSuccess`), só quando `podeEscrever`; abaixo, `<ol>` com `pages.flatMap((p) => p.data)`, cada item com rótulo do tipo (`NOTA` "Nota", `ATIVIDADE_CONCLUIDA` "Atividade", `NEGOCIO_*` "Negócio"), `registro.texto`, `registro.autor?.nome ?? 'Sistema'` e a data (`toLocaleString('pt-BR', ...)`); estado vazio "Nada registrado para este cliente ainda."; botão "Carregar mais" quando `hasNextPage`, `disabled={isFetchingNextPage}`; erro vira `Banner tom="perigo"`.

`AtividadesDoNegocio.tsx`: `useAtividadesDoNegocio(negocio.id)`; botão "Nova atividade" (só `podeEscrever` e negócio ABERTO) que alterna `FormularioDeAtividade negocioId={negocio.id}`; lista com `ItemDeAtividade`; vazio "Nenhuma atividade neste negócio."

`app/(app)/crm/clientes/[id]/page.tsx`:

```tsx
import { PaginaDoClienteNoCrm } from '@/features/crm/components/PaginaDoClienteNoCrm';

export default async function ClienteNoCrmPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <PaginaDoClienteNoCrm id={id} />;
}
```

`PaginaDoClienteNoCrm.tsx` (cliente): `useUsuario` para `podeEscrever`; `useQuery` das atividades do cliente (`crmApi.atividades({ cliente_id: id })`); cabeçalho com título "Cliente" e link "Voltar ao quadro" para `/crm/negocios`; `BotoesDeContato clienteId={id}`; card "Atividades" (lista + `FormularioDeAtividade clienteId={id}`); card "Timeline" com `TimelineDoCliente`. O nome do cliente vem da primeira atividade ou dos negócios do cliente; se não houver, o título fica "Cliente" (não existe endpoint de cliente no módulo `Crm`, e o `Crm` não importa Customers).

Em `DetalheDoNegocio.tsx`: abaixo do card Itens, acrescentar `<Card>` "Atividades" com `<AtividadesDoNegocio negocio={n} podeEscrever={podeEscrever} />`, `<BotoesDeContato clienteId={n.cliente?.id} negocioId={n.id} podeEscrever={podeEscrever && n.status === 'ABERTO'} />` no card Dados, e um link "Ver timeline do cliente" para `/crm/clientes/${n.cliente.id}` quando `n.cliente`. Se `n.parado`, mostrar `<SeloDeParado dias={n.dias_parado ?? 0} />` junto da situação. Atualizar `DetalheDoNegocio.test.tsx` só se algum teste existente quebrar por causa dos novos hooks (mockar `crmApi.atividades`).

Em `CartaoDoNegocio.tsx`: abaixo do bloco de responsável/previsão, `{negocio.parado ? <SeloDeParado dias={negocio.dias_parado ?? 0} /> : null}`. No `QuadroDeNegocios.tsx` (ou onde o filtro do kanban vive), acrescentar o checkbox "Só parados" que filtra `negocios.filter((n) => n.parado)` no cliente (a lista já vem inteira); teste em `Kanban.test.tsx`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run features/crm`, `npx tsc --noEmit`, `npx eslint .`, `npm run build`
Expected: PASS; o build lista a rota `/crm/clientes/[id]` e `/crm/agenda`.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(crm): atividades no negócio, contato em 1 clique, timeline do cliente e selo de parado"
```

---

### Task 10: Bloco CRM no painel

**Files:**
- Create: `frontend/features/crm/components/BlocoCrmNoPainel.tsx`
- Modify: `frontend/app/(app)/dashboard/page.tsx`
- Test: `frontend/features/crm/components/BlocoCrmNoPainel.test.tsx`

**Interfaces:**
- Consumes: `useResumoDoCrm` (Task 7), `useUsuario`, a forma como o painel decide mostrar `PainelFiscal` (`temFiscal`, variável existente na página).
- Produces: `BlocoCrmNoPainel()`.

- [ ] **Step 1: Write the failing test**

`BlocoCrmNoPainel.test.tsx`: `crmApi.resumo` devolve `{ hoje: 2, atrasadas: 1, parados: 3 }`. Esperar três cartões com os rótulos "Hoje", "Atrasadas" e "Parados" e os números 2, 1 e 3; cada cartão é link para `/crm/agenda` (hoje e atrasadas) e `/crm/negocios` (parados). Erro de API: nada é renderizado (o painel não quebra).

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run features/crm/components/BlocoCrmNoPainel.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Write minimal implementation**

`BlocoCrmNoPainel.tsx`: três cartões com o `StatCard` existente do design system (ler `frontend/components/ui/stat-card.tsx` ou equivalente e usar a mesma API), cada um dentro de `<Link>`. Em `dashboard/page.tsx`, depois de `{temFiscal ? <PainelFiscal /> : null}`, acrescentar `{temCrm ? <BlocoCrmNoPainel /> : null}`, onde `temCrm` segue a mesma lógica de `temFiscal` com o módulo `CRM` (copiar o cálculo existente) e o papel não é FISCAL.

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run features/crm app`, `npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(crm): bloco do CRM no painel (hoje, atrasadas e parados)"
```

---

### Task 11: Verificação final, revisão e fechamento

**Files:**
- Modify: `PROGRESSO.md`, `TAREFAS.md`

- [ ] **Step 1: Suítes completas**

Run (em `backend/`): `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`.
Run (em `frontend/`): `npx vitest run`, `npx tsc --noEmit`, `npx eslint .`, `npm run build`.
Expected: tudo verde. Anotar as contagens de testes.

- [ ] **Step 2: Varredura de regras invioláveis**

Run: `grep -rnE "\?\? 0|empty\(" backend/app/Modules/Crm` (nada novo), e conferir que nenhum arquivo novo do `Crm` importa `App\Modules\Customers`.
Run: conferir que toda rota nova tem teste de isolamento (Task 6 cobre `atividades`, `timeline`; `contatos`, `agenda` e `resumo` escopam por `visiveisPara`/`ConsultaDeClientes`).

- [ ] **Step 3: Revisão final independente**

Usar `superpowers:requesting-code-review` sobre o intervalo de commits da F7b (revisor na versão mais capaz). Corrigir Critical e Important com teste RED→GREEN; registrar os Minor em `TAREFAS.md` ("Pendências da F7b").

- [ ] **Step 4: Fechamento em `PROGRESSO.md`**

Acrescentar a seção "Fechamento da F7b" (feito, verificado, não verificado, decisões não óbvias, revisão final, minors deferidos), atualizar "Tarefa em andamento" e "Próxima tarefa" (F7c: propostas, aceite e emissão), reescrevendo "Contexto necessário" só para a F7c. Deixar explícito em "Não verificado": locks (`lockForUpdate` é no-op no SQLite), PostgreSQL, fumaça no navegador (agenda, timeline, contato), `window.open` bloqueado por popup blocker em navegador real.

- [ ] **Step 5: Commit**

```bash
git add PROGRESSO.md TAREFAS.md
git commit -m "docs: F7b concluída (atividades, agenda, timeline e negócio parado)"
```
