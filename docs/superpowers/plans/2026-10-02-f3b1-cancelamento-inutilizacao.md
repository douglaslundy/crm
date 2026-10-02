# F3b1: cancelamento e inutilização da NF-e: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** o usuário fiscal cancela uma NF-e autorizada (fila, estado `CANCELANDO`, reconciliação) e inutiliza faixas de numeração perdidas, sem nunca ficar em dúvida sobre o estado real na SEFAZ.

**Architecture:** o módulo `Fiscal` ganha `Justificativa` (value object), as Actions `IniciarCancelamento`, `AplicarResultadoDoCancelamento` e `InutilizarNumeracao`, o `CancelarNotaJob`, o Model `Inutilizacao` e métodos novos no contrato `EmissorDeNfe` (`cancelar`, `inutilizar`, e `consultarPorChave` passa a receber o ambiente da nota). A reconciliação existente passa a tratar `CANCELANDO`. O frontend estende `features/notas`.

**Tech Stack:** Laravel 12 / PHP 8.2, `nfephp-org/sped-nfe` 5.2.8 (já instalado), fila `database`, Next 16 + React + TanStack Query 5 + zod 4 + react-hook-form + vitest.

**Spec:** `docs/superpowers/specs/2026-10-02-f3b1-cancelamento-inutilizacao-design.md` (base: `docs/superpowers/specs/2026-10-01-f3a-nfe-emissao-design.md`).

## Global Constraints

- `declare(strict_types=1)` em todo PHP. TypeScript strict, sem `any`.
- **Nunca chutar valor fiscal.** Dado ausente bloqueia com mensagem acionável (`EmissaoBloqueadaException`, código `FISCAL_*`). Zero é válido: usar `=== null`, nunca `empty()` nem `?? 0`.
- **O prazo de cancelamento é decisão da SEFAZ:** nenhum número de prazo no código; recusa vira `cStat` + motivo.
- Justificativa: **15 a 255 caracteres** após sanear.
- Regra tributária fica fora do emissor; o `NfePhpEmissor` só traduz formato.
- Frontend e API pública chamam as mesmas Actions; nenhuma regra no controller.
- Todo Model de tenant usa `BelongsToTenant`; todo Model e endpoint novo ganha teste de isolamento.
- Schema só por migration. Segredos e XMLs nunca no JSON (`$hidden`). Nenhum dado real em fixtures.
- Dinheiro em centavos inteiros. Erros de negócio estendem `ErroDeNegocio` (`{message, codigo}`).
- Papéis que cancelam e inutilizam: PROPRIETARIO, ADMIN e FISCAL (`PoliticaDeNotas::garantirPodeEmitir`). VENDEDOR e LEITURA recusados.
- Nota cancelada continua contando na cota `DOCUMENTOS_MES` (decisão do usuário).
- Windows: arquivos voltam com CRLF; use Write/Edit (não scripts Python com barras invertidas nem heredocs longos com `\`). Comandos PHP rodam em `backend/`, os do frontend em `frontend/`.
- Testes de integração trocam de usuário com `$this->spaComo($usuario)` (helper do `TestCase`), nunca `spa()->actingAs()` repetido.
- Escopo: só NF-e modelo 55. NFC-e, PDF, contingência, cancelamento por substituição e extemporâneo ficam fora.

## Review Focus

Entradas que o spec implica e nenhuma tarefa de comportamento principal exercita; cada uma tem teste na tarefa dona.

1. **Duplo clique em "Cancelar"** (ou `POST /cancelar` repetido com a nota já `CANCELANDO`/`CANCELADA`): não despacha segundo job nem muda nada (Tarefa 5).
2. **Nota de homologação com o emitente já em produção:** consulta e cancelamento vão ao ambiente **da nota**, nunca ao atual do emitente (Tarefas 4 e 6).
3. **Cancelamento aceito pela SEFAZ mas resposta perdida (timeout):** a nota fica `CANCELANDO` e a reconciliação a leva a `CANCELADA`; nunca volta a `AUTORIZADA` antes do prazo (Tarefas 6 e 7).
4. **Justificativa só com espaços, só com emoji, com acentos, com 14, 15, 255 e 256 caracteres:** limites exatos e texto saneado (Tarefa 2).
5. **Inutilizar faixa que inclui número com nota autorizada ou número ainda não alocado:** bloqueia citando o número, sem chamar a SEFAZ (Tarefa 8).

---

## Estrutura de arquivos

Backend (`backend/`), em `app/Modules/Fiscal/` salvo indicação:

| Arquivo | Responsabilidade |
|---|---|
| `database/migrations/2026_10_03_000001_add_cancelamento_e_inutilizacoes.php` | colunas de cancelamento em `notas` e tabela `inutilizacoes` |
| `Domain/Enums/StatusNota.php` (modifica), `Domain/Enums/StatusInutilizacao.php` | `Cancelando`; status da inutilização |
| `Domain/Models/Nota.php` (modifica), `Domain/Models/Inutilizacao.php`, `database/factories/InutilizacaoFactory.php` | modelo de dados |
| `Domain/Justificativa.php` | value object 15 a 255 com sanitização |
| `Domain/Exceptions/{JustificativaInvalidaException,CancelamentoNaoPermitidoException,NotaSemXmlCancelamentoException}.php` | erros de negócio |
| `Domain/Emissao/{ResultadoDeCancelamento,ResultadoDeInutilizacao}.php` | DTOs |
| `Domain/Contracts/EmissorDeNfe.php` (modifica) | `cancelar`, `inutilizar`, `consultarPorChave` com ambiente |
| `Infrastructure/NfePhp/{InterpretadorDeResposta,NfePhpEmissor}.php` (modificam) | motor real |
| `Application/Actions/{IniciarCancelamento,AplicarResultadoDoCancelamento,InutilizarNumeracao}.php`, `Application/Actions/ConciliarNota.php` (modifica) | casos de uso |
| `Application/Jobs/CancelarNotaJob.php` | fila |
| `Application/ContadorDeDocumentosMes.php`, `Console/ReconciliarNotasCommand.php` (modificam) | cota e varredura |
| `Http/{Controllers/NotaController,Controllers/InutilizacaoController,Requests/*,Resources/*}`, `routes-app.php` | API |
| `tests/Fixtures/EmissorFake.php` (modifica) | apoio de teste |

Frontend (`frontend/`): `features/notas/{types,schemas,api}.ts` (modificam), `features/notas/components/{StatusDaNota,DetalheDaNota,FormInutilizacao,ListaDeInutilizacoes}.tsx`, `features/notas/components/Notas.test.tsx` (cresce), `app/(app)/notas/page.tsx` (link) e `app/(app)/notas/inutilizar/page.tsx`.

---

### Task 1: Migration, status `Cancelando`, Model `Inutilizacao` e colunas de cancelamento

**Files:**
- Create: `backend/database/migrations/2026_10_03_000001_add_cancelamento_e_inutilizacoes.php`
- Create: `app/Modules/Fiscal/Domain/Enums/StatusInutilizacao.php`
- Create: `app/Modules/Fiscal/Domain/Models/Inutilizacao.php`
- Create: `backend/database/factories/InutilizacaoFactory.php`
- Modify: `app/Modules/Fiscal/Domain/Enums/StatusNota.php`, `app/Modules/Fiscal/Domain/Models/Nota.php`
- Test: `backend/tests/Feature/Fiscal/InutilizacaoIsolamentoTest.php`, `backend/tests/Unit/Fiscal/StatusNotaTest.php`

**Interfaces:**
- Produces:
  - `StatusNota::Cancelando` (`'CANCELANDO'`), `editavel()` continua `false` para ele.
  - `StatusInutilizacao` (`Inutilizada='INUTILIZADA' | Recusada='RECUSADA' | Erro='ERRO'`).
  - `Nota` ganha as propriedades `cancelamento_solicitado_em`, `cancelamento_justificativa`, `cancelamento_protocolo`, `cancelado_em`, `cancelamento_cstat`, `cancelamento_motivo`, `xml_cancelamento` (oculta no JSON).
  - `Inutilizacao` (`BelongsToTenant`, `HasUuids`, `HasFactory`): `emitente_id`, `serie`, `numero_inicial`, `numero_final`, `justificativa`, `ambiente`, `status: StatusInutilizacao`, `cstat`, `motivo`, `protocolo`, `xml_resposta` (oculto), `usuario_id`, `created_at`.

- [ ] **Step 1: Escrever os testes**

`tests/Unit/Fiscal/StatusNotaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use PHPUnit\Framework\TestCase;

class StatusNotaTest extends TestCase
{
    public function test_cancelando_nao_e_editavel(): void
    {
        $this->assertSame('CANCELANDO', StatusNota::Cancelando->value);
        $this->assertFalse(StatusNota::Cancelando->editavel());
    }

    public function test_so_rascunho_rejeitada_e_erro_sao_editaveis(): void
    {
        $editaveis = array_filter(StatusNota::cases(), fn (StatusNota $s): bool => $s->editavel());
        $this->assertEqualsCanonicalizing(
            [StatusNota::Rascunho, StatusNota::Rejeitada, StatusNota::Erro],
            array_values($editaveis),
        );
    }
}
```

`tests/Feature/Fiscal/InutilizacaoIsolamentoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InutilizacaoIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_inutilizacao_e_isolada_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        $registro = Inutilizacao::factory()->create();
        $this->assertSame($tenantA->id, $registro->tenant_id);

        $contexto->set($tenantB->id);
        $this->assertSame(0, Inutilizacao::query()->count());
    }

    public function test_xml_da_resposta_nao_vai_para_o_json(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);
        $registro = Inutilizacao::factory()->create(['xml_resposta' => '<retInutNFe/>']);

        $this->assertArrayNotHasKey('xml_resposta', $registro->toArray());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="StatusNotaTest|InutilizacaoIsolamentoTest"`
Expected: FAIL (`Cancelando` e `Inutilizacao` inexistentes).

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
        Schema::table('notas', function (Blueprint $table): void {
            $table->timestamp('cancelamento_solicitado_em')->nullable();
            $table->text('cancelamento_justificativa')->nullable();
            $table->string('cancelamento_protocolo', 20)->nullable();
            $table->timestamp('cancelado_em')->nullable();
            $table->string('cancelamento_cstat', 4)->nullable();
            $table->text('cancelamento_motivo')->nullable();
            $table->longText('xml_cancelamento')->nullable();
        });

        Schema::create('inutilizacoes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('emitente_id')->constrained('emitentes');
            $table->string('serie', 3);
            $table->unsignedBigInteger('numero_inicial');
            $table->unsignedBigInteger('numero_final');
            $table->text('justificativa');
            $table->string('ambiente', 12);
            $table->string('status', 12);
            $table->string('cstat', 4)->nullable();
            $table->text('motivo')->nullable();
            $table->string('protocolo', 20)->nullable();
            $table->longText('xml_resposta')->nullable();
            $table->foreignUuid('usuario_id')->nullable()->constrained('usuarios');
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inutilizacoes');
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropColumn([
                'cancelamento_solicitado_em', 'cancelamento_justificativa', 'cancelamento_protocolo', 'cancelado_em',
                'cancelamento_cstat', 'cancelamento_motivo', 'xml_cancelamento',
            ]);
        });
    }
};
```

- [ ] **Step 4: Enums e Models**

Em `StatusNota.php`, acrescente o case (depois de `Cancelada`): `case Cancelando = 'CANCELANDO';`. `editavel()` não muda (já só aceita Rascunho, Rejeitada, Erro).

`StatusInutilizacao.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum StatusInutilizacao: string
{
    case Inutilizada = 'INUTILIZADA';
    case Recusada = 'RECUSADA';
    case Erro = 'ERRO';
}
```

`Inutilizacao.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\InutilizacaoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $emitente_id
 * @property string $serie
 * @property int $numero_inicial
 * @property int $numero_final
 * @property string $justificativa
 * @property string $ambiente
 * @property StatusInutilizacao $status
 * @property ?string $cstat
 * @property ?string $motivo
 * @property ?string $protocolo
 * @property ?string $xml_resposta
 * @property ?string $usuario_id
 * @property CarbonInterface $created_at
 */
class Inutilizacao extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InutilizacaoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'inutilizacoes';

    protected $fillable = [
        'emitente_id', 'serie', 'numero_inicial', 'numero_final', 'justificativa', 'ambiente',
        'status', 'cstat', 'motivo', 'protocolo', 'xml_resposta', 'usuario_id',
    ];

    protected $hidden = ['xml_resposta'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => StatusInutilizacao::class];
    }

    protected static function newFactory(): InutilizacaoFactory
    {
        return InutilizacaoFactory::new();
    }
}
```

`database/factories/InutilizacaoFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inutilizacao> */
class InutilizacaoFactory extends Factory
{
    protected $model = Inutilizacao::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'emitente_id' => Emitente::factory(),
            'serie' => '1',
            'numero_inicial' => 1,
            'numero_final' => 1,
            'justificativa' => 'Numeração perdida por falha de comunicação',
            'ambiente' => 'HOMOLOGACAO',
            'status' => StatusInutilizacao::Inutilizada,
        ];
    }
}
```

Em `Nota.php`: acrescente ao docblock `@property ?CarbonInterface $cancelamento_solicitado_em`, `@property ?string $cancelamento_justificativa`, `@property ?string $cancelamento_protocolo`, `@property ?CarbonInterface $cancelado_em`, `@property ?string $cancelamento_cstat`, `@property ?string $cancelamento_motivo`, `@property ?string $xml_cancelamento`; troque `protected $hidden = ['xml_enviado', 'xml_autorizado'];` por `protected $hidden = ['xml_enviado', 'xml_autorizado', 'xml_cancelamento'];` e acrescente em `casts()`: `'cancelamento_solicitado_em' => 'datetime', 'cancelado_em' => 'datetime',`.

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter="StatusNotaTest|InutilizacaoIsolamentoTest|NotaIsolamentoTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database backend/app/Modules/Fiscal/Domain backend/tests
git commit -m "feat(fiscal): status CANCELANDO, colunas de cancelamento e modelo de inutilização"
```

---

### Task 2: `Justificativa` e erros de negócio do cancelamento

**Files:**
- Create: `app/Modules/Fiscal/Domain/Justificativa.php`
- Create: `app/Modules/Fiscal/Domain/Exceptions/{JustificativaInvalidaException,CancelamentoNaoPermitidoException,NotaSemXmlCancelamentoException}.php`
- Test: `backend/tests/Unit/Fiscal/JustificativaTest.php`

**Interfaces:**
- Produces:
  - `Justificativa::de(string $texto): self` com `public readonly string $texto` já saneado; lança `JustificativaInvalidaException` (422, código `JUSTIFICATIVA_INVALIDA`).
  - `CancelamentoNaoPermitidoException::__construct(string $mensagem)` (409, `CANCELAMENTO_NAO_PERMITIDO`).
  - `NotaSemXmlCancelamentoException` (409, `NOTA_SEM_XML_CANCELAMENTO`).

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Exceptions\JustificativaInvalidaException;
use App\Modules\Fiscal\Domain\Justificativa;
use PHPUnit\Framework\TestCase;

class JustificativaTest extends TestCase
{
    public function test_aceita_exatamente_15_e_255_caracteres(): void
    {
        $this->assertSame(str_repeat('a', 15), Justificativa::de(str_repeat('a', 15))->texto);
        $this->assertSame(255, mb_strlen(Justificativa::de(str_repeat('b', 255))->texto));
    }

    public function test_recusa_14_e_256_caracteres(): void
    {
        foreach ([14, 256] as $tamanho) {
            try {
                Justificativa::de(str_repeat('a', $tamanho));
                $this->fail("Aceitou {$tamanho} caracteres.");
            } catch (JustificativaInvalidaException $e) {
                $this->assertSame('JUSTIFICATIVA_INVALIDA', $e->codigo());
                $this->assertStringContainsString('15', $e->getMessage());
                $this->assertStringContainsString('255', $e->getMessage());
            }
        }
    }

    public function test_so_espacos_ou_so_emoji_nao_conta_como_texto(): void
    {
        foreach ([str_repeat(' ', 40), str_repeat("\u{1F600}", 20), "\t\n\r".str_repeat(' ', 30)] as $texto) {
            try {
                Justificativa::de($texto);
                $this->fail('Aceitou texto sem conteúdo.');
            } catch (JustificativaInvalidaException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_acentos_sao_preservados_e_espacos_colapsados(): void
    {
        $j = Justificativa::de("  Cancelamento   por erro de   digitação \u{1F600} do valor\n total ");
        $this->assertSame('Cancelamento por erro de digitação do valor total', $j->texto);
    }

    public function test_o_tamanho_conta_depois_de_sanear(): void
    {
        // 7 + 1 + 6 = 14 caracteres úteis depois de colapsar os espaços: inválida.
        $this->expectException(JustificativaInvalidaException::class);
        Justificativa::de('abcdefg        hijklm');
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=JustificativaTest`
Expected: FAIL (classe inexistente).

- [ ] **Step 3: Implementar**

`JustificativaInvalidaException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class JustificativaInvalidaException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('A justificativa deve ter de 15 a 255 caracteres (sem contar espaços repetidos nem emojis).');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'JUSTIFICATIVA_INVALIDA';
    }
}
```

`CancelamentoNaoPermitidoException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class CancelamentoNaoPermitidoException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Só é possível cancelar uma NF-e autorizada.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 409;
    }

    public function codigo(): string
    {
        return 'CANCELAMENTO_NAO_PERMITIDO';
    }
}
```

`NotaSemXmlCancelamentoException.php` (mesmo molde, 409, `NOTA_SEM_XML_CANCELAMENTO`, mensagem padrão `'Só notas canceladas têm XML de cancelamento para baixar.'`).

`Justificativa.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use App\Modules\Fiscal\Domain\Exceptions\JustificativaInvalidaException;

/** Justificativa de cancelamento ou inutilização: 15 a 255 caracteres depois de sanear (regra da SEFAZ). */
final readonly class Justificativa
{
    private const MINIMO = 15;

    private const MAXIMO = 255;

    private function __construct(public string $texto) {}

    public static function de(string $texto): self
    {
        $limpo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto) ?? '';
        $limpo = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $limpo) ?? '';
        $limpo = trim((string) preg_replace('/\s+/u', ' ', $limpo));

        $tamanho = mb_strlen($limpo);
        if ($tamanho < self::MINIMO || $tamanho > self::MAXIMO) {
            throw new JustificativaInvalidaException;
        }

        return new self($limpo);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=JustificativaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal/Domain backend/tests/Unit/Fiscal/JustificativaTest.php
git commit -m "feat(fiscal): Justificativa (15 a 255) e erros de negócio do cancelamento"
```

---

### Task 3: DTOs de resultado e interpretador de cancelamento e inutilização

**Files:**
- Create: `app/Modules/Fiscal/Domain/Emissao/{ResultadoDeCancelamento,ResultadoDeInutilizacao}.php`
- Modify: `app/Modules/Fiscal/Infrastructure/NfePhp/InterpretadorDeResposta.php`
- Modify: `docs/superpowers/specs/2026-10-02-f3b1-cancelamento-inutilizacao-design.md` (§3.4: o XML do cancelamento é o `nfeProc` com o `retEvento` anexado, via `Complements::cancelRegister`)
- Test: `backend/tests/Unit/Fiscal/InterpretadorCancelamentoTest.php`

**Interfaces:**
- Produces (`final readonly`):
  - `ResultadoDeCancelamento(bool $aceito, ?string $cstat, ?string $motivo, ?string $protocolo, ?string $xmlCancelamento)`; fábricas `aceito(string $protocolo, string $xmlCancelamento, string $cstat = '135')` e `recusado(string $cstat, string $motivo)`.
  - `ResultadoDeInutilizacao(StatusInutilizacao $status, ?string $cstat, ?string $motivo, ?string $protocolo, ?string $xmlResposta)`; fábricas `inutilizada(string $protocolo, string $xmlResposta, string $cstat = '102')`, `recusada(string $cstat, string $motivo)`, `jaInutilizada(string $motivo)` (status Recusada, cstat `563`) e `erro(string $mensagem)`.
  - `InterpretadorDeResposta::cancelamento(string $respostaXml, string $xmlAutorizado): ResultadoDeCancelamento` e `::inutilizacao(string $respostaXml): ResultadoDeInutilizacao`. Resposta ilegível ou sem `cStat` **lança `RuntimeException`** (falha técnica: o job mantém `CANCELANDO`).

- [ ] **Step 1: Teste (respostas XML escritas à mão, sem dado real)**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;
use App\Modules\Fiscal\Infrastructure\NfePhp\InterpretadorDeResposta;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InterpretadorCancelamentoTest extends TestCase
{
    private const CHAVE = '35261011444777000161550010000000071123456789';

    private function nfeProc(): string
    {
        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe'.self::CHAVE.'"/></NFe>'
            .'<protNFe versao="4.00"><infProt><chNFe>'.self::CHAVE.'</chNFe><nProt>135260000000001</nProt><cStat>100</cStat></infProt></protNFe></nfeProc>';
    }

    private function evento(string $cStat, string $motivo = 'Evento registrado e vinculado a NF-e', string $cStatLote = '128'): string
    {
        return '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><cStat>'.$cStatLote.'</cStat><xMotivo>Lote de Evento Processado</xMotivo>'
            .'<retEvento versao="1.00"><infEvento><cStat>'.$cStat.'</cStat><xMotivo>'.$motivo.'</xMotivo><chNFe>'.self::CHAVE.'</chNFe><tpEvento>110111</tpEvento><nProt>135260000000555</nProt></infEvento></retEvento></retEnvEvento>';
    }

    public function test_135_136_e_155_sao_cancelamento_aceito_e_o_xml_traz_o_evento(): void
    {
        foreach (['135', '136', '155'] as $cStat) {
            $r = (new InterpretadorDeResposta)->cancelamento($this->evento($cStat), $this->nfeProc());

            $this->assertTrue($r->aceito, $cStat);
            $this->assertSame($cStat, $r->cstat);
            $this->assertSame('135260000000555', $r->protocolo);
            $this->assertStringContainsString('<nfeProc', (string) $r->xmlCancelamento);
            $this->assertStringContainsString('<retEvento', (string) $r->xmlCancelamento);
        }
    }

    public function test_recusa_da_sefaz_traz_cstat_e_motivo(): void
    {
        $r = (new InterpretadorDeResposta)->cancelamento($this->evento('501', 'Rejeicao: Pedido de Cancelamento intempestivo'), $this->nfeProc());

        $this->assertFalse($r->aceito);
        $this->assertSame('501', $r->cstat);
        $this->assertStringContainsString('intempestivo', (string) $r->motivo);
        $this->assertNull($r->xmlCancelamento);
    }

    public function test_lote_rejeitado_sem_retevento_e_recusa(): void
    {
        $xml = '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><cStat>215</cStat><xMotivo>Rejeicao: Falha no schema XML</xMotivo></retEnvEvento>';
        $r = (new InterpretadorDeResposta)->cancelamento($xml, $this->nfeProc());

        $this->assertFalse($r->aceito);
        $this->assertSame('215', $r->cstat);
    }

    public function test_aceito_sem_conseguir_montar_o_xml_guarda_a_resposta_crua(): void
    {
        $r = (new InterpretadorDeResposta)->cancelamento($this->evento('135'), '<nao-e-nfeproc/>');

        $this->assertTrue($r->aceito, 'a SEFAZ aceitou: continua sendo cancelamento');
        $this->assertStringContainsString('<retEvento', (string) $r->xmlCancelamento);
    }

    public function test_resposta_ilegivel_ou_sem_status_lanca_excecao(): void
    {
        foreach (['isto nao e xml', '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe"/>'] as $xml) {
            try {
                (new InterpretadorDeResposta)->cancelamento($xml, $this->nfeProc());
                $this->fail('Esperava RuntimeException.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_inutilizacao_102_563_e_outros(): void
    {
        $mk = fn (string $c, string $m = 'm', string $prot = ''): string => '<retInutNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><infInut><tpAmb>2</tpAmb><cStat>'.$c.'</cStat><xMotivo>'.$m.'</xMotivo>'
            .($prot !== '' ? '<nProt>'.$prot.'</nProt>' : '').'</infInut></retInutNFe>';
        $i = new InterpretadorDeResposta;

        $ok = $i->inutilizacao($mk('102', 'Inutilizacao de numero homologado', '135260000000777'));
        $this->assertSame(StatusInutilizacao::Inutilizada, $ok->status);
        $this->assertSame('135260000000777', $ok->protocolo);
        $this->assertStringContainsString('<retInutNFe', (string) $ok->xmlResposta);

        $dup = $i->inutilizacao($mk('563', 'Rejeicao: Ja existe pedido de Inutilizacao com a mesma faixa de inutilizacao'));
        $this->assertSame(StatusInutilizacao::Recusada, $dup->status);
        $this->assertSame('563', $dup->cstat);
        $this->assertStringContainsString('já foi inutilizada', (string) $dup->motivo);

        $rec = $i->inutilizacao($mk('241', 'Rejeicao: Um numero da faixa ja foi utilizado'));
        $this->assertSame(StatusInutilizacao::Recusada, $rec->status);
        $this->assertSame('241', $rec->cstat);
    }

    public function test_inutilizacao_ilegivel_lanca_excecao(): void
    {
        $this->expectException(RuntimeException::class);
        (new InterpretadorDeResposta)->inutilizacao('lixo');
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=InterpretadorCancelamentoTest`
Expected: FAIL (métodos e DTOs inexistentes).

- [ ] **Step 3: DTOs**

`ResultadoDeCancelamento.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class ResultadoDeCancelamento
{
    public function __construct(
        public bool $aceito,
        public ?string $cstat,
        public ?string $motivo,
        public ?string $protocolo,
        public ?string $xmlCancelamento,
    ) {}

    public static function aceito(string $protocolo, string $xmlCancelamento, string $cstat = '135'): self
    {
        return new self(true, $cstat, 'Cancelamento de NF-e homologado', $protocolo, $xmlCancelamento);
    }

    public static function recusado(string $cstat, string $motivo): self
    {
        return new self(false, $cstat, $motivo, null, null);
    }
}
```

`ResultadoDeInutilizacao.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;

final readonly class ResultadoDeInutilizacao
{
    public function __construct(
        public StatusInutilizacao $status,
        public ?string $cstat,
        public ?string $motivo,
        public ?string $protocolo,
        public ?string $xmlResposta,
    ) {}

    public static function inutilizada(string $protocolo, string $xmlResposta, string $cstat = '102'): self
    {
        return new self(StatusInutilizacao::Inutilizada, $cstat, 'Inutilização de número homologado', $protocolo, $xmlResposta);
    }

    public static function recusada(string $cstat, string $motivo): self
    {
        return new self(StatusInutilizacao::Recusada, $cstat, $motivo, null, null);
    }

    public static function jaInutilizada(string $motivo): self
    {
        return new self(StatusInutilizacao::Recusada, '563', 'Esta faixa já foi inutilizada na SEFAZ. ('.$motivo.')', null, null);
    }

    public static function erro(string $mensagem): self
    {
        return new self(StatusInutilizacao::Erro, null, $mensagem, null, null);
    }
}
```

- [ ] **Step 4: Métodos do interpretador**

Em `InterpretadorDeResposta.php`, acrescente (e os `use` de `ResultadoDeCancelamento`, `ResultadoDeInutilizacao` e `RuntimeException`):

```php
    public function cancelamento(string $respostaXml, string $xmlAutorizado): ResultadoDeCancelamento
    {
        $resp = $this->carregar($respostaXml);
        if ($resp === null) {
            throw new RuntimeException('Resposta ilegível da SEFAZ ao cancelar a nota.');
        }
        $resp->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $evento = $resp->xpath('//n:retEvento/n:infEvento')[0] ?? null;

        if ($evento === null) {
            // Sem retEvento: o lote foi recusado antes de chegar ao evento (o cStat de topo é o do lote).
            $cStat = (string) ($resp->xpath('//n:cStat')[0] ?? '');
            if ($cStat === '') {
                throw new RuntimeException('Resposta da SEFAZ sem status ao cancelar a nota.');
            }

            return ResultadoDeCancelamento::recusado($cStat, (string) ($resp->xpath('//n:xMotivo')[0] ?? ''));
        }

        // O cStat que vale é o de dentro de retEvento; o de topo é do lote.
        $cStat = (string) $evento->cStat;
        if (! in_array($cStat, ['135', '136', '155'], true)) {
            return ResultadoDeCancelamento::recusado($cStat, (string) $evento->xMotivo);
        }

        try {
            $xml = Complements::cancelRegister($xmlAutorizado, $respostaXml);
        } catch (Throwable) {
            // A SEFAZ aceitou: continua sendo cancelamento. Sem o nfeProc, guardamos a resposta crua do evento.
            $xml = $respostaXml;
        }

        return ResultadoDeCancelamento::aceito((string) $evento->nProt, $xml, $cStat);
    }

    public function inutilizacao(string $respostaXml): ResultadoDeInutilizacao
    {
        $resp = $this->carregar($respostaXml);
        if ($resp === null) {
            throw new RuntimeException('Resposta ilegível da SEFAZ ao inutilizar a numeração.');
        }
        $resp->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $cStat = (string) ($resp->xpath('//n:infInut/n:cStat')[0] ?? '');
        $motivo = (string) ($resp->xpath('//n:infInut/n:xMotivo')[0] ?? '');
        if ($cStat === '') {
            throw new RuntimeException('Resposta da SEFAZ sem status ao inutilizar a numeração.');
        }

        return match ($cStat) {
            '102' => ResultadoDeInutilizacao::inutilizada((string) ($resp->xpath('//n:infInut/n:nProt')[0] ?? ''), $respostaXml),
            '563' => ResultadoDeInutilizacao::jaInutilizada($motivo),
            default => ResultadoDeInutilizacao::recusada($cStat, $motivo),
        };
    }
```

Na spec (§3.4), troque a frase sobre `procEventoNFe` por: "o XML do cancelamento é o `nfeProc` autorizado com o `retEvento` anexado, montado por `Complements::cancelRegister` (conferido no vendor 5.2.8); se não puder ser montado, guarda-se a resposta crua do evento".

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter="InterpretadorCancelamentoTest|InterpretadorDeRespostaTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests docs/superpowers/specs/2026-10-02-f3b1-cancelamento-inutilizacao-design.md
git commit -m "feat(fiscal): resultados e interpretador de cancelamento e inutilização"
```

---

### Task 4: Contrato do emissor, `EmissorFake`, `NfePhpEmissor` e ambiente da nota nas consultas

**Files:**
- Modify: `app/Modules/Fiscal/Domain/Contracts/EmissorDeNfe.php`
- Modify: `tests/Fixtures/EmissorFake.php`
- Modify: `app/Modules/Fiscal/Infrastructure/NfePhp/NfePhpEmissor.php`
- Modify (chamadores de `consultarPorChave`): `app/Modules/Fiscal/Application/Actions/ConciliarNota.php`, `app/Modules/Fiscal/Application/Jobs/EmitirNotaJob.php`, `app/Modules/Fiscal/Application/Actions/AplicarResultadoDaEmissao.php`
- Modify (testes): `tests/Feature/Fiscal/ReconciliarNotasTest.php`, `tests/Feature/Fiscal/EmitirNotaJobTest.php`

**Interfaces:**
- Produces:
  - `EmissorDeNfe::consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado, string $ambiente): ResultadoDeConsulta` (**assinatura nova**; `$ambiente` é `'HOMOLOGACAO'` ou `'PRODUCAO'`, o da nota).
  - `EmissorDeNfe::cancelar(Emitente $emitente, string $chave, string $protocolo, string $justificativa, string $ambiente, string $xmlAutorizado): ResultadoDeCancelamento` (o motor precisa do XML autorizado para montar o XML do cancelamento; acréscimo em relação à spec).
  - `EmissorDeNfe::inutilizar(Emitente $emitente, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao`.
  - `EmissorFake`: `public array $ambientes` (ambiente recebido em cada chamada de consulta, cancelamento e inutilização, em ordem); `enfileirarCancelamento(ResultadoDeCancelamento ...)`, `enfileirarInutilizacao(ResultadoDeInutilizacao ...)`, `public ?Throwable $falharEmCancelar`, `public ?Throwable $falharEmInutilizar`. Sem item na fila: `cancelar` devolve `aceito('135260000000099', '<nfeProc>cancelada</nfeProc>')` e `inutilizar` devolve `inutilizada('135260000000098', '<retInutNFe/>')`. Cada chamada acrescenta `cancelar` ou `inutilizar` a `chamadas`.

- [ ] **Step 1: Testes que fixam o ambiente da nota**

Em `ReconciliarNotasTest`, acrescente:

```php
    public function test_a_consulta_usa_o_ambiente_da_nota_e_nao_o_do_emitente(): void
    {
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        $nota = $this->processando(str_repeat('7', 44), 30); // a nota é de HOMOLOGACAO
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::naoEncontrada());

        $this->rodar();

        $this->assertSame(['HOMOLOGACAO'], $this->emissor->ambientes);
        $this->assertSame(StatusNota::Erro, $nota->refresh()->status);
    }
```

Em `EmitirNotaJobTest`, no teste `test_retry_com_chave_consulta_antes_de_reenviar_e_concilia_autorizada`, depois da última asserção, acrescente: `$this->assertSame(['HOMOLOGACAO'], $this->emissor->ambientes);`.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="ReconciliarNotasTest|EmitirNotaJobTest"`
Expected: FAIL (`ambientes` inexistente no fake).

- [ ] **Step 3: Contrato**

Em `EmissorDeNfe.php`, troque a assinatura de `consultarPorChave` e acrescente os dois métodos (e os `use` de `ResultadoDeCancelamento` e `ResultadoDeInutilizacao`):

```php
    /** `$ambiente` é o da NOTA (HOMOLOGACAO ou PRODUCAO), nunca o atual do emitente: ele pode ter mudado depois da emissão. */
    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado, string $ambiente): ResultadoDeConsulta;

    /** Envia o evento de cancelamento. Falha de comunicação lança exceção (a nota permanece CANCELANDO). */
    public function cancelar(Emitente $emitente, string $chave, string $protocolo, string $justificativa, string $ambiente, string $xmlAutorizado): ResultadoDeCancelamento;

    public function inutilizar(Emitente $emitente, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao;
```

- [ ] **Step 4: `EmissorFake`**

Substitua o método `consultarPorChave` e acrescente o restante (mantenha o que já existe; acrescente `use` de `ResultadoDeCancelamento` e `ResultadoDeInutilizacao`):

```php
    /** @var list<string> */
    public array $ambientes = [];

    public ?Throwable $falharEmCancelar = null;

    public ?Throwable $falharEmInutilizar = null;

    /** @var list<ResultadoDeCancelamento> */
    private array $cancelamentos = [];

    /** @var list<ResultadoDeInutilizacao> */
    private array $inutilizacoes = [];

    public function enfileirarCancelamento(ResultadoDeCancelamento ...$resultados): void
    {
        array_push($this->cancelamentos, ...$resultados);
    }

    public function enfileirarInutilizacao(ResultadoDeInutilizacao ...$resultados): void
    {
        array_push($this->inutilizacoes, ...$resultados);
    }

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado, string $ambiente): ResultadoDeConsulta
    {
        $this->chamadas[] = 'consultar';
        $this->ambientes[] = $ambiente;

        return array_shift($this->consultas) ?? ResultadoDeConsulta::naoEncontrada();
    }

    public function cancelar(Emitente $emitente, string $chave, string $protocolo, string $justificativa, string $ambiente, string $xmlAutorizado): ResultadoDeCancelamento
    {
        $this->chamadas[] = 'cancelar';
        $this->ambientes[] = $ambiente;
        if ($this->falharEmCancelar !== null) {
            throw $this->falharEmCancelar;
        }

        return array_shift($this->cancelamentos) ?? ResultadoDeCancelamento::aceito('135260000000099', '<nfeProc>cancelada</nfeProc>');
    }

    public function inutilizar(Emitente $emitente, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao
    {
        $this->chamadas[] = 'inutilizar';
        $this->ambientes[] = $ambiente;
        if ($this->falharEmInutilizar !== null) {
            throw $this->falharEmInutilizar;
        }

        return array_shift($this->inutilizacoes) ?? ResultadoDeInutilizacao::inutilizada('135260000000098', '<retInutNFe/>');
    }
```

(Apague a versão antiga de `consultarPorChave` do arquivo.)

- [ ] **Step 5: Chamadores**

- `ConciliarNota::executar`: `$this->emissor->consultarPorChave($emitente, $nota->chave, $nota->xml_enviado, (string) $nota->ambiente)`.
- `EmitirNotaJob::handle`: `$emissor->consultarPorChave($emitente, $nota->chave, $nota->xml_enviado, (string) $nota->ambiente)`.
- `AplicarResultadoDaEmissao::tratarNumeroOcupado`: `$this->emissor->consultarPorChave($emitente, $chave, null, (string) $nota->ambiente)`.

- [ ] **Step 6: `NfePhpEmissor`**

Substitua `consultarPorChave` e acrescente `cancelar` e `inutilizar`; extraia o helper `toolsDoTenant`:

```php
    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado, string $ambiente): ResultadoDeConsulta
    {
        $tools = $this->toolsDoTenant($emitente, $ambiente);

        return $this->interpretador->consulta($tools->sefazConsultaChave($chave, $this->tpAmb($ambiente)), $xmlEnviado);
    }

    public function cancelar(Emitente $emitente, string $chave, string $protocolo, string $justificativa, string $ambiente, string $xmlAutorizado): ResultadoDeCancelamento
    {
        $tools = $this->toolsDoTenant($emitente, $ambiente);

        return $this->interpretador->cancelamento($tools->sefazCancela($chave, $justificativa, $protocolo), $xmlAutorizado);
    }

    public function inutilizar(Emitente $emitente, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao
    {
        $tools = $this->toolsDoTenant($emitente, $ambiente);

        return $this->interpretador->inutilizacao(
            $tools->sefazInutiliza($serie, $numeroInicial, $numeroFinal, $justificativa, $this->tpAmb($ambiente)),
        );
    }

    private function toolsDoTenant(Emitente $emitente, string $ambiente): Tools
    {
        $tenant = Tenant::query()->findOrFail($emitente->tenant_id);

        return $this->tools($emitente, $tenant->razao_social, preg_replace('/\D/', '', $tenant->cnpj) ?? '', $ambiente);
    }

    private function tpAmb(string $ambiente): int
    {
        return $ambiente === 'PRODUCAO' ? 1 : 2;
    }
```

Acrescente os `use` de `ResultadoDeCancelamento` e `ResultadoDeInutilizacao`. Confirme com `grep -n "public function sefazConsultaChave\|public function sefazCancela\|public function sefazInutiliza" vendor/nfephp-org/sped-nfe/src/Tools.php` que as assinaturas são `(string $chave, ?int $tpAmb)`, `(string $chave, string $xJust, string $nProt, ...)` e `(int $nSerie, int $nIni, int $nFin, string $xJust, ?int $tpAmb, ?string $ano)`.

- [ ] **Step 7: Rodar e ver passar**

Run: `php artisan test tests/Unit/Fiscal tests/Feature/Fiscal`
Expected: PASS (todos os testes da F3a continuam passando com a assinatura nova).

- [ ] **Step 8: Commit**

```bash
git add backend/app backend/tests
git commit -m "feat(fiscal): contrato com cancelar e inutilizar; consultas usam o ambiente da nota"
```

---

### Task 5: `IniciarCancelamento` (validações, trava, idempotência e despacho)

**Files:**
- Create: `app/Modules/Fiscal/Application/Actions/IniciarCancelamento.php`
- Create: `app/Modules/Fiscal/Application/Jobs/CancelarNotaJob.php` (esqueleto; corpo na Tarefa 6)
- Test: `backend/tests/Feature/Fiscal/IniciarCancelamentoTest.php`

**Interfaces:**
- Consumes: `PoliticaDeNotas`, `EntitlementService`, `Justificativa`, `NotaEvento::registrar`, exceções da Tarefa 2.
- Produces: `IniciarCancelamento::executar(string $notaId, string $justificativa, Usuario $autor): Nota`. Regras:
  - Exige papel que emite, `Justificativa` válida (422 `JUSTIFICATIVA_INVALIDA`), assinatura com escrita e módulo `FISCAL_NFE`.
  - Já `CANCELANDO` ou `CANCELADA`: devolve a nota sem mudar nada e **sem** despachar.
  - Só `AUTORIZADA` com `chave`, `protocolo` e `xml_autorizado`; outro status ou dado ausente lança `CancelamentoNaoPermitidoException`.
  - Grava `status=CANCELANDO`, `cancelamento_solicitado_em`, `cancelamento_justificativa` (saneada), zera `cancelamento_cstat` e `cancelamento_motivo`, registra o evento.
  - **Depois** da transação: `CancelarNotaJob::dispatch($nota->id, $nota->tenant_id)`.
- `CancelarNotaJob`: `final class ... implements ShouldQueue` com `Dispatchable, InteractsWithQueue, Queueable`, `public int $tries = 1; public int $timeout = 180;`, construtor `(public readonly string $notaId, public readonly string $tenantId)`, `handle()` vazio por ora.

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\IniciarCancelamento;
use App\Modules\Fiscal\Application\Jobs\CancelarNotaJob;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\CancelamentoNaoPermitidoException;
use App\Modules\Fiscal\Domain\Exceptions\JustificativaInvalidaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class IniciarCancelamentoTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Cancelamento por erro de digitação do valor total';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        Queue::fake();
    }

    private function autorizada(): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Autorizada, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 1,
            'chave' => str_repeat('1', 44), 'protocolo' => '135260000000001', 'xml_autorizado' => '<nfeProc>x</nfeProc>',
            'emitido_em' => now(),
        ])->save();

        return $nota->refresh();
    }

    public function test_inicia_o_cancelamento_e_despacha_o_job(): void
    {
        $nota = $this->autorizada();

        $r = app(IniciarCancelamento::class)->executar($nota->id, '  '.self::JUSTIFICATIVA.'  ', $this->admin);

        $this->assertSame(StatusNota::Cancelando, $r->status);
        $this->assertSame(self::JUSTIFICATIVA, $r->cancelamento_justificativa);
        $this->assertNotNull($r->cancelamento_solicitado_em);
        Queue::assertPushed(CancelarNotaJob::class, fn (CancelarNotaJob $j): bool => $j->notaId === $nota->id && $j->tenantId === $this->tenant->id);
        $ultimo = $r->eventos()->get()->last();
        $this->assertSame(['AUTORIZADA', 'CANCELANDO'], [$ultimo->status_de, $ultimo->status_para]);
    }

    public function test_duplo_clique_nao_despacha_segundo_job_nem_muda_nada(): void
    {
        $nota = $this->autorizada();
        app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin);
        $solicitado = $nota->refresh()->cancelamento_solicitado_em;

        $segunda = app(IniciarCancelamento::class)->executar($nota->id, 'Outra justificativa diferente da primeira', $this->admin);

        $this->assertSame(StatusNota::Cancelando, $segunda->status);
        $this->assertSame(self::JUSTIFICATIVA, $segunda->cancelamento_justificativa, 'não sobrescreve a justificativa já enviada');
        $this->assertEquals($solicitado, $segunda->cancelamento_solicitado_em);
        Queue::assertPushed(CancelarNotaJob::class, 1);
    }

    public function test_nota_ja_cancelada_devolve_sem_despachar(): void
    {
        $nota = $this->autorizada();
        $nota->forceFill(['status' => StatusNota::Cancelada])->save();

        $r = app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin);

        $this->assertSame(StatusNota::Cancelada, $r->status);
        Queue::assertNothingPushed();
    }

    public function test_so_nota_autorizada_pode_ser_cancelada(): void
    {
        foreach ([StatusNota::Rascunho, StatusNota::Processando, StatusNota::Rejeitada, StatusNota::Erro] as $status) {
            $nota = $this->autorizada();
            $nota->forceFill(['status' => $status])->save();
            try {
                app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin);
                $this->fail("Cancelou uma nota {$status->value}.");
            } catch (CancelamentoNaoPermitidoException $e) {
                $this->assertSame('CANCELAMENTO_NAO_PERMITIDO', $e->codigo());
            }
        }
        Queue::assertNothingPushed();
    }

    public function test_autorizada_sem_protocolo_ou_sem_xml_nao_pode_ser_cancelada(): void
    {
        $nota = $this->autorizada();
        $nota->forceFill(['protocolo' => null])->save();
        $this->expectException(CancelamentoNaoPermitidoException::class);
        app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin);
    }

    public function test_justificativa_invalida_bloqueia_sem_mudar_a_nota(): void
    {
        $nota = $this->autorizada();
        try {
            app(IniciarCancelamento::class)->executar($nota->id, 'curta', $this->admin);
            $this->fail('Esperava JustificativaInvalidaException.');
        } catch (JustificativaInvalidaException) {
            $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);
            Queue::assertNothingPushed();
        }
    }

    public function test_vendedor_e_leitura_nao_cancelam(): void
    {
        $nota = $this->autorizada();
        foreach ([Papel::Vendedor, Papel::Leitura] as $papel) {
            $usuario = Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);
            try {
                app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $usuario);
                $this->fail("{$papel->value} cancelou.");
            } catch (AcessoNegadoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_exige_modulo_e_assinatura_com_escrita(): void
    {
        $nota = $this->autorizada();

        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        try {
            app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin->refresh());
            $this->fail('Assinatura suspensa cancelou.');
        } catch (AssinaturaSemEscritaException) {
            $this->addToAssertionCount(1);
        }

        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();
        $this->tenant->plano->modulos()->delete();
        $this->tenant->unsetRelation('plano');
        $this->expectException(ModuloNaoContratadoException::class);
        app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin->refresh());
    }
}
```

Antes de rodar, confirme com `grep -n "case" app/Modules/Tenancy/Domain/Enums/SituacaoAssinatura.php` o nome do case "ativa" (use o que existir) e ajuste o teste.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=IniciarCancelamentoTest`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Esqueleto do job**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class CancelarNotaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $notaId, public readonly string $tenantId) {}

    public function handle(): void
    {
        // Corpo na Tarefa 6.
    }
}
```

- [ ] **Step 4: `IniciarCancelamento`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\Jobs\CancelarNotaJob;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\CancelamentoNaoPermitidoException;
use App\Modules\Fiscal\Domain\Justificativa;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use Illuminate\Support\Facades\DB;

final class IniciarCancelamento
{
    public function __construct(
        private readonly PoliticaDeNotas $politica,
        private readonly EntitlementService $planos,
    ) {}

    public function executar(string $notaId, string $justificativa, Usuario $autor): Nota
    {
        $this->politica->garantirPodeEmitir($autor);
        $texto = Justificativa::de($justificativa)->texto;

        $resultado = DB::transaction(function () use ($notaId, $texto, $autor): array {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();

            if (in_array($nota->status, [StatusNota::Cancelando, StatusNota::Cancelada], true)) {
                return [$nota, false];
            }

            $tenant = $autor->tenant;
            if (! $tenant->situacao->permiteEscrita()) {
                throw AssinaturaSemEscritaException::para($tenant->situacao);
            }
            $this->planos->garantirModulo($tenant, Modulo::FiscalNfe);

            if ($nota->status !== StatusNota::Autorizada) {
                throw new CancelamentoNaoPermitidoException('Só é possível cancelar uma NF-e autorizada. Status atual: '.$nota->status->value.'.');
            }
            $nota->makeVisible('xml_autorizado');
            if ($nota->chave === null || $nota->protocolo === null || $nota->xml_autorizado === null) {
                throw new CancelamentoNaoPermitidoException('A nota não tem chave, protocolo ou XML autorizado: concilie-a com a SEFAZ antes de cancelar.');
            }

            $nota->forceFill([
                'status' => StatusNota::Cancelando, 'cancelamento_solicitado_em' => now(), 'cancelamento_justificativa' => $texto,
                'cancelamento_cstat' => null, 'cancelamento_motivo' => null,
            ])->save();
            NotaEvento::registrar($nota, StatusNota::Autorizada, StatusNota::Cancelando, 'Cancelamento solicitado: '.$texto, $autor->id);

            return [$nota, true];
        });

        [$nota, $despachar] = $resultado;
        if ($despachar) {
            CancelarNotaJob::dispatch($nota->id, $nota->tenant_id);
        }

        return $nota->refresh();
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter=IniciarCancelamentoTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/IniciarCancelamentoTest.php
git commit -m "feat(fiscal): IniciarCancelamento (validações, idempotência e despacho)"
```

---

### Task 6: `AplicarResultadoDoCancelamento` e corpo do `CancelarNotaJob`

**Files:**
- Create: `app/Modules/Fiscal/Application/Actions/AplicarResultadoDoCancelamento.php`
- Modify: `app/Modules/Fiscal/Application/Jobs/CancelarNotaJob.php`
- Test: `backend/tests/Feature/Fiscal/CancelarNotaJobTest.php`

**Interfaces:**
- Consumes: `EmissorDeNfe::cancelar`, `ResultadoDeCancelamento`, `EmissorFake`.
- Produces:
  - `AplicarResultadoDoCancelamento::executar(string $notaId, ResultadoDeCancelamento $r): Nota`: só age em `CANCELANDO` (senão devolve sem mudar). Aceito: `CANCELADA`, `cancelado_em`, `cancelamento_protocolo`, `cancelamento_cstat`, `cancelamento_motivo`, `xml_cancelamento` e evento. Recusado: volta a `AUTORIZADA`, grava `cancelamento_cstat`/`cancelamento_motivo` e evento `"Cancelamento recusado (cStat X): motivo"`.
  - `AplicarResultadoDoCancelamento::registrarFalhaTecnica(string $notaId, string $mensagem): void`: só em `CANCELANDO`; mantém o status, grava um evento `CANCELANDO → CANCELANDO` com a mensagem.
  - `CancelarNotaJob::handle(EmissorDeNfe $emissor, AplicarResultadoDoCancelamento $aplicar, TenantContext $contexto)`: restaura o contexto anterior no fim (como o `EmitirNotaJob`), ignora nota fora de `CANCELANDO`, chama `cancelar` com o **ambiente da nota**, aplica o resultado; qualquer `Throwable` vira `registrarFalhaTecnica` e a nota **permanece** `CANCELANDO`.

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\AplicarResultadoDoCancelamento;
use App\Modules\Fiscal\Application\Actions\IniciarCancelamento;
use App\Modules\Fiscal\Application\Jobs\CancelarNotaJob;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class CancelarNotaJobTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Cancelamento por erro de digitação do valor total';

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    private function autorizada(string $ambiente = 'HOMOLOGACAO'): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Autorizada, 'ambiente' => $ambiente, 'serie' => '1', 'numero' => 1,
            'chave' => str_repeat('1', 44), 'protocolo' => '135260000000001', 'xml_autorizado' => '<nfeProc>x</nfeProc>', 'emitido_em' => now(),
        ])->save();

        return $nota->refresh();
    }

    /** Com QUEUE_CONNECTION=sync dos testes, `IniciarCancelamento` já executa o job. */
    private function cancelar(Nota $nota): Nota
    {
        return app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin)->refresh();
    }

    public function test_aceito_vira_cancelada_com_protocolo_e_xml(): void
    {
        $nota = $this->cancelar($this->autorizada());

        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertSame('135260000000099', $nota->cancelamento_protocolo);
        $this->assertSame('135', $nota->cancelamento_cstat);
        $this->assertNotNull($nota->cancelado_em);
        $nota->makeVisible('xml_cancelamento');
        $this->assertSame('<nfeProc>cancelada</nfeProc>', $nota->xml_cancelamento);
        $this->assertSame(['cancelar'], $this->emissor->chamadas);
    }

    public function test_recusa_da_sefaz_volta_a_autorizada_com_cstat_e_motivo(): void
    {
        $this->emissor->enfileirarCancelamento(ResultadoDeCancelamento::recusado('501', 'Pedido de Cancelamento intempestivo'));

        $nota = $this->cancelar($this->autorizada());

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('501', $nota->cancelamento_cstat);
        $this->assertSame('Pedido de Cancelamento intempestivo', $nota->cancelamento_motivo);
        $this->assertNull($nota->cancelado_em);
        $this->assertSame('135260000000001', $nota->protocolo, 'a autorização continua intacta');
    }

    public function test_falha_tecnica_mantem_cancelando_e_registra_o_evento(): void
    {
        $this->emissor->falharEmCancelar = new RuntimeException('cURL error 28: timeout');

        $nota = $this->cancelar($this->autorizada());

        $this->assertSame(StatusNota::Cancelando, $nota->status);
        $ultimo = $nota->eventos()->get()->last();
        $this->assertSame('CANCELANDO', $ultimo->status_para);
        $this->assertStringContainsString('timeout', (string) $ultimo->detalhe);
    }

    public function test_cancela_no_ambiente_da_nota_e_nao_no_do_emitente(): void
    {
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);

        $this->cancelar($this->autorizada('HOMOLOGACAO'));

        $this->assertSame(['HOMOLOGACAO'], $this->emissor->ambientes);
    }

    public function test_job_ignora_nota_que_nao_esta_cancelando(): void
    {
        $nota = $this->autorizada();

        (new CancelarNotaJob($nota->id, $this->tenant->id))->handle(
            $this->emissor, app(AplicarResultadoDoCancelamento::class), app(TenantContext::class),
        );

        $this->assertSame([], $this->emissor->chamadas);
        $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);
    }

    public function test_resultado_so_e_aplicado_em_nota_cancelando(): void
    {
        $nota = $this->autorizada();

        $r = app(AplicarResultadoDoCancelamento::class)->executar($nota->id, ResultadoDeCancelamento::aceito('1', '<x/>'));

        $this->assertSame(StatusNota::Autorizada, $r->status, 'sem estar CANCELANDO nada muda');
    }

    public function test_trilha_de_eventos_do_cancelamento(): void
    {
        $nota = $this->cancelar($this->autorizada());

        $this->assertSame(['CANCELANDO', 'CANCELADA'], $nota->eventos()->pluck('status_para')->all());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=CancelarNotaJobTest`
Expected: FAIL.

- [ ] **Step 3: `AplicarResultadoDoCancelamento`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use Illuminate\Support\Facades\DB;

/** Única porta de saída de um cancelamento: toda mudança de status vinda da SEFAZ passa por aqui, com lock. */
final class AplicarResultadoDoCancelamento
{
    public function executar(string $notaId, ResultadoDeCancelamento $r): Nota
    {
        return DB::transaction(function () use ($notaId, $r): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Cancelando) {
                return $nota;
            }

            if ($r->aceito) {
                $nota->forceFill([
                    'status' => StatusNota::Cancelada, 'cancelado_em' => now(), 'cancelamento_protocolo' => $r->protocolo,
                    'cancelamento_cstat' => $r->cstat, 'cancelamento_motivo' => $r->motivo, 'xml_cancelamento' => $r->xmlCancelamento,
                ])->save();
                NotaEvento::registrar($nota, StatusNota::Cancelando, StatusNota::Cancelada, "cStat {$r->cstat}: {$r->motivo}");

                return $nota;
            }

            $nota->forceFill([
                'status' => StatusNota::Autorizada, 'cancelamento_cstat' => $r->cstat, 'cancelamento_motivo' => $r->motivo,
            ])->save();
            NotaEvento::registrar($nota, StatusNota::Cancelando, StatusNota::Autorizada, "Cancelamento recusado (cStat {$r->cstat}): {$r->motivo}");

            return $nota;
        });
    }

    /** Falha de comunicação: a nota continua CANCELANDO e a reconciliação decide pelo estado real na SEFAZ. */
    public function registrarFalhaTecnica(string $notaId, string $mensagem): void
    {
        DB::transaction(function () use ($notaId, $mensagem): void {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->first();
            if ($nota === null || $nota->status !== StatusNota::Cancelando) {
                return;
            }
            NotaEvento::registrar($nota, StatusNota::Cancelando, StatusNota::Cancelando, 'Falha técnica ao cancelar: '.mb_substr($mensagem, 0, 300));
        });
    }
}
```

- [ ] **Step 4: Corpo do job**

Substitua `CancelarNotaJob.php` (mantendo o cabeçalho de traits, `tries` e `timeout` da Tarefa 5) por:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use App\Modules\Fiscal\Application\Actions\AplicarResultadoDoCancelamento;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class CancelarNotaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $notaId, public readonly string $tenantId) {}

    public function handle(EmissorDeNfe $emissor, AplicarResultadoDoCancelamento $aplicar, TenantContext $contexto): void
    {
        // Num worker o contexto anterior é nulo; com fila `sync` restauramos o do chamador.
        $anterior = $contexto->id();
        $contexto->set($this->tenantId);
        try {
            $nota = Nota::query()->find($this->notaId);
            if ($nota === null || $nota->status !== StatusNota::Cancelando) {
                return;
            }
            $emitente = Emitente::query()->findOrFail($nota->emitente_id);
            $nota->makeVisible('xml_autorizado');

            $resultado = $emissor->cancelar(
                $emitente, (string) $nota->chave, (string) $nota->protocolo, (string) $nota->cancelamento_justificativa,
                (string) $nota->ambiente, (string) $nota->xml_autorizado,
            );
            $aplicar->executar($nota->id, $resultado);
        } catch (Throwable $e) {
            report($e);
            $aplicar->registrarFalhaTecnica($this->notaId, $e->getMessage());
        } finally {
            $contexto->set($anterior);
        }
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter="CancelarNotaJobTest|IniciarCancelamentoTest"`
Expected: PASS. (No `IniciarCancelamentoTest` o `Queue::fake()` evita a execução do job.)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/CancelarNotaJobTest.php
git commit -m "feat(fiscal): resultado do cancelamento e job de cancelamento (CANCELANDO nunca fica em dúvida)"
```

---

### Task 7: Reconciliação de `CANCELANDO` e contador de cota com canceladas

**Files:**
- Modify: `app/Modules/Fiscal/Application/Actions/AplicarResultadoDoCancelamento.php` (métodos `aplicarConsulta` e `naoConfirmado`)
- Modify: `app/Modules/Fiscal/Application/Actions/ConciliarNota.php`
- Modify: `app/Modules/Fiscal/Console/ReconciliarNotasCommand.php`
- Modify: `app/Modules/Fiscal/Application/ContadorDeDocumentosMes.php`
- Test: `backend/tests/Feature/Fiscal/ReconciliarNotasTest.php`, `backend/tests/Feature/Fiscal/ContadorDeDocumentosMesTest.php` (acrescentar casos)

**Interfaces:**
- Produces:
  - `AplicarResultadoDoCancelamento::aplicarConsulta(string $notaId, ResultadoDeConsulta $consulta): Nota`: em `CANCELANDO` com `Cancelada` na SEFAZ → `CANCELADA` (`cancelado_em`, `cancelamento_cstat`, `cancelamento_motivo`; sem XML do evento) e evento "Cancelamento confirmado na SEFAZ".
  - `AplicarResultadoDoCancelamento::naoConfirmado(string $notaId): Nota`: em `CANCELANDO` → `AUTORIZADA`, `cancelamento_motivo = 'Cancelamento não confirmado pela SEFAZ; tente de novo.'`, evento.
  - `ConciliarNota` trata `CANCELANDO`: `Cancelada` → `aplicarConsulta`; `Autorizada` com `cancelamento_solicitado_em` anterior a `config('fiscal.minutos_para_nunca_enviada')` minutos (ou nulo) → `naoConfirmado`; `NaoEncontrada` e `Erro` → não muda.
  - O comando varre `PROCESSANDO` **e** `CANCELANDO`.
  - `ContadorDeDocumentosMes` soma `AUTORIZADA`, `CANCELANDO` e `CANCELADA` em produção no mês corrente.

- [ ] **Step 1: Testes**

Em `ReconciliarNotasTest`, acrescente:

```php
    private function cancelando(int $minutosAtras, ?string $chave = null): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Cancelando, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => random_int(1, 9999),
            'chave' => $chave ?? str_repeat('4', 44), 'protocolo' => '135260000000001', 'xml_autorizado' => '<nfeProc>x</nfeProc>',
            'cancelamento_justificativa' => 'Cancelamento por erro de digitação do valor total',
            'cancelamento_solicitado_em' => now()->subMinutes($minutosAtras),
        ])->save();

        return $nota;
    }

    public function test_cancelando_cancelada_na_sefaz_vira_cancelada(): void
    {
        $nota = $this->cancelando(2);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::cancelada());

        $this->rodar();

        $nota->refresh();
        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertNotNull($nota->cancelado_em);
        $this->assertSame('101', $nota->cancelamento_cstat);
    }

    public function test_cancelando_ainda_autorizada_depois_do_prazo_volta_a_autorizada_com_aviso(): void
    {
        $nota = $this->cancelando(30);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135260000000001', null));

        $this->rodar();

        $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertStringContainsString('não confirmado', (string) $nota->cancelamento_motivo);
    }

    public function test_cancelando_ainda_autorizada_dentro_do_prazo_continua_cancelando(): void
    {
        $nota = $this->cancelando(2);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135260000000001', null));

        $this->rodar();

        $this->assertSame(StatusNota::Cancelando, $nota->refresh()->status);
    }

    public function test_cancelando_com_erro_ou_nao_encontrada_na_consulta_nao_muda(): void
    {
        $a = $this->cancelando(30);
        $b = $this->cancelando(30);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::erro('timeout'), ResultadoDeConsulta::naoEncontrada());

        $this->rodar();

        $this->assertSame(StatusNota::Cancelando, $a->refresh()->status);
        $this->assertSame(StatusNota::Cancelando, $b->refresh()->status);
    }
```

Em `ContadorDeDocumentosMesTest`, troque o corpo de `test_conta_so_autorizadas_em_producao_no_mes_corrente` por:

```php
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now());
        $this->nota(StatusNota::Cancelada, 'PRODUCAO', now());
        $this->nota(StatusNota::Cancelando, 'PRODUCAO', now());
        $this->nota(StatusNota::Autorizada, 'HOMOLOGACAO', now());
        $this->nota(StatusNota::Cancelada, 'HOMOLOGACAO', now());
        $this->nota(StatusNota::Rejeitada, 'PRODUCAO', now());
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now()->subMonth());

        $this->assertSame(3, app(EntitlementService::class)->uso($this->tenant, Recurso::DocumentosMes), 'cancelada continua contando');
```

(e renomeie o teste para `test_conta_autorizadas_e_canceladas_em_producao_no_mes_corrente`).

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="ReconciliarNotasTest|ContadorDeDocumentosMesTest"`
Expected: FAIL nos casos novos.

- [ ] **Step 3: Métodos novos em `AplicarResultadoDoCancelamento`**

Acrescente (e o `use` de `ResultadoDeConsulta`):

```php
    /** Reconciliação: a SEFAZ diz que a chave está cancelada, mas a resposta do nosso pedido se perdeu. */
    public function aplicarConsulta(string $notaId, ResultadoDeConsulta $consulta): Nota
    {
        return DB::transaction(function () use ($notaId, $consulta): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Cancelando || $consulta->situacao !== SituacaoNaSefaz::Cancelada) {
                return $nota;
            }
            $nota->forceFill([
                'status' => StatusNota::Cancelada, 'cancelado_em' => now(),
                'cancelamento_cstat' => $consulta->cstat, 'cancelamento_motivo' => $consulta->motivo,
            ])->save();
            NotaEvento::registrar($nota, StatusNota::Cancelando, StatusNota::Cancelada, 'Cancelamento confirmado na SEFAZ');

            return $nota;
        });
    }

    /** Passou o prazo e a SEFAZ ainda mostra a nota autorizada: o pedido não chegou. Volta a AUTORIZADA para tentar de novo. */
    public function naoConfirmado(string $notaId): Nota
    {
        return DB::transaction(function () use ($notaId): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Cancelando) {
                return $nota;
            }
            $mensagem = 'Cancelamento não confirmado pela SEFAZ; tente de novo.';
            $nota->forceFill(['status' => StatusNota::Autorizada, 'cancelamento_motivo' => $mensagem])->save();
            NotaEvento::registrar($nota, StatusNota::Cancelando, StatusNota::Autorizada, $mensagem);

            return $nota;
        });
    }
```

com `use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;` e `use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;`.

- [ ] **Step 4: `ConciliarNota`**

Troque o construtor para `(EmissorDeNfe $emissor, AplicarResultadoDaEmissao $aplicar, AplicarResultadoDoCancelamento $cancelamento)` (propriedades `private readonly`). No início de `executar`, substitua o `if ($nota->status !== StatusNota::Processando) { return $nota; }` por:

```php
        if ($nota->status === StatusNota::Cancelando) {
            return $this->conciliarCancelamento($nota);
        }
        if ($nota->status !== StatusNota::Processando) {
            return $nota;
        }
```

e acrescente o método:

```php
    private function conciliarCancelamento(Nota $nota): Nota
    {
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        $consulta = $this->emissor->consultarPorChave($emitente, (string) $nota->chave, null, (string) $nota->ambiente);
        $vencida = $nota->cancelamento_solicitado_em === null
            || $nota->cancelamento_solicitado_em->lt(now()->subMinutes((int) config('fiscal.minutos_para_nunca_enviada')));

        return match ($consulta->situacao) {
            SituacaoNaSefaz::Cancelada => $this->cancelamento->aplicarConsulta($nota->id, $consulta),
            SituacaoNaSefaz::Autorizada => $vencida ? $this->cancelamento->naoConfirmado($nota->id) : $nota,
            SituacaoNaSefaz::NaoEncontrada, SituacaoNaSefaz::Erro => $nota,
        };
    }
```

- [ ] **Step 5: Comando e contador**

`ReconciliarNotasCommand`: troque `->where('status', StatusNota::Processando)` por `->whereIn('status', [StatusNota::Processando, StatusNota::Cancelando])` e a ordenação por `->orderBy('updated_at')`. Atualize `$description` para "...as notas em PROCESSANDO e CANCELANDO.".

`ContadorDeDocumentosMes::contar`: troque `->where('status', StatusNota::Autorizada)` por `->whereIn('status', [StatusNota::Autorizada, StatusNota::Cancelando, StatusNota::Cancelada])`.

- [ ] **Step 6: Rodar e ver passar**

Run: `php artisan test tests/Unit/Fiscal tests/Feature/Fiscal`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests
git commit -m "feat(fiscal): reconciliação de CANCELANDO e cota que continua contando canceladas"
```

---

### Task 8: `InutilizarNumeracao`

**Files:**
- Create: `app/Modules/Fiscal/Application/Actions/InutilizarNumeracao.php`
- Test: `backend/tests/Feature/Fiscal/InutilizarNumeracaoTest.php`

**Interfaces:**
- Consumes: `PoliticaDeNotas`, `EntitlementService`, `Justificativa`, `EmissorDeNfe::inutilizar`, `Inutilizacao`, `EmitenteSerie`.
- Produces: `InutilizarNumeracao::executar(array $dados, Usuario $autor): Inutilizacao`. `$dados`: `serie` (string), `numero_inicial` (int), `numero_final` (int), `justificativa` (string). Síncrona. Bloqueios (`EmissaoBloqueadaException`, 422), nesta ordem: `FISCAL_FAIXA_INVALIDA` (inicial < 1 ou inicial > final), `FISCAL_SERIE_NAO_CONFIGURADA` (série inexistente no emitente), `FISCAL_FAIXA_NAO_ALOCADA` (`numero_final >= proximo_numero`), `FISCAL_FAIXA_COM_NOTA` (nota nossa na mesma série e ambiente, dentro da faixa, com status `AUTORIZADA`, `CANCELANDO`, `CANCELADA` ou `PROCESSANDO`; a mensagem cita o número). O ambiente é o atual do emitente. O resultado é sempre gravado (`INUTILIZADA`, `RECUSADA` ou `ERRO`); falha técnica vira `ERRO` e nunca propaga, **exceto** `EmissaoBloqueadaException` (ex.: certificado ausente), que propaga com a mensagem acionável. Não altera `proximo_numero` nem notas.

- [ ] **Step 1: Teste**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\InutilizarNumeracao;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Exceptions\JustificativaInvalidaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class InutilizarNumeracaoTest extends TestCase
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
        // Números 1 a 9 já alocados: o próximo é o 10.
        $this->emitente->series()->update(['proximo_numero' => 10]);
    }

    /** @return array<string, mixed> */
    private function dados(array $sobrescrever = []): array
    {
        return array_replace([
            'serie' => '1', 'numero_inicial' => 3, 'numero_final' => 5,
            'justificativa' => 'Numeração perdida por queda do servidor',
        ], $sobrescrever);
    }

    private function assertBloqueio(string $codigo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Esperava o bloqueio {$codigo}.");
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame($codigo, $e->codigo(), $e->getMessage());
            $this->assertSame([], $this->emissor->chamadas, 'bloqueio local não chama a SEFAZ');
        }
    }

    private function notaNaFaixa(int $numero, StatusNota $status, string $ambiente = 'HOMOLOGACAO'): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill(['status' => $status, 'ambiente' => $ambiente, 'serie' => '1', 'numero' => $numero])->save();
    }

    public function test_inutiliza_a_faixa_e_grava_o_resultado(): void
    {
        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusInutilizacao::Inutilizada, $r->status);
        $this->assertSame('135260000000098', $r->protocolo);
        $this->assertSame([3, 5], [$r->numero_inicial, $r->numero_final]);
        $this->assertSame('HOMOLOGACAO', $r->ambiente);
        $this->assertSame($this->admin->id, $r->usuario_id);
        $this->assertSame(['inutilizar'], $this->emissor->chamadas);
        $this->assertSame(10, $this->emitente->series()->first()->proximo_numero, 'não mexe na numeração');
    }

    public function test_faixa_invalida_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_FAIXA_INVALIDA', fn () => app(InutilizarNumeracao::class)->executar($this->dados(['numero_inicial' => 6, 'numero_final' => 5]), $this->admin));
        $this->assertBloqueio('FISCAL_FAIXA_INVALIDA', fn () => app(InutilizarNumeracao::class)->executar($this->dados(['numero_inicial' => 0]), $this->admin));
    }

    public function test_faixa_futura_ou_nao_alocada_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_FAIXA_NAO_ALOCADA', fn () => app(InutilizarNumeracao::class)->executar($this->dados(['numero_inicial' => 8, 'numero_final' => 10]), $this->admin));
    }

    public function test_serie_inexistente_bloqueia(): void
    {
        $this->assertBloqueio('FISCAL_SERIE_NAO_CONFIGURADA', fn () => app(InutilizarNumeracao::class)->executar($this->dados(['serie' => '9']), $this->admin));
    }

    public function test_faixa_com_nota_autorizada_em_processamento_ou_cancelada_bloqueia_citando_o_numero(): void
    {
        foreach ([StatusNota::Autorizada, StatusNota::Processando, StatusNota::Cancelando, StatusNota::Cancelada] as $i => $status) {
            Nota::query()->delete();
            $this->notaNaFaixa(4, $status);
            try {
                app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);
                $this->fail("Inutilizou faixa com nota {$status->value}.");
            } catch (EmissaoBloqueadaException $e) {
                $this->assertSame('FISCAL_FAIXA_COM_NOTA', $e->codigo());
                $this->assertStringContainsString('4', $e->getMessage());
            }
        }
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_nota_rejeitada_ou_de_outro_ambiente_na_faixa_nao_bloqueia(): void
    {
        $this->notaNaFaixa(3, StatusNota::Rejeitada);
        $this->notaNaFaixa(4, StatusNota::Autorizada, 'PRODUCAO'); // o emitente está em HOMOLOGACAO

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusInutilizacao::Inutilizada, $r->status);
    }

    public function test_recusa_da_sefaz_e_gravada_com_cstat_e_motivo(): void
    {
        $this->emissor->enfileirarInutilizacao(ResultadoDeInutilizacao::recusada('241', 'Um numero da faixa ja foi utilizado'));

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusInutilizacao::Recusada, $r->status);
        $this->assertSame('241', $r->cstat);
    }

    public function test_duplicidade_563_diz_que_ja_foi_inutilizada(): void
    {
        $this->emissor->enfileirarInutilizacao(ResultadoDeInutilizacao::jaInutilizada('Ja existe pedido'));

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame('563', $r->cstat);
        $this->assertStringContainsString('já foi inutilizada', (string) $r->motivo);
    }

    public function test_falha_tecnica_vira_erro_gravado_e_nao_propaga(): void
    {
        $this->emissor->falharEmInutilizar = new RuntimeException('cURL error 28: timeout');

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame(StatusInutilizacao::Erro, $r->status);
        $this->assertStringContainsString('timeout', (string) $r->motivo);
    }

    public function test_usa_o_ambiente_atual_do_emitente(): void
    {
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);

        $r = app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin);

        $this->assertSame('PRODUCAO', $r->ambiente);
        $this->assertSame(['PRODUCAO'], $this->emissor->ambientes);
    }

    public function test_justificativa_papel_e_modulo(): void
    {
        try {
            app(InutilizarNumeracao::class)->executar($this->dados(['justificativa' => 'curta']), $this->admin);
            $this->fail('Justificativa curta passou.');
        } catch (JustificativaInvalidaException) {
            $this->addToAssertionCount(1);
        }

        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        try {
            app(InutilizarNumeracao::class)->executar($this->dados(), $vendedor);
            $this->fail('Vendedor inutilizou.');
        } catch (AcessoNegadoException) {
            $this->addToAssertionCount(1);
        }

        $this->tenant->plano->modulos()->delete();
        $this->tenant->unsetRelation('plano');
        $this->expectException(ModuloNaoContratadoException::class);
        app(InutilizarNumeracao::class)->executar($this->dados(), $this->admin->refresh());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=InutilizarNumeracaoTest`
Expected: FAIL.

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Justificativa;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use Throwable;

/** Ação administrativa e rara: síncrona, e o resultado (inclusive recusa e erro) é sempre gravado. */
final class InutilizarNumeracao
{
    public function __construct(
        private readonly PoliticaDeNotas $politica,
        private readonly EntitlementService $planos,
        private readonly EmissorDeNfe $emissor,
    ) {}

    /** @param array<string, mixed> $dados */
    public function executar(array $dados, Usuario $autor): Inutilizacao
    {
        $this->politica->garantirPodeEmitir($autor);
        $tenant = $autor->tenant;
        if (! $tenant->situacao->permiteEscrita()) {
            throw AssinaturaSemEscritaException::para($tenant->situacao);
        }
        $this->planos->garantirModulo($tenant, Modulo::FiscalNfe);
        $justificativa = Justificativa::de((string) $dados['justificativa'])->texto;

        $numeroSerie = (string) $dados['serie'];
        $inicial = (int) $dados['numero_inicial'];
        $final = (int) $dados['numero_final'];
        if ($inicial < 1 || $inicial > $final) {
            throw new EmissaoBloqueadaException('FISCAL_FAIXA_INVALIDA', 'O número inicial deve ser maior que zero e não pode passar do final.');
        }

        $emitente = Emitente::query()->firstOrCreate([], []);
        $serie = EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', ModeloDocumento::Nfe)->where('serie', $numeroSerie)->first();
        if ($serie === null) {
            throw new EmissaoBloqueadaException('FISCAL_SERIE_NAO_CONFIGURADA', "A série {$numeroSerie} da NF-e não está cadastrada em Configurações > Fiscal.");
        }
        if ($final >= $serie->proximo_numero) {
            throw new EmissaoBloqueadaException('FISCAL_FAIXA_NAO_ALOCADA', 'Só é possível inutilizar números já alocados (até o '.($serie->proximo_numero - 1).').');
        }

        $ambiente = $emitente->ambiente_fiscal->value;
        $conflito = Nota::query()
            ->where('serie', $numeroSerie)->where('ambiente', $ambiente)->whereBetween('numero', [$inicial, $final])
            ->whereIn('status', [StatusNota::Autorizada, StatusNota::Cancelando, StatusNota::Cancelada, StatusNota::Processando])
            ->orderBy('numero')->first();
        if ($conflito !== null) {
            throw new EmissaoBloqueadaException('FISCAL_FAIXA_COM_NOTA', "O número {$conflito->numero} pertence a uma NF-e {$conflito->status->value} nesta série e não pode ser inutilizado.");
        }

        try {
            $resultado = $this->emissor->inutilizar($emitente, (int) $numeroSerie, $inicial, $final, $justificativa, $ambiente);
        } catch (EmissaoBloqueadaException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $resultado = ResultadoDeInutilizacao::erro('Falha técnica ao inutilizar: '.mb_substr($e->getMessage(), 0, 300));
        }

        return Inutilizacao::query()->create([
            'emitente_id' => $emitente->id, 'serie' => $numeroSerie, 'numero_inicial' => $inicial, 'numero_final' => $final,
            'justificativa' => $justificativa, 'ambiente' => $ambiente, 'status' => $resultado->status, 'cstat' => $resultado->cstat,
            'motivo' => $resultado->motivo, 'protocolo' => $resultado->protocolo, 'xml_resposta' => $resultado->xmlResposta,
            'usuario_id' => $autor->id,
        ]);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=InutilizarNumeracaoTest`
Expected: PASS. (`Inutilizacao::query()->create` devolve a instância com os atributos preenchidos; `status` já vem como enum pelo cast.)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/InutilizarNumeracaoTest.php
git commit -m "feat(fiscal): InutilizarNumeracao com guardas locais e resultado sempre gravado"
```

---

### Task 9: API de cancelamento e inutilização

**Files:**
- Create: `app/Modules/Fiscal/Http/Requests/{CancelarNotaRequest,InutilizarNumeracaoRequest}.php`
- Create: `app/Modules/Fiscal/Http/Resources/InutilizacaoResource.php`
- Create: `app/Modules/Fiscal/Http/Controllers/InutilizacaoController.php`
- Modify: `app/Modules/Fiscal/Http/Controllers/NotaController.php`, `app/Modules/Fiscal/Http/Resources/{NotaResource,NotaStatusResource}.php`, `app/Modules/Fiscal/Http/routes-app.php`
- Test: `backend/tests/Feature/Fiscal/CancelamentoApiTest.php`, `backend/tests/Feature/Fiscal/InutilizacoesApiTest.php`

**Interfaces:**
- Produces (grupo `empresa`):
  - `POST /notas/{nota}/cancelar` `{ justificativa }` → 202 `NotaStatusResource`.
  - `GET /notas/{nota}/xml-cancelamento` → download `NFe-<numero>-cancelamento.xml` (só `CANCELADA` com `xml_cancelamento`; senão 409 `NOTA_SEM_XML_CANCELAMENTO`).
  - `GET /inutilizacoes` (25 por página, mais recentes primeiro) e `POST /inutilizacoes` `{ serie, numero_inicial, numero_final, justificativa }` → 201 `InutilizacaoResource`.
  - `NotaResource` ganha `cancelamento_justificativa`, `cancelado_em`, `cancelamento_protocolo`, `cancelamento_cstat`, `cancelamento_motivo`; `NotaStatusResource` ganha `cancelamento_cstat`, `cancelamento_motivo`. Nenhum expõe XML.
  - `InutilizacaoResource`: `id, serie, numero_inicial, numero_final, justificativa, ambiente, status, cstat, motivo, protocolo, created_at`.

- [ ] **Step 1: Testes**

`CancelamentoApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
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

class CancelamentoApiTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Cancelamento por erro de digitação do valor total';

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    private function autorizada(): string
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Autorizada, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 1,
            'chave' => str_repeat('1', 44), 'protocolo' => '135260000000001', 'xml_autorizado' => '<nfeProc>x</nfeProc>', 'emitido_em' => now(),
        ])->save();

        return $nota->id;
    }

    public function test_cancela_responde_202_e_conclui_com_o_fake(): void
    {
        $id = $this->autorizada();

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])
            ->assertStatus(202)->assertJsonStructure(['data' => ['id', 'status']]);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'CANCELADA')
            ->assertJsonPath('data.cancelamento_justificativa', self::JUSTIFICATIVA)
            ->assertJsonPath('data.cancelamento_protocolo', '135260000000099')
            ->assertJsonMissingPath('data.xml_cancelamento');
    }

    public function test_recusa_da_sefaz_fica_no_recurso_e_a_nota_segue_autorizada(): void
    {
        $this->emissor->enfileirarCancelamento(ResultadoDeCancelamento::recusado('501', 'Pedido de Cancelamento intempestivo'));
        $id = $this->autorizada();

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertStatus(202);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}/status")->assertOk()
            ->assertJsonPath('data.status', 'AUTORIZADA')
            ->assertJsonPath('data.cancelamento_cstat', '501')
            ->assertJsonPath('data.cancelamento_motivo', 'Pedido de Cancelamento intempestivo');
    }

    public function test_validacoes_da_justificativa_e_do_status(): void
    {
        $id = $this->autorizada();

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", [])->assertStatus(422)->assertJsonValidationErrors('justificativa');
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => 'curta'])->assertStatus(422);
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => str_repeat('a', 256)])->assertStatus(422);
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => str_repeat(' ', 30).str_repeat("\u{1F600}", 5)])
            ->assertStatus(422)->assertJsonPath('codigo', 'JUSTIFICATIVA_INVALIDA');

        Nota::query()->findOrFail($id)->forceFill(['status' => StatusNota::Rejeitada])->save();
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])
            ->assertStatus(409)->assertJsonPath('codigo', 'CANCELAMENTO_NAO_PERMITIDO');
    }

    public function test_baixa_o_xml_do_cancelamento_so_de_nota_cancelada(): void
    {
        $id = $this->autorizada();
        $this->spaComo($this->admin)->get("/api/app/notas/{$id}/xml-cancelamento", ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('codigo', 'NOTA_SEM_XML_CANCELAMENTO');

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA]);
        $resp = $this->spaComo($this->admin)->get("/api/app/notas/{$id}/xml-cancelamento")->assertOk();
        $this->assertSame('<nfeProc>cancelada</nfeProc>', $resp->getContent());
        $this->assertStringContainsString('NFe-1-cancelamento.xml', (string) $resp->headers->get('Content-Disposition'));
    }

    public function test_papeis(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        $fiscal = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Fiscal]);
        $id = $this->autorizada();

        $this->spaComo($vendedor)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertForbidden();
        $this->spaComo($leitura)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertForbidden();
        $this->spaComo($fiscal)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertStatus(202);
    }

    public function test_isolamento_entre_tenants(): void
    {
        $id = $this->autorizada();
        $outro = Tenant::factory()->for(Plano::factory())->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spaComo($intruso)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertNotFound();
        $this->spaComo($intruso)->get("/api/app/notas/{$id}/xml-cancelamento", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_sem_autenticacao_401(): void
    {
        $id = $this->autorizada();
        $this->spa()->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertUnauthorized();
    }
}
```

`InutilizacoesApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class InutilizacoesApiTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->app->instance(EmissorDeNfe::class, new EmissorFake);
        $this->emitente->series()->update(['proximo_numero' => 10]);
    }

    /** @return array<string, mixed> */
    private function corpo(array $sobrescrever = []): array
    {
        return array_replace(['serie' => '1', 'numero_inicial' => 3, 'numero_final' => 5, 'justificativa' => 'Numeração perdida por queda do servidor'], $sobrescrever);
    }

    public function test_cria_e_lista_sem_expor_o_xml(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo())->assertCreated()
            ->assertJsonPath('data.status', 'INUTILIZADA')->assertJsonPath('data.numero_inicial', 3)->assertJsonMissingPath('data.xml_resposta');

        $this->spaComo($this->admin)->getJson('/api/app/inutilizacoes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.numero_final', 5);
    }

    public function test_validacao_do_corpo(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo(['numero_inicial' => 'a']))->assertStatus(422)->assertJsonValidationErrors('numero_inicial');
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo(['justificativa' => 'curta']))->assertStatus(422);
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo(['serie' => '']))->assertStatus(422)->assertJsonValidationErrors('serie');
    }

    public function test_bloqueio_local_vira_422_com_codigo(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo(['numero_inicial' => 8, 'numero_final' => 12]))
            ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_FAIXA_NAO_ALOCADA');
    }

    public function test_papeis(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spaComo($vendedor)->postJson('/api/app/inutilizacoes', $this->corpo())->assertForbidden();
        $this->spaComo($leitura)->getJson('/api/app/inutilizacoes')->assertOk();
        $this->spaComo($leitura)->postJson('/api/app/inutilizacoes', $this->corpo())->assertForbidden();
    }

    public function test_isolamento_entre_tenants(): void
    {
        Inutilizacao::factory()->create(['emitente_id' => $this->emitente->id]);
        $outro = Tenant::factory()->for(Plano::factory())->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spaComo($intruso)->getJson('/api/app/inutilizacoes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sem_autenticacao_401(): void
    {
        $this->spa()->getJson('/api/app/inutilizacoes')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="CancelamentoApiTest|InutilizacoesApiTest"`
Expected: FAIL (404 nas rotas).

- [ ] **Step 3: Requests**

`CancelarNotaRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelarNotaRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // 15 a 255 é a regra da SEFAZ; a checagem final (depois de sanear) é do value object Justificativa.
        return ['justificativa' => ['required', 'string', 'min:15', 'max:255']];
    }

    public function justificativa(): string
    {
        return (string) $this->validated('justificativa');
    }
}
```

`InutilizarNumeracaoRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InutilizarNumeracaoRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'serie' => ['required', 'string', 'regex:/^\d{1,3}$/'],
            'numero_inicial' => ['required', 'integer', 'min:1'],
            'numero_final' => ['required', 'integer', 'min:1'],
            'justificativa' => ['required', 'string', 'min:15', 'max:255'],
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

- [ ] **Step 4: Resources**

`InutilizacaoResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Inutilizacao */
final class InutilizacaoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'serie' => $this->serie, 'numero_inicial' => $this->numero_inicial, 'numero_final' => $this->numero_final,
            'justificativa' => $this->justificativa, 'ambiente' => $this->ambiente, 'status' => $this->status->value,
            'cstat' => $this->cstat, 'motivo' => $this->motivo, 'protocolo' => $this->protocolo,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
```

Em `NotaResource::toArray`, depois de `'emitido_em'`, acrescente: `'cancelamento_justificativa' => $this->cancelamento_justificativa, 'cancelado_em' => $this->cancelado_em?->toIso8601String(), 'cancelamento_protocolo' => $this->cancelamento_protocolo, 'cancelamento_cstat' => $this->cancelamento_cstat, 'cancelamento_motivo' => $this->cancelamento_motivo,`. Em `NotaStatusResource::toArray`, acrescente `'cancelamento_cstat' => $this->cancelamento_cstat, 'cancelamento_motivo' => $this->cancelamento_motivo,`.

- [ ] **Step 5: Controllers e rotas**

Em `NotaController` (acrescente `use` de `CancelarNotaRequest`, `IniciarCancelamento`, `NotaSemXmlCancelamentoException`):

```php
    public function cancelar(CancelarNotaRequest $request, Nota $nota, IniciarCancelamento $acao): JsonResponse
    {
        $nota = $acao->executar($nota->id, $request->justificativa(), $this->autor($request));

        return (new NotaStatusResource($nota))->response()->setStatusCode(202);
    }

    public function xmlCancelamento(Nota $nota): Response
    {
        $nota->makeVisible('xml_cancelamento');
        if ($nota->status !== StatusNota::Cancelada || $nota->xml_cancelamento === null) {
            throw new NotaSemXmlCancelamentoException;
        }

        return response($nota->xml_cancelamento, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="NFe-'.$nota->numero.'-cancelamento.xml"',
        ]);
    }
```

`InutilizacaoController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\InutilizarNumeracao;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Fiscal\Http\Requests\InutilizarNumeracaoRequest;
use App\Modules\Fiscal\Http\Resources\InutilizacaoResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class InutilizacaoController
{
    public function index(): AnonymousResourceCollection
    {
        return InutilizacaoResource::collection(Inutilizacao::query()->latest('created_at')->paginate(25));
    }

    public function store(InutilizarNumeracaoRequest $request, InutilizarNumeracao $acao): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return (new InutilizacaoResource($acao->executar($request->dados(), $autor)))->response()->setStatusCode(201);
    }
}
```

Em `routes-app.php`, dentro do grupo `empresa` (e o `use` de `InutilizacaoController`):

```php
    Route::post('notas/{nota}/cancelar', [NotaController::class, 'cancelar'])->name('app.notas.cancelar');
    Route::get('notas/{nota}/xml-cancelamento', [NotaController::class, 'xmlCancelamento'])->name('app.notas.xml-cancelamento');
    Route::get('inutilizacoes', [InutilizacaoController::class, 'index'])->name('app.inutilizacoes.index');
    Route::post('inutilizacoes', [InutilizacaoController::class, 'store'])->name('app.inutilizacoes.store');
```

- [ ] **Step 6: Rodar e ver passar**

Run: `php artisan test --filter="CancelamentoApiTest|InutilizacoesApiTest|NotasApiTest"`
Expected: PASS. Observação: o `LEITURA` recebe 403 pela política dentro da Action; o 404 do intruso vem antes (route model binding com Global Scope). Um corpo inválido enviado por um `LEITURA` recebe 422 antes do 403 (validação do FormRequest roda primeiro): comportamento conhecido e registrado como pendência menor.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal
git commit -m "feat(fiscal): API de cancelamento e inutilização com papéis e isolamento"
```

---

### Task 10: Revisão do backend (suítes, estilo e análise estática)

**Files:** nenhum novo; correções que surgirem. Atualiza `PROGRESSO.md`.

- [ ] **Step 1: Suíte completa**

Run (em `backend/`): `php artisan test`
Expected: tudo PASS (376 anteriores mais os novos).

- [ ] **Step 2: Estilo**

Run: `vendor/bin/pint --test`
Se falhar, rode `vendor/bin/pint` e revise o diff.

- [ ] **Step 3: Análise estática**

Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: sem erros. Corrija a causa (tipos, `@var`); não use `@phpstan-ignore`.

- [ ] **Step 4: Regra do `?? 0` e `empty()`**

Run: `grep -rnE "empty\(|\?\? 0" backend/app/Modules/Fiscal`
Expected: nenhuma ocorrência em código fiscal.

- [ ] **Step 5: `PROGRESSO.md` e commit**

Registre: backend da F3b1 concluído (Tarefas 1 a 9), decisão de que `consultarPorChave` usa o ambiente da nota, que o cancelamento aceita `cStat` 135/136/155, que o XML do cancelamento é o `nfeProc` com `retEvento` (`Complements::cancelRegister`), e que o frontend é a próxima etapa.

```bash
git add -A backend PROGRESSO.md
git commit -m "chore(fiscal): revisão do backend da F3b1 (suítes, pint e phpstan)"
```

---

### Task 11: Frontend: tipos, schemas, API e badge `CANCELANDO`

**Files:**
- Modify: `frontend/features/notas/{types,schemas,api}.ts`, `frontend/features/notas/components/StatusDaNota.tsx`
- Test: `frontend/features/notas/schemas.test.ts`, `frontend/features/notas/components/Notas.test.tsx` (acrescentar casos)

**Interfaces:**
- Produces:
  - `types.ts`: `StatusNota` ganha `'CANCELANDO'`; `ROTULO_STATUS.CANCELANDO = 'Cancelando'`; `Nota` ganha `cancelamento_justificativa: string | null`, `cancelado_em: string | null`, `cancelamento_protocolo: string | null`, `cancelamento_cstat: string | null`, `cancelamento_motivo: string | null`; `NotaStatus` passa a incluir também `cancelamento_cstat` e `cancelamento_motivo`; novos `StatusInutilizacao = 'INUTILIZADA' | 'RECUSADA' | 'ERRO'` e `Inutilizacao` (`id, serie, numero_inicial, numero_final, justificativa, ambiente, status, cstat, motivo, protocolo, created_at`), `ROTULO_STATUS_INUTILIZACAO`.
  - `schemas.ts`: `cancelamentoSchema` (`{ justificativa }`, 15 a 255 depois de `trim`), `CancelamentoDados`, `inutilizacaoSchema` (`serie` `^\d{1,3}$`, `numero_inicial` e `numero_final` inteiros positivos como string, `justificativa` 15 a 255, `numero_inicial <= numero_final`), `InutilizacaoDados`, `paraPayloadInutilizacao(d): InutilizacaoPayload`.
  - `api.ts`: `notasApi.cancelar(id, justificativa)` (`POST /api/app/notas/{id}/cancelar`, devolve `{ data: NotaStatus }`), `urlDoXmlCancelamento(id)`, `QUERY_KEY_INUTILIZACOES`, `inutilizacoesApi { listar(), criar(payload) }`.

- [ ] **Step 1: Testes**

Em `schemas.test.ts` acrescente:

```ts
import { cancelamentoSchema, inutilizacaoSchema, paraPayloadInutilizacao } from './schemas';

describe('cancelamentoSchema', () => {
  it('aceita de 15 a 255 caracteres depois de aparar', () => {
    expect(cancelamentoSchema.safeParse({ justificativa: 'a'.repeat(15) }).success).toBe(true);
    expect(cancelamentoSchema.safeParse({ justificativa: 'a'.repeat(255) }).success).toBe(true);
    expect(cancelamentoSchema.safeParse({ justificativa: `  ${'a'.repeat(15)}  ` }).success).toBe(true);
  });

  it('recusa 14 e 256 caracteres e só espaços', () => {
    expect(cancelamentoSchema.safeParse({ justificativa: 'a'.repeat(14) }).success).toBe(false);
    expect(cancelamentoSchema.safeParse({ justificativa: 'a'.repeat(256) }).success).toBe(false);
    expect(cancelamentoSchema.safeParse({ justificativa: ' '.repeat(40) }).success).toBe(false);
  });
});

describe('inutilizacaoSchema', () => {
  const BASE = { serie: '1', numero_inicial: '3', numero_final: '5', justificativa: 'Numeração perdida por queda' };

  it('aceita uma faixa válida e converte para o payload', () => {
    expect(inutilizacaoSchema.safeParse(BASE).success).toBe(true);
    expect(paraPayloadInutilizacao(BASE)).toEqual({ serie: '1', numero_inicial: 3, numero_final: 5, justificativa: 'Numeração perdida por queda' });
  });

  it('recusa inicial maior que o final, zero e série inválida', () => {
    expect(inutilizacaoSchema.safeParse({ ...BASE, numero_inicial: '6' }).success).toBe(false);
    expect(inutilizacaoSchema.safeParse({ ...BASE, numero_inicial: '0' }).success).toBe(false);
    expect(inutilizacaoSchema.safeParse({ ...BASE, serie: 'AB' }).success).toBe(false);
    expect(inutilizacaoSchema.safeParse({ ...BASE, justificativa: 'curta' }).success).toBe(false);
  });
});
```

Em `Notas.test.tsx`, dentro de `describe('StatusDaNota', ...)`, acrescente:

```tsx
  it('mostra Cancelando como texto', () => {
    render(<StatusDaNota status="CANCELANDO" ambiente="PRODUCAO" />);
    expect(screen.getByText('Cancelando')).toBeInTheDocument();
  });
```

e acrescente `cancelar: vi.fn()` ao objeto `notasApi` do `vi.mock('../api', ...)` e `urlDoXmlCancelamento: (id: string) => \`http://api.test/api/app/notas/${id}/xml-cancelamento\`,` ao lado de `urlDoXml`. Acrescente também `QUERY_KEY_INUTILIZACOES: ['inutilizacoes'], inutilizacoesApi: { listar: vi.fn(), criar: vi.fn() },` ao mesmo mock. Em `NOTA` (e `rascunho`), inclua os cinco campos de cancelamento como `null`.

- [ ] **Step 2: Rodar e ver falhar**

Run (em `frontend/`): `npx vitest run features/notas`
Expected: FAIL.

- [ ] **Step 3: Implementar**

`types.ts`: faça as mudanças da seção Interfaces. Acrescente:

```ts
export type StatusInutilizacao = 'INUTILIZADA' | 'RECUSADA' | 'ERRO';

export const ROTULO_STATUS_INUTILIZACAO: Record<StatusInutilizacao, string> = {
  INUTILIZADA: 'Inutilizada',
  RECUSADA: 'Recusada pela SEFAZ',
  ERRO: 'Erro técnico',
};

export interface Inutilizacao {
  id: string;
  serie: string;
  numero_inicial: number;
  numero_final: number;
  justificativa: string;
  ambiente: 'HOMOLOGACAO' | 'PRODUCAO';
  status: StatusInutilizacao;
  cstat: string | null;
  motivo: string | null;
  protocolo: string | null;
  created_at: string;
}

export interface InutilizacaoPayload {
  serie: string;
  numero_inicial: number;
  numero_final: number;
  justificativa: string;
}
```

`NotaStatus` vira: `Pick<Nota, 'id' | 'status' | 'numero' | 'serie' | 'chave' | 'cstat' | 'motivo' | 'mensagem_erro' | 'cancelamento_cstat' | 'cancelamento_motivo'>`. Ajuste os objetos `NotaStatus` dos testes existentes de `DetalheDaNota` acrescentando `cancelamento_cstat: null, cancelamento_motivo: null`.

`schemas.ts`:

```ts
export const cancelamentoSchema = z.object({
  justificativa: z
    .string()
    .transform((v) => v.trim())
    .pipe(z.string().min(15, 'A justificativa deve ter de 15 a 255 caracteres.').max(255, 'A justificativa deve ter de 15 a 255 caracteres.')),
});

export type CancelamentoDados = z.input<typeof cancelamentoSchema>;

const INTEIRO_POSITIVO = /^[1-9]\d{0,8}$/;

export const inutilizacaoSchema = z
  .object({
    serie: z.string().regex(/^\d{1,3}$/, 'Informe a série (até 3 dígitos).'),
    numero_inicial: z.string().regex(INTEIRO_POSITIVO, 'Informe um número maior que zero.'),
    numero_final: z.string().regex(INTEIRO_POSITIVO, 'Informe um número maior que zero.'),
    justificativa: z.string().trim().min(15, 'A justificativa deve ter de 15 a 255 caracteres.').max(255, 'A justificativa deve ter de 15 a 255 caracteres.'),
  })
  .refine((d) => !INTEIRO_POSITIVO.test(d.numero_inicial) || !INTEIRO_POSITIVO.test(d.numero_final) || Number(d.numero_inicial) <= Number(d.numero_final), {
    message: 'O número inicial não pode passar do final.',
    path: ['numero_inicial'],
  });

export type InutilizacaoDados = z.infer<typeof inutilizacaoSchema>;

export function paraPayloadInutilizacao(d: InutilizacaoDados): InutilizacaoPayload {
  return { serie: d.serie, numero_inicial: Number(d.numero_inicial), numero_final: Number(d.numero_final), justificativa: d.justificativa.trim() };
}
```

(importe `InutilizacaoPayload` de `./types`.)

`api.ts`: acrescente ao `notasApi` `cancelar: (id: string, justificativa: string) => api<{ data: NotaStatus }>(\`/api/app/notas/${id}/cancelar\`, { method: 'POST', body: JSON.stringify({ justificativa }) }),` e:

```ts
export const urlDoXmlCancelamento = (id: string): string => `${API_URL}/api/app/notas/${id}/xml-cancelamento`;

export const QUERY_KEY_INUTILIZACOES = ['inutilizacoes'] as const;

export const inutilizacoesApi = {
  listar: () => api<{ data: Inutilizacao[] }>('/api/app/inutilizacoes'),
  criar: (dados: InutilizacaoPayload) => api<{ data: Inutilizacao }>('/api/app/inutilizacoes', { method: 'POST', body: JSON.stringify(dados) }),
};
```

(importe `Inutilizacao` e `InutilizacaoPayload`.)

`StatusDaNota.tsx`: acrescente `CANCELANDO: 'bg-warning/15 text-warning',` ao mapa `COR`.

- [ ] **Step 4: Rodar e ver passar**

Run: `npx vitest run features/notas && npx tsc --noEmit`
Expected: PASS e sem erros de tipo.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(frontend): tipos, schemas e API de cancelamento e inutilização"
```

---

### Task 12: Frontend: cancelar a NF-e no detalhe da nota

**Files:**
- Modify: `frontend/features/notas/components/DetalheDaNota.tsx`
- Modify: `frontend/features/notas/components/Notas.test.tsx`

**Interfaces:**
- Consumes: `notasApi.cancelar`, `urlDoXmlCancelamento`, `cancelamentoSchema`, `StatusDaNota`.
- Produces: no `DetalheDaNota`:
  - `AUTORIZADA` e quem emite (PROPRIETARIO, ADMIN, FISCAL): botão "Cancelar NF-e" abre um bloco com o campo "Justificativa do cancelamento" (contador `N/255`), o aviso "O prazo para cancelar é definido pela SEFAZ. Se passou do prazo, ela recusa e a nota continua autorizada." e o botão "Confirmar cancelamento" (segundo clique; sem `window.confirm`), mais "Voltar".
  - `CANCELANDO`: texto "Aguardando a SEFAZ confirmar o cancelamento. Esta página atualiza sozinha." e polling do status a cada 2 s (como em `PROCESSANDO`).
  - `AUTORIZADA` com `cancelamento_cstat` preenchido: `role="alert"` "Cancelamento recusado (cStat X): motivo" (a nota segue autorizada).
  - `CANCELADA`: protocolo, data, justificativa e link "Baixar XML do cancelamento".
  - Erro do servidor ao pedir o cancelamento aparece em `role="alert"` (como o de emissão).

- [ ] **Step 1: Testes** (acrescentar a `Notas.test.tsx`, dentro de um novo `describe('DetalheDaNota cancelamento', ...)`; reaproveite `comPapel`, `NOTA`, `renderizar`)

```tsx
describe('DetalheDaNota cancelamento', () => {
  const justificativa = 'Cancelamento por erro de digitação do valor total';

  it('cancela com confirmação em dois cliques e envia a justificativa', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    vi.mocked(notasApi.cancelar).mockResolvedValue({ data: { id: 'n1', status: 'CANCELANDO', numero: 7, serie: '1', chave: NOTA.chave, cstat: '100', motivo: null, mensagem_erro: null, cancelamento_cstat: null, cancelamento_motivo: null } });
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar NF-e' }));
    expect(screen.getByText(/prazo para cancelar é definido pela SEFAZ/)).toBeInTheDocument();
    await userEvent.type(screen.getByLabelText('Justificativa do cancelamento'), justificativa);
    expect(screen.getByText(`${justificativa.length}/255`)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar cancelamento' }));

    await waitFor(() => expect(notasApi.cancelar).toHaveBeenCalledWith('n1', justificativa));
  });

  it('não deixa confirmar com justificativa curta', async () => {
    comPapel('FISCAL');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar NF-e' }));
    await userEvent.type(screen.getByLabelText('Justificativa do cancelamento'), 'curta');
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar cancelamento' }));

    expect(await screen.findByText('A justificativa deve ter de 15 a 255 caracteres.')).toBeInTheDocument();
    expect(notasApi.cancelar).not.toHaveBeenCalled();
  });

  it('vendedor e leitura não veem o botão de cancelar', async () => {
    comPapel('VENDEDOR');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    renderizar(<DetalheDaNota id="n1" />);
    await screen.findByText(NOTA.chave as string);
    expect(screen.queryByRole('button', { name: 'Cancelar NF-e' })).not.toBeInTheDocument();
  });

  it('mostra o bloqueio do servidor ao pedir o cancelamento', async () => {
    comPapel('ADMIN');
    const { ApiError } = await import('@/lib/api');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    vi.mocked(notasApi.cancelar).mockRejectedValue(new ApiError(409, 'Só é possível cancelar uma NF-e autorizada.', {}, { codigo: 'CANCELAMENTO_NAO_PERMITIDO' }));
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar NF-e' }));
    await userEvent.type(screen.getByLabelText('Justificativa do cancelamento'), justificativa);
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar cancelamento' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Só é possível cancelar uma NF-e autorizada.');
  });

  it('em CANCELANDO mostra a espera e não oferece cancelar de novo', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...NOTA, status: 'CANCELANDO' } });
    vi.mocked(notasApi.status).mockResolvedValue({ data: { id: 'n1', status: 'CANCELANDO', numero: 7, serie: '1', chave: NOTA.chave, cstat: '100', motivo: null, mensagem_erro: null, cancelamento_cstat: null, cancelamento_motivo: null } });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByText(/Aguardando a SEFAZ confirmar o cancelamento/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancelar NF-e' })).not.toBeInTheDocument();
  });

  it('mostra a recusa do cancelamento com cStat e motivo, e a nota segue autorizada', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...NOTA, cancelamento_cstat: '501', cancelamento_motivo: 'Pedido de Cancelamento intempestivo' } });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByRole('alert')).toHaveTextContent('Cancelamento recusado (cStat 501): Pedido de Cancelamento intempestivo');
    expect(screen.getByRole('button', { name: 'Cancelar NF-e' })).toBeInTheDocument();
  });

  it('cancelada mostra protocolo, justificativa e o link do XML do cancelamento', async () => {
    comPapel('LEITURA');
    vi.mocked(notasApi.buscar).mockResolvedValue({
      data: { ...NOTA, status: 'CANCELADA', cancelamento_protocolo: '135260000000555', cancelado_em: '2026-10-02T11:00:00-03:00', cancelamento_justificativa: justificativa },
    });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByText('135260000000555')).toBeInTheDocument();
    expect(screen.getByText(justificativa)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Baixar XML do cancelamento' })).toHaveAttribute('href', 'http://api.test/api/app/notas/n1/xml-cancelamento');
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `npx vitest run features/notas/components/Notas.test.tsx`
Expected: FAIL nos casos novos.

- [ ] **Step 3: Implementar**

Em `DetalheDaNota.tsx`:

1. Imports novos: `import { useForm } from 'react-hook-form'`, `import { zodResolver } from '@hookform/resolvers/zod'`, `import { useWatch } from 'react-hook-form'`, `import { Campo } from '@/components/form/Campo'`, `import { TextareaNativo } from '@/components/ui/campos-nativos'`, `import { formatarData } from '@/lib/formatos'` não é necessário (use `toLocaleString`), `urlDoXmlCancelamento` de `../api`, `cancelamentoSchema` e `type CancelamentoDados` de `../schemas`.
2. Estado novo: `const [cancelando, setCancelando] = useState(false);` e `const formCancelamento = useForm<CancelamentoDados>({ resolver: zodResolver(cancelamentoSchema), defaultValues: { justificativa: '' } });` e `const textoJustificativa = useWatch({ control: formCancelamento.control, name: 'justificativa' });`.
3. Polling: `enabled: nota?.status === 'PROCESSANDO' || nota?.status === 'CANCELANDO'`; no `useEffect`, a condição de invalidar passa a comparar com o status em que a nota estava: use `const statusDaNota = nota?.status;` e invalide quando `statusDoPolling !== undefined && statusDoPolling !== statusDaNota`.
4. Mutation nova:

```tsx
  const cancelar = useMutation({
    mutationFn: (d: CancelamentoDados) => notasApi.cancelar(id, d.justificativa.trim()),
    onSuccess: () => {
      toast.info('Cancelamento solicitado.');
      setCancelando(false);
      formCancelamento.reset({ justificativa: '' });
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
    },
    onError: (erro) => {
      formCancelamento.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível solicitar o cancelamento. Tente novamente.' });
    },
  });
```

5. Renderização (depois do bloco "Autorização" e antes dos botões de edição):

```tsx
      {nota.status === 'AUTORIZADA' && nota.cancelamento_cstat !== null ? (
        <p role="alert" className="rounded-md bg-danger/10 p-3 text-sm text-danger">
          Cancelamento recusado (cStat {nota.cancelamento_cstat}): {nota.cancelamento_motivo}
        </p>
      ) : null}

      {nota.status === 'CANCELANDO' ? (
        <p className="text-sm text-muted-foreground">Aguardando a SEFAZ confirmar o cancelamento. Esta página atualiza sozinha.</p>
      ) : null}

      {nota.status === 'CANCELADA' ? (
        <Card>
          <CardHeader><CardTitle>Cancelamento</CardTitle></CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p>Protocolo: <span className="font-mono">{nota.cancelamento_protocolo}</span></p>
            {nota.cancelado_em ? <p>Cancelada em {new Date(nota.cancelado_em).toLocaleString('pt-BR')}</p> : null}
            <p>Justificativa:</p>
            <p>{nota.cancelamento_justificativa}</p>
            <a href={urlDoXmlCancelamento(nota.id)} className={buttonVariants({ variant: 'outline' })}>Baixar XML do cancelamento</a>
          </CardContent>
        </Card>
      ) : null}

      {nota.status === 'AUTORIZADA' && podeEmitir ? (
        cancelando ? (
          <Card>
            <CardHeader><CardTitle>Cancelar NF-e</CardTitle></CardHeader>
            <CardContent>
              <form noValidate className="space-y-3" onSubmit={envioUnico(formCancelamento.handleSubmit(async (d) => { await cancelar.mutateAsync(d).catch(() => undefined); }))}>
                <p className="text-sm text-muted-foreground">O prazo para cancelar é definido pela SEFAZ. Se passou do prazo, ela recusa e a nota continua autorizada.</p>
                <Campo id="cancelamento_justificativa" label="Justificativa do cancelamento" dica={`${(textoJustificativa ?? '').trim().length}/255`} erro={formCancelamento.formState.errors.justificativa?.message}>
                  {(a11y) => <TextareaNativo {...a11y} rows={3} {...formCancelamento.register('justificativa')} />}
                </Campo>
                {formCancelamento.formState.errors.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{formCancelamento.formState.errors.root.message}</p> : null}
                <div className="flex gap-2">
                  <Button type="submit" variant="destructive" disabled={cancelar.isPending}>Confirmar cancelamento</Button>
                  <Button type="button" variant="outline" onClick={() => setCancelando(false)}>Voltar</Button>
                </div>
              </form>
            </CardContent>
          </Card>
        ) : (
          <div><Button type="button" variant="outline" onClick={() => setCancelando(true)}>Cancelar NF-e</Button></div>
        )
      ) : null}
```

(Como o `dica` do `Campo` mostra o contador, o texto exato do teste é `N/255`; o aviso de prazo fica num parágrafo à parte.) Se o `getByText('{n}/255')` do teste colidir com outro texto, ajuste o teste para `getByText(/\/255$/)`.

- [ ] **Step 4: Rodar e ver passar**

Run: `npx vitest run features/notas && npx tsc --noEmit && npx eslint features/notas app`
Expected: PASS, sem erros de tipo nem de lint (use `useWatch`, sem `any`).

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(frontend): cancelar NF-e no detalhe da nota com justificativa, espera e recusa"
```

---

### Task 13: Frontend: tela de inutilização

**Files:**
- Create: `frontend/features/notas/components/{FormInutilizacao,ListaDeInutilizacoes}.tsx`
- Create: `frontend/app/(app)/notas/inutilizar/page.tsx`
- Modify: `frontend/app/(app)/notas/page.tsx` (link "Inutilizar numeração", só para quem emite)
- Modify: `frontend/features/notas/components/Notas.test.tsx`

**Interfaces:**
- Consumes: `inutilizacoesApi`, `QUERY_KEY_INUTILIZACOES`, `inutilizacaoSchema`, `paraPayloadInutilizacao`, `ROTULO_STATUS_INUTILIZACAO`.
- Produces: `<FormInutilizacao />` (campos "Série", "Número inicial", "Número final", "Justificativa"; botão "Inutilizar numeração"; erro do servidor em `role="alert"`; no sucesso, toast com o status e invalida a lista) e `<ListaDeInutilizacoes />` (faixa, série, ambiente, status por texto, `cStat` e motivo quando houver, data). Página `/notas/inutilizar` (acesso só para PROPRIETARIO, ADMIN e FISCAL; os outros veem "Você não tem permissão para inutilizar numeração.").

- [ ] **Step 1: Testes** (em `Notas.test.tsx`; acrescente `import { FormInutilizacao } from './FormInutilizacao';`, `import { ListaDeInutilizacoes } from './ListaDeInutilizacoes';` e `import { inutilizacoesApi } from '../api';` junto ao `notasApi`)

```tsx
describe('inutilização', () => {
  it('envia a faixa no formato da API', async () => {
    vi.mocked(inutilizacoesApi.listar).mockResolvedValue({ data: [] });
    vi.mocked(inutilizacoesApi.criar).mockResolvedValue({ data: { id: 'i1', serie: '1', numero_inicial: 3, numero_final: 5, justificativa: 'x', ambiente: 'HOMOLOGACAO', status: 'INUTILIZADA', cstat: '102', motivo: null, protocolo: '1', created_at: '2026-10-02T10:00:00-03:00' } });
    renderizar(<FormInutilizacao />);

    await userEvent.type(screen.getByLabelText('Série'), '1');
    await userEvent.type(screen.getByLabelText('Número inicial'), '3');
    await userEvent.type(screen.getByLabelText('Número final'), '5');
    await userEvent.type(screen.getByLabelText('Justificativa'), 'Numeração perdida por queda do servidor');
    await userEvent.click(screen.getByRole('button', { name: 'Inutilizar numeração' }));

    await waitFor(() => expect(inutilizacoesApi.criar).toHaveBeenCalledWith({
      serie: '1', numero_inicial: 3, numero_final: 5, justificativa: 'Numeração perdida por queda do servidor',
    }));
  });

  it('valida a faixa antes de enviar', async () => {
    renderizar(<FormInutilizacao />);
    await userEvent.type(screen.getByLabelText('Série'), '1');
    await userEvent.type(screen.getByLabelText('Número inicial'), '9');
    await userEvent.type(screen.getByLabelText('Número final'), '5');
    await userEvent.type(screen.getByLabelText('Justificativa'), 'Numeração perdida por queda do servidor');
    await userEvent.click(screen.getByRole('button', { name: 'Inutilizar numeração' }));

    expect(await screen.findByText('O número inicial não pode passar do final.')).toBeInTheDocument();
    expect(inutilizacoesApi.criar).not.toHaveBeenCalled();
  });

  it('mostra o bloqueio do servidor', async () => {
    const { ApiError } = await import('@/lib/api');
    vi.mocked(inutilizacoesApi.criar).mockRejectedValue(new ApiError(422, 'Só é possível inutilizar números já alocados (até o 9).', {}, { codigo: 'FISCAL_FAIXA_NAO_ALOCADA' }));
    renderizar(<FormInutilizacao />);

    await userEvent.type(screen.getByLabelText('Série'), '1');
    await userEvent.type(screen.getByLabelText('Número inicial'), '8');
    await userEvent.type(screen.getByLabelText('Número final'), '12');
    await userEvent.type(screen.getByLabelText('Justificativa'), 'Numeração perdida por queda do servidor');
    await userEvent.click(screen.getByRole('button', { name: 'Inutilizar numeração' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('já alocados');
  });

  it('lista o histórico com o status em texto', async () => {
    vi.mocked(inutilizacoesApi.listar).mockResolvedValue({ data: [
      { id: 'i1', serie: '1', numero_inicial: 3, numero_final: 5, justificativa: 'Queda', ambiente: 'HOMOLOGACAO', status: 'RECUSADA', cstat: '241', motivo: 'Um numero da faixa ja foi utilizado', protocolo: null, created_at: '2026-10-02T10:00:00-03:00' },
    ] });
    renderizar(<ListaDeInutilizacoes />);

    expect(await screen.findByText('Recusada pela SEFAZ')).toBeInTheDocument();
    expect(screen.getByText(/3 a 5/)).toBeInTheDocument();
    expect(screen.getByText(/cStat 241/)).toBeInTheDocument();
  });

  it('mostra o estado vazio do histórico', async () => {
    vi.mocked(inutilizacoesApi.listar).mockResolvedValueOnce({ data: [] });
    renderizar(<ListaDeInutilizacoes />);
    expect(await screen.findByText('Nenhuma inutilização registrada.')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `npx vitest run features/notas/components/Notas.test.tsx`
Expected: FAIL (componentes inexistentes).

- [ ] **Step 3: Implementar**

`FormInutilizacao.tsx` (padrão de `NotaForm`: `useForm` + `zodResolver`, `Campo`, `useEnvioUnico`, `useMutation`):

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { TextareaNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { inutilizacoesApi, QUERY_KEY_INUTILIZACOES } from '../api';
import { inutilizacaoSchema, paraPayloadInutilizacao, type InutilizacaoDados } from '../schemas';
import { ROTULO_STATUS_INUTILIZACAO } from '../types';

const VAZIO: InutilizacaoDados = { serie: '', numero_inicial: '', numero_final: '', justificativa: '' };

export function FormInutilizacao() {
  const queryClient = useQueryClient();
  const form = useForm<InutilizacaoDados>({ resolver: zodResolver(inutilizacaoSchema), defaultValues: VAZIO });
  const enviar = useMutation({
    mutationFn: (d: InutilizacaoDados) => inutilizacoesApi.criar(paraPayloadInutilizacao(d)),
    onSuccess: ({ data }) => {
      toast.success(`Inutilização: ${ROTULO_STATUS_INUTILIZACAO[data.status]}.`);
      form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_INUTILIZACOES });
    },
    onError: (erro) => {
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Não foi possível inutilizar. Tente novamente.' });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await enviar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="inut_serie" label="Série" erro={erros.serie?.message}>
        {(a11y) => <Input {...a11y} inputMode="numeric" {...form.register('serie')} />}
      </Campo>
      <Campo id="inut_inicial" label="Número inicial" erro={erros.numero_inicial?.message}>
        {(a11y) => <Input {...a11y} inputMode="numeric" {...form.register('numero_inicial')} />}
      </Campo>
      <Campo id="inut_final" label="Número final" erro={erros.numero_final?.message}>
        {(a11y) => <Input {...a11y} inputMode="numeric" {...form.register('numero_final')} />}
      </Campo>
      <Campo id="inut_justificativa" label="Justificativa" dica="De 15 a 255 caracteres." erro={erros.justificativa?.message}>
        {(a11y) => <TextareaNativo {...a11y} rows={3} {...form.register('justificativa')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>Inutilizar numeração</Button>
    </form>
  );
}
```

`ListaDeInutilizacoes.tsx`:

```tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { inutilizacoesApi, QUERY_KEY_INUTILIZACOES } from '../api';
import { ROTULO_STATUS_INUTILIZACAO } from '../types';

export function ListaDeInutilizacoes() {
  const { data, isPending, isError } = useQuery({ queryKey: QUERY_KEY_INUTILIZACOES, queryFn: async () => (await inutilizacoesApi.listar()).data });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar as inutilizações.</p>;
  if (data.length === 0) return <p className="text-muted-foreground">Nenhuma inutilização registrada.</p>;

  return (
    <ul className="divide-y">
      {data.map((i) => (
        <li key={i.id} className="space-y-1 py-3 text-sm">
          <p className="font-medium">Série {i.serie} · números {i.numero_inicial} a {i.numero_final} · {i.ambiente === 'PRODUCAO' ? 'Produção' : 'Homologação'}</p>
          <p>{ROTULO_STATUS_INUTILIZACAO[i.status]}{i.cstat ? ` · cStat ${i.cstat}` : ''}{i.motivo ? `: ${i.motivo}` : ''}</p>
          <p className="text-xs text-muted-foreground">{new Date(i.created_at).toLocaleString('pt-BR')}</p>
        </li>
      ))}
    </ul>
  );
}
```

`app/(app)/notas/inutilizar/page.tsx`:

```tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { FormInutilizacao } from '@/features/notas/components/FormInutilizacao';
import { ListaDeInutilizacoes } from '@/features/notas/components/ListaDeInutilizacoes';

const PAPEIS_QUE_INUTILIZAM = ['PROPRIETARIO', 'ADMIN', 'FISCAL'];

export default function InutilizarPage() {
  const { data: usuario } = useUsuario();
  const pode = Boolean(usuario && PAPEIS_QUE_INUTILIZAM.includes(usuario.papel));

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Inutilizar numeração</h1>
      {pode ? (
        <div className="grid gap-4 lg:grid-cols-[22rem_1fr]">
          <Card>
            <CardHeader><CardTitle>Nova inutilização</CardTitle></CardHeader>
            <CardContent><FormInutilizacao /></CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle>Histórico</CardTitle></CardHeader>
            <CardContent><ListaDeInutilizacoes /></CardContent>
          </Card>
        </div>
      ) : (
        <p className="text-muted-foreground">Você não tem permissão para inutilizar numeração.</p>
      )}
    </div>
  );
}
```

Em `app/(app)/notas/page.tsx`, ao lado do botão "Nova NF-e", acrescente (para quem emite: PROPRIETARIO, ADMIN, FISCAL) `<Link href="/notas/inutilizar" className={buttonVariants({ variant: 'outline' })}>Inutilizar numeração</Link>`, agrupando os botões num `<div className="flex gap-2">`.

- [ ] **Step 4: Rodar e ver passar**

Run: `npx vitest run features/notas && npx tsc --noEmit && npx eslint features/notas app`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(frontend): tela de inutilização de numeração com histórico"
```

---

### Task 14: Revisão do frontend (suíte, tipos, lint e build)

- [ ] **Step 1: Suíte, tipos, lint e build**

Run (em `frontend/`): `npx vitest run && npx tsc --noEmit && npx eslint . && npm run build`
Expected: tudo verde (o único warning tolerado é o antigo de `CadastroForm.tsx`). A rota `/notas/inutilizar` aparece no build. Se o `CadastroForm` estourar 5 s na suíte completa, é o problema conhecido; confirme com `npx vitest run features/cadastro`.

- [ ] **Step 2: Conferência visual (se possível)**

Abra `npm run dev` e confira `/notas/{id}` (cancelar, aguardando, recusa) e `/notas/inutilizar` nos temas claro e escuro e em largura de celular. Registre no `PROGRESSO.md` se não for feito (como na F2 e na F3a).

- [ ] **Step 3: Commit de eventuais correções**

```bash
git add -A frontend
git commit -m "chore(frontend): revisão da F3b1 (suíte, tipos, lint e build)"
```

---

### Task 15: Fumaça, roteiro de homologação e fechamento da F3b1

**Files:**
- Modify: `PROGRESSO.md`, `TAREFAS.md`
- Temporário no scratchpad (não commitar): `FumacaCancelamentoTest.php`

- [ ] **Step 1: Fumaça com o motor real (teste temporário fora do repositório)**

Reaproveite o padrão da fumaça da F3a: um teste `Tests\Smoke\FumacaCancelamentoTest` em arquivo no scratchpad (`php artisan test <caminho absoluto>`), usando `CenarioDeNota`, `spaComo`, **sem** `EmissorFake` e um `.pfx` autoassinado do openssl. Fluxo: criar uma nota `AUTORIZADA` com `forceFill` (chave de 44 dígitos, protocolo, `xml_autorizado` mínimo, `ambiente HOMOLOGACAO`), `POST /api/app/notas/{id}/cancelar` com justificativa válida e imprimir o estado. Esperado: resposta 202, nota **permanece `CANCELANDO`** com evento de falha técnica (o certificado autoassinado não tem o OID do CNPJ, como na F3a), `cancelamento_justificativa` gravada, e `fiscal:reconciliar-processando` não muda a nota (a consulta também falha). Depois, bloqueios: justificativa de 5 caracteres dá 422 `JUSTIFICATIVA_INVALIDA`; nota `RASCUNHO` dá 409 `CANCELAMENTO_NAO_PERMITIDO`; inutilização de faixa não alocada dá 422 `FISCAL_FAIXA_NAO_ALOCADA`. Registre o resultado de cada passo no `PROGRESSO.md`. A comunicação real com a SEFAZ continua dependendo do certificado A1 (roteiro abaixo).

- [ ] **Step 2: Roteiro de homologação real (para o usuário)**

Escreva no `PROGRESSO.md` (seção "Roteiro de homologação real da F3b1"): (1) ter emitido e autorizado uma NF-e de teste (roteiro da F3a); (2) em Notas > abrir a nota autorizada > "Cancelar NF-e" com justificativa de 15+ caracteres; esperar `CANCELADA`; baixar o XML do cancelamento; (3) emitir outra NF-e de teste, deixar passar do prazo da UF e tentar cancelar: a SEFAZ deve recusar (cStat 501 ou similar), a nota continua autorizada e a tela mostra o motivo; **não** contornar; (4) em Notas > Inutilizar numeração: inutilizar um número já alocado e não utilizado (ex.: um número de uma nota rejeitada que não será reemitida, se houver; senão, emitir uma sequência de teste e inutilizar a faixa pulada); (5) conferir códigos desconhecidos em `assets/cstat-table.md` da skill; (6) worker (`php artisan queue:work`) e agendador (`php artisan schedule:work`) de pé.

- [ ] **Step 3: Atualizar `TAREFAS.md` e `PROGRESSO.md`**

Marque a F3b1 como concluída (15 tarefas). Pendências conhecidas: cancelamento por substituição e extemporâneo, carta de correção, validação de papel antes do corpo no FormRequest (422 antes de 403), lista de inutilizações sem filtros e sem paginação no frontend, `NfePhpEmissor` sem teste automatizado (cancelar e inutilizar), conferência visual. Reescreva "Contexto necessário" para a F3b2 (PDF da NF-e: DANFE A4 a partir do `xml_autorizado`; `assets/pdf-layouts/danfe` e `sped-da` da skill; o XML do cancelamento também pode precisar de marca d'água "CANCELADA" no PDF) e a "Próxima tarefa" como "F3b2: brainstorming do spec".

- [ ] **Step 4: Verificação final**

Run: `cd backend && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G` e `cd ../frontend && npx vitest run && npx tsc --noEmit && npx eslint . && npm run build`
Expected: tudo verde.

- [ ] **Step 5: Commit e recomendação**

```bash
git add PROGRESSO.md TAREFAS.md
git commit -m "docs: F3b1 concluída (cancelamento e inutilização), roteiro de homologação real e próximos passos"
```

Recomende `/compact` ou `/clear` antes de iniciar a F3b2.

---

## Autoavaliação do plano

**Cobertura do spec:** §3.1 (estados, contador) Tarefas 1 e 7; §3.2 (dados) Tarefa 1; §3.3 (`Justificativa`) Tarefa 2; §3.4 (contrato, interpretador, ambiente da nota) Tarefas 3 e 4; §3.5 (cancelamento, job, aplicação, reconciliação) Tarefas 5, 6 e 7; §3.6 (inutilização) Tarefa 8; §4 (API) Tarefa 9; §5 (frontend) Tarefas 11 a 13; §6 (testes) distribuídos; §7 (critério de pronto) Tarefa 15.

**Acréscimos ao spec feitos ao planejar:** (1) `cancelar` recebe também o `xmlAutorizado` (o motor precisa dele para montar o XML do cancelamento com `Complements::cancelRegister`); a Tarefa 3 atualiza o spec. (2) O `registrarFalhaTecnica` grava um evento a cada falha para auditoria.

**Itens que o executor deve conferir contra o código real (pontos dependentes de versão ou de nomes existentes):** assinaturas de `sefazConsultaChave`, `sefazCancela` e `sefazInutiliza` (Tarefa 4, Step 6); nome do case "ativa" de `SituacaoAssinatura` (Tarefa 5, Step 1); o contador `N/255` exibido como `dica` do `Campo` (Tarefa 12, ajuste do seletor do teste se colidir).

**Consistência de tipos:** `ResultadoDeCancelamento`/`ResultadoDeInutilizacao` (Tarefa 3) usados nas Tarefas 4, 6 e 8; `EmissorDeNfe::cancelar`/`inutilizar`/`consultarPorChave(…, string $ambiente)` (Tarefa 4) usados nas Tarefas 6, 7 e 8; `IniciarCancelamento` (Tarefa 5), `AplicarResultadoDoCancelamento` (Tarefas 6 e 7) e `InutilizarNumeracao` (Tarefa 8) consumidos pela API (Tarefa 9); `NotaStatus` do frontend com `cancelamento_cstat`/`cancelamento_motivo` (Tarefa 11) usado na Tarefa 12.
