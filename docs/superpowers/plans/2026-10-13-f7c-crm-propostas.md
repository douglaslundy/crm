# F7c: Propostas, aceite público e nota em 1 clique: plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: usar superpowers:executing-plans (execução Native, direto na master, sem worktree: aprovação permanente do usuário) para implementar tarefa a tarefa. Passos com checkbox (`- [ ]`).

**Objetivo:** vendedor cria proposta a partir do negócio, envia link público, cliente aceita ou recusa sem login; aceite ganha o negócio e libera "Emitir nota" (rascunho fiscal pré-preenchido); tudo na timeline do cliente.

**Arquitetura:** módulo `Crm` ganha `Proposta`/`ItemDaProposta` e Actions; rota pública `api/public/propostas/{token}` que resolve o tenant pelo hash do token; PDF por dompdf; ponte para o Fiscal por contrato em `Crm/Domain/Contracts` implementado no módulo Fiscal (mesmo padrão de Customers/Catalog). Frontend: aba "Propostas" no negócio e página pública `/p/[token]`.

**Stack:** Laravel 12, PHPUnit, barryvdh/laravel-dompdf, Next.js (App Router), TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-13-crm-f7c-propostas-design.md`.

## Rulings do plano (alteram a spec; registrar na Tarefa 1)

1. **`notas_ids` (JSON, lista de 1 ou 2 ids) substitui `nota_id`.** Venda mista exige pagamentos somando o total da NF-e (`GravadorDaVenda`), e pagamento não pode ser chutado. Proposta mista gera dois rascunhos independentes (NFE de produtos sem pagamentos e DPS de serviços), cada um completado no editor fiscal.
2. **Modelo do rascunho:** produtos viram `NFE`, serviços viram `DPS`. NFC-e não é gerada pela proposta (o vendedor exclui o rascunho e cria a nota pelo fluxo comum). Anotar em "Pendências" da F7c.
3. **Numeração:** `max(numero)+1` por tenant com índice único `tenant_id+numero` e até 3 tentativas ao colidir (sem lock de agregado).
4. **Aceite público não checa módulo CRM do plano** (o cliente do cliente não deve ver erro de plano); operações autenticadas continuam checando.
5. **Autor do aceite:** `autor_id` nulo na timeline e `negocio.responsavel_id` como destinatário; o nome informado no aceite vai no texto e em `dados`.
6. **Frontend público sem TanStack Query:** `useEffect` + `api()` (a página fica fora do layout autenticado).

## Restrições globais

- `declare(strict_types=1)`; dinheiro em centavos inteiros; `=== null`, nunca `empty()`/`?? 0` para valores.
- Todo Model de tenant com `BelongsToTenant`; todo endpoint novo com teste de isolamento.
- Schema só por migration. Segredos criptografados (token). Sem dado real em fixtures.
- TS strict, sem `any`. Next.js tem breaking changes: ler `frontend/node_modules/next/dist/docs/` antes de criar a página pública e copiar o padrão de `frontend/app/(app)/crm/negocios/[id]/page.tsx` (`params` é `Promise`).
- Arquivos têm CRLF: editar com Edit/Write. `git add` sempre com caminhos específicos (não tocar `frontend/eslint.config.mjs`, `frontend/features/cadastro/components/CadastroForm.tsx`, `frontend/eslint-rules/`).
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u`.
- Comandos: backend `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=...`; frontend `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run <arquivo>`.

## Review Focus

- Aceite duplo concorrente: um só aceite, um só ganho (Tarefa 3).
- Token de outro tenant ou inexistente: 404 idêntico, sem vazar existência (Tarefas 3 e 7).
- Proposta enviada é imutável mesmo se o negócio mudar depois (Tarefa 2).
- Desconto igual ou maior que o subtotal bloqueia; desconto 0 é válido (Tarefa 2).
- Rascunho de nota duas vezes devolve os mesmos ids; sem Fiscal no plano bloqueia com mensagem (Tarefa 5).

## Estrutura de arquivos

Backend (`backend/app/Modules/Crm`):
- `Domain/Enums/StatusDaProposta.php`, `Domain/Models/Proposta.php`, `Domain/Models/ItemDaProposta.php`
- `Domain/Contracts/GeradorDeRascunhoDeNota.php`, `Domain/LinhaDeProposta.php`
- `Application/FecharNegocioComoGanho.php` (núcleo extraído de `GanharNegocio`)
- `Application/ResolvedorDePropostaPublica.php`, `Application/ApresentadorDePropostas.php`, `Application/GeradorDePdfDaProposta.php`
- `Application/Actions/{CriarProposta,AtualizarProposta,EnviarProposta,CancelarProposta,AceitarProposta,RecusarProposta,GerarRascunhoDeNotaDaProposta}.php`
- `Http/Controllers/{PropostasController,PropostaPublicaController}.php`, `Http/Requests/{PropostaRequest,AceiteRequest,RecusaRequest}.php`, `Http/routes-public.php`
- `backend/resources/views/pdf/proposta.blade.php`
- Fiscal: `backend/app/Modules/Fiscal/Application/GeradorDeRascunhoParaCrm.php`

Migration: `backend/database/migrations/2026_10_17_000001_create_crm_propostas.php`.

Frontend (`frontend/features/crm`): `types.ts`, `api.ts`, `hooks/usePropostas.ts`, `components/PropostasDoNegocio.tsx`, `components/PaginaPublicaDaProposta.tsx`; página `frontend/app/p/[token]/page.tsx`.

---

### Task 1: Migration, enums e models

**Files:**
- Create: `backend/database/migrations/2026_10_17_000001_create_crm_propostas.php`
- Create: `backend/app/Modules/Crm/Domain/Enums/StatusDaProposta.php`
- Create: `backend/app/Modules/Crm/Domain/Models/Proposta.php`, `.../ItemDaProposta.php`
- Modify: `backend/app/Modules/Crm/Domain/Enums/TipoDeRegistro.php`
- Modify: `docs/superpowers/specs/2026-10-13-crm-f7c-propostas-design.md` (rulings 1 e 2)
- Test: `backend/tests/Feature/Crm/PropostasModelosTest.php`

**Interfaces:**
- Produces: `StatusDaProposta` (`Rascunho='RASCUNHO'`, `Enviada='ENVIADA'`, `Aceita='ACEITA'`, `Recusada='RECUSADA'`, `Cancelada='CANCELADA'`); `Proposta` com `itens(): HasMany`, `negocio(): BelongsTo`, `expirada(): bool`, `statusApresentado(): string`, `linkToken(): ?string`; `TipoDeRegistro::{PropostaEnviada='PROPOSTA_ENVIADA', PropostaAceita='PROPOSTA_ACEITA', PropostaRecusada='PROPOSTA_RECUSADA', PropostaNotaGerada='PROPOSTA_NOTA_GERADA'}`. `crm_timeline.tipo` é `string(24)`: `PROPOSTA_NOTA_GERADA` tem 20, cabe.

- [ ] **Step 1: teste que falha**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Models\ItemDaProposta;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class PropostasModelosTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    private function proposta(array $extra = []): Proposta
    {
        $negocio = Negocio::factory()->create(['responsavel_id' => $this->admin->id]);

        return Proposta::create(array_merge([
            'negocio_id' => $negocio->id, 'cliente_id' => $negocio->cliente_id, 'numero' => 1,
            'status' => StatusDaProposta::Rascunho, 'validade_dias' => 15, 'desconto_centavos' => 0,
            'criado_por' => $this->admin->id,
        ], $extra));
    }

    public function test_cria_proposta_com_itens_e_tenant_do_contexto(): void
    {
        $p = $this->proposta();
        $p->itens()->create(['tipo' => 'PRODUTO', 'item_id' => (string) \Illuminate\Support\Str::uuid(), 'descricao' => 'X', 'quantidade' => 2, 'valor_unitario_centavos' => 500, 'ordem' => 1]);

        $this->assertSame($this->tenant->id, $p->tenant_id);
        $this->assertCount(1, $p->refresh()->itens);
        $this->assertSame(500, $p->itens->first()->valor_unitario_centavos);
    }

    public function test_outro_tenant_nao_enxerga(): void
    {
        $this->proposta();
        app(TenantContext::class)->set((string) \Illuminate\Support\Str::uuid());

        $this->assertSame(0, Proposta::query()->count());
        $this->assertSame(0, ItemDaProposta::query()->count());
    }

    public function test_enviada_vencida_e_apresentada_como_expirada(): void
    {
        $p = $this->proposta(['status' => StatusDaProposta::Enviada, 'validade_em' => now()->subMinute()]);

        $this->assertTrue($p->expirada());
        $this->assertSame('EXPIRADA', $p->statusApresentado());
    }

    public function test_enviada_no_prazo_nao_expira_e_aceita_vencida_continua_aceita(): void
    {
        $no_prazo = $this->proposta(['numero' => 2, 'status' => StatusDaProposta::Enviada, 'validade_em' => now()->addDay()]);
        $aceita = $this->proposta(['numero' => 3, 'status' => StatusDaProposta::Aceita, 'validade_em' => now()->subDay()]);

        $this->assertFalse($no_prazo->expirada());
        $this->assertSame('ACEITA', $aceita->statusApresentado());
    }

    public function test_token_e_gravado_criptografado(): void
    {
        $p = $this->proposta();
        $p->forceFill(['token' => 'abc', 'token_hash' => hash('sha256', 'abc')])->save();

        $bruto = \Illuminate\Support\Facades\DB::table('crm_propostas')->where('id', $p->id)->value('token');
        $this->assertNotSame('abc', $bruto);
        $this->assertSame('abc', $p->refresh()->token);
    }
}
```

- [ ] **Step 2: rodar e ver falhar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=PropostasModelosTest`
Expected: FAIL (class `Proposta` not found).

- [ ] **Step 3: implementar**

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
        Schema::create('crm_propostas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('negocio_id')->constrained('crm_negocios');
            $table->uuid('cliente_id');   // sem FK: pertence a outro módulo
            $table->unsignedInteger('numero');
            $table->string('status', 12);
            $table->unsignedSmallInteger('validade_dias');
            $table->timestamp('validade_em')->nullable();
            $table->unsignedBigInteger('desconto_centavos')->default(0);
            $table->text('observacoes')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->text('token')->nullable();   // criptografado (cast): reexibe o link ao vendedor
            $table->timestamp('enviada_em')->nullable();
            $table->timestamp('respondida_em')->nullable();
            $table->string('aceite_nome', 120)->nullable();
            $table->string('aceite_ip', 45)->nullable();
            $table->text('motivo_recusa')->nullable();
            $table->json('notas_ids')->nullable();
            $table->uuid('criado_por');
            $table->timestamps();
            $table->unique(['tenant_id', 'numero']);
            $table->index(['tenant_id', 'negocio_id', 'status']);
        });

        Schema::create('crm_itens_da_proposta', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('proposta_id')->constrained('crm_propostas')->cascadeOnDelete();
            $table->string('tipo', 8);
            $table->uuid('item_id');
            $table->string('descricao', 255);
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('valor_unitario_centavos');
            $table->unsignedSmallInteger('ordem');
            $table->timestamps();
            $table->index(['tenant_id', 'proposta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_itens_da_proposta');
        Schema::dropIfExists('crm_propostas');
    }
};
```

`StatusDaProposta` (enum string conforme Interfaces). `TipoDeRegistro`: acrescentar os 4 cases. `ItemDaProposta` (copiar `ItemDoNegocio`, tabela `crm_itens_da_proposta`, fillable `tipo,item_id,descricao,quantidade,valor_unitario_centavos,ordem`, cast `tipo => TipoDeItem`, inteiros). `Proposta`:

```php
class Proposta extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'crm_propostas';

    protected $fillable = [
        'negocio_id', 'cliente_id', 'numero', 'status', 'validade_dias', 'validade_em', 'desconto_centavos',
        'observacoes', 'criado_por',
    ];

    protected $hidden = ['token', 'token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatusDaProposta::class, 'numero' => 'integer', 'validade_dias' => 'integer',
            'desconto_centavos' => 'integer', 'validade_em' => 'datetime', 'enviada_em' => 'datetime',
            'respondida_em' => 'datetime', 'token' => 'encrypted', 'notas_ids' => 'array',
        ];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemDaProposta::class, 'proposta_id')->orderBy('ordem');
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function expirada(): bool
    {
        return $this->status === StatusDaProposta::Enviada && $this->validade_em !== null && $this->validade_em->isPast();
    }

    public function statusApresentado(): string
    {
        return $this->expirada() ? 'EXPIRADA' : $this->status->value;
    }

    public function subtotalCentavos(): int
    {
        return (int) $this->itens->sum(fn (ItemDaProposta $i): int => $i->quantidade * $i->valor_unitario_centavos);
    }

    public function totalCentavos(): int
    {
        return $this->subtotalCentavos() - $this->desconto_centavos;
    }
}
```

Spec: trocar `nota_id` por `notas_ids` (lista) e acrescentar a Decisão "modelo NFE/DPS" (rulings 1 e 2 deste plano).

- [ ] **Step 4: passar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=PropostasModelosTest`
Expected: PASS (5 testes).

- [ ] **Step 5: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add backend/database/migrations/2026_10_17_000001_create_crm_propostas.php backend/app/Modules/Crm/Domain backend/tests/Feature/Crm/PropostasModelosTest.php docs/superpowers/specs/2026-10-13-crm-f7c-propostas-design.md
git commit -m "feat(crm): modelo de propostas e itens" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 2: Criar, atualizar, enviar e cancelar proposta

**Files:**
- Create: `backend/app/Modules/Crm/Application/Actions/{CriarProposta,AtualizarProposta,EnviarProposta,CancelarProposta}.php`
- Test: `backend/tests/Feature/Crm/PropostasActionsTest.php`
- Create (suporte de teste): `backend/tests/Support/ComPropostas.php`

**Interfaces:**
- Consumes: `PoliticaDoCrm::garantirPodeOperarNegocios`, `RegistradorDaTimeline::registrar(TipoDeRegistro, string $clienteId, ?string $negocioId, ?string $autorId, string $texto, array $dados = [])`, `RegraDoCrmException(string $mensagem, string $codigo)`, `Negocio::garantirAberto()`, `Negocio::visiveisPara`.
- Produces: `CriarProposta::executar(Usuario $autor, Negocio $negocio, array{validade_dias?: int, desconto_centavos?: int, observacoes?: ?string} $dados): Proposta`; `AtualizarProposta::executar(Usuario $autor, Proposta $p, array $dados): Proposta`; `EnviarProposta::executar(Usuario $autor, Proposta $p): Proposta` (token em `$p->token`); `CancelarProposta::executar(Usuario $autor, Proposta $p): Proposta`. Códigos de erro: `NEGOCIO_SEM_ITENS`, `PROPOSTA_DESCONTO_INVALIDO`, `PROPOSTA_NAO_EDITAVEL`, `PROPOSTA_NAO_CANCELAVEL`.

- [ ] **Step 1: helper e testes que falham**

`tests/Support/ComPropostas.php`: trait que usa `ComCrm`, com `negocioComItens(int $quantidade = 2, int $unitario = 1500): Negocio` (cria `Funil::factory()->padrao()`, `Etapa::factory()`, `Negocio::factory()` com `responsavel_id = $this->admin->id` e um `ItemDoNegocio` via `$negocio->itens()->create([...'tipo'=>'PRODUTO','item_id'=>uuid,'descricao'=>'Item A',...])`).

Testes (um por comportamento, todos com `use ComCrm, ComPropostas, RefreshDatabase`):
- `test_cria_com_snapshot_dos_itens_numero_e_validade_padrao`: após `CriarProposta`, status Rascunho, `numero === 1`, `validade_dias === 15`, 1 item copiado; segunda proposta tem `numero === 2`.
- `test_negocio_sem_itens_bloqueia`: negócio sem itens → `RegraDoCrmException` código `NEGOCIO_SEM_ITENS`.
- `test_negocio_fechado_bloqueia`: `status` Ganho → código `NEGOCIO_FECHADO`.
- `test_desconto_zero_e_valido_e_desconto_igual_ao_subtotal_bloqueia`: desconto 0 passa; `desconto_centavos = 3000` (subtotal 2×1500) → `PROPOSTA_DESCONTO_INVALIDO`.
- `test_atualizar_so_em_rascunho`: Enviada → `PROPOSTA_NAO_EDITAVEL`.
- `test_enviar_congela_gera_token_e_registra_timeline`: após enviar, `status` Enviada, `token` 64 chars, `token_hash === hash('sha256', token)`, `validade_em` ≈ now+15d, registro `PROPOSTA_ENVIADA` na timeline do cliente; alterar `ItemDoNegocio` depois não muda `itens` da proposta.
- `test_cancelar_invalida_o_link`: cancelar Enviada → `Cancelada` e `token_hash` nulo; cancelar Aceita → `PROPOSTA_NAO_CANCELAVEL`.
- `test_leitor_nao_opera`: `Papel::Leitura` → `AcessoNegadoException`.
- `test_vendedor_so_cria_para_negocio_que_enxerga` fica no teste de API (Tarefa 6).

- [ ] **Step 2: rodar e ver falhar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=PropostasActionsTest`
Expected: FAIL (classes de Action inexistentes).

- [ ] **Step 3: implementar**

`CriarProposta`:

```php
final class CriarProposta
{
    private const TENTATIVAS = 3;

    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param array{validade_dias?: int, desconto_centavos?: int, observacoes?: ?string} $dados */
    public function executar(Usuario $autor, Negocio $negocio, array $dados = []): Proposta
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        for ($tentativa = 1; ; $tentativa++) {
            try {
                return DB::transaction(fn (): Proposta => $this->criar($autor, $negocio, $dados));
            } catch (UniqueConstraintViolationException $e) {
                if ($tentativa >= self::TENTATIVAS) {
                    throw $e;
                }
            }
        }
    }

    /** @param array<string, mixed> $dados */
    private function criar(Usuario $autor, Negocio $negocio, array $dados): Proposta
    {
        $negocio = Negocio::query()->with('itens')->lockForUpdate()->findOrFail($negocio->id);
        $negocio->garantirAberto();
        if ($negocio->itens->isEmpty()) {
            throw new RegraDoCrmException('Adicione ao menos um item ao negócio antes de criar a proposta.', 'NEGOCIO_SEM_ITENS');
        }

        $proposta = Proposta::create([
            'negocio_id' => $negocio->id,
            'cliente_id' => $negocio->cliente_id,
            'numero' => (int) Proposta::query()->max('numero') + 1,
            'status' => StatusDaProposta::Rascunho,
            'validade_dias' => $dados['validade_dias'] ?? 15,
            'desconto_centavos' => $dados['desconto_centavos'] ?? 0,
            'observacoes' => $dados['observacoes'] ?? null,
            'criado_por' => $autor->id,
        ]);
        foreach ($negocio->itens as $i => $item) {
            $proposta->itens()->create([
                'tipo' => $item->tipo, 'item_id' => $item->item_id, 'descricao' => $item->descricao,
                'quantidade' => $item->quantidade, 'valor_unitario_centavos' => $item->valor_unitario_centavos, 'ordem' => $i + 1,
            ]);
        }
        $proposta->load('itens');
        ValidadorDeProposta::garantirDescontoValido($proposta);

        return $proposta;
    }
}
```

`ValidadorDeProposta` (classe estática em `Application/`): `garantirDescontoValido(Proposta $p)` lança `PROPOSTA_DESCONTO_INVALIDO` ("O desconto deve ser menor que o total dos itens (R$ X).") quando `desconto_centavos >= subtotalCentavos()`; desconto 0 sempre passa. Aplicar também em `AtualizarProposta` (recarrega itens) e em `EnviarProposta`.

`AtualizarProposta`: `garantirPodeOperarNegocios`; transação, `lockForUpdate`, exige `status === Rascunho` (senão `PROPOSTA_NAO_EDITAVEL`, "Só rascunho pode ser editado."), `fill` apenas de `validade_dias`, `desconto_centavos`, `observacoes` presentes em `$dados` (usar `array_key_exists`), valida desconto, salva.

`EnviarProposta`: transação, lock, exige Rascunho, `Negocio::findOrFail(...)->garantirAberto()`, valida desconto, `$token = Str::random(64)`, `forceFill(['status'=>Enviada,'token'=>$token,'token_hash'=>hash('sha256',$token),'enviada_em'=>now(),'validade_em'=>now()->addDays($p->validade_dias)])`, registra `PROPOSTA_ENVIADA` com texto `"Proposta #{$numero} enviada (R$ ...)"` e `dados: ['proposta_id','numero','total_centavos']`.

`CancelarProposta`: aceita Rascunho ou Enviada (senão `PROPOSTA_NAO_CANCELAVEL`), `forceFill(['status'=>Cancelada,'token_hash'=>null,'respondida_em'=>now()])`; sem registro de timeline (spec não lista).

- [ ] **Step 4: passar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=PropostasActionsTest`
Expected: PASS.

- [ ] **Step 5: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add backend/app/Modules/Crm/Application backend/tests/Support/ComPropostas.php backend/tests/Feature/Crm/PropostasActionsTest.php
git commit -m "feat(crm): criar, editar, enviar e cancelar proposta" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 3: Aceite e recusa públicos

**Files:**
- Create: `backend/app/Modules/Crm/Application/FecharNegocioComoGanho.php`
- Modify: `backend/app/Modules/Crm/Application/Actions/GanharNegocio.php`
- Create: `backend/app/Modules/Crm/Application/ResolvedorDePropostaPublica.php`
- Create: `backend/app/Modules/Crm/Application/Actions/{AceitarProposta,RecusarProposta}.php`
- Test: `backend/tests/Feature/Crm/AceiteDePropostaTest.php`

**Interfaces:**
- Consumes: `NegocioGanho(string $tenantId, string $negocioId, string $clienteId, ?string $autorId)`, `ConsultaDeClientes::resumo/converterEmCliente`, `TenantContext::set`.
- Produces: `FecharNegocioComoGanho::executar(Negocio $negocio, ?string $autorId): Negocio` (trava, `garantirAberto`, ganha, converte lead, dispara evento depois do commit; deve rodar dentro de transação); `ResolvedorDePropostaPublica::resolver(string $token): Proposta` (fixa o `TenantContext`; `ModelNotFoundException` para token vazio/inexistente/cancelado); `AceitarProposta::executar(string $token, string $nome, ?string $ip): Proposta`; `RecusarProposta::executar(string $token, ?string $motivo, ?string $ip): Proposta`. Erros: `PROPOSTA_EXPIRADA`, `PROPOSTA_JA_RESPONDIDA` (status 422 via `RegraDoCrmException`), `NEGOCIO_FECHADO`.

- [ ] **Step 1: testes que falham**

Em `AceiteDePropostaTest` (setup: `prepararCrm`, uma proposta Enviada criada por `CriarProposta`+`EnviarProposta`; guardar `$token`; depois `app(TenantContext::class)->clear()` para simular a rota pública):
- `test_aceite_ganha_o_negocio_cancela_irmas_e_grava_timeline`: segunda proposta Enviada do mesmo negócio vira `Cancelada`; negócio `Ganho`; timeline tem `PROPOSTA_ACEITA` e `NEGOCIO_GANHO` (rodar com listeners reais); `aceite_nome`, `aceite_ip`, `respondida_em` gravados.
- `test_aceite_repetido_e_idempotente`: segunda chamada com mesmo token retorna a proposta `Aceita` sem erro e sem novo registro (`PROPOSTA_ACEITA` count 1, `NEGOCIO_GANHO` count 1).
- `test_recusa_nao_mexe_no_negocio`: `Recusada`, `motivo_recusa` gravado, negócio continua `Aberto`, `PROPOSTA_RECUSADA` na timeline.
- `test_aceitar_depois_de_recusar_bloqueia`: código `PROPOSTA_JA_RESPONDIDA`.
- `test_expirada_bloqueia_aceite`: `travel(16)->days()`; aceitar → `PROPOSTA_EXPIRADA`; negócio segue aberto.
- `test_token_invalido_ou_cancelado_e_404`: token `'x'`, token de proposta cancelada → `ModelNotFoundException`.
- `test_token_de_outro_tenant_resolve_so_o_proprio`: cria segundo tenant com proposta; resolver com o token do segundo devolve a proposta dele e deixa `TenantContext` nele (nunca mistura).
- `test_aceite_com_negocio_ja_ganho_pela_equipe_bloqueia`: ganhar o negócio manualmente antes → `NEGOCIO_FECHADO`; proposta continua `Enviada`.
- `test_aceite_duplo_concorrente_grava_um_so_ganho`: chamar `AceitarProposta` duas vezes em sequência na mesma instância já cobre o caminho; o lock (`lockForUpdate`) é verificado pelo teste de idempotência acima (SQLite não prova concorrência real: anotar em PROGRESSO como não verificado).

- [ ] **Step 2: rodar e ver falhar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=AceiteDePropostaTest`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: implementar**

`FecharNegocioComoGanho` (mover o corpo da transação de `GanharNegocio`, sem política):

```php
final class FecharNegocioComoGanho
{
    public function __construct(private readonly ConsultaDeClientes $clientes) {}

    /** Chamar dentro de uma transação: o evento só sai depois do commit. */
    public function executar(Negocio $negocio, ?string $autorId): Negocio
    {
        $negocio = Negocio::query()->lockForUpdate()->findOrFail($negocio->id);
        $negocio->garantirAberto();
        $negocio->update(['status' => StatusDoNegocio::Ganho, 'fechado_em' => now()]);

        if ($this->clientes->resumo($negocio->cliente_id)?->ehLead === true) {
            $this->clientes->converterEmCliente($negocio->cliente_id);
        }

        DB::afterCommit(fn () => event(new NegocioGanho($negocio->tenant_id, $negocio->id, $negocio->cliente_id, $autorId)));

        return $negocio->refresh()->load('itens');
    }
}
```

`GanharNegocio`: construtor passa a receber `PoliticaDoCrm` e `FecharNegocioComoGanho`; `executar` mantém `garantirPodeOperarNegocios` e `return DB::transaction(fn () => $this->fechar->executar($negocio, $autor->id));`. Os testes existentes `MoverGanharPerderTest` devem continuar verdes (rodar).

`ResolvedorDePropostaPublica`:

```php
final class ResolvedorDePropostaPublica
{
    public function resolver(string $token): Proposta
    {
        if ($token === '') {
            throw (new ModelNotFoundException)->setModel(Proposta::class);
        }
        // Única leitura cross-tenant do módulo: o hash identifica a proposta e fixa o tenant antes de qualquer outra consulta.
        $proposta = Proposta::withoutTenantScope()->where('token_hash', hash('sha256', $token))->first();
        if ($proposta === null) {
            throw (new ModelNotFoundException)->setModel(Proposta::class);
        }
        app(TenantContext::class)->set($proposta->tenant_id);

        return $proposta->load('itens');
    }
}
```

`AceitarProposta`:

```php
public function executar(string $token, string $nome, ?string $ip): Proposta
{
    $resolvida = $this->resolvedor->resolver($token);

    return DB::transaction(function () use ($resolvida, $nome, $ip): Proposta {
        $proposta = Proposta::query()->lockForUpdate()->findOrFail($resolvida->id);
        if ($proposta->status === StatusDaProposta::Aceita) {
            return $proposta;   // idempotente
        }
        $this->garantirRespondivel($proposta);

        $negocio = Negocio::query()->findOrFail($proposta->negocio_id);
        $proposta->forceFill([
            'status' => StatusDaProposta::Aceita, 'aceite_nome' => trim($nome), 'aceite_ip' => $ip, 'respondida_em' => now(),
        ])->save();

        $this->fechar->executar($negocio, null);   // lança NEGOCIO_FECHADO e desfaz o aceite
        Proposta::query()->where('negocio_id', $negocio->id)->where('id', '!=', $proposta->id)
            ->whereIn('status', [StatusDaProposta::Rascunho->value, StatusDaProposta::Enviada->value])
            ->get()->each(fn (Proposta $o) => $o->forceFill(['status' => StatusDaProposta::Cancelada, 'token_hash' => null, 'respondida_em' => now()])->save());

        $this->timeline->registrar(
            TipoDeRegistro::PropostaAceita, $proposta->cliente_id, $negocio->id, null,
            "Proposta #{$proposta->numero} aceita por {$proposta->aceite_nome}",
            ['proposta_id' => $proposta->id, 'numero' => $proposta->numero, 'aceite_nome' => $proposta->aceite_nome],
        );

        return $proposta->refresh()->load('itens');
    });
}
```

`garantirRespondivel` (compartilhado em `ValidadorDeProposta::garantirRespondivel(Proposta $p)`): Aceita/Recusada → `PROPOSTA_JA_RESPONDIDA` ("Esta proposta já foi respondida."); Rascunho/Cancelada → `ModelNotFoundException`; `expirada()` → `PROPOSTA_EXPIRADA` ("Esta proposta expirou. Peça uma nova ao vendedor."). Atenção: se o nome vier vazio após `trim`, `RegraDoCrmException('Informe seu nome para aceitar.', 'ACEITE_SEM_NOME')`.

`RecusarProposta`: mesmo esqueleto, sem fechar negócio; `status = Recusada`, `motivo_recusa = trim($motivo) ?: null` (usar `=== ''`), registra `PROPOSTA_RECUSADA`; recusa repetida devolve a proposta Recusada.

- [ ] **Step 4: passar (incluindo regressão do GanharNegocio)**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter="AceiteDePropostaTest|MoverGanharPerderTest|TimelineDoNegocioTest"`
Expected: PASS.

- [ ] **Step 5: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add backend/app/Modules/Crm/Application backend/tests/Feature/Crm/AceiteDePropostaTest.php
git commit -m "feat(crm): aceite e recusa públicos de proposta" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 4: PDF da proposta e apresentador

**Files:**
- Create: `backend/app/Modules/Crm/Application/GeradorDePdfDaProposta.php`, `.../ApresentadorDePropostas.php`
- Create: `backend/resources/views/pdf/proposta.blade.php`
- Test: `backend/tests/Feature/Crm/PdfDaPropostaTest.php`

**Interfaces:**
- Consumes: `Proposta`, `Tenant` (`razao_social`, `nome_fantasia`, `cnpj`), `ConsultaDeClientes::resumo(string): ?ResumoDoCliente`, `Barryvdh\DomPDF\Facade\Pdf::loadView(...)->setPaper([0,0,595,842],'portrait')->output()`.
- Produces: `GeradorDePdfDaProposta::gerar(Proposta $p): string` (bytes); `ApresentadorDePropostas::apresentar(Proposta $p, bool $comLink = false): array` com chaves `id, numero, status, negocio_id, cliente_id, validade_dias, validade_em, desconto_centavos, subtotal_centavos, total_centavos, observacoes, enviada_em, respondida_em, aceite_nome, motivo_recusa, notas_ids, itens[{tipo, descricao, quantidade, valor_unitario_centavos, total_centavos}]` e, se `$comLink`, `link` (`rtrim(config('app.frontend_url') ?? config('app.url'),'/').'/p/'.$token`). `ApresentadorDePropostas::paraPublico(Proposta $p): array`: `numero, status, empresa{nome,cnpj}, cliente{nome}, validade_em, itens[...], subtotal_centavos, desconto_centavos, total_centavos, observacoes, aceite_nome, respondida_em`; nunca ids internos de usuário.

- [ ] **Step 1: teste que falha**

```php
public function test_pdf_comeca_com_assinatura_pdf_e_apresentador_publico_nao_vaza_ids_de_usuario(): void
{
    $proposta = $this->propostaEnviada();   // helper do trait ComPropostas (Tarefa 2)

    $bytes = app(GeradorDePdfDaProposta::class)->gerar($proposta);
    $this->assertStringStartsWith('%PDF', $bytes);

    $publico = app(ApresentadorDePropostas::class)->paraPublico($proposta);
    $this->assertArrayNotHasKey('criado_por', $publico);
    $this->assertArrayNotHasKey('token', $publico);
    $this->assertSame($proposta->totalCentavos(), $publico['total_centavos']);
}

public function test_link_so_aparece_para_o_vendedor_e_de_proposta_enviada(): void
{
    $proposta = $this->propostaEnviada();
    $this->assertStringContainsString('/p/'.$proposta->token, app(ApresentadorDePropostas::class)->apresentar($proposta, true)['link']);
    $this->assertArrayNotHasKey('link', app(ApresentadorDePropostas::class)->apresentar($proposta));
}
```

Acrescentar `propostaEnviada()` ao trait `ComPropostas` (Criar + Enviar via Actions).

- [ ] **Step 2: rodar e ver falhar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=PdfDaPropostaTest`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: implementar**

View `pdf/proposta.blade.php`: HTML simples, CSS inline, fonte DejaVu Sans (aceita acentos): cabeçalho com `nome_fantasia ?? razao_social` e CNPJ, "Proposta nº X", cliente, tabela de itens (descrição, qtd, unitário, total), subtotal, desconto, total, validade (`d/m/Y`), observações. Valores formatados com `number_format($c / 100, 2, ',', '.')` dentro de uma closure `$brl` declarada no topo da view. `GeradorDePdfDaProposta::gerar`: monta `$dados` com `$proposta`, `$tenant = Tenant::query()->findOrFail($proposta->tenant_id)`, `$cliente = $this->clientes->resumo($proposta->cliente_id)` e retorna `Pdf::loadView('pdf.proposta', $dados)->setPaper([0, 0, 595, 842], 'portrait')->output()`. Sem cálculo fiscal. `ApresentadorDePropostas` conforme Interfaces; datas em ISO 8601 (`->toIso8601String()`, nulo permanece `null`).

- [ ] **Step 4: passar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=PdfDaPropostaTest`
Expected: PASS.

- [ ] **Step 5: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add backend/app/Modules/Crm/Application backend/resources/views/pdf/proposta.blade.php backend/tests/Feature/Crm/PdfDaPropostaTest.php backend/tests/Support/ComPropostas.php
git commit -m "feat(crm): PDF e apresentadores da proposta" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 5: Ponte com o Fiscal e rascunho de nota

**Files:**
- Create: `backend/app/Modules/Crm/Domain/LinhaDeProposta.php`, `backend/app/Modules/Crm/Domain/Contracts/GeradorDeRascunhoDeNota.php`
- Create: `backend/app/Modules/Fiscal/Application/GeradorDeRascunhoParaCrm.php`
- Modify: `backend/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (um `bind` em `register()`)
- Create: `backend/app/Modules/Crm/Application/Actions/GerarRascunhoDeNotaDaProposta.php`
- Test: `backend/tests/Feature/Crm/RascunhoDeNotaDaPropostaTest.php`

**Interfaces:**
- Consumes: `CriarRascunhoDeNota::executar(array $dados, Usuario $autor, ?string $vendaId = null): Nota` (dados: `modelo` `'NFE'|'DPS'`, `cliente_id`, `itens[{produto_id|servico_id, quantidade, valor_unitario_centavos}]`, `desconto_centavos`, `pagamentos` lista (vazia permitida)); `RateioDeDesconto::{totalDoItem(int $milesimos, int $unit), ratear(int $desconto, list<int> $totais): list<int>}`; `Quantidade::paraMilesimos(string)`; `EntitlementService::garantirModulo(Tenant, Modulo)`; `Modulo::{FiscalNfe, FiscalNfse}`.
- Produces: `LinhaDeProposta(TipoDeItem $tipo, string $itemId, int $quantidade, int $valorUnitarioCentavos)`; contrato `GeradorDeRascunhoDeNota::gerar(Usuario $autor, string $clienteId, list<LinhaDeProposta> $linhas, int $descontoCentavos): list<string>` e `disponivel(Usuario $autor): bool`; `GerarRascunhoDeNotaDaProposta::executar(Usuario $autor, Proposta $p): list<string>` (grava `notas_ids`, registra `PROPOSTA_NOTA_GERADA`). Erros: `PROPOSTA_NAO_ACEITA`, `FISCAL_INDISPONIVEL` ("O plano não inclui emissão fiscal.").

- [ ] **Step 1: testes que falham**

Antes: `Grep "class ProdutoFactory|class ServicoFactory" backend/database/factories` para confirmar as factories; se não existirem, criar os registros pelos models com os campos obrigatórios que `CriarRascunhoDeNota` lê (`preco_centavos`, e para serviço `c_trib_nac`, `codigo_municipal`, `aliquota_iss`, `nbs`).

Cenários (plano de teste com `Modulo::Crm`, `Modulo::FiscalNfe`, `Modulo::FiscalNfse`; usar `Plano::factory()->comModulos(...)`; ajustar o `prepararCrm` com parâmetro de módulos extras no trait `ComPropostas`):
- `test_so_produtos_gera_um_rascunho_nfe_com_cliente_itens_e_desconto`: `Nota::count() === 1`, `modelo NFE`, `status Rascunho`, `desconto_centavos` igual ao da proposta, `pagamentos` vazio, `cliente_id` da proposta, `valor_unitario_centavos` do snapshot (não o preço atual do produto).
- `test_so_servicos_gera_dps`.
- `test_mista_gera_duas_notas_com_desconto_rateado`: soma dos descontos das duas = desconto da proposta.
- `test_segunda_chamada_devolve_os_mesmos_ids_sem_criar_nota`.
- `test_proposta_nao_aceita_bloqueia`: Enviada → `PROPOSTA_NAO_ACEITA`.
- `test_plano_sem_fiscal_bloqueia_com_mensagem`: só `Modulo::Crm` → `FISCAL_INDISPONIVEL`, `Nota::count() === 0`.
- `test_grava_registro_na_timeline`: `PROPOSTA_NOTA_GERADA`.

- [ ] **Step 2: rodar e ver falhar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=RascunhoDeNotaDaPropostaTest`
Expected: FAIL.

- [ ] **Step 3: implementar**

Adaptador no Fiscal (agrupa por tipo, rateia o desconto, chama `CriarRascunhoDeNota` por grupo):

```php
final class GeradorDeRascunhoParaCrm implements GeradorDeRascunhoDeNota
{
    public function __construct(private readonly CriarRascunhoDeNota $rascunhos, private readonly EntitlementService $planos) {}

    public function disponivel(Usuario $autor): bool
    {
        return $autor->tenant !== null
            && ($this->planos->temModulo($autor->tenant, Modulo::FiscalNfe) || $this->planos->temModulo($autor->tenant, Modulo::FiscalNfse));
    }

    public function gerar(Usuario $autor, string $clienteId, array $linhas, int $descontoCentavos): array
    {
        $grupos = [];
        foreach (['PRODUTO' => 'NFE', 'SERVICO' => 'DPS'] as $tipo => $modelo) {
            $do_tipo = array_values(array_filter($linhas, fn (LinhaDeProposta $l): bool => $l->tipo->value === $tipo));
            if ($do_tipo !== []) {
                $grupos[$modelo] = $do_tipo;
            }
        }
        $totais = array_map(fn (array $g): int => array_sum(array_map(
            fn (LinhaDeProposta $l): int => RateioDeDesconto::totalDoItem(Quantidade::paraMilesimos((string) $l->quantidade), $l->valorUnitarioCentavos),
            $g,
        )), array_values($grupos));
        $descontos = RateioDeDesconto::ratear($descontoCentavos, $totais);

        $ids = [];
        foreach (array_keys($grupos) as $i => $modelo) {
            $chave = $modelo === 'DPS' ? 'servico_id' : 'produto_id';
            $ids[] = $this->rascunhos->executar([
                'modelo' => $modelo,
                'cliente_id' => $clienteId,
                'desconto_centavos' => $descontos[$i],
                'itens' => array_map(fn (LinhaDeProposta $l): array => [
                    $chave => $l->itemId, 'quantidade' => (string) $l->quantidade, 'valor_unitario_centavos' => $l->valorUnitarioCentavos,
                ], $grupos[$modelo]),
                'pagamentos' => [],
            ], $autor)->id;
        }

        return $ids;
    }
}
```

Antes de usar `temModulo`, `Grep "public function" EntitlementService` e usar o método booleano real (se só existir `garantirModulo`, capturar a exceção dele em `disponivel`). `FiscalServiceProvider::register()`: `$this->app->bind(GeradorDeRascunhoDeNota::class, GeradorDeRascunhoParaCrm::class);`.

`GerarRascunhoDeNotaDaProposta::executar`: `garantirPodeOperarNegocios`; transação com `lockForUpdate` na proposta; `status !== Aceita` → `PROPOSTA_NAO_ACEITA`; `notas_ids` já preenchido → devolve; `! $gerador->disponivel($autor)` → `FISCAL_INDISPONIVEL`; monta `LinhaDeProposta` dos itens; `gerar(...)`; `forceFill(['notas_ids' => $ids])->save()`; registra `PROPOSTA_NOTA_GERADA` (`"Rascunho de nota gerado a partir da proposta #N"`, `dados: ['proposta_id','notas_ids']`).

- [ ] **Step 4: passar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=RascunhoDeNotaDaPropostaTest`
Expected: PASS.

- [ ] **Step 5: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add backend/app/Modules/Crm backend/app/Modules/Fiscal backend/tests
git commit -m "feat(crm): rascunho de nota a partir da proposta aceita" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 6: API autenticada de propostas

**Files:**
- Create: `backend/app/Modules/Crm/Http/Controllers/PropostasController.php`, `backend/app/Modules/Crm/Http/Requests/PropostaRequest.php`
- Modify: `backend/app/Modules/Crm/Http/routes-app.php`
- Test: `backend/tests/Feature/Crm/PropostasApiTest.php`

**Interfaces:**
- Rotas (dentro do grupo `empresa`, nomes `app.crm.propostas.*`): `GET negocios/{id}/propostas` (index), `POST negocios/{id}/propostas` (store), `GET propostas/{id}` (show), `PUT propostas/{id}` (update), `POST propostas/{id}/enviar`, `POST propostas/{id}/cancelar`, `POST propostas/{id}/nota`, `GET propostas/{id}/pdf`.
- Respostas: `{data: ApresentadorDePropostas::apresentar($p, $comLink = status Enviada|Aceita|Recusada)}`; `nota` devolve a proposta apresentada; `pdf` devolve `response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="proposta-N.pdf"'])`.
- `PropostaRequest`: `validade_dias` `sometimes|integer|between:1,365`, `desconto_centavos` `sometimes|integer|min:0`, `observacoes` `sometimes|nullable|string|max:2000`.

- [ ] **Step 1: testes que falham** (padrão de `NegociosApiTest`: `actingAs($this->admin)`, `postJson`)

- `test_ciclo_criar_editar_enviar_devolve_link`.
- `test_pdf_devolve_content_type_pdf`.
- `test_negocio_de_outro_tenant_e_404` e `test_proposta_de_outro_tenant_e_404` (isolamento obrigatório) em todas as rotas.
- `test_vendedor_so_ve_propostas_dos_seus_negocios` com `ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true])`: proposta de negócio de colega → 404.
- `test_leitura_lista_mas_nao_cria` (200 em GET, 403 em POST).
- `test_erro_de_regra_traz_codigo`: negócio sem itens → 422 com `codigo = NEGOCIO_SEM_ITENS`.
- `test_nota_gera_rascunho_e_repete_idempotente`.

- [ ] **Step 2: rodar e ver falhar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=PropostasApiTest`
Expected: FAIL (404 de rota).

- [ ] **Step 3: implementar**

Controller final com `PoliticaDoCrm` e `ApresentadorDePropostas`; copiar o estilo de `NegociosController` (`autor()`, 404 por `findOrFail`). Visibilidade da proposta: `Proposta::query()->whereHas('negocio', fn ($q) => $q->visiveisPara($autor))->with('itens')->findOrFail($id)`. `index` exige `garantirPodeLer`, ordena por `numero` desc. Rotas acrescentadas ao grupo existente (antes de `configuracao`), com `whereUuid` já aplicado ao grupo (`id`).

- [ ] **Step 4: passar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=PropostasApiTest`
Expected: PASS.

- [ ] **Step 5: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add backend/app/Modules/Crm backend/tests/Feature/Crm/PropostasApiTest.php
git commit -m "feat(crm): API autenticada de propostas" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 7: API pública da proposta

**Files:**
- Create: `backend/app/Modules/Crm/Http/Controllers/PropostaPublicaController.php`, `backend/app/Modules/Crm/Http/Requests/{AceiteRequest,RecusaRequest}.php`, `backend/app/Modules/Crm/Http/routes-public.php`
- Modify: `backend/app/Modules/Crm/Providers/CrmServiceProvider.php`
- Test: `backend/tests/Feature/Crm/PropostaPublicaApiTest.php`

**Interfaces:**
- Rotas `api/public/propostas/{token}`: `GET` (dados via `paraPublico`), `GET /pdf`, `POST /aceitar` (`nome` `required|string|min:2|max:120`), `POST /recusar` (`motivo` `nullable|string|max:500`). Middleware `['api', 'throttle:60,1']`, `where('token', '[A-Za-z0-9]{64}')`. Token fora do formato ou inexistente: 404 JSON `{"message": "Proposta não encontrada."}`. IP: `$request->ip()`.
- `ModelNotFoundException` já vira 404 pelo handler padrão (verificar a mensagem no teste: não pode citar "Proposta" com id).

- [ ] **Step 1: testes que falham**

- `test_get_devolve_so_campos_publicos` (sem `criado_por`, sem `token`, sem ids de usuário; `status` pode ser `EXPIRADA`).
- `test_aceitar_devolve_200_ganha_negocio_e_e_idempotente`.
- `test_recusar_devolve_200`.
- `test_token_inexistente_formato_valido_e_formato_invalido_dao_o_mesmo_404`: ambos `assertNotFound()` e corpos iguais.
- `test_proposta_cancelada_e_404`.
- `test_token_de_um_tenant_nao_le_proposta_de_outro` (isolamento): dois tenants, cada token devolve só a própria; resposta de A nunca contém o `numero`/cliente de B.
- `test_pdf_publico_devolve_pdf`.
- `test_expirada_aceite_422_com_codigo`.
- `test_aceite_sem_nome_422`.
- `test_throttle`: 61 requisições no mesmo minuto → 429 na última.
- Sem sessão/cookie: nenhum `actingAs`.

- [ ] **Step 2: rodar e ver falhar**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=PropostaPublicaApiTest`
Expected: FAIL.

- [ ] **Step 3: implementar**

`routes-public.php`:

```php
Route::get('propostas/{token}', [PropostaPublicaController::class, 'show'])->name('public.propostas.show');
Route::get('propostas/{token}/pdf', [PropostaPublicaController::class, 'pdf'])->name('public.propostas.pdf');
Route::post('propostas/{token}/aceitar', [PropostaPublicaController::class, 'aceitar'])->name('public.propostas.aceitar');
Route::post('propostas/{token}/recusar', [PropostaPublicaController::class, 'recusar'])->name('public.propostas.recusar');
```

`CrmServiceProvider::boot`: após o bloco `$rotas`, `Route::middleware(['api', 'throttle:60,1'])->prefix('api/public')->where(['token' => '[A-Za-z0-9]{64}'])->group(__DIR__.'/../Http/routes-public.php');`. O controller usa `ResolvedorDePropostaPublica`, `AceitarProposta`, `RecusarProposta`, `ApresentadorDePropostas::paraPublico`, `GeradorDePdfDaProposta`. Para `show`, `statusApresentado()` reflete expiração; proposta `Rascunho`/`Cancelada` já é 404 pelo resolvedor (`token_hash` nulo no cancelamento; rascunho não tem token).

- [ ] **Step 4: passar + regressão do módulo**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test --filter=Crm`
Expected: PASS.

- [ ] **Step 5: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add backend/app/Modules/Crm backend/tests/Feature/Crm/PropostaPublicaApiTest.php
git commit -m "feat(crm): API pública de proposta (ver, PDF, aceitar, recusar)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 8: Frontend — aba Propostas no negócio e timeline

**Files:**
- Modify: `frontend/features/crm/types.ts` (tipos `StatusDaProposta`, `Proposta`, `ItemDaProposta`; `TipoDeRegistro` ganha os 4 valores)
- Modify: `frontend/features/crm/api.ts`
- Create: `frontend/features/crm/hooks/usePropostas.ts`, `frontend/features/crm/components/PropostasDoNegocio.tsx`
- Modify: `frontend/features/crm/components/DetalheDoNegocio.tsx` (novo `Card` "Propostas" entre Itens e Atividades)
- Modify: `frontend/features/crm/components/TimelineDoCliente.tsx` (rótulos dos 4 tipos)
- Test: `frontend/features/crm/components/PropostasDoNegocio.test.tsx`

**Interfaces:**
- `crmApi.propostas(negocioId)`, `criarProposta(negocioId, dados)`, `editarProposta(id, dados)`, `enviarProposta(id)`, `cancelarProposta(id)`, `gerarNotaDaProposta(id)`; URL do PDF: `${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000'}/api/app/crm/propostas/${id}/pdf` (link `<a target="_blank" rel="noopener">`, a sessão vai por cookie).
- Hooks: `usePropostasDoNegocio(negocioId)`, `useCriarProposta`, `useEnviarProposta`, `useCancelarProposta`, `useGerarNotaDaProposta` (invalidam `QUERY_KEY_CRM`, `toast` com mensagem de `ApiError`).
- Componente `PropostasDoNegocio({ negocio, podeEscrever, temFiscal })`: lista (número, status em português, total com `formatarCentavos`, validade com `formatarData`), formulário curto para nova proposta (validade em dias, desconto em reais convertido a centavos, observações), ações por status: Rascunho → Enviar, Cancelar; Enviada → Copiar link (`navigator.clipboard.writeText(proposta.link)`), WhatsApp/E-mail (reusar `BotoesDeContato` com `negocioId`), Cancelar; Aceita → "Emitir nota" (só `temFiscal`; depois mostra links `/notas/{id}` para cada `notas_ids`); sempre Baixar PDF. `temFiscal` vem de `useAssinatura()` (`plano.modulos` contém `FISCAL_NFE` ou `FISCAL_NFSE`), calculado em `DetalheDoNegocio` e passado como prop.

- [ ] **Step 1: testes que falham** (padrão de `BlocoCrmNoPainel.test.tsx`: `vi.mock('../api')` espalhando `real.crmApi`)
  - lista mostra status e total; Rascunho mostra Enviar e Cancelar, não mostra Copiar link.
  - Enviada mostra "Copiar link" e chama `navigator.clipboard.writeText` com o link.
  - Aceita com `temFiscal` mostra "Emitir nota" e, após `notas_ids` preenchido, links `/notas/n1`.
  - Aceita sem `temFiscal` não mostra "Emitir nota".
  - `podeEscrever=false` esconde ações de escrita, mantém PDF.
  - erro da API vira `toast.error` (mock de `sonner`).

- [ ] **Step 2: rodar e ver falhar**

Run: `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run features/crm/components/PropostasDoNegocio.test.tsx`
Expected: FAIL (módulo não existe).

- [ ] **Step 3: implementar** conforme Interfaces, seguindo `AtividadesDoNegocio.tsx` e `useAtividades.ts` (mesmos helpers `mensagem`, mesma invalidação).

- [ ] **Step 4: passar + checagens**

Run: `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run features/crm && npx tsc --noEmit && npx eslint features/crm`
Expected: tudo verde.

- [ ] **Step 5: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add frontend/features/crm
git commit -m "feat(crm): aba de propostas no negócio e novos registros na timeline" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 9: Frontend — página pública `/p/[token]`

**Files:**
- Create: `frontend/features/crm/components/PaginaPublicaDaProposta.tsx`, `frontend/features/crm/publico.ts` (cliente fino da API pública: `lerProposta`, `aceitarProposta`, `recusarProposta`, `urlDoPdf`)
- Create: `frontend/app/p/[token]/page.tsx`
- Test: `frontend/features/crm/components/PaginaPublicaDaProposta.test.tsx`

**Interfaces:**
- Antes: ler `frontend/node_modules/next/dist/docs/` (App Router, `params` assíncrono) e o `frontend/app/layout.tsx` para confirmar que a rota fora de `(app)` renderiza sem menu/sessão.
- `publico.ts` usa `api()` de `@/lib/api` com `/api/public/propostas/${token}`; tipo `PropostaPublica` = retorno de `paraPublico` (Task 4).
- Componente `PaginaPublicaDaProposta({ token })` com `useState`/`useEffect` (sem TanStack Query). Estados: carregando; 404 → "Proposta não encontrada ou indisponível."; `ENVIADA` → itens, totais, validade, botão "Baixar PDF", campo "Seu nome" + "Aceitar proposta", botão "Recusar" que abre campo de motivo opcional e confirma; `ACEITA` → "Proposta aceita por {nome} em {data}"; `RECUSADA`; `EXPIRADA` → "Proposta expirada. Fale com {empresa}"; erros de regra (422) em `Banner tom="perigo"`. Sem `any`.

- [ ] **Step 1: testes que falham**
  - Enviada: aceitar exige nome (botão desabilitado sem nome), chama `aceitarProposta(token, nome)` e passa a mostrar "Proposta aceita".
  - Recusar com motivo chama `recusarProposta(token, motivo)`.
  - 404 mostra mensagem genérica.
  - Expirada não mostra botões de resposta.
  - Aceita já respondida mostra o nome de quem aceitou, sem botões.

- [ ] **Step 2: rodar e ver falhar**

Run: `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run features/crm/components/PaginaPublicaDaProposta.test.tsx`
Expected: FAIL.

- [ ] **Step 3: implementar** (`page.tsx`: `export default async function PropostaPublicaPage({ params }: { params: Promise<{ token: string }> }) { const { token } = await params; return <PaginaPublicaDaProposta token={token} />; }`; adicionar `export const metadata = { robots: { index: false, follow: false }, title: 'Proposta' }` na página; o componente é `'use client'`).

- [ ] **Step 4: passar + suíte frontend**

Run: `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run && npx tsc --noEmit && npx eslint features/crm app/p && npx next build`
Expected: verde; `next build` lista a rota `/p/[token]`.

- [ ] **Step 5: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add frontend/features/crm frontend/app/p
git commit -m "feat(crm): página pública da proposta (aceitar, recusar, PDF)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

---

### Task 10: Verificação final e documentação

**Files:**
- Modify: `PROGRESSO.md`, `TAREFAS.md`, `docs/superpowers/specs/2026-10-13-crm-f7c-propostas-design.md` (pendências)
- Modify: `docs/` da API pública se houver `/docs/api` listando rotas: **não** (a rota pública não é API de integração; registrar essa decisão em PROGRESSO).

- [ ] **Step 1: suíte completa e ferramentas**

Run: `cd /c/Users/dougl/workspace/crm/backend && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G`
Expected: tudo verde (a contagem anterior era 1424 testes; anotar a nova).
Run: `cd /c/Users/dougl/workspace/crm/frontend && npx vitest run && npx tsc --noEmit && npx eslint . && npx next build`
Expected: verde (anterior: 411 testes). Se o `eslint .` apontar só os arquivos não relacionados (`CadastroForm.tsx`, `eslint-rules/`), registrar como preexistente e não tocar.

- [ ] **Step 2: revisão final (auto-revisão declarada, sem subagente por regra do usuário)**

Ler o diff completo da F7c (`git diff e1e9fa2..HEAD -- backend/app frontend/features frontend/app/p`) procurando: `empty()` ou `?? 0` em valor; `withoutTenantScope` fora do `ResolvedorDePropostaPublica`; dado interno no JSON público; rota sem teste de isolamento; `any`. Corrigir com teste que falha antes (TDD).

- [ ] **Step 3: documentação**

`PROGRESSO.md`: seção "Fechamento da F7c" (o que foi feito, rulings do plano, o que não foi verificado: concorrência real do aceite em PostgreSQL, fumaça no navegador, PDF visual) e "Próxima tarefa" = F7d. `TAREFAS.md`: marcar F7c; "Pendências da F7c": modelo NFC-e não gerado pela proposta; sem e-mail pelo servidor; reenvio/renovação de proposta expirada; edição de rascunho após envio exige cancelar e criar outra; aviso de privacidade na página pública; `aceite_ip` na política de privacidade. Ajustar a spec (pendências resolvidas: contrato Crm→Fiscal por `GeradorDeRascunhoDeNota`; rota pública em `CrmServiceProvider` com `throttle:60,1`).

- [ ] **Step 4: commit**

```bash
cd /c/Users/dougl/workspace/crm && git add PROGRESSO.md TAREFAS.md docs/superpowers/specs/2026-10-13-crm-f7c-propostas-design.md
git commit -m "docs: F7c concluída (propostas, aceite público e nota em 1 clique)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u"
```

- [ ] **Step 5:** recomendar `/compact`; push só com confirmação do usuário (mesmo modelo da F7b).
