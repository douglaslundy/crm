# F8d Testes de carga Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar scripts k6, comando de semeadura, emissor de carga e runbook para o usuário medir (metas e estresse) o staging da VPS com 50 empresas e 100 usuários simultâneos.

**Architecture:** Backend ganha um guarda de ambiente (`APP_AMBIENTE=staging`), um emissor de NF-e simulado (só liga com o guarda) e os comandos `carga:semear`/`carga:limpar`. A carga em si é um conjunto de scripts k6 versionados em `carga/k6/`, rodados em container pela VPS por um overlay do Compose. Nada roda contra SEFAZ/ADN.

**Tech Stack:** Laravel 12 / PHP (Pest-estilo PHPUnit), k6 (`grafana/k6`), Docker Compose.

**Spec:** `docs/superpowers/specs/2026-10-16-f8d-testes-de-carga-design.md` (a Task 1 corrige dois pontos dele: o guarda não pode ser `APP_ENV`, porque o staging roda com `APP_ENV=production`, e o emissor simulado cobre só NF-e/NFC-e).

## Global Constraints

- `declare(strict_types=1)` em todo PHP; JS dos scripts k6 em ES modules simples.
- Nenhum dado real; CNPJ gerado com dígitos válidos; segredos sempre criptografados como no resto do sistema.
- O guarda é **fail-closed**: `config('app.ambiente')` vem de `APP_AMBIENTE` com padrão `producao`; só o valor exato `staging` libera emissor simulado e semeadura.
- O emissor de carga **nunca** pode subir com `APP_AMBIENTE != staging`: o boot aborta.
- Metas: leitura p95 < 800 ms; escrita CRM p95 < 1,5 s; erros 5xx/rede < 1%; fila drena 20 notas/s sem backlog crescente nos últimos 5 min; API pública só 200/429, nunca 5xx.
- Estresse: rampa até 3x; `abortOnFail` se p95 > 5 s ou erros > 10%.
- Teste de isolamento por amostragem em cada cenário (resposta nunca traz id de outra empresa).
- Windows: shell come barras invertidas e CRLF atrapalha. Criar arquivos com Write/Edit. `.sh` e `.js` com LF (o `.gitattributes` do repo já cobre `*.sh`; confirmar `*.js`).
- Backend roda testes com `cd backend && php artisan test --filter=...`. Pint: `vendor/bin/pint`. PHPStan: `vendor/bin/phpstan analyse`.

## Review Focus

- `APP_AMBIENTE` ausente ou com valor estranho (`Staging`, vazio) com `FISCAL_EMISSOR_SIMULADO=1`: o boot tem que abortar. (Task 1 e 2.)
- `carga:semear` rodado duas vezes seguidas: não pode duplicar empresas nem estourar CNPJ único. (Task 3.)
- `carga:limpar` não pode apagar empresa que não seja de carga. (Task 3.)
- Vendedor semeado enxerga só os próprios negócios (o interruptor "vendedor vê só os seus" não pode quebrar a carga): os cenários usam só o usuário admin para escrever. (Task 4.)
- Chave de NF-e simulada igual entre duas empresas com o mesmo número: não pode colidir. (Task 2.)

---

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `backend/app/Modules/Shared/Domain/AmbienteDeCarga.php` | Guarda `permitido()`/`exigir()` |
| `backend/config/app.php`, `backend/config/fiscal.php` | `ambiente`, `emissor_simulado`, `emissor_simulado_latencia_ms` |
| `backend/app/Modules/Fiscal/Infrastructure/Simulado/EmissorDeNfeSimulado.php` | Emissor de NF-e sintético |
| `backend/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` | Troca o binding sob o guarda |
| `backend/app/Modules/Platform/Console/CargaSemearCommand.php`, `CargaLimparCommand.php` | Semeadura e limpeza |
| `carga/k6/lib/comum.js` | Login por sessão, cabeçalhos, escrita com XSRF, isolamento |
| `carga/k6/{leitura,crm-escrita,emissao,api-publica,estresse}.js` | Cenários |
| `deploy/docker-compose.carga.yml` | Overlay: liga o emissor simulado em `app`/`worker` e define o serviço `k6` |
| `docs/carga/runbook-testes-de-carga.md`, `docs/carga/_modelo-de-resultado.md` | Runbook e modelo do relatório |

---

### Task 1: Guarda de ambiente de carga

**Files:**
- Create: `backend/app/Modules/Shared/Domain/AmbienteDeCarga.php`
- Modify: `backend/config/app.php`, `deploy/.env.example`, `deploy/.env.production.example`
- Modify: `docs/superpowers/specs/2026-10-16-f8d-testes-de-carga-design.md`
- Test: `backend/tests/Unit/Shared/AmbienteDeCargaTest.php`

**Interfaces:**
- Produces: `AmbienteDeCarga::permitido(): bool`; `AmbienteDeCarga::exigir(string $recurso): void` (lança `RuntimeException` com a mensagem `"{$recurso} só roda com APP_AMBIENTE=staging."`).

- [ ] **Step 1: Escrever o teste**

`backend/tests/Unit/Shared/AmbienteDeCargaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\AmbienteDeCarga;
use RuntimeException;
use Tests\TestCase;

class AmbienteDeCargaTest extends TestCase
{
    public function test_so_o_valor_exato_staging_libera(): void
    {
        foreach (['staging' => true, 'producao' => false, 'Staging' => false, '' => false, null => false] as $valor => $esperado) {
            config(['app.ambiente' => $valor === '' ? '' : ($valor ?: null)]);
            $this->assertSame($esperado, AmbienteDeCarga::permitido(), "valor: ".var_export($valor, true));
        }
    }

    public function test_exigir_lanca_fora_do_staging(): void
    {
        config(['app.ambiente' => 'producao']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('carga:semear só roda com APP_AMBIENTE=staging.');
        AmbienteDeCarga::exigir('carga:semear');
    }

    public function test_padrao_sem_variavel_e_producao(): void
    {
        // Nenhum .env de teste define APP_AMBIENTE: o padrão do config tem que bloquear.
        $this->assertSame('producao', config('app.ambiente'));
    }
}
```

Se o `phpunit.xml` ou o `.env.testing` definir `APP_AMBIENTE`, remover o último teste e conferir o padrão (`'producao'`) lendo `config/app.php` no Step 3.

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=AmbienteDeCargaTest`
Expected: FAIL (`Class "App\Modules\Shared\Domain\AmbienteDeCarga" not found`).

- [ ] **Step 3: Implementar**

`backend/app/Modules/Shared/Domain/AmbienteDeCarga.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use RuntimeException;

/** O staging roda com APP_ENV=production, então o guarda é uma variável própria. Fail-closed: o padrão é `producao`. */
final class AmbienteDeCarga
{
    public static function permitido(): bool
    {
        return config('app.ambiente') === 'staging';
    }

    public static function exigir(string $recurso): void
    {
        if (! self::permitido()) {
            throw new RuntimeException("{$recurso} só roda com APP_AMBIENTE=staging.");
        }
    }
}
```

Em `backend/config/app.php`, logo depois da chave `'env'`, acrescentar:

```php
    /** `staging` libera ferramentas de carga (emissor simulado, carga:semear). Qualquer outro valor, inclusive ausente, bloqueia. */
    'ambiente' => env('APP_AMBIENTE', 'producao'),
```

`deploy/.env.example`: adicionar a linha `APP_AMBIENTE=staging` abaixo de `APP_ENV=production`. `deploy/.env.production.example`: adicionar `APP_AMBIENTE=producao` abaixo de `APP_ENV=production`.

No spec (`docs/superpowers/specs/2026-10-16-f8d-testes-de-carga-design.md`): em "Emissor de carga" trocar `APP_ENV=production` por `APP_AMBIENTE != staging` (e dizer que o guarda é `AmbienteDeCarga`, porque o staging usa `APP_ENV=production`); em "Dados de carga" idem; em "Emissor de carga" acrescentar "cobre NF-e e NFC-e (`EmissorDeNfe`); NFS-e fica fora".

- [ ] **Step 4: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=AmbienteDeCargaTest`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Shared/Domain/AmbienteDeCarga.php backend/config/app.php backend/tests/Unit/Shared/AmbienteDeCargaTest.php deploy/.env.example deploy/.env.production.example docs/superpowers/specs/2026-10-16-f8d-testes-de-carga-design.md
git commit -m "feat(carga): guarda APP_AMBIENTE=staging para ferramentas de carga"
```

---

### Task 2: Emissor de NF-e simulado

**Files:**
- Create: `backend/app/Modules/Fiscal/Infrastructure/Simulado/EmissorDeNfeSimulado.php`
- Modify: `backend/config/fiscal.php`, `backend/app/Modules/Fiscal/Providers/FiscalServiceProvider.php:44-48`
- Test: `backend/tests/Feature/Fiscal/EmissorDeNfeSimuladoTest.php`

**Interfaces:**
- Consumes: `AmbienteDeCarga::exigir()` (Task 1); contrato `EmissorDeNfe`; `XmlAssinado(chave, xml)`; `ResultadoDeEmissao::autorizada($protocolo, $xml)`, `ResultadoDeConsulta::naoEncontrada()`, `ResultadoDeCancelamento::aceito($protocolo, $xml)`, `ResultadoDeInutilizacao::inutilizada($protocolo, $xml)`.
- Produces: `EmissorDeNfeSimulado` (construtor `__construct(int $latenciaMs = 0)`); config `fiscal.emissor_simulado` (bool) e `fiscal.emissor_simulado_latencia_ms` (int).

- [ ] **Step 1: Escrever o teste**

`backend/tests/Feature/Fiscal/EmissorDeNfeSimuladoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Infrastructure\Simulado\EmissorDeNfeSimulado;
use App\Modules\Fiscal\Providers\FiscalServiceProvider;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class EmissorDeNfeSimuladoTest extends TestCase
{
    use CenarioDeApi;
    use CenarioDeNota;
    use RefreshDatabase;

    public function test_o_provider_aborta_com_a_flag_ligada_fora_do_staging(): void
    {
        foreach (['producao', 'Staging', ''] as $ambiente) {
            config(['app.ambiente' => $ambiente, 'fiscal.emissor_simulado' => true]);
            try {
                (new FiscalServiceProvider($this->app))->register();
                $this->fail("Subiu com APP_AMBIENTE='{$ambiente}'");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('APP_AMBIENTE=staging', $e->getMessage());
            }
        }
    }

    public function test_no_staging_com_a_flag_o_contrato_resolve_para_o_simulado(): void
    {
        config(['app.ambiente' => 'staging', 'fiscal.emissor_simulado' => true]);
        (new FiscalServiceProvider($this->app))->register();

        $this->assertInstanceOf(EmissorDeNfeSimulado::class, $this->app->make(EmissorDeNfe::class));
    }

    public function test_emissao_pela_api_passa_pela_fila_e_termina_autorizada(): void
    {
        config(['queue.default' => 'sync']);
        $this->montarCenario(Modulo::Api, Modulo::FiscalNfe);
        $this->tenant->plano?->limites()->create(['recurso' => Recurso::ApiRequisicoesMin->value, 'limite' => -1]);
        [, $texto] = $this->novaChave($this->admin, AmbienteDaChave::Test);
        $this->app->instance(EmissorDeNfe::class, new EmissorDeNfeSimulado);

        $resposta = $this->comoApi($texto)->withHeaders(['Idempotency-Key' => 'carga-1'])->postJson('/api/v1/notas', [
            'modelo' => 'NFE', 'cliente' => ['id' => $this->cliente->id], 'desconto_centavos' => 0,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1']],
            'pagamentos' => [['tpag' => '01', 'valor_centavos' => 1000]],
        ])->assertStatus(202);

        $nota = Nota::query()->findOrFail($resposta->json('data.id'));
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame(44, strlen((string) $nota->chave));
    }

    public function test_chave_nao_colide_entre_empresas_com_o_mesmo_numero(): void
    {
        $this->montarCenario(Modulo::FiscalNfe);
        $a = $this->emitente;
        $outro = $a->replicate();
        $outro->id = '00000000-0000-4000-8000-000000000002';
        // Mesmo número nas duas empresas: construir o `NotaParaEmissao` como em EmitirNotaJobTest (grep "new NotaParaEmissao(").
        $nota = $this->notaParaEmissaoComNumero(7);
        $emissor = new EmissorDeNfeSimulado;

        $this->assertNotSame($emissor->preparar($a, $nota)->chave, $emissor->preparar($outro, $nota)->chave);
    }

    private function notaParaEmissaoComNumero(int $numero): NotaParaEmissao
    {
        // Preencher com o construtor real de NotaParaEmissao (ler o arquivo), usando $this->cliente e $this->produto do cenário.
        throw new \LogicException('implementar lendo NotaParaEmissao e EmitirNotaJobTest');
    }
}
```

O helper `notaParaEmissaoComNumero` precisa ser preenchido no Step 1: ler `backend/app/Modules/Fiscal/Domain/Emissao/NotaParaEmissao.php` (construtor) e copiar a construção mínima de `backend/tests/Feature/Fiscal/EmitirNotaJobTest.php` (`grep -n "new NotaParaEmissao(" -B3 -A12`), com `numero` = `$numero`. Adicionar o `use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;`. Se a construção direta for impraticável (muitos campos), trocar este teste por um que roda `preparar()` com dois `NotaParaEmissao` obtidos de `Nota` criadas por `Nota::factory()` + o serviço que monta a nota (o mesmo que o job usa), mantendo a asserção `assertNotSame` das chaves.

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=EmissorDeNfeSimuladoTest`
Expected: FAIL (classe `EmissorDeNfeSimulado` não existe).

- [ ] **Step 3: Implementar**

`backend/config/fiscal.php`, antes do `];` final:

```php

    /** Só para teste de carga (exige APP_AMBIENTE=staging, senão o boot aborta). Troca o envio à SEFAZ por uma resposta sintética. */
    'emissor_simulado' => (bool) env('FISCAL_EMISSOR_SIMULADO', false),

    'emissor_simulado_latencia_ms' => (int) env('FISCAL_EMISSOR_SIMULADO_LATENCIA_MS', 150),
```

`backend/app/Modules/Fiscal/Infrastructure/Simulado/EmissorDeNfeSimulado.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Simulado;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEpec;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Só para teste de carga: autoriza tudo, sem rede e sem certificado. O XML é sintético e NÃO é válido contra o XSD.
 * O provider só o registra com APP_AMBIENTE=staging.
 */
final class EmissorDeNfeSimulado implements EmissorDeNfe
{
    public function __construct(private readonly int $latenciaMs = 0) {}

    public function preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado
    {
        // 2 + 10 + 32 = 44 dígitos. O crc32 do emitente separa empresas que usam o mesmo número.
        $chave = '35'.str_pad((string) crc32($emitente->id), 10, '0', STR_PAD_LEFT).str_pad((string) $nota->numero, 32, '0', STR_PAD_LEFT);

        return new XmlAssinado($chave, '<NFe>simulado</NFe>');
    }

    public function prepararContingencia(Emitente $emitente, NotaParaEmissao $nota, string $justificativa, CarbonImmutable $em): XmlAssinado
    {
        throw new LogicException('Contingência não é simulada.');
    }

    public function prepararEpec(Emitente $emitente, NotaParaEmissao $nota, string $justificativa, CarbonImmutable $em): XmlAssinado
    {
        throw new LogicException('EPEC não é simulado.');
    }

    public function registrarEpec(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEpec
    {
        throw new LogicException('EPEC não é simulado.');
    }

    public function transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao
    {
        $this->esperar();

        return ResultadoDeEmissao::autorizada('135'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT), '<nfeProc>simulado</nfeProc>');
    }

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado, string $ambiente): ResultadoDeConsulta
    {
        $this->esperar();

        return ResultadoDeConsulta::naoEncontrada();
    }

    public function cancelar(Emitente $emitente, string $chave, string $protocolo, string $justificativa, string $ambiente, string $xmlAutorizado): ResultadoDeCancelamento
    {
        $this->esperar();

        return ResultadoDeCancelamento::aceito('135000000000099', '<nfeProc>cancelada-simulada</nfeProc>');
    }

    public function inutilizar(Emitente $emitente, ModeloDocumento $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao
    {
        $this->esperar();

        return ResultadoDeInutilizacao::inutilizada('135000000000098', '<retInutNFe/>');
    }

    private function esperar(): void
    {
        if ($this->latenciaMs > 0) {
            usleep($this->latenciaMs * 1000);
        }
    }
}
```

`FiscalServiceProvider::register()`: trocar a linha do binding de `EmissorDeNfe` por:

```php
        if (config('fiscal.emissor_simulado')) {
            // Aborta o boot fora do staging: o emissor simulado nunca pode atender uma empresa real.
            AmbienteDeCarga::exigir('FISCAL_EMISSOR_SIMULADO');
            $this->app->bind(EmissorDeNfe::class, fn (): EmissorDeNfeSimulado => new EmissorDeNfeSimulado((int) config('fiscal.emissor_simulado_latencia_ms')));
        } else {
            $this->app->bind(EmissorDeNfe::class, NfePhpEmissor::class);
        }
```

e os `use` de `App\Modules\Shared\Domain\AmbienteDeCarga` e `App\Modules\Fiscal\Infrastructure\Simulado\EmissorDeNfeSimulado`. Manter o comentário "Os testes sobrescrevem..." acima do `if`.

- [ ] **Step 4: Rodar e ver passar**

Run: `cd backend && php artisan test --filter=EmissorDeNfeSimuladoTest`
Expected: PASS (4 testes). Se o teste 3 falhar por plano/limite, ajustar só o cenário do teste (limites do plano), não o emissor.

- [ ] **Step 5: Qualidade e commit**

Run: `cd backend && vendor/bin/pint --test app/Modules/Fiscal app/Modules/Shared tests/Feature/Fiscal/EmissorDeNfeSimuladoTest.php && vendor/bin/phpstan analyse --memory-limit=1G`
Expected: sem erros (se o Pint reclamar, rodar sem `--test`).

```bash
git add backend/app/Modules/Fiscal backend/config/fiscal.php backend/tests/Feature/Fiscal/EmissorDeNfeSimuladoTest.php
git commit -m "feat(carga): emissor de NF-e simulado, bloqueado fora do staging"
```

---

### Task 3: Comandos `carga:semear` e `carga:limpar`

**Files:**
- Create: `backend/app/Modules/Platform/Console/CargaSemearCommand.php`, `backend/app/Modules/Platform/Console/CargaLimparCommand.php`
- Modify: `backend/app/Modules/Platform/Providers/PlatformServiceProvider.php` (lista de `commands()`, linha ~23; confirmar o nome do arquivo com `ls backend/app/Modules/Platform/Providers`)
- Test: `backend/tests/Feature/Platform/CargaSemearTest.php`

**Interfaces:**
- Consumes: `AmbienteDeCarga` (Task 1).
- Produces: `php artisan carga:semear {--empresas=50} {--saida=}` grava JSON em `--saida` (padrão `storage_path('app/carga/contas.json')`) no formato:

```json
{"senha":"Senha123","empresas":[{"indice":1,"tenant_id":"…","usuarios":[{"email":"carga1.u1@carga.local","papel":"ADMIN"},{"email":"carga1.u2@carga.local","papel":"VENDEDOR"}],"chave_api":"test_…","funil_id":"…","etapa_ids":["…"],"cliente_ids":["…"],"produto_id":"…","negocio_ids":["…"]}]}
```

  e `php artisan carga:limpar` apaga só as empresas de carga (razão social começando por `CARGA-`).

- [ ] **Step 1: Escrever o teste**

`backend/tests/Feature/Platform/CargaSemearTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CargaSemearTest extends TestCase
{
    use RefreshDatabase;

    private string $saida;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saida = sys_get_temp_dir().'/contas-'.uniqid().'.json';
        config(['app.ambiente' => 'staging']);
    }

    protected function tearDown(): void
    {
        @unlink($this->saida);
        parent::tearDown();
    }

    public function test_recusa_fora_do_staging(): void
    {
        config(['app.ambiente' => 'producao']);

        $this->artisan('carga:semear', ['--empresas' => 1, '--saida' => $this->saida])->assertFailed();
        $this->artisan('carga:limpar')->assertFailed();
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_semeia_empresas_com_tudo_para_a_carga(): void
    {
        $this->artisan('carga:semear', ['--empresas' => 2, '--saida' => $this->saida])->assertSuccessful();

        $dados = json_decode((string) file_get_contents($this->saida), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Senha123', $dados['senha']);
        $this->assertCount(2, $dados['empresas']);
        $e = $dados['empresas'][0];
        $this->assertCount(2, $e['usuarios']);
        $this->assertSame('ADMIN', $e['usuarios'][0]['papel']);
        $this->assertCount(10, $e['cliente_ids']);
        $this->assertCount(20, $e['negocio_ids']);
        $this->assertCount(3, $e['etapa_ids']);
        $this->assertStringStartsWith('test_', $e['chave_api']);
        $this->assertSame(2, Tenant::query()->where('razao_social', 'like', 'CARGA-%')->count());
    }

    public function test_dados_de_uma_empresa_nao_aparecem_na_outra(): void
    {
        $this->artisan('carga:semear', ['--empresas' => 2, '--saida' => $this->saida])->assertSuccessful();
        $dados = json_decode((string) file_get_contents($this->saida), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEmpty(array_intersect($dados['empresas'][0]['cliente_ids'], $dados['empresas'][1]['cliente_ids']));
        $this->assertNotSame($dados['empresas'][0]['tenant_id'], $dados['empresas'][1]['tenant_id']);
    }

    public function test_rodar_duas_vezes_exige_limpar_antes(): void
    {
        $this->artisan('carga:semear', ['--empresas' => 1, '--saida' => $this->saida])->assertSuccessful();
        $this->artisan('carga:semear', ['--empresas' => 1, '--saida' => $this->saida])->assertFailed();
        $this->assertSame(1, Tenant::query()->where('razao_social', 'like', 'CARGA-%')->count());
    }

    public function test_limpar_remove_so_as_empresas_de_carga(): void
    {
        $outra = Tenant::factory()->create(['razao_social' => 'Empresa Real Ltda']);
        $this->artisan('carga:semear', ['--empresas' => 1, '--saida' => $this->saida])->assertSuccessful();

        $this->artisan('carga:limpar')->assertSuccessful();

        $this->assertSame(0, Tenant::query()->where('razao_social', 'like', 'CARGA-%')->count());
        $this->assertNotNull(Tenant::query()->find($outra->id));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=CargaSemearTest`
Expected: FAIL (comando não definido).

- [ ] **Step 3: Implementar `CargaSemearCommand`**

`backend/app/Modules/Platform/Console/CargaSemearCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Crm\Application\Actions\CriarNegocio;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\AmbienteDeCarga;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Console\Command;
use RuntimeException;

/** Só staging. Cria empresas fictícias (`CARGA-n`) com tudo que os cenários do k6 usam e grava as contas em JSON. */
final class CargaSemearCommand extends Command
{
    protected $signature = 'carga:semear {--empresas=50} {--saida=}';

    protected $description = 'Semeia empresas fictícias para o teste de carga (só com APP_AMBIENTE=staging).';

    public const SENHA = 'Senha123';

    public function handle(TenantContext $contexto, PreparadorDoCrm $preparador, CriarNegocio $criarNegocio): int
    {
        try {
            AmbienteDeCarga::exigir('carga:semear');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (Tenant::query()->where('razao_social', 'like', 'CARGA-%')->exists()) {
            $this->error('Já existem empresas de carga. Rode carga:limpar antes.');

            return self::FAILURE;
        }

        $plano = Plano::factory()
            ->comModulos(Modulo::Crm, Modulo::Api, Modulo::FiscalNfe)
            ->comLimites([
                Recurso::ApiRequisicoesMin->value => 300, Recurso::Clientes->value => Plano::ILIMITADO,
                Recurso::Produtos->value => 1000, Recurso::DocumentosMes->value => 1000000,
            ])
            ->create(['nome' => 'CARGA (não usar)', 'visivel' => false]);

        $empresas = [];
        $total = (int) $this->option('empresas');
        for ($i = 1; $i <= $total; $i++) {
            $empresas[] = $this->semearEmpresa($i, $plano, $contexto, $preparador, $criarNegocio);
        }
        $contexto->clear();

        $saida = (string) ($this->option('saida') ?: storage_path('app/carga/contas.json'));
        if (! is_dir(dirname($saida))) {
            mkdir(dirname($saida), 0770, true);
        }
        file_put_contents($saida, json_encode(['senha' => self::SENHA, 'empresas' => $empresas], JSON_THROW_ON_ERROR));
        $this->info("{$total} empresas semeadas. Contas em {$saida} (contém chaves de API: não vai para o git).");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function semearEmpresa(int $i, Plano $plano, TenantContext $contexto, PreparadorDoCrm $preparador, CriarNegocio $criarNegocio): array
    {
        $tenant = Tenant::factory()->create(['plano_id' => $plano->id, 'razao_social' => "CARGA-{$i}", 'cnpj' => self::cnpj($i)]);
        $contexto->set($tenant->id);

        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin, 'email' => "carga{$i}.u1@carga.local"]);
        Usuario::factory()->for($tenant)->create(['papel' => Papel::Vendedor, 'email' => "carga{$i}.u2@carga.local"]);

        $emitente = Emitente::factory()->completo()->create([
            'inscricao_estadual' => '110042490114', 'certificado_status' => CertificadoStatus::Valido,
            'certificado_validade' => now()->addYear()->toDateString(),
        ]);
        EmitenteSerie::factory()->for($emitente)->create(['modelo' => ModeloDocumento::Nfe, 'serie' => '1']);

        $clientes = Cliente::factory()->comCpfValido()->count(10)->create([
            'estagio' => EstagioCliente::Cliente, 'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro',
            'cidade' => 'São Paulo', 'uf' => 'SP', 'cep' => '01001000', 'codigo_ibge' => '3550308',
        ]);
        $produto = Produto::factory()->completo()->create(['preco_centavos' => 1000, 'sku' => 'CARGA-1', 'nome' => 'Produto de carga']);

        $preparador->garantir($admin);
        $funil = Funil::query()->where('padrao', true)->with('etapas')->firstOrFail();
        $etapaIds = $funil->etapas->sortBy('posicao')->pluck('id')->values()->all();

        $negocioIds = [];
        for ($n = 1; $n <= 20; $n++) {
            $negocio = $criarNegocio->executar($admin, [
                'titulo' => "Negócio de carga {$n}", 'funil_id' => $funil->id, 'etapa_id' => $etapaIds[$n % count($etapaIds)],
                'cliente_id' => $clientes[$n % 10]->id,
                'itens' => [['tipo' => TipoDeItem::Produto->value, 'item_id' => $produto->id, 'quantidade' => 1]],
            ]);
            $negocioIds[] = $negocio->id;
        }

        $gerada = (new GeradorDeChaveDeApi)->gerar(AmbienteDaChave::Test);
        ChaveDeApi::factory()->create(['usuario_id' => $admin->id, 'ambiente' => AmbienteDaChave::Test, 'hash' => $gerada->hash, 'prefixo' => $gerada->prefixo, 'ultimos4' => $gerada->ultimos4]);

        return [
            'indice' => $i, 'tenant_id' => $tenant->id,
            'usuarios' => [['email' => $admin->email, 'papel' => 'ADMIN'], ['email' => "carga{$i}.u2@carga.local", 'papel' => 'VENDEDOR']],
            'chave_api' => $gerada->texto, 'funil_id' => $funil->id, 'etapa_ids' => $etapaIds,
            'cliente_ids' => $clientes->pluck('id')->all(), 'produto_id' => $produto->id, 'negocio_ids' => $negocioIds,
        ];
    }

    /** CNPJ com dígitos verificadores válidos: 8 dígitos de base a partir do índice, filial 0001. */
    private static function cnpj(int $i): string
    {
        $base = str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT).'0001';
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach ($pesos as $k => $peso) {
                $soma += (int) $base[$k] * $peso;
            }
            $resto = $soma % 11;
            $base .= (string) ($resto < 2 ? 0 : 11 - $resto);
        }

        return $base;
    }
}
```

Confirmar antes de rodar: (a) `TenantContext` tem `set()` e `clear()` (`grep -n "function" backend/app/Modules/Tenancy/Domain/TenantContext.php`; se o método de limpar tiver outro nome, usar o real); (b) o tipo de retorno de `CriarNegocio::executar` tem `->id`; (c) os `PlanoFactory`/`ChaveDeApiFactory` aceitam esses atributos (o `CenarioDeApi` do teste faz o mesmo).

- [ ] **Step 4: Implementar `CargaLimparCommand` e registrar**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\AmbienteDeCarga;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use RuntimeException;

/** Remove só as empresas `CARGA-%` (e o plano de carga). Depende dos `cascadeOnDelete` das migrations. */
final class CargaLimparCommand extends Command
{
    protected $signature = 'carga:limpar';

    protected $description = 'Remove as empresas fictícias do teste de carga (só com APP_AMBIENTE=staging).';

    public function handle(): int
    {
        try {
            AmbienteDeCarga::exigir('carga:limpar');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $apagadas = Tenant::query()->where('razao_social', 'like', 'CARGA-%')->get()->each->delete()->count();
        Plano::query()->where('nome', 'CARGA (não usar)')->delete();
        $this->info("{$apagadas} empresas de carga removidas.");

        return self::SUCCESS;
    }
}
```

No `PlatformServiceProvider`, adicionar `CargaSemearCommand::class, CargaLimparCommand::class` à lista de `commands()` (com os `use`).

- [ ] **Step 5: Rodar**

Run: `cd backend && php artisan test --filter=CargaSemearTest`
Expected: PASS (5). Se `carga:limpar` falhar por chave estrangeira (tabelas sem `cascadeOnDelete`), o teste `test_limpar_remove_so_as_empresas_de_carga` aponta a tabela: trocar o `delete()` por uma limpeza explícita das tabelas filhas dentro de `DB::transaction`, sempre filtrando por `tenant_id` das empresas `CARGA-%` (nunca `truncate`). Anotar a decisão no `PROGRESSO.md`.

- [ ] **Step 6: Qualidade e commit**

Run: `cd backend && vendor/bin/pint app/Modules/Platform tests/Feature/Platform/CargaSemearTest.php && vendor/bin/phpstan analyse --memory-limit=1G`
Expected: sem erros.

```bash
git add backend/app/Modules/Platform backend/tests/Feature/Platform/CargaSemearTest.php
git commit -m "feat(carga): comandos carga:semear e carga:limpar (só staging)"
```

---

### Task 4: k6 base, leitura e escrita do CRM

**Files:**
- Create: `carga/k6/lib/comum.js`, `carga/k6/leitura.js`, `carga/k6/crm-escrita.js`, `carga/.gitignore`, `carga/dados/.gitkeep`
- Modify: `.gitattributes` (se não houver `*.js text eol=lf`)

**Interfaces:**
- Consumes: formato de `contas.json` (Task 3).
- Produces (`comum.js`): `API`, `contas`, `donoDe(id)`, `contaDoVu()`, `entrar(conta, indiceDoUsuario)`, `ler(caminho, grupo)`, `escrever(metodo, caminho, corpo, grupo)`, `limiaresPadrao`.

Sem Docker aqui, esses scripts não rodam localmente: a verificação é `node --check` (sintaxe) e a leitura do `k6 inspect` na VPS (runbook).

- [ ] **Step 1: `carga/.gitignore`**

```
dados/*
!dados/.gitkeep
saida/
```

- [ ] **Step 2: `carga/k6/lib/comum.js`**

```js
import http from 'k6/http';
import { check, fail } from 'k6';

export const API = __ENV.API_URL || 'http://app:8080';
// O Sanctum só trata como SPA (sessão) quem manda Origin/Referer de um domínio de SANCTUM_STATEFUL_DOMAINS.
const ORIGEM = __ENV.ORIGEM || 'http://192.168.0.233:3200';

const dados = JSON.parse(open('/dados/contas.json'));
export const senha = dados.senha;
export const contas = dados.empresas;

// id (cliente, negócio) → índice da empresa dona: base do teste de isolamento.
const dono = new Map();
for (const c of contas) {
  for (const id of [...c.cliente_ids, ...c.negocio_ids]) dono.set(id, c.indice);
}
export function donoDe(id) {
  return dono.get(id);
}

export function contaDoVu() {
  return contas[(__VU - 1) % contas.length];
}

function cabecalhos(extra = {}) {
  return { Accept: 'application/json', Origin: ORIGEM, Referer: `${ORIGEM}/`, ...extra };
}

function xsrf() {
  const lista = http.cookieJar().cookiesForURL(API)['XSRF-TOKEN'];
  return lista && lista.length ? decodeURIComponent(lista[0]) : '';
}

/** Login por sessão (cookie), como o frontend faz. Chamar uma vez por VU. */
export function entrar(conta, indiceDoUsuario = 0) {
  http.get(`${API}/sanctum/csrf-cookie`, { headers: cabecalhos(), tags: { grupo: 'login' } });
  const r = http.post(
    `${API}/api/app/auth/login`,
    JSON.stringify({ email: conta.usuarios[indiceDoUsuario].email, password: senha }),
    { headers: cabecalhos({ 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() }), tags: { grupo: 'login' } },
  );
  if (!check(r, { 'login 2xx': (x) => x.status >= 200 && x.status < 300 })) fail(`login falhou: ${r.status}`);
}

export function ler(caminho, grupo) {
  return http.get(`${API}${caminho}`, { headers: cabecalhos(), tags: { grupo } });
}

export function escrever(metodo, caminho, corpo, grupo) {
  return http.request(metodo, `${API}${caminho}`, corpo === undefined ? null : JSON.stringify(corpo), {
    headers: cabecalhos({ 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() }),
    tags: { grupo },
  });
}

export function semVazamento(resposta, conta) {
  const itens = (resposta.json('data') || []).map((x) => x.id);
  return itens.every((id) => donoDe(id) === undefined || donoDe(id) === conta.indice);
}

/** Metas da spec. `grupo` é a tag usada nos pedidos de cada cenário. */
export function limiaresPadrao(grupos) {
  const t = {
    'http_req_failed{status:>=500}': ['rate<0.01'],
    checks: ['rate>0.99'],
  };
  for (const [grupo, p95] of Object.entries(grupos)) t[`http_req_duration{grupo:${grupo}}`] = [`p(95)<${p95}`];
  return t;
}
```

Observação ao executor: o filtro `http_req_failed{status:>=500}` não existe no k6 (tags de status são texto). Trocar por um `Rate` próprio: em `comum.js`, `import { Rate } from 'k6/metrics'; export const erro5xx = new Rate('erro_5xx');` e, em cada cenário, `erro5xx.add(r.status >= 500)` depois de cada pedido; o limiar fica `erro_5xx: ['rate<0.01']`. Aplicar essa troca ao escrever o arquivo (não deixar a chave `http_req_failed{status:>=500}`).

- [ ] **Step 3: `carga/k6/leitura.js`**

```js
import { sleep, check } from 'k6';
import { contaDoVu, entrar, ler, semVazamento, limiaresPadrao, erro5xx } from './lib/comum.js';

export const options = {
  scenarios: {
    leitura: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '2m', target: 100 }, { duration: '10m', target: 100 }, { duration: '30s', target: 0 }] },
  },
  thresholds: limiaresPadrao({ leitura: 800 }),
};

let logado = false;
const ROTAS = ['/api/app/auth/me', '/api/app/dashboard/fiscal', '/api/app/clientes', '/api/app/notas', '/api/app/crm/funis', '/api/app/crm/negocios', '/api/app/crm/resumo', '/api/app/crm/agenda'];

export default function () {
  const conta = contaDoVu();
  if (!logado) {
    entrar(conta, __VU % 2);
    logado = true;
  }
  for (const rota of ROTAS) {
    const r = ler(rota, 'leitura');
    erro5xx.add(r.status >= 500);
    check(r, { [`${rota} 200`]: (x) => x.status === 200 });
  }
  const clientes = ler('/api/app/clientes', 'leitura');
  check(clientes, { 'isolamento: clientes só da própria empresa': (x) => x.status !== 200 || semVazamento(x, conta) });
  sleep(1 + Math.random() * 2);
}
```

- [ ] **Step 4: `carga/k6/crm-escrita.js`**

Fluxo por iteração (só usuário admin, índice 0): criar negócio → mover para a etapa seguinte → criar atividade → criar proposta e enviar → aceite público.

```js
import { sleep, check } from 'k6';
import http from 'k6/http';
import { contaDoVu, entrar, escrever, ler, limiaresPadrao, erro5xx, API } from './lib/comum.js';

export const options = {
  scenarios: {
    escrita: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '2m', target: 50 }, { duration: '10m', target: 50 }, { duration: '30s', target: 0 }] },
  },
  thresholds: limiaresPadrao({ escrita: 1500, publica: 1500 }),
};

let logado = false;

export default function () {
  const conta = contaDoVu();
  if (!logado) {
    entrar(conta, 0);
    logado = true;
  }
  const marca = `${__VU}-${__ITER}`;
  const passo = (r, nome) => {
    erro5xx.add(r.status >= 500);
    return check(r, { [nome]: (x) => x.status >= 200 && x.status < 300 });
  };

  const criado = escrever('POST', '/api/app/crm/negocios', {
    titulo: `Carga ${marca}`, funil_id: conta.funil_id, etapa_id: conta.etapa_ids[0], cliente_id: conta.cliente_ids[__ITER % 10],
    itens: [{ tipo: 'PRODUTO', item_id: conta.produto_id, quantidade: 1 }],
  }, 'escrita');
  if (!passo(criado, 'criar negócio')) return;
  const id = criado.json('data.id');

  passo(escrever('POST', `/api/app/crm/negocios/${id}/mover`, { etapa_id: conta.etapa_ids[1], posicao: 0 }, 'escrita'), 'mover negócio');
  passo(escrever('POST', '/api/app/crm/atividades', { tipo: 'TAREFA', titulo: `Ligar ${marca}`, agendada_para: new Date(Date.now() + 86400000).toISOString(), negocio_id: id }, 'escrita'), 'criar atividade');

  const proposta = escrever('POST', `/api/app/crm/negocios/${id}/propostas`, { validade_dias: 15 }, 'escrita');
  if (!passo(proposta, 'criar proposta')) return;
  const enviada = escrever('POST', `/api/app/crm/propostas/${proposta.json('data.id')}/enviar`, undefined, 'escrita');
  if (!passo(enviada, 'enviar proposta')) return;

  // O link vem na resposta de `enviar` (ApresentadorDePropostas com link); o token é o último trecho.
  const link = enviada.json('data.link');
  if (!check(enviada, { 'enviar devolve o link': () => typeof link === 'string' && link.includes('/p/') })) return;
  const token = link.split('/p/')[1];
  const aceite = http.post(`${API}/api/public/propostas/${token}/aceitar`, JSON.stringify({ nome: `Cliente ${marca}` }), {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, tags: { grupo: 'publica' },
  });
  erro5xx.add(aceite.status >= 500);
  // 429 é o throttle de 60/min por IP da rota pública: aceitável, não conta como falha.
  check(aceite, { 'aceite público 2xx ou 429': (x) => (x.status >= 200 && x.status < 300) || x.status === 429 });
  sleep(2 + Math.random() * 3);
}
```

Ao rodar a primeira vez na VPS, se `enviar` não devolver `data.link`, o check "enviar devolve o link" acusa: nesse caso trocar por um `GET /api/app/crm/propostas/{id}` e ler o link de lá (o `Apresentador` só traz `link` quando o controller pede; ver `PropostasController::show`). Registrar a decisão no relatório. Como o throttle público é por IP e o k6 sai de um IP só, o aceite vai bater em 429 acima de 60/min: é esperado e medido no cenário `api-publica` (Task 5), então aqui o 429 não derruba a meta.

- [ ] **Step 5: Verificar sintaxe e commit**

Run: `node --check --input-type=module < carga/k6/lib/comum.js` para cada arquivo (ou `for f in carga/k6/*.js carga/k6/lib/*.js; do node --input-type=module --check < "$f" || echo "ERRO $f"; done`). `open`, `__ENV`, `__VU` são globais do k6: `--check` só valida a sintaxe, então não executa nada.
Expected: nenhuma linha `ERRO`.

```bash
git add carga .gitattributes
git commit -m "feat(carga): scripts k6 base, leitura e escrita do CRM"
```

---

### Task 5: k6 de emissão simulada e API pública

**Files:**
- Create: `carga/k6/emissao.js`, `carga/k6/api-publica.js`

**Interfaces:**
- Consumes: `contas`, `API`, `erro5xx` (Task 4); campo `chave_api`, `cliente_ids`, `produto_id` do `contas.json`.

- [ ] **Step 1: `carga/k6/emissao.js`**

Dispara emissões por API (`POST /api/v1/notas`, 202) a 20/s e mede a fila: depois do disparo, consulta o status de uma amostra até `AUTORIZADA`; o backlog é a diferença entre emitidas e autorizadas.

```js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { contas, API, erro5xx } from './lib/comum.js';

const emitidas = new Counter('notas_emitidas');
const autorizadas = new Counter('notas_autorizadas');
const ateAutorizar = new Trend('tempo_ate_autorizar_ms', true);

export const options = {
  scenarios: {
    emissao: { executor: 'constant-arrival-rate', rate: 20, timeUnit: '1s', duration: '10m', preAllocatedVUs: 50, maxVUs: 200 },
  },
  thresholds: {
    erro_5xx: ['rate<0.01'],
    checks: ['rate>0.99'],
    // Fila drena: 95% das notas amostradas autorizam em até 60 s.
    tempo_ate_autorizar_ms: ['p(95)<60000'],
  },
};

export default function () {
  const conta = contas[(__VU + __ITER) % contas.length];
  const cab = { Authorization: `Bearer ${conta.chave_api}`, Accept: 'application/json', 'Content-Type': 'application/json', 'Idempotency-Key': `carga-${__VU}-${__ITER}-${Date.now()}` };
  const r = http.post(`${API}/api/v1/notas`, JSON.stringify({
    modelo: 'NFE', cliente: { id: conta.cliente_ids[__ITER % 10] }, desconto_centavos: 0,
    itens: [{ produto_id: conta.produto_id, quantidade: '1' }], pagamentos: [{ tpag: '01', valor_centavos: 1000 }],
  }), { headers: cab, tags: { grupo: 'emissao' } });
  erro5xx.add(r.status >= 500);
  // 429 = limite de requisições do plano (300/min por chave); ignorado, mas contado nos checks de status esperados.
  if (!check(r, { 'emitir 202 ou 429': (x) => x.status === 202 || x.status === 429 }) || r.status !== 202) return;
  emitidas.add(1);

  // Amostra: 1 em cada 10 notas é acompanhada até autorizar.
  if (__ITER % 10 !== 0) return;
  const id = r.json('data.id');
  const inicio = Date.now();
  for (let t = 0; t < 30; t++) {
    sleep(2);
    const s = http.get(`${API}/api/v1/notas/${id}/status`, { headers: cab, tags: { grupo: 'emissao-status' } });
    erro5xx.add(s.status >= 500);
    if (s.status === 200 && s.json('data.status') === 'AUTORIZADA') {
      autorizadas.add(1);
      ateAutorizar.add(Date.now() - inicio);
      return;
    }
  }
}
```

Atenção ao executor: 20 notas/s sobre 50 empresas dá 0,4 nota/s por empresa, ou 24/min por chave, abaixo do limite de 300 req/min do plano de carga. Se aparecer 429, o plano de carga (Task 3, `ApiRequisicoesMin`) foi alterado: conferir.

- [ ] **Step 2: `carga/k6/api-publica.js`**

Dois grupos: um VU "normal" que fica abaixo do limite, e um "abusivo" que passa de 300/min numa chave só. A meta é só 200 e 429, nunca 5xx.

```js
import http from 'k6/http';
import { check } from 'k6';
import { contas, API, erro5xx } from './lib/comum.js';

export const options = {
  scenarios: {
    normal: { executor: 'constant-vus', vus: 30, duration: '5m', exec: 'normal' },
    abusivo: { executor: 'constant-arrival-rate', rate: 15, timeUnit: '1s', duration: '5m', preAllocatedVUs: 20, exec: 'abusivo' },
  },
  thresholds: {
    erro_5xx: ['rate<0.01'],
    'http_req_duration{grupo:api-normal}': ['p(95)<800'],
    checks: ['rate>0.99'],
  },
};

const cab = (conta) => ({ Authorization: `Bearer ${conta.chave_api}`, Accept: 'application/json' });

export function normal() {
  const conta = contas[(__VU - 1) % contas.length];
  for (const rota of ['/api/v1/empresa', '/api/v1/notas']) {
    const r = http.get(`${API}${rota}`, { headers: cab(conta), tags: { grupo: 'api-normal' } });
    erro5xx.add(r.status >= 500);
    check(r, { 'api normal 200': (x) => x.status === 200 });
  }
  // ~4 req/s por chave = 240/min: abaixo dos 300/min do plano de carga.
  http.get(`${API}/api/v1/empresa`, { headers: cab(conta), tags: { grupo: 'api-normal' } });
}

export function abusivo() {
  // Sempre a mesma chave: 15 req/s = 900/min, muito acima do limite.
  const r = http.get(`${API}/api/v1/empresa`, { headers: cab(contas[0]), tags: { grupo: 'api-abusivo' } });
  erro5xx.add(r.status >= 500);
  check(r, { 'abusivo: só 200 ou 429': (x) => x.status === 200 || x.status === 429 });
  if (r.status === 429) check(r, { '429 traz Retry-After': (x) => x.headers['Retry-After'] !== undefined });
}
```

Se o 429 da API não trouxer `Retry-After` (a exceção `LimiteDaApiExcedidoException` pode usar outro cabeçalho), o check acusa: ver `backend/app/Modules/Identity/Domain/Exceptions/LimiteDaApiExcedidoException.php`, ajustar o check ao cabeçalho real, sem mexer na API.

Os 30 VUs `normal` chamam `/empresa` 3 vezes e `/notas` 1 por iteração sem pausa: dependendo da latência passam do limite. Acrescentar `sleep(1)` ao fim de `normal` (importar `sleep`) e medir; ajustar para ficar abaixo de 300/min por chave e registrar no relatório.

- [ ] **Step 3: Sintaxe e commit**

Run: `for f in carga/k6/*.js; do node --input-type=module --check < "$f" || echo "ERRO $f"; done`
Expected: nenhuma linha `ERRO`.

```bash
git add carga/k6/emissao.js carga/k6/api-publica.js
git commit -m "feat(carga): cenarios k6 de emissao simulada e API publica"
```

---

### Task 6: Estresse e overlay do Compose

**Files:**
- Create: `carga/k6/estresse.js`, `deploy/docker-compose.carga.yml`

- [ ] **Step 1: `carga/k6/estresse.js`**

Reusa o fluxo de leitura com rampa até 300 VUs e aborta no teto de segurança.

```js
import { sleep } from 'k6';
import { contaDoVu, entrar, ler, erro5xx } from './lib/comum.js';

export const options = {
  scenarios: {
    estresse: {
      executor: 'ramping-vus', startVUs: 0,
      stages: [
        { duration: '2m', target: 100 }, { duration: '3m', target: 100 },
        { duration: '2m', target: 200 }, { duration: '3m', target: 200 },
        { duration: '2m', target: 300 }, { duration: '3m', target: 300 },
        { duration: '1m', target: 0 },
      ],
    },
  },
  // Teto de segurança (VPS compartilhada): aborta cedo, sem esperar o fim da rampa.
  thresholds: {
    'http_req_duration{grupo:leitura}': [{ threshold: 'p(95)<5000', abortOnFail: true, delayAbortEval: '30s' }],
    erro_5xx: [{ threshold: 'rate<0.10', abortOnFail: true, delayAbortEval: '30s' }],
  },
};

let logado = false;
const ROTAS = ['/api/app/auth/me', '/api/app/clientes', '/api/app/notas', '/api/app/crm/negocios', '/api/app/crm/resumo'];

export default function () {
  const conta = contaDoVu();
  if (!logado) {
    entrar(conta, __VU % 2);
    logado = true;
  }
  for (const rota of ROTAS) {
    const r = ler(rota, 'leitura');
    erro5xx.add(r.status >= 500);
  }
  sleep(0.5 + Math.random());
}
```

- [ ] **Step 2: `deploy/docker-compose.carga.yml`**

```yaml
# Overlay de TESTE DE CARGA. Só no staging. Uso (dentro de deploy/, na VPS): veja docs/carga/runbook-testes-de-carga.md.
#   docker compose -f docker-compose.yml -f docker-compose.carga.yml up -d app worker
#   docker compose -f docker-compose.yml -f docker-compose.carga.yml run --rm k6 run /scripts/leitura.js
# Para voltar ao normal: docker compose up -d app worker   (sem este arquivo, o emissor simulado desliga).
x-simulado: &simulado
  FISCAL_EMISSOR_SIMULADO: "1"
  FISCAL_EMISSOR_SIMULADO_LATENCIA_MS: ${FISCAL_EMISSOR_SIMULADO_LATENCIA_MS:-150}

services:
  app:
    environment: *simulado
  worker:
    environment: *simulado
  k6:
    image: grafana/k6:0.54.0
    profiles: ["carga"]
    environment:
      API_URL: http://app:8080
      ORIGEM: ${CARGA_ORIGEM:-http://192.168.0.233:3200}
    volumes:
      - ../carga/k6:/scripts:ro
      - ../carga/dados:/dados:ro
      - ../carga/saida:/saida
    # Sem entrypoint próprio: o `run --rm k6 run /scripts/x.js` usa o do container (k6).
```

Se o `docker compose run --rm k6 ...` não subir por causa do `profiles`, trocar para `--profile carga` no comando (o runbook usa o comando com `--profile carga`). Sem Docker aqui, a validação é a leitura do YAML; na VPS o runbook começa por `docker compose -f ... config -q`.

- [ ] **Step 3: Sintaxe e commit**

Run: `node --input-type=module --check < carga/k6/estresse.js && echo OK`
Expected: `OK`.

```bash
git add carga/k6/estresse.js deploy/docker-compose.carga.yml
git commit -m "feat(carga): cenario de estresse e overlay do compose"
```

---

### Task 7: Runbook, modelo do relatório e fechamento

**Files:**
- Create: `docs/carga/runbook-testes-de-carga.md`, `docs/carga/_modelo-de-resultado.md`
- Modify: `PROGRESSO.md`, `TAREFAS.md` (linha da F8d), `deploy/README.md` (uma linha apontando para o runbook)

- [ ] **Step 1: Escrever o runbook**

`docs/carga/runbook-testes-de-carga.md` com estas seções, cada passo com o comando exato (substituir `<HOST>` pelo IP da VPS, `192.168.0.233` no staging):

1. **Antes de começar:** a VPS é compartilhada; rodar fora do horário dos outros projetos; ter um segundo terminal com `docker stats`; confirmar `APP_AMBIENTE=staging` em `deploy/.env` (`grep APP_AMBIENTE .env`); nunca usar em produção.
2. **Atualizar o staging:** reimplantar conforme `deploy/README.md` (`git archive` + `deploy.sh`), garantindo `INSTALL_DEV=1` (a semeadura usa factories e Faker).
3. **Validar o overlay:** `cd ~/crm-plataforma/src/deploy && docker compose -f docker-compose.yml -f docker-compose.carga.yml config -q && echo OK`.
4. **Ligar o emissor simulado:** `docker compose -f docker-compose.yml -f docker-compose.carga.yml up -d app worker`. Provar que ligou: `docker compose exec app php artisan tinker --execute="echo get_class(app(App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe::class));"` deve imprimir `App\Modules\Fiscal\Infrastructure\Simulado\EmissorDeNfeSimulado`.
5. **Semear:** `docker compose exec app php artisan carga:semear --empresas=50`, depois `docker compose exec -T app cat storage/app/carga/contas.json > ../carga/contas.json && mv ../carga/contas.json ../carga/dados/contas.json` (o arquivo tem chaves de API de empresas fictícias; não commitar).
6. **Rodar cada cenário** (gravando saída): `mkdir -p ../carga/saida && docker compose -f docker-compose.yml -f docker-compose.carga.yml --profile carga run --rm k6 run --summary-export=/saida/<cenario>.json /scripts/<cenario>.js`, na ordem: `leitura.js`, `crm-escrita.js`, `emissao.js`, `api-publica.js`. Um por vez; esperar a fila esvaziar (`docker compose exec app php artisan queue:monitor default`) entre eles.
7. **Estresse (opcional, depois dos 4 passarem):** `run --summary-export=/saida/estresse.json /scripts/estresse.js`. Vigiar `docker stats`; cancelar com Ctrl+C se a VPS passar do limite que você definir (o script aborta sozinho com p95 > 5 s ou erros > 10%).
8. **Limpar e desligar:** `docker compose exec app php artisan carga:limpar`; `rm ../carga/dados/contas.json`; `docker compose up -d app worker` (sem o overlay: emissor simulado desliga); repetir o `tinker` do passo 4 e ver `NfePhpEmissor`.
9. **Como ler o resultado:** tabela "o que cada threshold em vermelho significa e onde olhar primeiro" (leitura lenta → consultas/N+1: `APP_DEBUG` desligado, ligar log de query lenta; escrita lenta → locks do funil; fila não drena → `--max-time`/número de workers: escalar com `docker compose up -d --scale worker=3`; 5xx → `docker compose logs app --tail=200`; 429 inesperado → limite do plano de carga).
10. **Registrar:** copiar `docs/carga/_modelo-de-resultado.md` para `docs/carga/AAAA-MM-DD-resultado.md`, preencher e me mandar os JSONs de `carga/saida/` para eu analisar os gargalos.

- [ ] **Step 2: Modelo de resultado**

`docs/carga/_modelo-de-resultado.md`: cabeçalho (data/hora, commit implantado, carga dos outros projetos na VPS no momento, versão do k6), tabela cenário × meta × medido × passou, seção "Estresse: ponto de saturação e primeiro recurso saturado", seção "Gargalos e correções" (cada linha com causa, correção e se entrou na F8d ou virou tarefa), seção "O que não foi medido".

- [ ] **Step 3: Atualizar docs do projeto**

- `PROGRESSO.md`: nova seção "Fechamento da F8d" (padrão das anteriores): o que foi entregue, decisões não óbvias (guarda `APP_AMBIENTE` porque o staging roda `APP_ENV=production`; emissor simulado só NF-e/NFC-e; plano de carga com 300 req/min; throttle público de 60/min por IP limita o aceite no k6), **não verificado** (nenhum script k6 foi executado: sem Docker aqui; execução e relatório são do usuário), e a próxima tarefa. Trocar o parágrafo "Tarefa em andamento" para apontar a execução do runbook pelo usuário.
- `TAREFAS.md`: marcar `[x] F8d` com a mesma ressalva (entregue o kit; medição pendente do usuário).
- `deploy/README.md`: uma linha: "Teste de carga: `docs/carga/runbook-testes-de-carga.md`".

- [ ] **Step 4: Verificação final e commit**

Run: `cd backend && php artisan test 2>&1 | tail -5 && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G | tail -3`
Expected: suíte toda verde (1524 + os novos), Pint e PHPStan limpos.

```bash
git add docs/carga PROGRESSO.md TAREFAS.md deploy/README.md
git commit -m "docs: runbook de carga e fechamento da F8d"
```

---

## Self-Review

- **Spec coverage:** estrutura (`carga/k6`, overlay, runbook, resultado): Tasks 4 a 7. Dados de carga: Task 3. Emissor de carga + guarda: Tasks 1 e 2 (com correção do guarda). Metas: limiares nas Tasks 4 e 5. Estresse com teto: Task 6. Correções: o runbook manda o usuário devolver os JSONs; correções entram em trabalho posterior (a spec prevê). Fora do escopo respeitado. Testes backend: Tasks 1 a 3.
- **Placeholders:** os dois "Observação ao executor" (limiar 5xx do k6 e `Retry-After`) trazem a ação exata; o ajuste do teste de colisão de chave (Task 2, Step 1) manda trocar por chamada direta com instrução concreta.
- **Tipos:** `AmbienteDeCarga::exigir` (Task 1) usada nas Tasks 2 e 3; `contas.json` (Task 3) lido por `comum.js` (Task 4) e pelos cenários (Tasks 4 a 6); `erro5xx` exportado por `comum.js` e usado em todos os cenários.
- **Ressalva de verificação:** nenhum k6 roda aqui; só `node --check` valida sintaxe. A confiança nos scripts vem da primeira execução do usuário na VPS.
