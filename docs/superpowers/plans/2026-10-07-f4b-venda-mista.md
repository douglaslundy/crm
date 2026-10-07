# F4b Venda mista Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução Native escolhida pelo usuário). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Receber produtos e serviços numa venda e gerar uma NF-e e uma NFS-e independentes, com o desconto geral rateado entre elas, API e tela.

**Architecture:** Tabela `vendas` agrupa duas `Nota` comuns por `notas.venda_id`. As Actions novas reaproveitam `CriarRascunhoDeNota::preencher` (gravação em duas passadas para conhecer os subtotais) e `IniciarEmissao`. Uma verificação sem efeito colateral (`IniciarEmissao::verificar`) permite decidir antes de emitir.

**Tech Stack:** Laravel 12, PHPUnit, Pint, PHPStan; Next.js, React, zod, react-hook-form, TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-07-f4b-venda-mista-design.md`

## Global Constraints

- Nunca chutar valor fiscal; zero é válido (`=== null`, nunca `empty()`/`?? 0`).
- Frontend e API chamam as mesmas Actions; nenhuma regra no controller.
- Todo Model de tenant tem `BelongsToTenant` e teste de isolamento; todo endpoint novo tem teste de isolamento.
- Schema só por migration. `declare(strict_types=1)` em todo PHP. TS strict, sem `any`.
- Testes backend: `cd backend && php artisan test --filter=<Nome>`; suíte: `php artisan test`; lint: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`. Frontend: `cd frontend && npx vitest run <arquivo>`, `npx tsc --noEmit`, `npm run lint`.
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs`. Sem push.
- Erros de negócio estendem `ErroDeNegocio` (`status()`, `codigo()`, `extras()`); o JSON sai em `bootstrap/app.php`.

## Review Focus

- Desconto de 1 centavo (resíduo): vai para a última nota que comporta, soma exata (Task 2).
- Retentativa da emissão: nota já Processando/Autorizada é pulada, não reemitida nem reverificada (Task 5).
- Uma nota bloqueada sem confirmação: nenhuma é emitida e nenhuma é alterada, nem o número da série (Task 5).
- Estouro da cota de documentos entre a verificação e a emissão da segunda nota vira bloqueio daquela nota, sem desfazer a primeira (Task 5).
- Venda de outro tenant dá 404 em todos os verbos (Task 6).

## File Structure

- `backend/database/migrations/2026_10_08_000001_create_vendas.php` (tabela e `notas.venda_id`)
- `backend/app/Modules/Fiscal/Domain/Models/Venda.php`; `Nota.php` (relação, `venda_id`)
- `backend/app/Modules/Fiscal/Application/Vendas/{GravadorDaVenda,ResultadoDaVenda}.php`
- `backend/app/Modules/Fiscal/Application/Actions/{CriarVendaMista,AtualizarVendaMista,ExcluirVendaMista,EmitirVendaMista}.php`
- `backend/app/Modules/Fiscal/Domain/Exceptions/{VendaNaoEditavelException,VendaComNotaBloqueadaException}.php`
- `backend/app/Modules/Fiscal/Http/{Controllers/VendaController,Requests/SalvarVendaRequest,Resources/VendaResource}.php`, `routes-app.php`
- `frontend/features/vendas/{types.ts,api.ts,schemas.ts,rateio.ts,components/{VendaForm,DetalheDaVenda}.tsx}`, páginas `app/(app)/vendas/{nova,[id],[id]/editar}/page.tsx`

---

### Task 1: Tabela, Model e relação

**Files:**
- Create: `backend/database/migrations/2026_10_08_000001_create_vendas.php`, `backend/app/Modules/Fiscal/Domain/Models/Venda.php`, `backend/database/factories/VendaFactory.php`, `backend/tests/Feature/Fiscal/VendaIsolamentoTest.php`
- Modify: `backend/app/Modules/Fiscal/Domain/Models/Nota.php`

**Interfaces:**
- Produces: `Venda` (uuid, `tenant_id`, `cliente_id`, `desconto_centavos`, `subtotal_centavos`, `total_centavos`, `criada_por`; fillable `cliente_id`, `desconto_centavos`, `subtotal_centavos`, `total_centavos`; `notas(): HasMany<Nota>`); `Nota::venda_id` (não fillable; só `forceFill`).

- [ ] **Step 1: Teste de isolamento (RED)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class VendaIsolamentoTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    public function test_venda_e_isolada_por_tenant(): void
    {
        $this->montarCenarioNfse();
        $venda = Venda::factory()->create(['cliente_id' => $this->cliente->id]);
        $this->assertSame(1, Venda::query()->count());

        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);

        $this->assertSame(0, Venda::query()->count());
        $this->assertNull(Venda::query()->find($venda->id));
    }
}
```

- [ ] **Step 2:** `cd backend && php artisan test --filter=VendaIsolamentoTest`. Expected: FAIL (class Venda not found).

- [ ] **Step 3: Migration, Model, Factory**

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
        Schema::create('vendas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('cliente_id');
            $table->unsignedBigInteger('desconto_centavos')->default(0);
            $table->unsignedBigInteger('subtotal_centavos')->default(0);
            $table->unsignedBigInteger('total_centavos')->default(0);
            $table->uuid('criada_por')->nullable();
            $table->timestamps();
        });
        Schema::table('notas', function (Blueprint $table): void {
            $table->uuid('venda_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropColumn('venda_id');
        });
        Schema::dropIfExists('vendas');
    }
};
```

Antes de escrever, abrir a migration de `notas` mais antiga (`grep -l "create('notas'" database/migrations/*`) e copiar o estilo de `tenant_id`/FK que ela usa (se ela declarar `foreignUuid(...)->constrained()`, usar o mesmo em `vendas.tenant_id`/`cliente_id`; o spec pede FK de `notas.venda_id` para `vendas`: `$table->foreign('venda_id')->references('id')->on('vendas')`, sem cascade; em SQLite o `Schema::table` com `foreign` é suportado no Laravel 12).

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Database\Factories\VendaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $cliente_id
 * @property int $desconto_centavos
 * @property int $subtotal_centavos
 * @property int $total_centavos
 */
class Venda extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VendaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'vendas';

    protected $fillable = ['cliente_id', 'desconto_centavos', 'subtotal_centavos', 'total_centavos'];

    /** @return HasMany<Nota, $this> */
    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    protected static function newFactory(): VendaFactory
    {
        return VendaFactory::new();
    }
}
```

Factory (`database/factories/VendaFactory.php`, mesmo estilo de `NotaFactory`): `definition()` devolve `['desconto_centavos' => 0, 'subtotal_centavos' => 0, 'total_centavos' => 0]` (o teste passa `cliente_id`; `tenant_id` vem do `BelongsToTenant` como nos demais models). Em `Nota.php` acrescentar `@property ?string $venda_id` e:

```php
    /** @return BelongsTo<Venda, $this> */
    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class);
    }
```

- [ ] **Step 4:** `php artisan test --filter=VendaIsolamentoTest`. Expected: PASS.
- [ ] **Step 5:** `vendor/bin/pint` e `git add -A backend && git commit -m "feat(vendas): tabela vendas e notas.venda_id"` (com as linhas de atribuição).

---

### Task 2: Gravador da venda (rateio entre as duas notas)

**Files:**
- Create: `backend/app/Modules/Fiscal/Application/Vendas/GravadorDaVenda.php`
- Modify: `backend/app/Modules/Fiscal/Application/Actions/CriarRascunhoDeNota.php` (parâmetro `?string $vendaId`)
- Test: `backend/tests/Feature/Fiscal/GravadorDaVendaTest.php`

**Interfaces:**
- Consumes: `CriarRascunhoDeNota::preencher(Nota, array)`, `RateioDeDesconto::ratear(int, list<int>)`.
- Produces: `CriarRascunhoDeNota::executar(array $dados, Usuario $autor, ?string $vendaId = null): Nota` (grava `venda_id` por `forceFill`); `GravadorDaVenda::gravar(Nota $produtos, Nota $servicos, array $dados): array{subtotal:int, desconto:int, total:int}` onde `$dados` tem `itens_produtos`, `itens_servicos`, `pagamentos`, `desconto_centavos`, `cliente_id`, `informacoes_complementares`.

- [ ] **Step 1: Teste (RED)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Application\Vendas\GravadorDaVenda;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class GravadorDaVendaTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function dados(int $desconto, ?int $pagamento = null): array
    {
        return [
            'cliente_id' => $this->cliente->id, 'desconto_centavos' => $desconto, 'informacoes_complementares' => null,
            'itens_produtos' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'itens_servicos' => [['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => $pagamento ?? 1000 - intdiv($desconto * 1000, 16000)]],
        ];
    }

    /** @return array{0: \App\Modules\Fiscal\Domain\Models\Nota, 1: \App\Modules\Fiscal\Domain\Models\Nota} */
    private function rascunhos(): array
    {
        $criar = app(CriarRascunhoDeNota::class);
        $base = ['cliente_id' => $this->cliente->id, 'desconto_centavos' => 0, 'pagamentos' => [['tpag' => '01', 'valor_centavos' => 1]]];
        $p = $criar->executar($base + ['modelo' => 'NFE', 'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1']]], $this->admin);
        $s = $criar->executar($base + ['modelo' => 'DPS', 'itens' => [['servico_id' => $this->servico->id, 'quantidade' => '1']]], $this->admin);

        return [$p, $s];
    }

    public function test_ratea_o_desconto_em_proporcao_aos_subtotais_e_a_soma_fecha(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        $r = app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(1600));

        $this->assertSame(['subtotal' => 16000, 'desconto' => 1600, 'total' => 14400], $r);
        $this->assertSame([100, 1500], [$p->refresh()->desconto_centavos, $s->refresh()->desconto_centavos]);
        $this->assertSame([900, 13500], [$p->total_centavos, $s->total_centavos]);
    }

    public function test_residuo_de_um_centavo_vai_para_a_ultima_nota(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(1, 1000));

        $this->assertSame([0, 1], [$p->refresh()->desconto_centavos, $s->refresh()->desconto_centavos]);
    }

    public function test_desconto_zero_e_valido_e_nao_altera_as_notas(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(0));

        $this->assertSame([0, 0], [$p->refresh()->desconto_centavos, $s->refresh()->desconto_centavos]);
        $this->assertSame([1000, 15000], [$p->total_centavos, $s->total_centavos]);
    }

    public function test_desconto_maior_que_o_subtotal_bloqueia(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        $this->expectException(EmissaoBloqueadaException::class);

        app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(16001));
    }

    public function test_desconto_igual_ao_subtotal_bloqueia_porque_a_venda_ficaria_sem_valor(): void
    {
        $this->montarCenarioNfse();
        [$p, $s] = $this->rascunhos();

        $this->expectException(EmissaoBloqueadaException::class);

        app(GravadorDaVenda::class)->gravar($p, $s, $this->dados(16000));
    }

    public function test_executar_grava_o_venda_id_quando_informado(): void
    {
        $this->montarCenarioNfse();
        $venda = \App\Modules\Fiscal\Domain\Models\Venda::factory()->create(['cliente_id' => $this->cliente->id]);

        $nota = app(CriarRascunhoDeNota::class)->executar([
            'modelo' => 'DPS', 'cliente_id' => $this->cliente->id, 'desconto_centavos' => 0,
            'itens' => [['servico_id' => $this->servico->id, 'quantidade' => '1']],
        ], $this->admin, $venda->id);

        $this->assertSame($venda->id, $nota->venda_id);
    }
}
```

- [ ] **Step 2:** `php artisan test --filter=GravadorDaVendaTest`. Expected: FAIL (GravadorDaVenda inexistente / 3º argumento).

- [ ] **Step 3: Implementar**

Em `CriarRascunhoDeNota::executar` trocar a assinatura por `executar(array $dados, Usuario $autor, ?string $vendaId = null): Nota` e, depois de `$nota->cliente_id = ...; $nota->save();` ... antes do `save()` acrescentar `$nota->venda_id = $vendaId;` (atribuição direta; `venda_id` não é fillable, mas atribuição por propriedade funciona).

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Vendas;

use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\RateioDeDesconto;

/**
 * Grava os itens das duas notas da venda e reparte o desconto geral entre elas. Duas passadas: a primeira (sem
 * desconto) calcula os subtotais pelas mesmas contas da nota comum; a segunda grava o desconto de cada uma.
 * Chamar dentro de uma transação.
 */
final class GravadorDaVenda
{
    public function __construct(private readonly CriarRascunhoDeNota $notas) {}

    /**
     * @param  array<string, mixed>  $dados
     * @return array{subtotal: int, desconto: int, total: int}
     */
    public function gravar(Nota $produtos, Nota $servicos, array $dados): array
    {
        $comum = [
            'cliente_id' => $dados['cliente_id'], 'consumidor_final' => null,
            'informacoes_complementares' => $dados['informacoes_complementares'] ?? null,
        ];
        $paraProdutos = $comum + ['itens' => $dados['itens_produtos'], 'pagamentos' => $dados['pagamentos']];
        $paraServicos = $comum + ['itens' => $dados['itens_servicos'], 'pagamentos' => []];

        $this->notas->preencher($produtos, $paraProdutos + ['desconto_centavos' => 0]);
        $this->notas->preencher($servicos, $paraServicos + ['desconto_centavos' => 0]);

        $subtotais = [$produtos->refresh()->subtotal_centavos, $servicos->refresh()->subtotal_centavos];
        $desconto = (int) $dados['desconto_centavos'];
        if ($desconto >= array_sum($subtotais)) {
            throw new EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', 'O desconto da venda deve ser menor que o total dos itens.');
        }
        [$descontoProdutos, $descontoServicos] = RateioDeDesconto::ratear($desconto, $subtotais);

        $this->notas->preencher($produtos, $paraProdutos + ['desconto_centavos' => $descontoProdutos]);
        $this->notas->preencher($servicos, $paraServicos + ['desconto_centavos' => $descontoServicos]);
        $produtos->refresh();
        $servicos->refresh();

        return [
            'subtotal' => $produtos->subtotal_centavos + $servicos->subtotal_centavos,
            'desconto' => $produtos->desconto_centavos + $servicos->desconto_centavos,
            'total' => $produtos->total_centavos + $servicos->total_centavos,
        ];
    }
}
```

- [ ] **Step 4:** `php artisan test --filter=GravadorDaVendaTest`. Expected: 6 PASS. Se `preencher` rejeitar `pagamentos => []` para serviço: ele só lê `pagamentos` quando não é serviço (confirmado no código), então passa.
- [ ] **Step 5:** pint, phpstan nos arquivos novos, commit `feat(vendas): gravador da venda com rateio do desconto entre as notas`.

---

### Task 3: CriarVendaMista, AtualizarVendaMista, ExcluirVendaMista

**Files:**
- Create: `Application/Actions/{CriarVendaMista,AtualizarVendaMista,ExcluirVendaMista}.php`, `Domain/Exceptions/VendaNaoEditavelException.php`
- Modify: `Application/Actions/AtualizarRascunhoDeNota.php`, `Application/Actions/ExcluirRascunhoDeNota.php`
- Test: `backend/tests/Feature/Fiscal/VendaMistaActionsTest.php`, `backend/tests/Fixtures/CenarioDeVenda.php`

**Interfaces:**
- Consumes: `GravadorDaVenda::gravar`, `CriarRascunhoDeNota::executar(..., ?string $vendaId)`.
- Produces: `CriarVendaMista::executar(array $dados, Usuario $autor): Venda`; `AtualizarVendaMista::executar(Venda $venda, array $dados, Usuario $autor): Venda`; `ExcluirVendaMista::executar(Venda $venda, Usuario $autor): void`; `ExcluirRascunhoDeNota::executar(Nota $nota, bool $daVenda = false)`; códigos `FISCAL_VENDA_MISTA_INCOMPLETA`, `FISCAL_VENDA_NAO_EDITAVEL`, `FISCAL_NOTA_DA_VENDA` (todos 422, via `EmissaoBloqueadaException` salvo `VendaNaoEditavelException`).

- [ ] **Step 1: Fixture e testes (RED)**

`tests/Fixtures/CenarioDeVenda.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Platform\Domain\Enums\Modulo;

/** Cenário da NF-e e da NFS-e (módulos, séries, produto, serviço e cliente). */
trait CenarioDeVenda
{
    use CenarioDeNfse;

    protected function montarCenarioVenda(): void
    {
        $this->montarCenarioNfse(Modulo::FiscalNfe);
    }

    /**
     * Produto R$ 10,00 + serviço R$ 150,00. Com desconto 0 a NF-e fecha em 1000 e a NFS-e em 15000.
     *
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    protected function corpoVenda(array $sobrescrever = []): array
    {
        return array_replace([
            'cliente_id' => $this->cliente->id, 'desconto_centavos' => 0, 'informacoes_complementares' => null,
            'itens_produtos' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'itens_servicos' => [['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $sobrescrever);
    }
}
```

`VendaMistaActionsTest` (use `CenarioDeVenda`, `RefreshDatabase`; helper `criar(array $sobrescrever = [])` = `app(CriarVendaMista::class)->executar($this->corpoVenda($sobrescrever), $this->admin)`):

```php
public function test_cria_a_venda_e_as_duas_notas_ligadas_com_o_desconto_rateado(): void
{
    $this->montarCenarioVenda();

    $venda = $this->criar(['desconto_centavos' => 1600, 'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 900]]]);

    $this->assertSame([16000, 1600, 14400], [$venda->subtotal_centavos, $venda->desconto_centavos, $venda->total_centavos]);
    $notas = $venda->notas()->orderBy('modelo')->get();
    $this->assertSame(['DPS', 'NFE'], $notas->map(fn ($n) => $n->modelo->value)->all());
    $this->assertSame([1500, 100], $notas->pluck('desconto_centavos')->all());
    $this->assertSame([13500, 900], $notas->pluck('total_centavos')->all());
    $this->assertSame([$venda->id, $venda->id], $notas->pluck('venda_id')->all());
}

public function test_sem_servico_ou_sem_produto_bloqueia(): void
{
    $this->montarCenarioVenda();
    foreach (['itens_servicos', 'itens_produtos'] as $lado) {
        try {
            $this->criar([$lado => []]);
            $this->fail('deveria bloquear');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_VENDA_MISTA_INCOMPLETA', $e->codigo());
        }
    }
    $this->assertSame(0, Venda::query()->count());
    $this->assertSame(0, Nota::query()->count());
}

public function test_plano_sem_nfse_bloqueia_sem_criar_nada(): void
{
    $this->montarCenarioVenda();
    $this->removerModuloNfse();

    $this->expectException(ModuloNaoContratadoException::class);
    try {
        $this->criar();
    } finally {
        $this->assertSame(0, Venda::query()->count());
        $this->assertSame(0, Nota::query()->count());
    }
}

public function test_atualizar_refaz_itens_e_rateio_das_duas_notas(): void
{
    $this->montarCenarioVenda();
    $venda = $this->criar();

    $venda = app(AtualizarVendaMista::class)->executar($venda, $this->corpoVenda([
        'desconto_centavos' => 1600, 'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 900]],
    ]), $this->admin);

    $this->assertSame([16000, 1600, 14400], [$venda->subtotal_centavos, $venda->desconto_centavos, $venda->total_centavos]);
    $this->assertSame(2, $venda->notas()->count());
    $this->assertSame(2, \App\Modules\Fiscal\Domain\Models\NotaItem::query()->count());
}

public function test_atualizar_so_com_as_duas_notas_em_rascunho(): void
{
    $this->montarCenarioVenda();
    $venda = $this->criar();
    $venda->notas()->where('modelo', 'DPS')->first()->forceFill(['status' => StatusNota::Processando])->save();

    $this->expectException(VendaNaoEditavelException::class);

    app(AtualizarVendaMista::class)->executar($venda, $this->corpoVenda(), $this->admin);
}

public function test_excluir_remove_a_venda_e_as_duas_notas(): void
{
    $this->montarCenarioVenda();
    $venda = $this->criar();

    app(ExcluirVendaMista::class)->executar($venda, $this->admin);

    $this->assertSame(0, Venda::query()->count());
    $this->assertSame(0, Nota::query()->count());
}

public function test_excluir_so_com_as_duas_notas_em_rascunho(): void
{
    $this->montarCenarioVenda();
    $venda = $this->criar();
    $venda->notas()->first()->forceFill(['status' => StatusNota::Autorizada])->save();

    $this->expectException(VendaNaoEditavelException::class);

    app(ExcluirVendaMista::class)->executar($venda, $this->admin);
}

public function test_nota_da_venda_nao_se_edita_nem_se_exclui_pelas_acoes_de_nota(): void
{
    $this->montarCenarioVenda();
    $nota = $this->criar()->notas()->first();

    foreach ([
        fn () => app(AtualizarRascunhoDeNota::class)->executar($nota, ['desconto_centavos' => 0], $this->admin),
        fn () => app(ExcluirRascunhoDeNota::class)->executar($nota),
    ] as $acao) {
        try {
            $acao();
            $this->fail('deveria bloquear');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_NOTA_DA_VENDA', $e->codigo());
        }
    }
}
```

(Imports no topo do arquivo de teste: `CriarVendaMista`, `AtualizarVendaMista`, `ExcluirVendaMista`, `AtualizarRascunhoDeNota`, `ExcluirRascunhoDeNota`, `EmissaoBloqueadaException`, `VendaNaoEditavelException`, `Venda`, `Nota`, `StatusNota`, `ModuloNaoContratadoException` (`App\Modules\Platform\Domain\Exceptions`).)

- [ ] **Step 2:** `php artisan test --filter=VendaMistaActionsTest`. Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

```php
// Domain/Exceptions/VendaNaoEditavelException.php
final class VendaNaoEditavelException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Só é possível alterar ou excluir a venda enquanto as duas notas estão em rascunho.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int { return 422; }

    public function codigo(): string { return 'FISCAL_VENDA_NAO_EDITAVEL'; }
}
```

`CriarVendaMista` (injeta `PoliticaDeNotas`, `EntitlementService`, `CriarRascunhoDeNota`, `GravadorDaVenda`):

```php
public function executar(array $dados, Usuario $autor): Venda
{
    $this->politica->garantirPodeRascunhar($autor);
    if (($dados['itens_produtos'] ?? []) === [] || ($dados['itens_servicos'] ?? []) === []) {
        throw new EmissaoBloqueadaException('FISCAL_VENDA_MISTA_INCOMPLETA', 'A venda mista precisa de ao menos um produto e um serviço. Com um só tipo, use a nota comum.');
    }
    $this->planos->garantirModulo($autor->tenant, Modulo::FiscalNfe);
    $this->planos->garantirModulo($autor->tenant, Modulo::FiscalNfse);

    return DB::transaction(function () use ($dados, $autor): Venda {
        $venda = new Venda;
        $venda->fill(['cliente_id' => $dados['cliente_id']]);
        $venda->forceFill(['criada_por' => $autor->id])->save();

        $base = ['cliente_id' => $dados['cliente_id'], 'desconto_centavos' => 0];
        $produtos = $this->rascunhos->executar($base + ['modelo' => 'NFE', 'itens' => $dados['itens_produtos'], 'pagamentos' => $dados['pagamentos']], $autor, $venda->id);
        $servicos = $this->rascunhos->executar($base + ['modelo' => 'DPS', 'itens' => $dados['itens_servicos']], $autor, $venda->id);

        $totais = $this->gravador->gravar($produtos, $servicos, $dados);
        $venda->forceFill([
            'desconto_centavos' => $totais['desconto'], 'subtotal_centavos' => $totais['subtotal'], 'total_centavos' => $totais['total'],
        ])->save();

        return $venda->refresh();
    });
}
```

Observação: `executar` de `CriarRascunhoDeNota` já faz `garantirModulo` por modelo (NFCE/DPS) e `garantirPodeRascunhar`; a checagem de `FiscalNfe` aqui é explícita porque ela não a faz para NFE. O `pagamentos` da primeira passada pode divergir do total, e isso só vale na emissão; o gravador regrava os itens e mantém os pagamentos do `$dados`.

`AtualizarVendaMista`:

```php
public function executar(Venda $venda, array $dados, Usuario $autor): Venda
{
    $this->politica->garantirPodeRascunhar($autor);
    if (($dados['itens_produtos'] ?? []) === [] || ($dados['itens_servicos'] ?? []) === []) {
        throw new EmissaoBloqueadaException('FISCAL_VENDA_MISTA_INCOMPLETA', 'A venda mista precisa de ao menos um produto e um serviço. Com um só tipo, use a nota comum.');
    }

    return DB::transaction(function () use ($venda, $dados): Venda {
        $venda = Venda::query()->whereKey($venda->id)->lockForUpdate()->firstOrFail();
        [$produtos, $servicos] = $this->notasEditaveis($venda);

        $totais = $this->gravador->gravar($produtos, $servicos, $dados);
        $venda->forceFill([
            'cliente_id' => $dados['cliente_id'],
            'desconto_centavos' => $totais['desconto'], 'subtotal_centavos' => $totais['subtotal'], 'total_centavos' => $totais['total'],
        ])->save();

        return $venda->refresh();
    });
}
```

`notasEditaveis(Venda): array{Nota, Nota}` fica em `GravadorDaVenda` como método público `notasEditaveis(Venda $venda): array` (travar com `lockForUpdate`, carregar as notas da venda, exigir exatamente uma `NFE` e uma `DPS` e `status === Rascunho` nas duas, senão `VendaNaoEditavelException`); `AtualizarVendaMista` e `ExcluirVendaMista` o usam. O teste de `Atualizar` mantém `cliente_id` igual; se o cliente muda, `gravar` já passa `cliente_id` às notas por `preencher`.

`ExcluirVendaMista`:

```php
public function executar(Venda $venda, Usuario $autor): void
{
    $this->politica->garantirPodeRascunhar($autor);
    DB::transaction(function () use ($venda): void {
        $venda = Venda::query()->whereKey($venda->id)->lockForUpdate()->firstOrFail();
        foreach ($this->gravador->notasEditaveis($venda) as $nota) {
            $this->exclusao->executar($nota, true);
        }
        $venda->delete();
    });
}
```

`ExcluirRascunhoDeNota::executar(Nota $nota, bool $daVenda = false)`: dentro do lock, depois de recarregar, `if ($nota->venda_id !== null && ! $daVenda) { throw new EmissaoBloqueadaException('FISCAL_NOTA_DA_VENDA', 'Esta nota pertence a uma venda mista. Altere ou exclua a venda.'); }` (antes da checagem de status). `AtualizarRascunhoDeNota::executar`: mesma guarda logo depois do lock/recarga (a Action de venda não a usa; usa `GravadorDaVenda`).

- [ ] **Step 4:** `php artisan test --filter=VendaMistaActionsTest` e depois `php artisan test --filter="RascunhoDeNota|RascunhoDeNfse|NotasApi"`. Expected: PASS (as guardas não afetam notas sem venda).
- [ ] **Step 5:** pint, phpstan, commit `feat(vendas): criar, atualizar e excluir venda mista`.

---

### Task 4: IniciarEmissao::verificar (validação sem efeito colateral)

**Files:**
- Modify: `backend/app/Modules/Fiscal/Application/Actions/IniciarEmissao.php`
- Test: `backend/tests/Feature/Fiscal/VerificarEmissaoTest.php`

**Interfaces:**
- Produces: `IniciarEmissao::verificar(Nota $nota, Usuario $autor): void` (lança a mesma exceção que `executar` lançaria; não altera nota, itens, série nem status).

- [ ] **Step 1: Testes (RED)** (use `CenarioDeVenda`):

```php
public function test_nota_apta_passa_e_nada_muda(): void
{
    $this->montarCenarioVenda();
    $nota = $this->rascunhoDeNfse();
    $antes = [$nota->refresh()->status, $nota->numero, \App\Modules\Fiscal\Domain\Models\EmitenteSerie::query()->where('modelo', 'DPS')->value('proximo_numero')];

    app(IniciarEmissao::class)->verificar($nota, $this->admin);

    $nota->refresh();
    $this->assertSame($antes, [$nota->status, $nota->numero, \App\Modules\Fiscal\Domain\Models\EmitenteSerie::query()->where('modelo', 'DPS')->value('proximo_numero')]);
}

public function test_nota_bloqueada_lanca_o_mesmo_bloqueio_da_emissao(): void
{
    $this->montarCenarioVenda();
    $nota = $this->rascunhoDeNfse(['itens' => [['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]]]);
    $this->servico->forceFill(['c_trib_nac' => null])->save(); // cadastro incompleto não muda o snapshot do rascunho
    $nota->itens()->update(['c_trib_nac' => null]);

    try {
        app(IniciarEmissao::class)->verificar($nota, $this->admin);
        $this->fail('deveria bloquear');
    } catch (EmissaoBloqueadaException $e) {
        $this->assertStringStartsWith('FISCAL_', $e->codigo());
    }
    $this->assertSame(StatusNota::Rascunho, $nota->refresh()->status);
}

public function test_nao_grava_o_snapshot_fiscal_da_nota(): void
{
    $this->montarCenarioVenda();
    $nota = $this->rascunhoDeNfse();

    app(IniciarEmissao::class)->verificar($nota, $this->admin);

    $this->assertNull($nota->refresh()->destinatario);
}

public function test_sem_permissao_de_emitir_nega(): void
{
    $this->montarCenarioVenda();
    $nota = $this->rascunhoDeNfse();
    $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

    $this->expectException(AcessoNegadoException::class);

    app(IniciarEmissao::class)->verificar($nota, $vendedor);
}
```

(Se o bloqueio do 2º teste não disparar porque o preparador lê o snapshot da nota e o cadastro do serviço, ajustar para o bloqueio mais simples: apagar o `Emitente` fiscal incompleto com `$this->emitente->forceFill(['certificado_status' => CertificadoStatus::Ausente])->save()` e esperar `EmissaoBloqueadaException`. Registre a escolha no ledger.)

- [ ] **Step 2:** `php artisan test --filter=VerificarEmissaoTest`. Expected: FAIL (método inexistente).

- [ ] **Step 3: Implementar.** Em `IniciarEmissao`, extrair das linhas `$tenant = ...` até `garantirCapacidade` um método privado:

```php
/** Os bloqueios de plano, assinatura e emitente que valem antes de preparar a nota. */
private function garantirPodeEmitirNota(Nota $nota, Usuario $autor): Emitente
{
    $tenant = $autor->tenant;
    if (! $tenant->situacao->permiteEscrita()) {
        throw AssinaturaSemEscritaException::para($tenant->situacao);
    }
    // ... exatamente o corpo atual: garantirModulo(match), Emitente::findOrFail, garantirApto*/garantirCsc, garantirCapacidade
    return $emitente;
}
```

`executar` passa a chamar `$emitente = $this->garantirPodeEmitirNota($nota, $autor);` no lugar do trecho extraído (mesma ordem de verificações). Novo método:

```php
public function verificar(Nota $nota, Usuario $autor): void
{
    $this->politica->garantirPodeEmitir($autor);
    $nota = Nota::query()->whereKey($nota->id)->firstOrFail();
    if (! $nota->status->editavel()) {
        throw new NotaNaoEditavelException('Esta nota não pode ser emitida.');
    }
    $emitente = $this->garantirPodeEmitirNota($nota, $autor);

    // `preparar` valida e congela o snapshot; aqui só a validação interessa, então tudo é desfeito.
    DB::beginTransaction();
    try {
        $nota->modelo === ModeloDocumento::Dps
            ? $this->preparadorDeServico->preparar($nota, $emitente)
            : $this->preparador->preparar($nota, $emitente);
    } finally {
        DB::rollBack();
    }
}
```

Atenção ao `RefreshDatabase` (transação do teste): `beginTransaction`/`rollBack` aninhados usam savepoint e funcionam.

- [ ] **Step 4:** `php artisan test --filter="VerificarEmissaoTest|IniciarEmissao"`. Expected: PASS (inclui `IniciarEmissaoTest` e `IniciarEmissaoNfseTest`, que provam que a extração não mudou a emissão).
- [ ] **Step 5:** pint, phpstan, commit `refactor(emissao): verificar() valida a nota sem emitir`.

---

### Task 5: EmitirVendaMista

**Files:**
- Create: `Application/Vendas/ResultadoDaVenda.php`, `Application/Actions/EmitirVendaMista.php`, `Domain/Exceptions/VendaComNotaBloqueadaException.php`
- Test: `backend/tests/Feature/Fiscal/EmitirVendaMistaTest.php`

**Interfaces:**
- Consumes: `IniciarEmissao::verificar`/`executar`, `GravadorDaVenda`.
- Produces: `EmitirVendaMista::executar(Venda $venda, Usuario $autor, bool $emitirApenasValidas = false): ResultadoDaVenda`; `ResultadoDaVenda::notas(): list<array{nota_id:string, modelo:string, status:string, bloqueio:?array{codigo:string, mensagem:string}}>`; `VendaComNotaBloqueadaException` (409, `FISCAL_VENDA_NOTA_BLOQUEADA`, `extras()['notas']` com o mesmo formato).

- [ ] **Step 1: Testes (RED)**. Fakes: o cenário de emissão usa `EmissorFake`/`EmissorDeNfseFake` ligados no container como em `IniciarEmissaoNfseTest` (copiar o `setUp` daquele teste: `Queue::fake()`; as notas passam a PROCESSANDO e o job é despachado, sem rede). Casos:

```php
public function test_emite_as_duas_notas_quando_ambas_estao_aptas(): void
{
    Queue::fake();
    $this->montarCenarioVenda();
    $venda = $this->criarVenda();

    $r = app(EmitirVendaMista::class)->executar($venda, $this->admin);

    $this->assertSame(['PROCESSANDO', 'PROCESSANDO'], array_column($r->notas(), 'status'));
    $this->assertSame([null, null], array_column($r->notas(), 'bloqueio'));
    Queue::assertPushed(EmitirNotaJob::class, 1);
    Queue::assertPushed(EmitirNfseJob::class, 1);
}

public function test_nota_bloqueada_sem_confirmacao_nao_emite_nenhuma_e_nao_gasta_numero(): void
{
    Queue::fake();
    $this->montarCenarioVenda();
    $venda = $this->criarVenda();
    $this->emitente->forceFill(['certificado_status' => CertificadoStatus::Ausente])->save();
    // a NF-e e a NFS-e dependem do certificado; para bloquear só uma, tirar a série da NF-e:
    $this->emitente->forceFill(['certificado_status' => CertificadoStatus::Valido])->save();
    $this->produto->forceFill(['ncm' => null])->save();   // ver nota abaixo

    try {
        app(EmitirVendaMista::class)->executar($venda, $this->admin);
        $this->fail('deveria bloquear');
    } catch (VendaComNotaBloqueadaException $e) {
        $this->assertSame('FISCAL_VENDA_NOTA_BLOQUEADA', $e->codigo());
        $this->assertSame(409, $e->status());
        $this->assertNotNull(collect($e->extras()['notas'])->firstWhere('modelo', 'NFE')['bloqueio']);
        $this->assertNull(collect($e->extras()['notas'])->firstWhere('modelo', 'DPS')['bloqueio']);
    }
    Queue::assertNothingPushed();
    $this->assertSame(['RASCUNHO', 'RASCUNHO'], $venda->notas()->pluck('status')->map->value->all());
    $this->assertSame(1, (int) EmitenteSerie::query()->where('modelo', 'DPS')->value('proximo_numero'));
}

public function test_com_confirmacao_emite_so_a_valida_e_devolve_o_bloqueio_da_outra(): void
{
    // mesmo preparo do teste anterior
    $r = app(EmitirVendaMista::class)->executar($venda, $this->admin, true);
    // NFS-e PROCESSANDO; NF-e RASCUNHO com bloqueio preenchido; só EmitirNfseJob despachado
}

public function test_as_duas_bloqueadas_lancam_mesmo_com_confirmacao(): void { /* bloquear NF-e (ncm nulo) e NFS-e (servico c_trib_nac nulo no snapshot) */ }

public function test_nota_ja_processando_e_pulada_na_retentativa(): void
{
    // emite a venda, depois chama executar de novo: nenhum job novo, nenhuma exceção
}

public function test_cota_estourada_na_segunda_nota_vira_bloqueio_e_nao_desfaz_a_primeira(): void
{
    // ambiente PRODUCAO, limite DocumentosMes = 1: passa na verificação (nada emitido ainda) e a 2ª falha ao emitir
    // com confirmação=true: a NF-e fica PROCESSANDO, a NFS-e RASCUNHO com bloqueio LimiteDoPlanoAtingidoException
}

public function test_vendedor_nao_emite(): void { /* AcessoNegadoException */ }
```

`criarVenda()` = `app(CriarVendaMista::class)->executar($this->corpoVenda(), $this->admin)`. Nota sobre o bloqueio da NF-e: abrir `PreparadorDaNota::exigirProduto` e usar o campo do `Produto` que ele exige primeiro (por exemplo `ncm`); como o snapshot do produto é lido na emissão, `$this->produto->forceFill([...=>null])->save()` basta. Para o caso do estouro de cota use `$this->emitente->forceFill(['ambiente_fiscal' => AmbienteFiscal::Producao])->save()` e o plano do cenário já tem `DocumentosMes => 100`; reduza criando a cota com `Plano::...comLimites([Recurso::DocumentosMes->value => 1])` e pré-popule 1 nota AUTORIZADA em PRODUCAO do mês via `ContadorDeDocumentosMes` (olhar `ContadorDeDocumentosMesTest` para a forma de contar) de modo que a 1ª nota emitida já consome o último espaço: a verificação das duas passa (a cota é lida antes de qualquer emissão) e a emissão da segunda falha.

- [ ] **Step 2:** `php artisan test --filter=EmitirVendaMistaTest`. Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

```php
// Application/Vendas/ResultadoDaVenda.php
final class ResultadoDaVenda
{
    /** @param list<array{nota_id: string, modelo: string, status: string, bloqueio: ?array{codigo: string, mensagem: string}}> $notas */
    public function __construct(private readonly array $notas) {}

    /** @return list<array{nota_id: string, modelo: string, status: string, bloqueio: ?array{codigo: string, mensagem: string}}> */
    public function notas(): array { return $this->notas; }

    public function temBloqueio(): bool { return array_filter($this->notas, fn (array $n): bool => $n['bloqueio'] !== null) !== []; }
}
```

`VendaComNotaBloqueadaException(ResultadoDaVenda $r)`: `status()` 409, `codigo()` `FISCAL_VENDA_NOTA_BLOQUEADA`, mensagem "Uma das notas da venda está com pendência. Corrija e emita de novo, ou emita só a nota válida.", `extras()` → `['notas' => $r->notas()]`.

`EmitirVendaMista`:

```php
public function executar(Venda $venda, Usuario $autor, bool $emitirApenasValidas = false): ResultadoDaVenda
{
    $this->politica->garantirPodeEmitir($autor);
    $notas = $venda->notas()->get();   // com Global Scope

    $bloqueios = [];
    foreach ($notas as $nota) {
        if (in_array($nota->status, [StatusNota::Processando, StatusNota::Autorizada], true)) {
            continue; // retentativa: já emitida
        }
        try {
            $this->emissao->verificar($nota, $autor);
        } catch (ErroDeNegocio $e) {
            $bloqueios[$nota->id] = ['codigo' => $e->codigo(), 'mensagem' => $e->getMessage()];
        }
    }

    $aptas = $notas->reject(fn (Nota $n): bool => isset($bloqueios[$n->id]));
    $emitiveis = $aptas->filter(fn (Nota $n): bool => $n->status->editavel());
    if ($bloqueios !== [] && (! $emitirApenasValidas || $emitiveis->isEmpty())) {
        throw new VendaComNotaBloqueadaException($this->resultado($notas, $bloqueios));
    }

    foreach ($emitiveis as $nota) {
        try {
            $this->emissao->executar($nota->id, $autor);
        } catch (ErroDeNegocio $e) {
            $bloqueios[$nota->id] = ['codigo' => $e->codigo(), 'mensagem' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);
            $bloqueios[$nota->id] = ['codigo' => 'FISCAL_VENDA_FALHA_AO_EMITIR', 'mensagem' => 'Não foi possível iniciar a emissão desta nota. Tente de novo.'];
        }
    }

    return $this->resultado($venda->notas()->get(), $bloqueios);
}
```

`resultado(Collection $notas, array $bloqueios): ResultadoDaVenda` monta a lista com `status->value` atual e `bloqueio => $bloqueios[$id] ?? null`. Atenção ao caso "retentativa": quando as duas já estão emitidas, `emitiveis` vazio e `bloqueios` vazio → não lança, devolve o resultado atual.

- [ ] **Step 4:** `php artisan test --filter=EmitirVendaMistaTest`. Expected: PASS.
- [ ] **Step 5:** pint, phpstan, commit `feat(vendas): emitir as duas notas da venda com verificação prévia`.

---

### Task 6: API (rotas, request, resource, controller, isolamento)

**Files:**
- Create: `Http/Requests/SalvarVendaRequest.php`, `Http/Resources/VendaResource.php`, `Http/Controllers/VendaController.php`
- Modify: `Http/routes-app.php`, `Http/Resources/NotaResource.php` (`venda_id`)
- Test: `backend/tests/Feature/Fiscal/VendasApiTest.php`

**Interfaces:**
- Produces: `POST /api/app/vendas` (201), `GET /vendas/{venda}`, `PUT /vendas/{venda}`, `DELETE /vendas/{venda}` (204), `POST /vendas/{venda}/emitir` (202 com `data.notas`; 409 no bloqueio; corpo opcional `emitir_apenas_validas: bool`). Resposta de venda: `data: {id, cliente: {id, nome}, desconto_centavos, subtotal_centavos, total_centavos, notas: [{id, modelo, status, numero, serie, subtotal_centavos, desconto_centavos, total_centavos}]}`. A `{venda}` resolve pelo Global Scope (404 de outro tenant).

- [ ] **Step 1: Testes (RED)** usando `spaComo($this->admin)`:

- `test_cria_a_venda_pela_api`: `postJson('/api/app/vendas', $this->corpoVenda(['desconto_centavos' => 1600, 'pagamentos' => [[... 900]]]))->assertCreated()` + `assertJsonPath('data.total_centavos', 14400)`, `data.notas` com 2 itens, `assertJsonCount(2, 'data.notas')`.
- `test_validacao`: sem `itens_servicos` → 422 (`FISCAL_VENDA_MISTA_INCOMPLETA` pela Action ou `itens_servicos` pela request: a request exige `min:1`, então 422 com erro de validação); `cliente_id` de outro tenant → 422; `modelo` enviado → ignorado; `pagamentos` ausente → 422.
- `test_mostra_atualiza_e_exclui`: GET devolve a venda; PUT refaz; DELETE → 204 e `Venda::count()===0`.
- `test_emitir_devolve_202_com_o_status_de_cada_nota` e `test_emitir_com_bloqueio_devolve_409_com_as_notas_e_com_confirmacao_202` (mesmo preparo da Task 5).
- `test_editar_nota_da_venda_pelas_rotas_de_nota_devolve_422_fiscal_nota_da_venda` (PUT e DELETE em `/api/app/notas/{id}`).
- `test_venda_de_outro_tenant_da_404_em_todos_os_verbos`: cria a venda no tenant A; autentica um admin do tenant B (copiar o setup de `NotasApiTest`/`NfseIsolamentoTest`); GET/PUT/DELETE/POST emitir → 404.
- `test_leitura_nao_cria_nem_emite`: papel LEITURA em POST e em emitir → 403.

- [ ] **Step 2:** `php artisan test --filter=VendasApiTest`. Expected: FAIL (404 das rotas).

- [ ] **Step 3: Implementar**

`SalvarVendaRequest::rules()` (mesmos limites de `SalvarNotaRequest`, `Rule::exists(...)->where('tenant_id', $tenantId)`):

```php
return [
    'cliente_id' => ['required', 'uuid', Rule::exists('clientes', 'id')->where('tenant_id', $tenantId)],
    'desconto_centavos' => ['required', 'integer', 'min:0'],
    'informacoes_complementares' => ['nullable', 'string', 'max:255'], // o menor limite das duas notas (DPS)
    'itens_produtos' => ['required', 'array', 'min:1', 'max:990'],
    'itens_produtos.*.produto_id' => ['required', 'uuid', Rule::exists('produtos', 'id')->where('tenant_id', $tenantId)],
    'itens_produtos.*.quantidade' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
    'itens_produtos.*.valor_unitario_centavos' => ['nullable', 'integer', 'min:0'],
    'itens_servicos' => ['required', 'array', 'min:1', 'max:990'],
    'itens_servicos.*.servico_id' => ['required', 'uuid', Rule::exists('servicos', 'id')->where('tenant_id', $tenantId)],
    'itens_servicos.*.quantidade' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
    'itens_servicos.*.valor_unitario_centavos' => ['nullable', 'integer', 'min:0'],
    'pagamentos' => ['required', 'array', 'min:1'],
    'pagamentos.*.tpag' => ['required', Rule::in(['01', '03', '04', '17', '99'])],
    'pagamentos.*.xpag' => ['nullable', 'string', 'max:60'],
    'pagamentos.*.valor_centavos' => ['required', 'integer', 'min:1'],
];
```

mais `dados()` igual ao de `SalvarNotaRequest`. `VendaResource` (mixin `Venda`, `whenLoaded('notas')` e `cliente` carregados pelo controller). Controller (`VendaController`, `final`): `store` (`CriarVendaMista`, 201), `show` (`$venda->load(['cliente', 'notas'])`), `update` (`AtualizarVendaMista`), `destroy` (`ExcluirVendaMista`, `noContent`), `emitir` (valida `emitir_apenas_validas` boolean com `$request->validate`; chama `EmitirVendaMista`; resposta `response()->json(['data' => ['id' => $venda->id, 'notas' => $resultado->notas()]], 202)`). O `autor()` é o mesmo helper de `NotaController` (copiar a mesma implementação: `/** @var Usuario */ $request->user()`; abrir o método e replicar). Rotas dentro do grupo `empresa`:

```php
Route::post('vendas', [VendaController::class, 'store'])->name('app.vendas.store');
Route::get('vendas/{venda}', [VendaController::class, 'show'])->name('app.vendas.show');
Route::put('vendas/{venda}', [VendaController::class, 'update'])->name('app.vendas.update');
Route::delete('vendas/{venda}', [VendaController::class, 'destroy'])->name('app.vendas.destroy');
Route::post('vendas/{venda}/emitir', [VendaController::class, 'emitir'])->name('app.vendas.emitir');
```

`NotaResource`: acrescentar `'venda_id' => $this->venda_id,` ao array. A leitura (papel LEITURA) é negada pelas Actions via `PoliticaDeNotas`; confira que o `AcessoNegadoException` vira 403 (como em `NotasApiTest`).

- [ ] **Step 4:** `php artisan test --filter=VendasApiTest`, depois a suíte inteira `php artisan test` e `vendor/bin/phpstan analyse` + `vendor/bin/pint --test`. Expected: tudo verde (711 + novos).
- [ ] **Step 5:** commit `feat(vendas): API da venda mista`.

---

### Task 7: Frontend base (tipos, API, schema e prévia do rateio)

**Files:**
- Create: `frontend/features/vendas/{types.ts,api.ts,rateio.ts,schemas.ts}`, `frontend/features/vendas/rateio.test.ts`, `frontend/features/vendas/schemas.test.ts`
- Modify: `frontend/features/notas/types.ts` (`venda_id: string | null` em `Nota`)

**Interfaces:**
- Produces: `ratearDesconto(descontoCentavos: number, totais: [number, number]): [number, number]` (porta exata de `RateioDeDesconto::ratear`, lança `Error('desconto')` se negativo ou maior que a soma); `vendaSchema`, `VendaFormDados`, `paraPayloadDeVenda(d): VendaPayload`; `vendasApi.{criar,buscar,editar,excluir,emitir}`; `QUERY_KEY_VENDAS`; tipos `Venda`, `VendaNota`, `ResultadoDaEmissaoDaVenda`.

- [ ] **Step 1: Testes (RED)**

```ts
// rateio.test.ts
import { describe, expect, it } from 'vitest';
import { ratearDesconto } from './rateio';

describe('ratearDesconto', () => {
  it('reparte em proporção e a soma fecha', () => expect(ratearDesconto(1600, [1000, 15000])).toEqual([100, 1500]));
  it('o resíduo de um centavo vai para o último que comporta', () => expect(ratearDesconto(1, [1000, 15000])).toEqual([0, 1]));
  it('zero é válido', () => expect(ratearDesconto(0, [1000, 15000])).toEqual([0, 0]));
  it('desconto maior que o subtotal lança', () => expect(() => ratearDesconto(16001, [1000, 15000])).toThrow());
});
```

`schemas.test.ts`: cliente obrigatório; mínimo de um produto e um serviço; quantidade com regex; `paraPayloadDeVenda` converte `'10,50'` → 1050, quantidade `'2,5'` → `'2.5'`, valor vazio → `null`, `xpag` vazio → `null`, `informacoes_complementares` vazia → `null`.

- [ ] **Step 2:** `cd frontend && npx vitest run features/vendas`. Expected: FAIL (módulos inexistentes).

- [ ] **Step 3: Implementar**

```ts
// rateio.ts: mesma aritmética inteira do backend
export function ratearDesconto(desconto: number, totais: [number, number]): [number, number] {
  const subtotal = totais[0] + totais[1];
  if (desconto < 0 || desconto > subtotal) throw new Error('desconto');
  if (desconto === 0 || subtotal === 0) return [0, 0];
  const partes: [number, number] = [Math.floor((desconto * totais[0]) / subtotal), Math.floor((desconto * totais[1]) / subtotal)];
  let residuo = desconto - partes[0] - partes[1];
  for (let i = 1; i >= 0 && residuo > 0; i--) {
    const acrescimo = Math.min(residuo, totais[i] - partes[i]);
    partes[i] += acrescimo;
    residuo -= acrescimo;
  }
  return partes;
}
```

`schemas.ts`: reaproveitar `itemSchema`/`pagamentoSchema`/`servicoItemSchema` de `features/notas/schemas.ts` (exportá-los: acrescentar `export` nas três constantes, sem mudar o comportamento) e montar `vendaSchema = z.object({ cliente_id: z.string().uuid('Escolha um cliente.'), desconto: dinheiro, informacoes_complementares: max 255, itens_produtos: z.array(itemSchema).min(1, 'Adicione ao menos um produto.'), itens_servicos: z.array(servicoItemSchema).min(1, 'Adicione ao menos um serviço.'), pagamentos: z.array(pagamentoSchema).min(1, ...) })`; `paraPayloadDeVenda` segue o mesmo molde de `paraPayload`/`paraPayloadDeServico`. `types.ts`: `VendaNota = {id, modelo: ModeloDaNota, status: StatusNota, numero, serie, subtotal_centavos, desconto_centavos, total_centavos}`; `Venda = {id, cliente: {id, nome}, desconto_centavos, subtotal_centavos, total_centavos, notas: VendaNota[]}`; `NotaDaEmissao = {nota_id, modelo, status, bloqueio: {codigo, mensagem} | null}`. `api.ts` no molde de `notasApi` (`api<{data: Venda}>('/api/app/vendas', ...)`; `emitir(id, apenasValidas)` envia `{ emitir_apenas_validas: apenasValidas }` e devolve `api<{data: {id: string; notas: NotaDaEmissao[]}}>`). Em `features/notas/types.ts` acrescentar `venda_id: string | null;` em `Nota` e corrigir os literais de `Nota` que o `tsc` apontar (fixtures dos testes: `venda_id: null`).

- [ ] **Step 4:** `npx vitest run features/vendas features/notas` e `npx tsc --noEmit`. Expected: PASS.
- [ ] **Step 5:** commit `feat(vendas): tipos, schema e prévia do rateio no frontend`.

---

### Task 8: Formulário da venda e telas de criar/editar

**Files:**
- Create: `frontend/features/vendas/components/VendaForm.tsx`, `frontend/features/vendas/components/VendaForm.test.tsx`, `frontend/app/(app)/vendas/nova/page.tsx`, `frontend/app/(app)/vendas/[id]/editar/page.tsx`
- Modify: `frontend/app/(app)/notas/page.tsx` (atalho "Nova venda (produtos e serviços)")

**Interfaces:**
- Consumes: `vendaSchema`, `paraPayloadDeVenda`, `vendasApi`, `ratearDesconto`; padrões de `NotaForm`/`NotaDeServicoForm` (ler os dois antes: `NotaForm.tsx` para o bloco de pagamentos e `paraFormulario`).
- Produces: `<VendaForm venda? />` (cria ou edita; ao salvar vai para `/vendas/{id}`).

- [ ] **Step 1: Teste (RED)** no padrão de `Notas.test.tsx` (copiar a infraestrutura de mocks de `@/lib/api`/`next/navigation`/QueryClient): (a) mostra os blocos Produtos, Serviços e Pagamentos; (b) com produto R$ 10,00, serviço R$ 150,00 e desconto 16,00 mostra a prévia "NF-e: desconto R$ 1,00, total R$ 9,00" e "NFS-e: desconto R$ 15,00, total R$ 135,00"; (c) enviar sem serviço mostra "Adicione ao menos um serviço." e não chama a API; (d) envio válido chama `POST /api/app/vendas` com `itens_produtos`, `itens_servicos` e `desconto_centavos: 1600` e navega para `/vendas/<id>`; (e) erro da API aparece no banner.

- [ ] **Step 2:** `npx vitest run features/vendas/components/VendaForm.test.tsx`. Expected: FAIL.

- [ ] **Step 3: Implementar `VendaForm`.** Estrutura de `NotaDeServicoForm` com: seletor de cliente (só estágio CLIENTE), `useFieldArray` para `itens_produtos` (campos de `NotaForm`: produto, quantidade, preço) e para `itens_servicos` (campos de `NotaDeServicoForm`), campo de desconto, informações complementares, bloco de pagamentos copiado de `NotaForm`. Prévia: com `useWatch`, subtotal de produtos e de serviços (mesma função `milesimos`/preço do cadastro dos formulários existentes; extrair `milesimos` para `features/notas/schemas.ts`? **não**: duplicar 4 linhas é aceitável, mas não duplicar a regra de rateio: usar `ratearDesconto` em `try/catch` e, se lançar, exibir "O desconto não pode passar do total dos itens."). Exibir três linhas: "NF-e: desconto X, total Y", "NFS-e: desconto X, total Y", "Total da venda: Z", e uma dica junto dos pagamentos: "Os pagamentos devem somar o total da NF-e (Y)." `onSuccess`: `toast.success('Venda salva.')`, invalidar `QUERY_KEY_VENDAS` e `QUERY_KEY_NOTAS`, `router.push('/vendas/' + id)`. Edição: `paraFormulario(venda, notas)` a partir de `GET /vendas/{id}` + `GET /notas/{id}` das duas notas (itens e pagamentos). Páginas no molde de `notas/nova/page.tsx`; a de edição lê a venda por `useQuery`, carrega as duas notas e só renderiza o formulário quando as duas estiverem em RASCUNHO (senão `Banner` "Esta venda não pode mais ser editada."). Em `notas/page.tsx` acrescentar um `Link` com o texto "Nova venda (produtos e serviços)" para `/vendas/nova` ao lado do botão de nova nota.

- [ ] **Step 4:** `npx vitest run features/vendas`, `npx tsc --noEmit`, `npm run lint`. Expected: PASS.
- [ ] **Step 5:** commit `feat(vendas): formulário da venda mista com prévia do rateio`.

---

### Task 9: Detalhe da venda, emissão com confirmação e fronteiras na tela da nota

**Files:**
- Create: `frontend/features/vendas/components/DetalheDaVenda.tsx`, `frontend/features/vendas/components/DetalheDaVenda.test.tsx`, `frontend/app/(app)/vendas/[id]/page.tsx`
- Modify: `frontend/features/notas/components/DetalheDaNota.tsx`, `frontend/features/notas/components/Notas.test.tsx`

**Interfaces:**
- Consumes: `vendasApi.buscar/emitir/excluir`, `StatusDaNota`, `ROTULO_MODELO`, `formatarCentavos`, `ApiError` (ler `ApiError` em `lib/api.ts` para saber onde ficam `codigo` e os extras do 409; se o extras `notas` não estiverem expostos, estender `ApiError` com `extras: Record<string, unknown>` e testar).
- Produces: tela `/vendas/[id]` e, em `DetalheDaNota`, link "Ver venda" e ocultação de Editar/Excluir quando `nota.venda_id !== null`.

- [ ] **Step 1: Testes (RED)**: (a) renderiza as duas notas (NF-e e NFS-e) com status e link para `/notas/{id}`; (b) "Emitir as duas" chama `POST .../emitir` com `emitir_apenas_validas: false` e mostra o status de cada nota; (c) resposta 409 `FISCAL_VENDA_NOTA_BLOQUEADA` mostra a mensagem de cada nota bloqueada e o botão "Emitir só a válida", que reenvia com `emitir_apenas_validas: true`; (d) "Excluir venda" só aparece com as duas em RASCUNHO e chama o DELETE; (e) em `DetalheDaNota`, nota com `venda_id` não mostra Editar/Excluir e mostra o link "Ver venda" (`/vendas/{venda_id}`); nota sem `venda_id` mantém o comportamento (teste existente).

- [ ] **Step 2:** `npx vitest run features/vendas features/notas`. Expected: FAIL.

- [ ] **Step 3: Implementar** (`useQuery` da venda com `refetchInterval` de 3 s enquanto alguma nota estiver PROCESSANDO, no padrão de `DetalheDaNota`; `useMutation` de emissão; estado local `bloqueios: NotaDaEmissao[]` preenchido a partir do erro 409; `Banner` por bloqueio). Botões e rótulos em pt-BR. `DetalheDaNota`: onde hoje decide os botões Editar/Excluir (procurar `RASCUNHO`/`editavel`), acrescentar `&& nota.venda_id === null` e, no cabeçalho, `nota.venda_id ? <Link href={\`/vendas/${nota.venda_id}\`}>Ver venda</Link> : null`.

- [ ] **Step 4:** `npx vitest run`, `npx tsc --noEmit`, `npm run lint`, `npm run build` (se o build for lento, ao menos `tsc` e `lint`). Expected: PASS.
- [ ] **Step 5:** commit `feat(vendas): detalhe da venda, emissão das duas notas e fronteiras na nota`.

---

### Task 10: Documentação e fechamento

**Files:**
- Modify: `TAREFAS.md` (F4b `[x]`, "Pendências da F4b": cancelar a venda inteira, NFC-e na venda mista, várias notas por tipo, homologação real da venda mista, menores adiados da revisão), `PROGRESSO.md` ("Fechamento da F4b", próxima tarefa F5, contexto necessário para a F5), memória `project_estado_atual.md` e `MEMORY.md`, `docs/referencia-fiscal/` (uma seção curta "Venda mista" no arquivo da NFS-e `02-nfephp-nfse.md`: duas notas independentes, rateio proporcional, resíduo na última).

- [ ] **Step 1:** Ler a F5 em `TAREFAS.md` e reescrever o "Contexto necessário" do `PROGRESSO.md` só com o que a F5 usa.
- [ ] **Step 2:** Suíte completa (`php artisan test`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`; `npx vitest run`, `npx tsc --noEmit`, `npm run lint`). Expected: tudo verde.
- [ ] **Step 3:** Revisão final da branch por subagente (modelo `fable`) usando `review-package`; corrigir Critical/Important em uma passada com teste RED→GREEN; Minors vão para o ledger e para "Pendências da F4b".
- [ ] **Step 4:** Commit `docs: F4b concluída (venda mista)`; apagar o workspace `.superpowers/sdd/2026-10-07-f4b-venda-mista`; recomendar `/compact` ou `/clear`. Sem push.
