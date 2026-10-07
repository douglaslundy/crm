# F4a: NFS-e Nacional — Plano de Implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use `superpowers:subagent-driven-development` (recomendado) ou `superpowers:executing-plans` para executar este plano tarefa a tarefa. Os passos usam caixas `- [ ]`.

**Objetivo:** emitir, consultar, cancelar e imprimir (DANFSe) NFS-e Nacional pelo mesmo fluxo de notas da NF-e, para prestadores do Simples Nacional/MEI.

**Arquitetura:** a NFS-e é o modelo `DPS` da tabela `notas`. Um pipeline paralelo e enxuto (interface `EmissorDeNfse`, `NfseNacionalEmissor` sobre `nfse-nacional/nfse-php`, `EmitirNfseJob`, `AplicarResultadoDaNfse`, `ConciliarNfse`) reaproveita rascunho, `NotaEvento`, cancelamento (`CANCELANDO`), políticas, listagem e telas. A idempotência vem da própria ADN: antes de enviar, o job consulta a DPS (`verificarDps`/`consultarDps`); incerteza vira ERRO e nunca reenvia.

**Tech Stack:** Laravel 12 / PHP 8.2, `nfse-nacional/nfse-php` ^1.21@beta, `barryvdh/laravel-dompdf` ^3.1, `endroid/qr-code` ^6, PHPUnit, Next.js + React + Vitest.

**Spec:** `docs/superpowers/specs/2026-10-07-f4a-nfse-nacional-design.md`. Referências: `docs/referencia-fiscal/02-nfephp-nfse.md`; skill `br-fiscal-note-emission` (`assets/pdf-layouts/danfse`); lib de referência (somente leitura) em `C:\Users\dougl\workspace\mecanicapro\backend\vendor\nfse-nacional\nfse-php` e `app/Services/Fiscal/NfePhp/MotorNfse.php`.

## Global Constraints

- Nunca chutar valor fiscal: dado ausente bloqueia com mensagem acionável; sem default; zero é valor válido (`=== null`, nunca `empty()` nem `?? 0`).
- Regra tributária fica fora do motor; o motor só traduz formato. Frontend e API pública chamam as mesmas Actions.
- Todo Model de tenant tem Global Scope; todo endpoint novo ganha teste de isolamento (nesta fatia não há rota nova; os testes de isolamento cobrem as rotas existentes com nota `DPS`).
- `declare(strict_types=1)` em todo PHP. TypeScript strict, sem `any`. Mudança de schema só por migration. Nenhum dado real em fixtures.
- **DPS (conforme `02-nfephp-nfse.md`):** `prest.IM` e `prest.end` NUNCA enviados (E0120/E0128); `tpRetISSQN=1`; `pAliq` omitido no Simples não retido (E0625); `totTrib` obrigatório com `pTotTribSN=0.001` (a lib formata `0.00`; E0712 e bug do `0.0`); `cTribNac` com 6 dígitos (E1235); `cTribMun` só com exatamente 3 dígitos; `dhEmi` ISO8601 com fuso; `infDPS@Id` por `IdGenerator::generateDpsId`.
- **Chave da NFS-e:** 50 dígitos, gravada **sem** o prefixo `NFS` (pitfall #21); consulta sempre com os 50 dígitos.
- **Escalas diferentes:** `opSimpNac` da NFS-e (1 não optante, 2 MEI, 3 ME/EPP) não é o CRT da NF-e; tradução explícita (`SIMPLES`→3, `MEI`→2; `NORMAL` e nulo bloqueiam).
- Limites: `xDescServ` 2000 caracteres; `xInfComp` 255 (limite do DTO da lib); justificativa de cancelamento de 15 a 255.
- Comandos (de `backend/` e `frontend/`; confira `composer.json`/`package.json` se algum nome divergir): `php artisan test --filter=<Nome>`, `vendor/bin/pint`, `vendor/bin/phpstan analyse --memory-limit=1G`; `npx vitest run <arquivo>`, `npx tsc --noEmit`, `npm run lint`, `npm run build`.
- Commits em pt-BR terminando com as duas linhas de atribuição (use `-m` duas vezes, como nos passos):
  ```
  Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs
  ```
- `../mecanicapro` é só leitura. Push só com confirmação do usuário. **Nunca** escrever arquivos em PowerShell com here-string de aspas duplas (o acento grave é escape e corrompe o texto): usar as ferramentas de edição ou `@'...'@`.

## Rulings que o plano já traz (divergem do spec; a Tarefa 13 corrige o spec)

1. **Inscrição municipal não é exigida do emitente.** O spec §4 listava IM, mas a DPS nunca a envia (E0120); exigi-la só criaria atrito sem efeito fiscal.
2. **Sem validação da DPS contra o XSD.** A sonda mostrou que `DPS_v1.01.xsd` não compila no libxml (regex com lookahead `(?!0{1,5}$)`), e o `DpsValidator` da lib exige endereço do tomador, que a ADN aceita sem. Os testes conferem a estrutura do XML gerado (presença e ausência dos elementos, ordem do desconto).
3. **Desconto confirmado:** a lib expõe `valores.vDescCondIncond.vDescIncond`, entre `vServPrest` e `trib`; a NFS-e aceita desconto incondicional (spec §4 estava condicional).
4. **Certificado:** `NfseContext(certificateContent: <bytes do PFX>)`; a lib grava o temporário e o apaga no destrutor. Sem arquivo próprio.
5. **Segurança (pendência para o go-live, F8):** os clientes HTTP da lib fixam `CURLOPT_SSL_VERIFYPEER=0` e `VERIFYHOST=0` (verificação TLS desligada), sem opção de configurar. Registrar em `TAREFAS.md`.

## Review Focus

1. **Idempotência da emissão:** DPS já existente na ADN (resposta perdida) nunca vira segunda nota; consulta da DPS com falha vira ERRO e **não reenvia**. Tarefa 8.
2. **`consultar` nulo:** a lib devolve `null` para "não encontrada" e para falha; nunca interpretar como "não existe". Tarefa 7.
3. **Serviços divergentes e limites:** itens com `c_trib_nac` ou alíquota diferentes bloqueiam; alíquota 0 é válida; descrição composta > 2000, informações > 255 e desconto ≥ subtotal bloqueiam. Tarefa 4.
4. **Cancelamento:** resposta sem `eventoXmlGZipB64` não confirma; recusa da ADN volta a AUTORIZADA; falha de rede mantém CANCELANDO. Tarefas 7 e 9.
5. **Chave de 50 dígitos sem `NFS`** em toda a cadeia (gravação, consulta, QR do DANFSe). Tarefas 7 e 10.
6. **Fronteiras do modelo:** nota `DPS` nunca entra em inutilização, contingência, retransmissão nem aceita produto/pagamento; NF-e/NFC-e nunca aceitam serviço; módulo `FISCAL_NFSE` desligado bloqueia rascunho, emissão e cancelamento; troca de modelo no rascunho é recusada. Tarefas 3, 4, 9 e 11.

---

### Task 1: Dependências e contrato da biblioteca

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock`
- Create: `backend/tests/Unit/Fiscal/Nfse/BibliotecaNfseTest.php`

**Interfaces:**
- Produces: pacotes `nfse-nacional/nfse-php` (namespace `Nfse\`), `barryvdh/laravel-dompdf` (`Barryvdh\DomPDF\Facade\Pdf`) e `endroid/qr-code` (`Endroid\QrCode\Builder\Builder`) instalados; um teste de contrato que fixa o comportamento da lib de que o plano depende.

- [ ] **Step 1: Escrever o teste de contrato (falha porque os pacotes não existem)**

`backend/tests/Unit/Fiscal/Nfse/BibliotecaNfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use Nfse\Dto\Nfse\DpsData;
use Nfse\Support\IdGenerator;
use Nfse\Xml\DpsXmlBuilder;
use Tests\TestCase;

/** Fixa o que o plano usa da nfse-php: se uma atualização da lib mudar isto, o teste acusa antes da ADN. */
class BibliotecaNfseTest extends TestCase
{
    private function dps(array $valores): DpsData
    {
        return new DpsData([
            '@attributes' => ['versao' => '1.01'],
            'infDPS' => [
                '@attributes' => ['Id' => IdGenerator::generateDpsId('11444777000161', '3550308', '1', '7')],
                'tpAmb' => 2, 'dhEmi' => '2026-10-07T10:00:00-03:00', 'verAplic' => 'plataforma-1.0', 'serie' => '1', 'nDPS' => '7',
                'dCompet' => '2026-10-07', 'tpEmit' => 1, 'cLocEmi' => '3550308',
                'prest' => ['CNPJ' => '11444777000161', 'regTrib' => ['opSimpNac' => 3, 'regApTribSN' => 1, 'regEspTrib' => 0]],
                'toma' => ['CPF' => '52998224725', 'xNome' => 'Maria Teste'],
                'serv' => ['locPrest' => ['cLocPrestacao' => '3550308'], 'cServ' => ['cTribNac' => '140101', 'xDescServ' => 'Troca de oleo']],
                'valores' => $valores,
            ],
        ]);
    }

    public function test_o_id_da_dps_tem_45_caracteres_no_formato_esperado(): void
    {
        $id = IdGenerator::generateDpsId('11444777000161', '3550308', '1', '7');

        $this->assertSame('DPS3550308211444777000161'.'00001'.'000000000000007', $id);
        $this->assertSame(45, strlen($id));
    }

    public function test_o_xml_da_dps_leva_o_desconto_entre_o_valor_e_a_tributacao(): void
    {
        $xml = (new DpsXmlBuilder)->build($this->dps([
            'vServPrest' => ['vServ' => 150.0],
            'vDescCondIncond' => ['vDescIncond' => 10.0],
            'trib' => ['tribMun' => ['tribISSQN' => 1, 'tpRetISSQN' => 1], 'totTrib' => ['pTotTribSN' => 0.001]],
        ]));

        $this->assertStringContainsString('<vServ>150.00</vServ>', $xml);
        $this->assertStringContainsString('<vDescIncond>10.00</vDescIncond>', $xml);
        $this->assertStringContainsString('<pTotTribSN>0.00</pTotTribSN>', $xml);
        $this->assertStringNotContainsString('<pAliq>', $xml);
        $this->assertLessThan(strpos($xml, '<vDescCondIncond>'), strpos($xml, '<vServPrest>'));
        $this->assertLessThan(strpos($xml, '<trib>'), strpos($xml, '<vDescCondIncond>'));
    }

    public function test_dompdf_e_qr_code_estao_disponiveis(): void
    {
        $this->assertTrue(class_exists(\Barryvdh\DomPDF\Facade\Pdf::class));
        $this->assertTrue(class_exists(\Endroid\QrCode\Builder\Builder::class));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run (em `backend/`): `php artisan test --filter=BibliotecaNfseTest`
Expected: FAIL (`Class "Nfse\Support\IdGenerator" not found`).

- [ ] **Step 3: Instalar os pacotes**

Run (em `backend/`): `composer require "nfse-nacional/nfse-php:^1.21@beta" "barryvdh/laravel-dompdf:^3.1" "endroid/qr-code:^6.0"`
Expected: os três instalados sem remover pacotes existentes. Se o Composer recusar por estabilidade ou por versão do PHP, registrar o erro e usar a menor versão que resolve; a `nfse-php` 1.21.0-beta é a que o Mecânica Pro usa em produção.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=BibliotecaNfseTest` e depois `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; PHPStan limpo.

- [ ] **Step 5: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/tests/Unit/Fiscal/Nfse/BibliotecaNfseTest.php
git commit -m "chore(nfse): dependências da NFS-e e teste de contrato da biblioteca" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 2: Dados da NFS-e (migration, models e resource)

**Files:**
- Create: `backend/database/migrations/2026_10_07_000001_add_nfse_as_notas.php`
- Modify: `backend/app/Modules/Fiscal/Domain/Models/Nota.php`, `backend/app/Modules/Fiscal/Domain/Models/NotaItem.php`
- Modify: `backend/app/Modules/Fiscal/Http/Resources/NotaResource.php`
- Test: `backend/tests/Feature/Fiscal/NfseDadosTest.php`

**Interfaces:**
- Produces: `notas.chave` com 50 caracteres; `notas.numero_nfse` (string nulável); `nota_itens.produto_id` nulável e `servico_id`, `c_trib_nac`, `codigo_municipal`, `aliquota_iss`, `nbs` (nuláveis); `Nota::$numero_nfse`; `NotaItem::$servico_id`, `$c_trib_nac`, `$codigo_municipal`, `$aliquota_iss` (cast `decimal:2`), `$nbs` (todos em `$fillable`); `NotaResource` com `numero_nfse` e, em cada item, `servico_id`, `c_trib_nac`, `codigo_municipal`, `aliquota_iss`, `nbs`.

- [ ] **Step 1: Escrever o teste que falha**

`backend/tests/Feature/Fiscal/NfseDadosTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class NfseDadosTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private function notaDps(): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id]);
        $nota->forceFill(['modelo' => ModeloDocumento::Dps])->save();

        return $nota->refresh();
    }

    public function test_a_chave_da_nfse_com_50_digitos_e_o_numero_oficial_sao_gravados(): void
    {
        $this->montarCenario(Modulo::FiscalNfse);
        $chave = '3550308211444777000161'.str_repeat('1', 28);
        $nota = $this->notaDps();

        $nota->forceFill(['chave' => $chave, 'numero_nfse' => '123'])->save();

        $nota = $nota->refresh();
        $this->assertSame(50, strlen((string) $nota->chave));
        $this->assertSame($chave, $nota->chave);
        $this->assertSame('123', $nota->numero_nfse);
    }

    public function test_item_de_servico_nao_tem_produto_e_guarda_o_snapshot_fiscal(): void
    {
        $this->montarCenario(Modulo::FiscalNfse);
        $servico = Servico::factory()->completo()->create(['nome' => 'Troca de óleo', 'preco_centavos' => 15000, 'codigo_municipal' => '123', 'nbs' => '115090000']);
        $nota = $this->notaDps();

        $item = $nota->itens()->create([
            'servico_id' => $servico->id, 'produto_id' => null, 'ordem' => 1, 'quantidade_milesimos' => 1000,
            'valor_unitario_centavos' => 15000, 'total_centavos' => 15000, 'descricao' => 'Troca de óleo',
            'c_trib_nac' => '140101', 'codigo_municipal' => '123', 'aliquota_iss' => '5.00', 'nbs' => '115090000',
        ]);

        $item = $item->refresh();
        $this->assertNull($item->produto_id);
        $this->assertSame($servico->id, $item->servico_id);
        $this->assertSame('140101', $item->c_trib_nac);
        $this->assertSame('5.00', $item->aliquota_iss);
    }

    public function test_o_resource_expoe_o_numero_da_nfse_e_os_campos_do_servico(): void
    {
        $this->montarCenario(Modulo::FiscalNfse);
        $servico = Servico::factory()->completo()->create();
        $nota = $this->notaDps();
        $nota->forceFill(['numero_nfse' => '77'])->save();
        $nota->itens()->create([
            'servico_id' => $servico->id, 'produto_id' => null, 'ordem' => 1, 'quantidade_milesimos' => 1000,
            'valor_unitario_centavos' => 1000, 'total_centavos' => 1000, 'c_trib_nac' => '140101', 'aliquota_iss' => '5.00',
        ]);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$nota->id}")
            ->assertOk()
            ->assertJsonPath('data.modelo', 'DPS')
            ->assertJsonPath('data.numero_nfse', '77')
            ->assertJsonPath('data.itens.0.produto_id', null)
            ->assertJsonPath('data.itens.0.servico_id', $servico->id)
            ->assertJsonPath('data.itens.0.c_trib_nac', '140101')
            ->assertJsonPath('data.itens.0.aliquota_iss', '5.00');
    }

    public function test_nota_de_produtos_continua_igual(): void
    {
        $this->montarCenario(Modulo::FiscalNfe);
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id]);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$nota->id}")
            ->assertOk()->assertJsonPath('data.numero_nfse', null);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=NfseDadosTest`
Expected: FAIL (coluna `numero_nfse` inexistente).

- [ ] **Step 3: Implementar**

`backend/database/migrations/2026_10_07_000001_add_nfse_as_notas.php`:

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
            // A chave da NFS-e Nacional tem 50 dígitos (a da NF-e, 44). `numero` continua sendo o nDPS.
            $table->string('chave', 50)->nullable()->change();
            $table->string('numero_nfse', 20)->nullable();
        });
        Schema::table('nota_itens', function (Blueprint $table): void {
            $table->uuid('produto_id')->nullable()->change();
            $table->uuid('servico_id')->nullable();
            $table->string('c_trib_nac', 6)->nullable();
            $table->string('codigo_municipal', 3)->nullable();
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->string('nbs', 9)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nota_itens', function (Blueprint $table): void {
            $table->dropColumn(['servico_id', 'c_trib_nac', 'codigo_municipal', 'aliquota_iss', 'nbs']);
        });
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropColumn('numero_nfse');
        });
    }
};
```

`Nota.php`: no docblock, depois de `@property ?string $chave`, acrescentar `@property ?string $numero_nfse`.

`NotaItem.php`: no docblock, trocar `@property string $produto_id` por `@property ?string $produto_id` e acrescentar `@property ?string $servico_id`, `@property ?string $c_trib_nac`, `@property ?string $codigo_municipal`, `@property ?string $aliquota_iss`, `@property ?string $nbs`; em `$fillable`, acrescentar `'servico_id', 'c_trib_nac', 'codigo_municipal', 'aliquota_iss', 'nbs'`; em `casts()`, trocar por `return ['origem' => 'integer', 'aliquota_iss' => 'decimal:2'];`.

`NotaResource.php`: depois de `'protocolo' => $this->protocolo,` acrescentar `'numero_nfse' => $this->numero_nfse,`; no mapa de itens, depois de `'cfop' => $i->cfop, 'csosn' => $i->csosn,` acrescentar:

```php
                'servico_id' => $i->servico_id, 'c_trib_nac' => $i->c_trib_nac, 'codigo_municipal' => $i->codigo_municipal,
                'aliquota_iss' => $i->aliquota_iss, 'nbs' => $i->nbs,
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter="NfseDadosTest|NotasApiTest|NotaIsolamentoTest"` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; PHPStan limpo. Se o `->change()` do SQLite perder o índice `chave`, recriá-lo na migration (`$table->index('chave')`) e anotar no ledger.

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_10_07_000001_add_nfse_as_notas.php backend/app/Modules/Fiscal/Domain/Models/Nota.php backend/app/Modules/Fiscal/Domain/Models/NotaItem.php backend/app/Modules/Fiscal/Http/Resources/NotaResource.php backend/tests/Feature/Fiscal/NfseDadosTest.php
git commit -m "feat(nfse): dados da NFS-e (chave de 50 dígitos, número oficial e item de serviço)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 3: Rascunho de NFS-e

**Files:**
- Create: `backend/tests/Fixtures/CenarioDeNfse.php`
- Modify: `backend/app/Modules/Fiscal/Http/Requests/SalvarNotaRequest.php`
- Modify: `backend/app/Modules/Fiscal/Application/Actions/CriarRascunhoDeNota.php`, `backend/app/Modules/Fiscal/Application/Actions/AtualizarRascunhoDeNota.php`
- Modify: `backend/app/Modules/Fiscal/Http/Controllers/NotaController.php` (filtro `modelo` do `index`)
- Test: `backend/tests/Feature/Fiscal/RascunhoDeNfseTest.php`

**Interfaces:**
- Consumes: colunas da Tarefa 2; `Servico` (`c_trib_nac`, `codigo_municipal`, `aliquota_iss`, `nbs`, `preco_centavos`).
- Produces: trait de teste `CenarioDeNfse` (`montarCenarioNfse(Modulo ...$extras): void`, `corpoNfse(array $sobrescrever = []): array`, `rascunhoDeNfse(array $sobrescrever = []): Nota`, propriedade `$servico`); rascunho `DPS` pela API (`modelo=DPS`, `itens.*.servico_id`, sem `pagamentos`); recusa de troca de modelo no `PUT`; filtro `?modelo=DPS`.

- [ ] **Step 1: Criar a fixture de cenário**

`backend/tests/Fixtures/CenarioDeNfse.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;

/** Cenário da NF-e mais o módulo NFS-e, a série DPS e um serviço completo (Simples, SP, tomador PF). */
trait CenarioDeNfse
{
    use CenarioDeNota;

    protected Servico $servico;

    protected function montarCenarioNfse(Modulo ...$extras): void
    {
        $this->montarCenario(Modulo::FiscalNfse, ...$extras);
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Dps, 'serie' => '1', 'proximo_numero' => 1]);
        $this->servico = Servico::factory()->completo()->create(['nome' => 'Troca de óleo', 'preco_centavos' => 15000]);
    }

    /**
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    protected function corpoNfse(array $sobrescrever = []): array
    {
        return array_replace([
            'modelo' => 'DPS', 'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0,
            'informacoes_complementares' => null,
            'itens' => [['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
        ], $sobrescrever);
    }

    /** @param  array<string, mixed>  $sobrescrever */
    protected function rascunhoDeNfse(array $sobrescrever = []): Nota
    {
        $id = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse($sobrescrever))->assertCreated()->json('data.id');

        return Nota::query()->findOrFail($id);
    }
}
```

- [ ] **Step 2: Escrever o teste que falha**

`backend/tests/Feature/Fiscal/RascunhoDeNfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class RascunhoDeNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    public function test_cria_rascunho_de_nfse_com_snapshot_do_servico_e_sem_pagamentos(): void
    {
        $this->montarCenarioNfse();

        $r = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse())->assertCreated();

        $r->assertJsonPath('data.modelo', 'DPS')
            ->assertJsonPath('data.status', 'RASCUNHO')
            ->assertJsonPath('data.total_centavos', 15000)
            ->assertJsonPath('data.itens.0.produto_id', null)
            ->assertJsonPath('data.itens.0.servico_id', $this->servico->id)
            ->assertJsonPath('data.itens.0.c_trib_nac', '140101')
            ->assertJsonPath('data.itens.0.aliquota_iss', '5.00');
        $this->assertSame([], $r->json('data.pagamentos'));
    }

    public function test_varios_itens_somam_o_subtotal_e_o_desconto_entra_no_total(): void
    {
        $this->montarCenarioNfse();
        $outro = Servico::factory()->completo()->create(['nome' => 'Alinhamento', 'preco_centavos' => 8000]);

        $r = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse([
            'desconto_centavos' => 1000,
            'itens' => [
                ['servico_id' => $this->servico->id, 'quantidade' => '2', 'valor_unitario_centavos' => null],
                ['servico_id' => $outro->id, 'quantidade' => '1', 'valor_unitario_centavos' => 9000],
            ],
        ]))->assertCreated();

        $r->assertJsonPath('data.subtotal_centavos', 39000)->assertJsonPath('data.total_centavos', 38000);
    }

    public function test_tenant_sem_modulo_nfse_nao_cria_rascunho(): void
    {
        $this->montarCenarioNfse();
        $this->tenant->update(['plano_id' => Plano::factory()->comModulos(\App\Modules\Platform\Domain\Enums\Modulo::FiscalNfe)->create()->id]);

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse())
            ->assertStatus(403)->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_nota_de_servico_recusa_produto_pagamento_e_servico_de_outro_tenant(): void
    {
        $this->montarCenarioNfse();
        $produto = \App\Modules\Catalog\Domain\Models\Produto::factory()->completo()->create();
        $servicoDeOutro = $this->servicoDeOutroTenant();

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse(['itens' => [['produto_id' => $produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['itens.0.produto_id', 'itens.0.servico_id']);
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse(['pagamentos' => [['tpag' => '01', 'valor_centavos' => 15000]]]))
            ->assertStatus(422)->assertJsonValidationErrors('pagamentos');
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse(['itens' => [['servico_id' => $servicoDeOutro, 'quantidade' => '1', 'valor_unitario_centavos' => null]]]))
            ->assertStatus(422)->assertJsonValidationErrors('itens.0.servico_id');
    }

    public function test_nfe_recusa_item_de_servico(): void
    {
        $this->montarCenarioNfse(\App\Modules\Platform\Domain\Enums\Modulo::FiscalNfe);

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse(['modelo' => 'NFE', 'pagamentos' => [['tpag' => '01', 'valor_centavos' => 15000]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['itens.0.produto_id', 'itens.0.servico_id']);
    }

    public function test_edita_o_rascunho_de_nfse_e_recusa_trocar_o_modelo(): void
    {
        $this->montarCenarioNfse(\App\Modules\Platform\Domain\Enums\Modulo::FiscalNfe);
        $nota = $this->rascunhoDeNfse();

        $this->spaComo($this->admin)->putJson("/api/app/notas/{$nota->id}", $this->corpoNfse(['desconto_centavos' => 500]))
            ->assertOk()->assertJsonPath('data.total_centavos', 14500);

        $this->spaComo($this->admin)->putJson("/api/app/notas/{$nota->id}", $this->corpoNfse(['modelo' => 'NFE']))
            ->assertStatus(422);
        $this->assertSame(ModeloDocumento::Dps, $nota->refresh()->modelo);
    }

    public function test_lista_filtra_por_modelo_dps(): void
    {
        $this->montarCenarioNfse();
        $this->rascunhoDeNfse();

        $this->spaComo($this->admin)->getJson('/api/app/notas?modelo=DPS')->assertOk()->assertJsonCount(1, 'data');
        $this->spaComo($this->admin)->getJson('/api/app/notas?modelo=NFE')->assertOk()->assertJsonCount(0, 'data');
    }

    private function servicoDeOutroTenant(): string
    {
        $anterior = $this->tenant->id;
        $outro = Tenant::factory()->for(Plano::factory())->create();
        app(TenantContext::class)->set($outro->id);
        $id = Servico::factory()->completo()->create()->id;
        app(TenantContext::class)->set($anterior);

        return $id;
    }
}
```

> Se `Plano::factory()->comModulos(...)` e `tenant->update(['plano_id' => ...])` não refletirem o nome real da coluna do plano no `Tenant`, abrir `backend/database/factories/TenantFactory.php` e ajustar a troca de plano do teste de módulo desligado (o padrão está em `NotaNfceApiTest::test_tenant_sem_modulo_nfce_nao_cria_rascunho_de_nfce`).

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --filter=RascunhoDeNfseTest`
Expected: FAIL (`modelo` DPS recusado; regras de `produto_id` e `pagamentos` ainda são de NF-e).

- [ ] **Step 4: Implementar**

`SalvarNotaRequest.php` (substituir o método `rules`):

```php
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $modelo = (string) ($this->input('modelo') ?? 'NFE');
        $servico = $modelo === 'DPS';

        return [
            'modelo' => ['nullable', Rule::in(['NFE', 'NFCE', 'DPS'])],
            // NFC-e vende sem identificar o consumidor; NF-e e NFS-e sempre têm cliente (destinatário ou tomador).
            'cliente_id' => [
                Rule::requiredIf(fn (): bool => $modelo !== 'NFCE'),
                'nullable', 'uuid', Rule::exists('clientes', 'id')->where('tenant_id', $tenantId),
            ],
            'consumidor_final' => ['nullable', 'boolean'],
            'desconto_centavos' => ['required', 'integer', 'min:0'],
            'informacoes_complementares' => ['nullable', 'string', 'max:5000'],
            'itens' => ['required', 'array', 'min:1', 'max:990'],
            // Nota de serviço só tem servico_id; nota de produtos só tem produto_id.
            'itens.*.produto_id' => [$servico ? 'prohibited' : 'required', 'uuid', Rule::exists('produtos', 'id')->where('tenant_id', $tenantId)],
            'itens.*.servico_id' => [$servico ? 'required' : 'prohibited', 'uuid', Rule::exists('servicos', 'id')->where('tenant_id', $tenantId)],
            'itens.*.quantidade' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'itens.*.valor_unitario_centavos' => ['nullable', 'integer', 'min:0'],
            'pagamentos' => [$servico ? 'prohibited' : 'required', 'array', 'min:1'],
            'pagamentos.*.tpag' => ['required', Rule::in(['01', '03', '04', '17', '99'])],
            'pagamentos.*.xpag' => ['nullable', 'string', 'max:60'],
            'pagamentos.*.valor_centavos' => ['required', 'integer', 'min:1'],
        ];
    }
```

`CriarRascunhoDeNota.php`: adicionar `use App\Modules\Catalog\Domain\Models\Servico;`; em `executar`, substituir o bloco do módulo por:

```php
        if ($modelo === ModeloDocumento::Nfce) {
            $this->planos->garantirModulo($autor->tenant, Modulo::FiscalNfce);
        }
        if ($modelo === ModeloDocumento::Dps) {
            $this->planos->garantirModulo($autor->tenant, Modulo::FiscalNfse);
        }
```

e substituir o método `preencher` por:

```php
    public function preencher(Nota $nota, array $dados): void
    {
        $servico = $nota->modelo === ModeloDocumento::Dps;
        $itens = [];
        $subtotal = 0;
        foreach (array_values($dados['itens']) as $i => $linha) {
            $milesimos = Quantidade::paraMilesimos((string) $linha['quantidade']);
            if ($servico) {
                $origem = Servico::query()->findOrFail($linha['servico_id']);
                $unitario = $linha['valor_unitario_centavos'] ?? $origem->preco_centavos;
                // Snapshot do cadastro: a nota não muda se o serviço for editado depois.
                $extra = [
                    'servico_id' => $origem->id, 'produto_id' => null, 'c_trib_nac' => $origem->c_trib_nac,
                    'codigo_municipal' => $origem->codigo_municipal, 'aliquota_iss' => $origem->aliquota_iss, 'nbs' => $origem->nbs,
                ];
            } else {
                $origem = Produto::query()->findOrFail($linha['produto_id']);
                $unitario = $linha['valor_unitario_centavos'] ?? $origem->preco_centavos;
                $extra = ['produto_id' => $origem->id];
            }
            $total = RateioDeDesconto::totalDoItem($milesimos, $unitario);
            $subtotal += $total;
            $itens[] = $extra + [
                'ordem' => $i + 1, 'quantidade_milesimos' => $milesimos, 'valor_unitario_centavos' => $unitario, 'total_centavos' => $total,
            ];
        }

        $desconto = (int) $dados['desconto_centavos'];
        $nota->fill([
            'cliente_id' => $dados['cliente_id'] ?? null,
            'consumidor_final' => $dados['consumidor_final'] ?? null,
            'desconto_centavos' => $desconto,
            'informacoes_complementares' => $dados['informacoes_complementares'] ?? null,
            'subtotal_centavos' => $subtotal,
            'total_centavos' => $subtotal - $desconto,
        ])->save();

        $nota->itens()->delete();
        $nota->itens()->createMany($itens);
        $nota->pagamentos()->delete();
        if (! $servico) {
            $nota->pagamentos()->createMany(array_map(fn (array $p): array => [
                'tpag' => $p['tpag'], 'xpag' => $p['xpag'] ?? null, 'valor_centavos' => (int) $p['valor_centavos'],
            ], array_values($dados['pagamentos'])));
        }
    }
```

`AtualizarRascunhoDeNota.php`: adicionar `use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;` e, dentro da transação, depois do bloco `if (! $nota->status->editavel()) { ... }`:

```php
            if (($dados['modelo'] ?? $nota->modelo->value) !== $nota->modelo->value) {
                throw new EmissaoBloqueadaException('FISCAL_MODELO_IMUTAVEL', 'Não é possível trocar o modelo de um rascunho. Crie uma nova nota.');
            }
```

`NotaController::index`: trocar `Rule::in(['NFE', 'NFCE'])` por `Rule::in(['NFE', 'NFCE', 'DPS'])`.

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter="RascunhoDeNfseTest|NotaNfceApiTest|NotasApiTest|RascunhoDeNotaTest"` e `vendor/bin/pint` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; Pint e PHPStan limpos.

- [ ] **Step 6: Commit**

```bash
git add backend/tests/Fixtures/CenarioDeNfse.php backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/RascunhoDeNfseTest.php
git commit -m "feat(nfse): rascunho de NFS-e com itens de serviço" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 4: Bloqueios e início da emissão da NFS-e

**Files:**
- Create: `backend/app/Modules/Fiscal/Application/Emissao/PreparadorDaNotaDeServico.php`
- Modify: `backend/app/Modules/Fiscal/Application/Emissao/VerificadorDoEmitente.php`
- Modify: `backend/app/Modules/Fiscal/Application/Actions/IniciarEmissao.php`
- Test: `backend/tests/Feature/Fiscal/PreparadorDaNotaDeServicoTest.php`, `backend/tests/Feature/Fiscal/IniciarEmissaoNfseTest.php`

**Interfaces:**
- Consumes: `CenarioDeNfse` (Tarefa 3), colunas da Tarefa 2, `RateioDeDesconto`, `EmissaoBloqueadaException(string $codigo, string $mensagem)`.
- Produces: `PreparadorDaNotaDeServico::preparar(Nota $nota, Emitente $emitente): void` (congela o snapshot do serviço, o tomador em `notas.destinatario` como `['tipo','documento','nome']` e os totais); `VerificadorDoEmitente::garantirAptoParaNfse(Emitente $emitente, Tenant $tenant): void`; `IniciarEmissao` aceita notas `DPS` (módulo `FISCAL_NFSE`, série `DPS`, status `PROCESSANDO`) e despacha `App\Modules\Fiscal\Application\Jobs\EmitirNfseJob` (criado na Tarefa 8).

Códigos de bloqueio (todos `EmissaoBloqueadaException`, HTTP 422): `FISCAL_NOTA_SEM_ITENS`, `FISCAL_TOMADOR_INCOMPLETO`, `FISCAL_SERVICO_INCOMPLETO`, `FISCAL_SERVICOS_DIVERGENTES`, `FISCAL_DESCRICAO_LONGA`, `FISCAL_INFO_COMPLEMENTAR_LONGA`, `FISCAL_DESCONTO_INVALIDO`, `FISCAL_EMITENTE_INCOMPLETO`, `FISCAL_REGIME_NAO_SUPORTADO`, `FISCAL_CERTIFICADO_INVALIDO`, `FISCAL_SERIE_NAO_CONFIGURADA`.

- [ ] **Step 1: Escrever os testes que falham**

`backend/tests/Feature/Fiscal/PreparadorDaNotaDeServicoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Application\Emissao\PreparadorDaNotaDeServico;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class PreparadorDaNotaDeServicoTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
    }

    private function preparar(Nota $nota): void
    {
        app(PreparadorDaNotaDeServico::class)->preparar($nota->refresh(), $this->emitente);
    }

    private function assertBloqueio(string $codigo, Nota $nota): void
    {
        try {
            $this->preparar($nota);
            $this->fail("Deveria bloquear com {$codigo}.");
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame($codigo, $e->codigo());
        }
    }

    public function test_congela_o_tomador_o_snapshot_do_servico_e_os_totais(): void
    {
        $nota = $this->rascunhoDeNfse(['desconto_centavos' => 1000]);

        $this->preparar($nota);

        $nota = $nota->refresh();
        $this->assertSame(['tipo' => 'PF', 'documento' => preg_replace('/\D/', '', (string) $this->cliente->cpf_cnpj), 'nome' => 'Maria Teste'], $nota->destinatario);
        $this->assertSame(15000, $nota->subtotal_centavos);
        $this->assertSame(14000, $nota->total_centavos);
        $item = $nota->itens()->first();
        $this->assertSame('Troca de óleo', $item->descricao);
        $this->assertSame('140101', $item->c_trib_nac);
        $this->assertSame(1000, $item->desconto_centavos);
        $this->assertSame(15000, $item->total_centavos);
    }

    public function test_tomador_sem_documento_bloqueia(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->cliente->forceFill(['cpf_cnpj' => null])->save();

        $this->assertBloqueio('FISCAL_TOMADOR_INCOMPLETO', $nota);
    }

    public function test_servico_sem_codigo_de_6_digitos_ou_sem_aliquota_bloqueia(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->servico->forceFill(['c_trib_nac' => '14.01'])->save();
        $this->assertBloqueio('FISCAL_SERVICO_INCOMPLETO', $nota);

        $this->servico->forceFill(['c_trib_nac' => '140101', 'aliquota_iss' => null])->save();
        $this->assertBloqueio('FISCAL_SERVICO_INCOMPLETO', $nota);
    }

    public function test_aliquota_zero_e_valida(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->servico->forceFill(['aliquota_iss' => '0.00'])->save();

        $this->preparar($nota);

        $this->assertSame('0.00', $nota->itens()->first()->aliquota_iss);
    }

    public function test_servicos_com_codigos_ou_aliquotas_diferentes_bloqueiam(): void
    {
        $outro = Servico::factory()->completo()->create(['nome' => 'Lavagem', 'c_trib_nac' => '140201']);
        $nota = $this->rascunhoDeNfse(['itens' => [
            ['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null],
            ['servico_id' => $outro->id, 'quantidade' => '1', 'valor_unitario_centavos' => null],
        ]]);
        $this->assertBloqueio('FISCAL_SERVICOS_DIVERGENTES', $nota);

        $outro->forceFill(['c_trib_nac' => '140101', 'aliquota_iss' => '2.00'])->save();
        $this->assertBloqueio('FISCAL_SERVICOS_DIVERGENTES', $nota);

        $outro->forceFill(['aliquota_iss' => '5.00'])->save();
        $this->preparar($nota);
        $this->assertSame(30000, $nota->refresh()->subtotal_centavos);
    }

    public function test_descricao_composta_acima_de_2000_bloqueia(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->servico->forceFill(['nome' => str_repeat('a', 150)])->save();
        $itens = array_fill(0, 14, ['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]);
        $nota = $this->rascunhoDeNfse(['itens' => $itens]);

        $this->assertBloqueio('FISCAL_DESCRICAO_LONGA', $nota);
    }

    public function test_informacoes_complementares_acima_de_255_bloqueiam(): void
    {
        $nota = $this->rascunhoDeNfse(['informacoes_complementares' => str_repeat('x', 256)]);

        $this->assertBloqueio('FISCAL_INFO_COMPLEMENTAR_LONGA', $nota);
    }

    public function test_desconto_que_zera_ou_passa_do_total_bloqueia(): void
    {
        $nota = $this->rascunhoDeNfse(['desconto_centavos' => 15000]);

        $this->assertBloqueio('FISCAL_DESCONTO_INVALIDO', $nota);
    }
}
```

`backend/tests/Feature/Fiscal/IniciarEmissaoNfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Jobs\EmitirNfseJob;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class IniciarEmissaoNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
        Bus::fake();
    }

    private function emitir(string $id): \Illuminate\Testing\TestResponse
    {
        return $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir");
    }

    public function test_emitir_aloca_o_ndps_da_serie_dps_e_despacha_o_job_da_nfse(): void
    {
        $nota = $this->rascunhoDeNfse();

        $this->emitir($nota->id)->assertStatus(202)->assertJsonPath('data.status', 'PROCESSANDO');

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Processando, $nota->status);
        $this->assertSame(1, $nota->numero);
        $this->assertSame('1', $nota->serie);
        $this->assertSame(AmbienteFiscal::Homologacao->value, $nota->ambiente);
        $this->assertSame(2, EmitenteSerie::query()->where('modelo', ModeloDocumento::Dps)->firstOrFail()->proximo_numero);
        Bus::assertDispatched(EmitirNfseJob::class, fn (EmitirNfseJob $j): bool => $j->notaId === $nota->id);
    }

    public function test_emitir_de_novo_nao_aloca_outro_numero_nem_despacha_duas_vezes(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->emitir($nota->id)->assertStatus(202);

        $this->emitir($nota->id)->assertStatus(202);

        $this->assertSame(1, $nota->refresh()->numero);
        Bus::assertDispatchedTimes(EmitirNfseJob::class, 1);
    }

    public function test_sem_modulo_nfse_bloqueia_a_emissao(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->tenant->update(['plano_id' => Plano::factory()->comModulos(Modulo::FiscalNfe)->create()->id]);

        $this->emitir($nota->id)->assertStatus(403)->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_regime_normal_e_regime_ausente_bloqueiam(): void
    {
        $nota = $this->rascunhoDeNfse();

        $this->emitente->update(['regime_tributario' => 'NORMAL']);
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_REGIME_NAO_SUPORTADO');

        $this->emitente->update(['regime_tributario' => null]);
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_EMITENTE_INCOMPLETO');
    }

    public function test_sem_codigo_ibge_certificado_valido_ou_serie_dps_bloqueia(): void
    {
        $nota = $this->rascunhoDeNfse();

        $this->emitente->update(['codigo_ibge' => null]);
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_EMITENTE_INCOMPLETO');
        $this->emitente->update(['codigo_ibge' => '3550308', 'certificado_status' => CertificadoStatus::Invalido]);
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_CERTIFICADO_INVALIDO');
        $this->emitente->update(['certificado_status' => CertificadoStatus::Valido]);
        EmitenteSerie::query()->where('modelo', ModeloDocumento::Dps)->delete();
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_SERIE_NAO_CONFIGURADA');
        Bus::assertNotDispatched(EmitirNfseJob::class);
    }

    public function test_a_ie_e_o_endereco_do_emitente_nao_sao_exigidos_para_a_nfse(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->emitente->update(['inscricao_estadual' => null, 'logradouro' => null, 'cep' => null]);

        $this->emitir($nota->id)->assertStatus(202);
    }
}
```

> Conferir em `CertificadoStatus` o nome do caso inválido (`Invalido`) e em `AmbienteFiscal` o do homologação (`Homologacao`); ajustar se diferirem. `EmitirNfseJob` ainda não existe: o `Bus::assertDispatched` referencia só o nome da classe, então a Tarefa 8 a cria; **nesta tarefa crie um esqueleto** (Step 4) para o teste compilar.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="PreparadorDaNotaDeServicoTest|IniciarEmissaoNfseTest"`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar o preparador e o verificador**

`backend/app/Modules/Fiscal/Application/Emissao/PreparadorDaNotaDeServico.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Emissao;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\RateioDeDesconto;

/**
 * Valida o que depende do tomador e dos serviços da NFS-e e congela o resultado na nota (snapshot fiscal).
 * Uma DPS leva um único serviço: todos os itens precisam ter o mesmo código de tributação e a mesma alíquota.
 */
final class PreparadorDaNotaDeServico
{
    public const LIMITE_DESCRICAO = 2000;

    public const LIMITE_INFO_COMPLEMENTAR = 255;

    public function preparar(Nota $nota, Emitente $emitente): void
    {
        $itens = $nota->itens()->get();
        if ($itens->isEmpty()) {
            throw new EmissaoBloqueadaException('FISCAL_NOTA_SEM_ITENS', 'Adicione ao menos um serviço à nota.');
        }

        $tomador = $this->tomador(Cliente::query()->findOrFail($nota->cliente_id));

        $info = $nota->informacoes_complementares;
        if ($info !== null && mb_strlen($info) > self::LIMITE_INFO_COMPLEMENTAR) {
            throw new EmissaoBloqueadaException('FISCAL_INFO_COMPLEMENTAR_LONGA', 'As informações complementares da NFS-e aceitam até '.self::LIMITE_INFO_COMPLEMENTAR.' caracteres.');
        }

        $totais = $itens->map(fn ($i): int => RateioDeDesconto::totalDoItem($i->quantidade_milesimos, $i->valor_unitario_centavos))->all();
        $descontos = RateioDeDesconto::ratear($nota->desconto_centavos, array_values($totais));
        $subtotal = array_sum($totais);
        $total = $subtotal - $nota->desconto_centavos;
        if ($total <= 0) {
            throw new EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', 'O total da nota deve ser maior que zero.');
        }

        $snapshots = [];
        foreach ($itens->values() as $i => $item) {
            $servico = Servico::query()->findOrFail($item->servico_id);
            $this->exigirServico($servico);
            $snapshots[$i] = [
                'descricao' => $servico->nome, 'unidade' => null,
                'c_trib_nac' => $servico->c_trib_nac, 'codigo_municipal' => $servico->codigo_municipal,
                'aliquota_iss' => $servico->aliquota_iss, 'nbs' => $servico->nbs,
                'desconto_centavos' => $descontos[$i], 'total_centavos' => $totais[$i],
            ];
        }

        $codigos = array_unique(array_column($snapshots, 'c_trib_nac'));
        $aliquotas = array_unique(array_map(fn (array $s): string => number_format((float) $s['aliquota_iss'], 2, '.', ''), $snapshots));
        if (count($codigos) > 1 || count($aliquotas) > 1) {
            throw new EmissaoBloqueadaException('FISCAL_SERVICOS_DIVERGENTES', 'Os serviços da nota têm códigos de tributação ou alíquotas diferentes. Emita uma NFS-e para cada código.');
        }

        $descricao = implode('; ', array_column($snapshots, 'descricao'));
        if (mb_strlen($descricao) > self::LIMITE_DESCRICAO) {
            throw new EmissaoBloqueadaException('FISCAL_DESCRICAO_LONGA', 'A descrição dos serviços passa de '.self::LIMITE_DESCRICAO.' caracteres. Emita menos serviços por nota.');
        }

        foreach ($itens->values() as $i => $item) {
            $item->update($snapshots[$i]);
        }
        $nota->forceFill(['subtotal_centavos' => $subtotal, 'total_centavos' => $total, 'destinatario' => $tomador])->save();
    }

    private function exigirServico(Servico $servico): void
    {
        $faltas = [];
        if ($servico->c_trib_nac === null || preg_match('/^\d{6}$/', $servico->c_trib_nac) !== 1) {
            $faltas[] = 'código de tributação nacional de 6 dígitos (ex.: 140101)';
        }
        if ($servico->aliquota_iss === null) {
            $faltas[] = 'alíquota do ISS';
        }
        if ($faltas !== []) {
            throw new EmissaoBloqueadaException('FISCAL_SERVICO_INCOMPLETO', "O serviço \"{$servico->nome}\" está sem: ".implode(', ', $faltas).'. Complete em Serviços.');
        }
    }

    /** @return array{tipo: string, documento: string, nome: string} */
    private function tomador(Cliente $c): array
    {
        $documento = $c->cpf_cnpj === null ? '' : (string) preg_replace('/\D/', '', $c->cpf_cnpj);
        if ($documento === '') {
            throw new EmissaoBloqueadaException('FISCAL_TOMADOR_INCOMPLETO', "O cliente \"{$c->nome}\" está sem: CPF/CNPJ. Complete em Clientes.");
        }

        return ['tipo' => $c->tipo->value, 'documento' => $documento, 'nome' => $c->nome];
    }
}
```

`VerificadorDoEmitente.php`: extrair o bloco do certificado para um método privado e criar o método da NFS-e. Substituir, em `garantirApto`, o bloco do certificado (`$validade = ...` até o `throw` do certificado) por `$this->garantirCertificado($emitente);` e acrescentar:

```php
    /** A NFS-e não exige IE nem endereço do emitente (a ADN já tem o cadastro do CNPJ); exige o município, o regime, o certificado e a série DPS. */
    public function garantirAptoParaNfse(Emitente $emitente, Tenant $tenant): void
    {
        if ($emitente->codigo_ibge === null || trim($emitente->codigo_ibge) === '') {
            throw new EmissaoBloqueadaException('FISCAL_EMITENTE_INCOMPLETO', 'Complete em Configurações > Fiscal: código IBGE do município.');
        }

        $this->crt->crt($emitente->regime_tributario);
        $this->garantirCertificado($emitente);

        if (! $emitente->series()->where('modelo', ModeloDocumento::Dps)->exists()) {
            throw new EmissaoBloqueadaException('FISCAL_SERIE_NAO_CONFIGURADA', 'Cadastre a série e o próximo número da NFS-e (DPS) em Configurações > Fiscal.');
        }

        if (trim($tenant->cnpj) === '') {
            throw new EmissaoBloqueadaException('FISCAL_EMITENTE_INCOMPLETO', 'O CNPJ da empresa não está cadastrado.');
        }
    }

    private function garantirCertificado(Emitente $emitente): void
    {
        $validade = $emitente->certificado_validade;
        if ($emitente->certificado_status !== CertificadoStatus::Valido || $validade === null || $validade->lt(now()->startOfDay())) {
            throw new EmissaoBloqueadaException('FISCAL_CERTIFICADO_INVALIDO', 'O certificado A1 está ausente, inválido ou vencido. Envie um certificado válido em Configurações > Fiscal.');
        }
    }
```

- [ ] **Step 4: Implementar o início da emissão e o esqueleto do job**

`backend/app/Modules/Fiscal/Application/Jobs/EmitirNfseJob.php` (esqueleto; a Tarefa 8 preenche `handle`):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class EmitirNfseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $notaId, public readonly string $tenantId) {}

    public function handle(): void
    {
        // Preenchido na Tarefa 8.
    }
}
```

`IniciarEmissao.php`: adicionar `use App\Modules\Fiscal\Application\Emissao\PreparadorDaNotaDeServico;` e `use App\Modules\Fiscal\Application\Jobs\EmitirNfseJob;`; o construtor ganha `private readonly PreparadorDaNotaDeServico $preparadorDeServico,` (depois de `$preparador`); dentro da transação, trocar o trecho de módulo/verificação/preparo por:

```php
            $nfce = $nota->modelo === ModeloDocumento::Nfce;
            $servico = $nota->modelo === ModeloDocumento::Dps;
            $this->planos->garantirModulo($tenant, match (true) {
                $servico => Modulo::FiscalNfse,
                $nfce => Modulo::FiscalNfce,
                default => Modulo::FiscalNfe,
            });

            $emitente = Emitente::query()->findOrFail($nota->emitente_id);
            if ($servico) {
                $this->verificador->garantirAptoParaNfse($emitente, $tenant);
            } else {
                $this->verificador->garantirApto($emitente, $tenant, $nota->modelo);
            }
            if ($nfce) {
                $this->verificador->garantirCsc($emitente, $emitente->ambiente_fiscal->value);
            }
            if ($emitente->ambiente_fiscal === AmbienteFiscal::Producao) {
                $this->planos->garantirCapacidade($tenant, Recurso::DocumentosMes);
            }

            if ($servico) {
                $this->preparadorDeServico->preparar($nota, $emitente);
            } else {
                $this->preparador->preparar($nota, $emitente);
            }
```

(substitui as linhas que iam de `$nfce = ...` até `$this->preparador->preparar($nota, $emitente);`) e, no despacho no fim do método, trocar `EmitirNotaJob::dispatch(...)` por:

```php
            if ($nota->modelo === ModeloDocumento::Dps) {
                EmitirNfseJob::dispatch($nota->id, $nota->tenant_id);
            } else {
                EmitirNotaJob::dispatch($nota->id, $nota->tenant_id);
            }
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter="PreparadorDaNotaDeServicoTest|IniciarEmissaoNfseTest|IniciarEmissaoTest|BloqueiosDeEmissaoTest|EmissaoNfceTest"` e `vendor/bin/pint` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS (a emissão de NF-e e NFC-e não muda); Pint e PHPStan limpos.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/PreparadorDaNotaDeServicoTest.php backend/tests/Feature/Fiscal/IniciarEmissaoNfseTest.php
git commit -m "feat(nfse): bloqueios e início da emissão da NFS-e" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 5: Domínio da NFS-e (DTOs, resultados, interface e emissor falso)

**Files:**
- Create: `backend/app/Modules/Fiscal/Domain/Emissao/NotaParaNfse.php` (com `EmitenteParaNfse`, `TomadorParaNfse`, `ServicoParaNfse` no mesmo diretório, um arquivo cada)
- Create: `backend/app/Modules/Fiscal/Domain/Emissao/ResultadoDeEmissaoNfse.php`, `ResultadoDeConsultaNfse.php`
- Create: `backend/app/Modules/Fiscal/Domain/Contracts/EmissorDeNfse.php`
- Create: `backend/tests/Fixtures/EmissorDeNfseFake.php`
- Test: `backend/tests/Feature/Fiscal/NotaParaNfseTest.php`

**Interfaces:**
- Consumes: nota preparada pela Tarefa 4 (`destinatario` com `documento` e `nome`, itens com snapshot).
- Produces (nomes exatos usados nas Tarefas 6 a 11):
  - `EmitenteParaNfse(string $cnpj, string $codigoIbge, string $regime)` (`regime` = `'SIMPLES'` ou `'MEI'`).
  - `TomadorParaNfse(string $documento, string $nome)`.
  - `ServicoParaNfse(string $cTribNac, ?string $cTribMun, string $descricao, string $aliquotaIss, ?string $nbs)`.
  - `NotaParaNfse(string $notaId, string $ambiente, string $serie, int $numeroDps, EmitenteParaNfse $emitente, TomadorParaNfse $tomador, ServicoParaNfse $servico, int $valorServicosCentavos, int $descontoCentavos, ?string $informacoesComplementares)` e `NotaParaNfse::deNota(Nota $nota, Emitente $emitente, Tenant $tenant): self`.
  - `ResultadoDeEmissaoNfse` (`readonly`: `StatusNota $status`, `?string $chave`, `?string $numeroNfse`, `?string $xmlAutorizado`, `?string $cstat`, `?string $motivo`, `?string $mensagemErro`) com `autorizada(string $chave, string $numeroNfse, string $xml, string $cstat = '100')`, `rejeitada(string $cstat, string $motivo)`, `erro(string $mensagem)`.
  - `ResultadoDeConsultaNfse` (`readonly`: `SituacaoNaSefaz $situacao`, `?string $chave`, `?string $numeroNfse`, `?string $xmlAutorizado`, `?string $cstat`, `?string $mensagem`) com `autorizada(string $chave, string $numeroNfse, string $xml, string $cstat = '100')`, `cancelada(?string $chave = null)`, `naoEncontrada()`, `erro(string $mensagem)`.
  - `EmissorDeNfse`: `consultarDps(Emitente, NotaParaNfse): ResultadoDeConsultaNfse`; `emitir(Emitente, NotaParaNfse): ResultadoDeEmissaoNfse`; `consultarPorChave(Emitente, string $chave, string $ambiente): ResultadoDeConsultaNfse`; `cancelar(Emitente, string $chave, string $justificativa, string $ambiente): ResultadoDeCancelamento`.
  - `EmissorDeNfseFake` (em `Tests\Fixtures`): `$chamadas` (`list<string>`), `enfileirarConsultaDps(ResultadoDeConsultaNfse ...)`, `enfileirarEmissao(ResultadoDeEmissaoNfse ...)`, `enfileirarConsultaPorChave(ResultadoDeConsultaNfse ...)`, `enfileirarCancelamento(ResultadoDeCancelamento ...)`, `$falharEmEmitir`, `$falharEmCancelar`, `$falharEmConsultar` (`?Throwable`); padrões: `consultarDps` → `naoEncontrada()`, `emitir` → `autorizada(chave de 50 dígitos '3550308211444777000161'.str_repeat('1', 28), '1', '<NFSe>fake</NFSe>')`, `consultarPorChave` → `autorizada(...)` igual, `cancelar` → aceito.

- [ ] **Step 1: Escrever o teste que falha**

`backend/tests/Feature/Fiscal/NotaParaNfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Emissao\PreparadorDaNotaDeServico;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class NotaParaNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
    }

    private function dto(array $corpo = [], ?string $regime = null): NotaParaNfse
    {
        $nota = $this->rascunhoDeNfse($corpo);
        app(PreparadorDaNotaDeServico::class)->preparar($nota, $this->emitente);
        $nota->forceFill(['numero' => 7, 'serie' => '1', 'ambiente' => 'HOMOLOGACAO'])->save();
        if ($regime !== null) {
            $this->emitente->update(['regime_tributario' => $regime]);
        }

        return NotaParaNfse::deNota($nota->refresh(), $this->emitente->refresh(), Tenant::query()->findOrFail($nota->tenant_id));
    }

    public function test_monta_o_retrato_a_partir_do_snapshot(): void
    {
        $dto = $this->dto(['desconto_centavos' => 1000, 'informacoes_complementares' => 'Placa ABC1D23']);

        $this->assertSame('HOMOLOGACAO', $dto->ambiente);
        $this->assertSame('1', $dto->serie);
        $this->assertSame(7, $dto->numeroDps);
        $this->assertSame('11444777000161', $dto->emitente->cnpj);
        $this->assertSame('3550308', $dto->emitente->codigoIbge);
        $this->assertSame('SIMPLES', $dto->emitente->regime);
        $this->assertSame('Maria Teste', $dto->tomador->nome);
        $this->assertSame('140101', $dto->servico->cTribNac);
        $this->assertSame('Troca de óleo', $dto->servico->descricao);
        $this->assertSame('5.00', $dto->servico->aliquotaIss);
        $this->assertSame(15000, $dto->valorServicosCentavos);
        $this->assertSame(1000, $dto->descontoCentavos);
        $this->assertSame('Placa ABC1D23', $dto->informacoesComplementares);
    }

    public function test_descricao_de_varios_itens_e_composta_com_ponto_e_virgula(): void
    {
        $outro = \App\Modules\Catalog\Domain\Models\Servico::factory()->completo()->create(['nome' => 'Alinhamento']);
        $dto = $this->dto(['itens' => [
            ['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null],
            ['servico_id' => $outro->id, 'quantidade' => '1', 'valor_unitario_centavos' => null],
        ]]);

        $this->assertSame('Troca de óleo; Alinhamento', $dto->servico->descricao);
    }

    public function test_mei_vira_regime_mei(): void
    {
        $this->assertSame('MEI', $this->dto([], 'MEI')->emitente->regime);
    }

    public function test_nota_nao_preparada_lanca(): void
    {
        $nota = $this->rascunhoDeNfse();

        $this->expectException(LogicException::class);

        NotaParaNfse::deNota($nota, $this->emitente, Tenant::query()->findOrFail($nota->tenant_id));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=NotaParaNfseTest`
Expected: FAIL (classe `NotaParaNfse` inexistente).

- [ ] **Step 3: Implementar o domínio**

Cada arquivo em `backend/app/Modules/Fiscal/Domain/Emissao/` começa com `<?php`, `declare(strict_types=1);` e `namespace App\Modules\Fiscal\Domain\Emissao;`.

`EmitenteParaNfse.php`:

```php
final readonly class EmitenteParaNfse
{
    /** @param  string  $regime  'SIMPLES' ou 'MEI' (o Regime Normal é bloqueado antes). */
    public function __construct(public string $cnpj, public string $codigoIbge, public string $regime) {}
}
```

`TomadorParaNfse.php`:

```php
final readonly class TomadorParaNfse
{
    public function __construct(public string $documento, public string $nome) {}
}
```

`ServicoParaNfse.php`:

```php
final readonly class ServicoParaNfse
{
    public function __construct(
        public string $cTribNac,
        public ?string $cTribMun,
        public string $descricao,
        public string $aliquotaIss,
        public ?string $nbs,
    ) {}
}
```

`NotaParaNfse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\Models\Tenant;
use LogicException;

/** Retrato imutável da nota de serviço, lido só do snapshot congelado. É o que o motor da NFS-e recebe. */
final readonly class NotaParaNfse
{
    public function __construct(
        public string $notaId,
        public string $ambiente,
        public string $serie,
        public int $numeroDps,
        public EmitenteParaNfse $emitente,
        public TomadorParaNfse $tomador,
        public ServicoParaNfse $servico,
        public int $valorServicosCentavos,
        public int $descontoCentavos,
        public ?string $informacoesComplementares,
    ) {}

    public static function deNota(Nota $nota, Emitente $emitente, Tenant $tenant): self
    {
        $d = $nota->destinatario;
        if ($d === null) {
            throw new LogicException('Nota sem snapshot do tomador: prepare antes de emitir.');
        }
        $itens = $nota->itens()->get();
        $primeiro = $itens->first();
        if ($primeiro === null || $primeiro->c_trib_nac === null || $primeiro->aliquota_iss === null) {
            throw new LogicException('Nota sem snapshot do serviço: prepare antes de emitir.');
        }
        $regime = $emitente->regime_tributario;
        if (! in_array($regime, ['SIMPLES', 'MEI'], true)) {
            throw new LogicException('Regime tributário sem tradução para a NFS-e.');
        }

        return new self(
            $nota->id, (string) $nota->ambiente, (string) $nota->serie, (int) $nota->numero,
            new EmitenteParaNfse(preg_replace('/\D/', '', $tenant->cnpj) ?? '', (string) $emitente->codigo_ibge, $regime),
            new TomadorParaNfse((string) $d['documento'], (string) $d['nome']),
            new ServicoParaNfse(
                $primeiro->c_trib_nac, $primeiro->codigo_municipal,
                implode('; ', $itens->pluck('descricao')->map(fn ($t): string => (string) $t)->all()),
                $primeiro->aliquota_iss, $primeiro->nbs,
            ),
            $nota->subtotal_centavos, $nota->desconto_centavos, $nota->informacoes_complementares,
        );
    }
}
```

`ResultadoDeEmissaoNfse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\StatusNota;

final readonly class ResultadoDeEmissaoNfse
{
    public function __construct(
        public StatusNota $status,
        public ?string $chave = null,
        public ?string $numeroNfse = null,
        public ?string $xmlAutorizado = null,
        public ?string $cstat = null,
        public ?string $motivo = null,
        public ?string $mensagemErro = null,
    ) {}

    public static function autorizada(string $chave, string $numeroNfse, string $xml, string $cstat = '100'): self
    {
        return new self(StatusNota::Autorizada, $chave, $numeroNfse, $xml, $cstat, 'NFS-e gerada');
    }

    public static function rejeitada(string $cstat, string $motivo): self
    {
        return new self(StatusNota::Rejeitada, cstat: $cstat, motivo: $motivo);
    }

    public static function erro(string $mensagem): self
    {
        return new self(StatusNota::Erro, mensagemErro: $mensagem);
    }
}
```

`ResultadoDeConsultaNfse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;

final readonly class ResultadoDeConsultaNfse
{
    public function __construct(
        public SituacaoNaSefaz $situacao,
        public ?string $chave = null,
        public ?string $numeroNfse = null,
        public ?string $xmlAutorizado = null,
        public ?string $cstat = null,
        public ?string $mensagem = null,
    ) {}

    public static function autorizada(string $chave, string $numeroNfse, string $xml, string $cstat = '100'): self
    {
        return new self(SituacaoNaSefaz::Autorizada, $chave, $numeroNfse, $xml, $cstat);
    }

    public static function cancelada(?string $chave = null): self
    {
        return new self(SituacaoNaSefaz::Cancelada, $chave);
    }

    public static function naoEncontrada(): self
    {
        return new self(SituacaoNaSefaz::NaoEncontrada);
    }

    public static function erro(string $mensagem): self
    {
        return new self(SituacaoNaSefaz::Erro, mensagem: $mensagem);
    }
}
```

`Domain/Contracts/EmissorDeNfse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Contracts;

use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Models\Emitente;

/** O motor só traduz formato: nenhuma regra tributária mora aqui. A NFS-e não tem contingência. */
interface EmissorDeNfse
{
    /**
     * A DPS já existe na ADN? É a defesa contra duplicata (resposta perdida): nunca reenviar sem perguntar.
     * `Erro` quer dizer incerteza: quem chama NÃO reenvia.
     */
    public function consultarDps(Emitente $emitente, NotaParaNfse $nota): ResultadoDeConsultaNfse;

    /** Assina, envia e interpreta. Rejeição da ADN volta em `rejeitada`; falha técnica volta em `erro`. */
    public function emitir(Emitente $emitente, NotaParaNfse $nota): ResultadoDeEmissaoNfse;

    /** `$chave` com 50 dígitos, sem o prefixo `NFS`. A lib não distingue "não encontrada" de falha: nulo vira `Erro`. */
    public function consultarPorChave(Emitente $emitente, string $chave, string $ambiente): ResultadoDeConsultaNfse;

    /** Evento 101101. Recusa da ADN volta em `recusado`; falha de rede lança exceção (a nota permanece CANCELANDO). */
    public function cancelar(Emitente $emitente, string $chave, string $justificativa, string $ambiente): ResultadoDeCancelamento;
}
```

`ResultadoDeCancelamento` já aceita `protocolo` nulo no construtor; para a NFS-e (sem protocolo) o `NfseNacionalEmissor` (Tarefa 7) usa `new ResultadoDeCancelamento(true, '101101', 'Cancelamento de NFS-e registrado', null, $xmlDoEvento)`.

`backend/tests/Fixtures/EmissorDeNfseFake.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Throwable;

final class EmissorDeNfseFake implements EmissorDeNfse
{
    public const CHAVE = '3550308211444777000161'.'11111111111111111111111111'.'11';

    /** @var list<string> */
    public array $chamadas = [];

    public ?Throwable $falharEmEmitir = null;

    public ?Throwable $falharEmCancelar = null;

    public ?Throwable $falharEmConsultar = null;

    /** @var list<ResultadoDeConsultaNfse> */
    private array $consultasDps = [];

    /** @var list<ResultadoDeEmissaoNfse> */
    private array $emissoes = [];

    /** @var list<ResultadoDeConsultaNfse> */
    private array $consultasPorChave = [];

    /** @var list<ResultadoDeCancelamento> */
    private array $cancelamentos = [];

    public function enfileirarConsultaDps(ResultadoDeConsultaNfse ...$r): void
    {
        array_push($this->consultasDps, ...$r);
    }

    public function enfileirarEmissao(ResultadoDeEmissaoNfse ...$r): void
    {
        array_push($this->emissoes, ...$r);
    }

    public function enfileirarConsultaPorChave(ResultadoDeConsultaNfse ...$r): void
    {
        array_push($this->consultasPorChave, ...$r);
    }

    public function enfileirarCancelamento(ResultadoDeCancelamento ...$r): void
    {
        array_push($this->cancelamentos, ...$r);
    }

    public function consultarDps(Emitente $emitente, NotaParaNfse $nota): ResultadoDeConsultaNfse
    {
        $this->chamadas[] = 'consultarDps';
        if ($this->falharEmConsultar !== null) {
            throw $this->falharEmConsultar;
        }

        return array_shift($this->consultasDps) ?? ResultadoDeConsultaNfse::naoEncontrada();
    }

    public function emitir(Emitente $emitente, NotaParaNfse $nota): ResultadoDeEmissaoNfse
    {
        $this->chamadas[] = 'emitir';
        if ($this->falharEmEmitir !== null) {
            throw $this->falharEmEmitir;
        }

        return array_shift($this->emissoes) ?? ResultadoDeEmissaoNfse::autorizada(self::CHAVE, (string) $nota->numeroDps, '<NFSe>fake</NFSe>');
    }

    public function consultarPorChave(Emitente $emitente, string $chave, string $ambiente): ResultadoDeConsultaNfse
    {
        $this->chamadas[] = 'consultarPorChave';
        if ($this->falharEmConsultar !== null) {
            throw $this->falharEmConsultar;
        }

        return array_shift($this->consultasPorChave) ?? ResultadoDeConsultaNfse::autorizada($chave, '1', '<NFSe>fake</NFSe>');
    }

    public function cancelar(Emitente $emitente, string $chave, string $justificativa, string $ambiente): ResultadoDeCancelamento
    {
        $this->chamadas[] = 'cancelar';
        if ($this->falharEmCancelar !== null) {
            throw $this->falharEmCancelar;
        }

        return array_shift($this->cancelamentos)
            ?? new ResultadoDeCancelamento(true, '101101', 'Cancelamento de NFS-e registrado', null, '<evento>fake</evento>');
    }
}
```

> `CHAVE` precisa ter exatamente 50 dígitos: `3550308211444777000161` (22) + 26 `1` + `11` (2) = 50. Se a contagem divergir ao rodar, ajustar o literal; o teste da Tarefa 8 confere `strlen(...) === 50`.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=NotaParaNfseTest` e `vendor/bin/pint` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; Pint e PHPStan limpos.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal/Domain backend/tests/Fixtures/EmissorDeNfseFake.php backend/tests/Feature/Fiscal/NotaParaNfseTest.php
git commit -m "feat(nfse): domínio da NFS-e (retrato, resultados, interface e emissor falso)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 6: Montagem da DPS

**Files:**
- Create: `backend/app/Modules/Fiscal/Infrastructure/Nfse/MontadorDeDps.php`
- Test: `backend/tests/Unit/Fiscal/Nfse/MontadorDeDpsTest.php`

**Interfaces:**
- Consumes: `NotaParaNfse` e filhos (Tarefa 5); `Nfse\Dto\Nfse\DpsData`, `Nfse\Support\IdGenerator`.
- Produces: `MontadorDeDps::montar(NotaParaNfse $nota): DpsData` e `MontadorDeDps::idDaDps(NotaParaNfse $nota): string` (45 caracteres, usado pela consulta de idempotência).

- [ ] **Step 1: Escrever o teste que falha**

`backend/tests/Unit/Fiscal/Nfse/MontadorDeDpsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use App\Modules\Fiscal\Domain\Emissao\EmitenteParaNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ServicoParaNfse;
use App\Modules\Fiscal\Domain\Emissao\TomadorParaNfse;
use App\Modules\Fiscal\Infrastructure\Nfse\MontadorDeDps;
use Carbon\CarbonImmutable;
use Nfse\Xml\DpsXmlBuilder;
use RuntimeException;
use Tests\TestCase;

class MontadorDeDpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-07 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function nota(string $regime = 'SIMPLES', string $documento = '52998224725', int $desconto = 0, ?string $cTribMun = null, ?string $info = null, string $descricao = 'Troca de oleo'): NotaParaNfse
    {
        return new NotaParaNfse(
            'nota-1', 'HOMOLOGACAO', '1', 7,
            new EmitenteParaNfse('11444777000161', '3550308', $regime),
            new TomadorParaNfse($documento, 'Maria Teste'),
            new ServicoParaNfse('140101', $cTribMun, $descricao, '5.00', null),
            15000, $desconto, $info,
        );
    }

    private function xml(NotaParaNfse $nota): string
    {
        return (new DpsXmlBuilder)->build((new MontadorDeDps)->montar($nota));
    }

    public function test_dps_do_simples_segue_as_regras_da_adn(): void
    {
        $xml = $this->xml($this->nota());

        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<serie>1</serie>', $xml);
        $this->assertStringContainsString('<nDPS>7</nDPS>', $xml);
        $this->assertStringContainsString('<dCompet>2026-10-07</dCompet>', $xml);
        $this->assertSame(1, preg_match('/<dhEmi>2026-10-07T10:00:00-03:00<\/dhEmi>/', $xml), 'dhEmi com o offset de São Paulo');
        $this->assertStringContainsString('<tpEmit>1</tpEmit>', $xml);
        $this->assertStringContainsString('<cLocEmi>3550308</cLocEmi>', $xml);
        $this->assertStringContainsString('<cLocPrestacao>3550308</cLocPrestacao>', $xml);
        $this->assertStringContainsString('<opSimpNac>3</opSimpNac>', $xml);
        $this->assertStringContainsString('<regApTribSN>1</regApTribSN>', $xml);
        $this->assertStringContainsString('<cTribNac>140101</cTribNac>', $xml);
        $this->assertStringContainsString('<tribISSQN>1</tribISSQN>', $xml);
        $this->assertStringContainsString('<tpRetISSQN>1</tpRetISSQN>', $xml);
        $this->assertStringContainsString('<pTotTribSN>0.00</pTotTribSN>', $xml);
        $this->assertStringContainsString('<vServ>150.00</vServ>', $xml);
        // Nunca enviados: IM e endereço do prestador (E0120/E0128), alíquota no Simples (E0625), indTotTrib (E0712).
        preg_match('/<prest>.*<\/prest>/s', $xml, $prest);
        $this->assertStringNotContainsString('<IM>', $prest[0]);
        $this->assertStringNotContainsString('<end', $prest[0]);
        $this->assertStringNotContainsString('<pAliq>', $xml);
        $this->assertStringNotContainsString('<indTotTrib>', $xml);
        $this->assertStringNotContainsString('<vDescCondIncond>', $xml);
    }

    public function test_mei_usa_op_simp_nac_2(): void
    {
        $this->assertStringContainsString('<opSimpNac>2</opSimpNac>', $this->xml($this->nota('MEI')));
    }

    public function test_regime_sem_traducao_lanca(): void
    {
        $this->expectException(RuntimeException::class);

        (new MontadorDeDps)->montar($this->nota('NORMAL'));
    }

    public function test_tomador_cpf_e_cnpj(): void
    {
        $this->assertStringContainsString('<CPF>52998224725</CPF>', $this->xml($this->nota()));
        $this->assertStringContainsString('<CNPJ>11222333000181</CNPJ>', $this->xml($this->nota('SIMPLES', '11222333000181')));
    }

    public function test_desconto_vai_entre_o_valor_e_a_tributacao(): void
    {
        $xml = $this->xml($this->nota('SIMPLES', '52998224725', 1000));

        $this->assertStringContainsString('<vDescIncond>10.00</vDescIncond>', $xml);
        $this->assertLessThan(strpos($xml, '<vDescCondIncond>'), strpos($xml, '<vServPrest>'));
        $this->assertLessThan(strpos($xml, '<trib>'), strpos($xml, '<vDescCondIncond>'));
    }

    public function test_c_trib_mun_so_com_exatamente_3_digitos(): void
    {
        $this->assertStringContainsString('<cTribMun>123</cTribMun>', $this->xml($this->nota('SIMPLES', '52998224725', 0, '123')));
        $this->assertStringNotContainsString('<cTribMun>', $this->xml($this->nota('SIMPLES', '52998224725', 0, '1234')));
        $this->assertStringNotContainsString('<cTribMun>', $this->xml($this->nota()));
    }

    public function test_informacoes_complementares_so_quando_ha_texto(): void
    {
        $this->assertStringContainsString('<xInfComp>Placa ABC1D23</xInfComp>', $this->xml($this->nota('SIMPLES', '52998224725', 0, null, 'Placa ABC1D23')));
        $this->assertStringNotContainsString('<xInfComp>', $this->xml($this->nota('SIMPLES', '52998224725', 0, null, '   ')));
        $this->assertStringNotContainsString('<xInfComp>', $this->xml($this->nota()));
    }

    public function test_descricao_e_sanitizada(): void
    {
        $xml = $this->xml($this->nota('SIMPLES', '52998224725', 0, null, null, "Troca\n\n de   oleo\t& filtro"));

        $this->assertStringContainsString('<xDescServ>Troca de oleo &amp; filtro</xDescServ>', $xml);
    }

    public function test_id_da_dps_tem_45_caracteres_e_usa_serie_e_ndps(): void
    {
        $id = MontadorDeDps::idDaDps($this->nota());

        $this->assertSame('DPS3550308211444777000161'.'00001'.'000000000000007', $id);
        $this->assertStringContainsString('Id="'.$id.'"', $this->xml($this->nota()));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=MontadorDeDpsTest`
Expected: FAIL (classe inexistente).

- [ ] **Step 3: Implementar**

`backend/app/Modules/Fiscal/Infrastructure/Nfse/MontadorDeDps.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Nfse;

use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use Nfse\Dto\Nfse\DpsData;
use Nfse\Support\IdGenerator;
use RuntimeException;

/**
 * Só traduz formato: tudo que chega aqui já foi resolvido e validado pelo `PreparadorDaNotaDeServico`.
 * As regras da ADN estão em `docs/referencia-fiscal/02-nfephp-nfse.md` (E0120, E0128, E0625, E0712, E1235).
 */
final class MontadorDeDps
{
    private const FUSO = 'America/Sao_Paulo';

    public static function idDaDps(NotaParaNfse $nota): string
    {
        return IdGenerator::generateDpsId($nota->emitente->cnpj, $nota->emitente->codigoIbge, $nota->serie, $nota->numeroDps);
    }

    public function montar(NotaParaNfse $nota): DpsData
    {
        $agora = now()->setTimezone(self::FUSO);
        $e = $nota->emitente;
        $regTrib = $this->regimeDoPrestador($e->regime);
        $tomador = [(strlen($nota->tomador->documento) > 11 ? 'CNPJ' : 'CPF') => $nota->tomador->documento, 'xNome' => $this->texto($nota->tomador->nome, 150)];

        $cServ = [
            // 6 dígitos (item + subitem + desdobro): "14.01" daria E1235.
            'cTribNac' => $nota->servico->cTribNac,
            // O código municipal é opcional e nunca se chuta: só vai com exatamente 3 dígitos.
            'cTribMun' => preg_match('/^\d{3}$/', (string) $nota->servico->cTribMun) === 1 ? $nota->servico->cTribMun : null,
            'xDescServ' => $this->texto($nota->servico->descricao, 2000),
        ];
        $serv = ['locPrest' => ['cLocPrestacao' => $e->codigoIbge], 'cServ' => array_filter($cServ, static fn ($v): bool => $v !== null)];
        $info = $nota->informacoesComplementares === null ? '' : $this->texto($nota->informacoesComplementares, 255);
        if ($info !== '') {
            $serv['infoCompl'] = ['xInfComp' => $info];
        }

        // A ordem das chaves é a do schema: vServPrest, vDescCondIncond, trib.
        $valores = ['vServPrest' => ['vServ' => $this->reais($nota->valorServicosCentavos)]];
        if ($nota->descontoCentavos > 0) {
            $valores['vDescCondIncond'] = ['vDescIncond' => $this->reais($nota->descontoCentavos)];
        }
        $valores['trib'] = [
            // tpRetISSQN 1 = não retido. No Simples não retido a alíquota é da própria ADN: informar `pAliq` dá E0625.
            'tribMun' => ['tribISSQN' => 1, 'tpRetISSQN' => 1],
            // ME/EPP e MEI usam pTotTribSN (indTotTrib dá E0712). 0.001 e não 0: a lib testa `if ($valor)` e some com 0.0;
            // ela formata com 2 casas, então vai "0.00" no XML.
            'totTrib' => ['pTotTribSN' => 0.001],
        ];

        return new DpsData([
            '@attributes' => ['versao' => '1.01'],
            'infDPS' => [
                '@attributes' => ['Id' => self::idDaDps($nota)],
                'tpAmb' => $nota->ambiente === 'PRODUCAO' ? 1 : 2,
                // ISO8601 com fuso, como o schema exige.
                'dhEmi' => $agora->format('c'),
                'verAplic' => (string) config('fiscal.ver_proc'),
                'serie' => $nota->serie,
                'nDPS' => (string) $nota->numeroDps,
                'dCompet' => $agora->format('Y-m-d'),
                'tpEmit' => 1,
                'cLocEmi' => $e->codigoIbge,
                // IM e endereço do prestador NUNCA vão: E0120 e E0128 (o prestador é o emitente, o cadastro está na ADN).
                'prest' => ['CNPJ' => $e->cnpj, 'regTrib' => $regTrib],
                'toma' => $tomador,
                'serv' => $serv,
                'valores' => $valores,
            ],
        ]);
    }

    /**
     * O `opSimpNac` da NFS-e (1 não optante, 2 MEI, 3 ME/EPP) NÃO é o CRT da NF-e: tradução explícita, nunca o valor bruto.
     *
     * @return array{opSimpNac: int, regApTribSN: int, regEspTrib: int}
     */
    private function regimeDoPrestador(string $regime): array
    {
        return match ($regime) {
            'MEI' => ['opSimpNac' => 2, 'regApTribSN' => 1, 'regEspTrib' => 0],
            'SIMPLES' => ['opSimpNac' => 3, 'regApTribSN' => 1, 'regEspTrib' => 0],
            default => throw new RuntimeException("Regime \"{$regime}\" sem tradução para a NFS-e: o Regime Normal fica bloqueado."),
        };
    }

    /** Dinheiro em centavos inteiros para reais (a lib formata com 2 casas). */
    private function reais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    /** Sem caracteres de controle, espaços colapsados, corte no limite. O escape de `&` e `<` fica com a lib. */
    private function texto(string $valor, int $limite): string
    {
        $limpo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valor) ?? '';
        $limpo = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $limpo) ?? '';
        $limpo = trim((string) preg_replace('/\s+/u', ' ', $limpo));

        return mb_substr($limpo, 0, $limite);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter=MontadorDeDpsTest` e `vendor/bin/pint` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; Pint e PHPStan limpos. Se alguma asserção de elemento falhar por causa da forma como a lib serializa (ex.: `<end` aparecendo em outro grupo), ler o XML impresso no erro e ajustar a asserção ao nome real do elemento, sem relaxar a regra (IM e endereço do prestador continuam ausentes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal/Infrastructure/Nfse/MontadorDeDps.php backend/tests/Unit/Fiscal/Nfse/MontadorDeDpsTest.php
git commit -m "feat(nfse): montagem da DPS v1.01" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

### Task 7: Motor da ADN (interpretação, evento de cancelamento, certificado e `NfseNacionalEmissor`)

**Files:**
- Create: `backend/app/Modules/Fiscal/Infrastructure/Nfse/InterpretadorDeNfse.php`, `MontadorDeEventoDeCancelamento.php`, `NfseNacionalEmissor.php`
- Modify: `backend/app/Modules/Fiscal/Infrastructure/NfePhp/CertificadoStore.php` (novo `pfxESenha`)
- Modify: `backend/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` (binding)
- Create: `backend/tests/Fixtures/nfse_nacional_autorizada.xml` (cópia da fixture anonimizada da skill)
- Test: `backend/tests/Unit/Fiscal/Nfse/InterpretadorDeNfseTest.php`, `MontadorDeEventoDeCancelamentoTest.php`, `backend/tests/Unit/Fiscal/CertificadoStoreTest.php`

**Interfaces:**
- Consumes: `EmissorDeNfse`, `ResultadoDeEmissaoNfse`, `ResultadoDeConsultaNfse`, `ResultadoDeCancelamento` (Tarefa 5); `MontadorDeDps` (Tarefa 6); `Nfse\Nfse`, `Nfse\Http\NfseContext`, `Nfse\Enums\{TipoAmbiente,CodigoStatus}`, `Nfse\Dto\Nfse\{NfseData,PedRegEventoData}`, `Nfse\Dto\Http\RegistroEventoResponse`, `Nfse\Http\Exceptions\NfseApiException`, `Nfse\Xml\{NfseXmlParser,EventosXmlBuilder}`.
- Produces:
  - `CertificadoStore::pfxESenha(Emitente $emitente): array{pfx: string, senha: string}` (bytes do PFX decifrados em memória e a senha).
  - `InterpretadorDeNfse`: `chaveDe(?string $id): ?string` (50 dígitos sem `NFS`, ou nulo); `emissao(NfseData $r): ResultadoDeEmissaoNfse`; `consulta(?NfseData $r, bool $cancelada): ResultadoDeConsultaNfse` (nulo vira `Erro`); `rejeicao(NfseApiException $e): ?ResultadoDeEmissaoNfse` (só quando a ADN devolveu `erros`); `existeEventoDeCancelamento(callable $consultarEvento): bool`; `cancelamento(RegistroEventoResponse $r): ResultadoDeCancelamento` (lança `RuntimeException` sem `eventoXmlGZipB64`); `codigoDoMotivo(string $texto): string` (`'1'`, `'2'` ou `'9'`).
  - `MontadorDeEventoDeCancelamento::montar(string $chave, string $justificativa, string $ambiente, string $cnpjAutor): PedRegEventoData`.
  - `NfseNacionalEmissor implements EmissorDeNfse`, ligado no container.

- [ ] **Step 1: Copiar a fixture e escrever os testes que falham**

Run (PowerShell, a partir da raiz do repositório): `Copy-Item "$HOME\.claude\skills\br-fiscal-note-emission\assets\pdf-layouts\danfse\nfse_nacional_autorizada.xml" backend\tests\Fixtures\nfse_nacional_autorizada.xml`

`backend/tests/Unit/Fiscal/Nfse/InterpretadorDeNfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Infrastructure\Nfse\InterpretadorDeNfse;
use Nfse\Dto\Http\RegistroEventoResponse;
use Nfse\Dto\Nfse\NfseData;
use Nfse\Http\Exceptions\NfseApiException;
use Nfse\Xml\NfseXmlParser;
use RuntimeException;
use Tests\TestCase;

class InterpretadorDeNfseTest extends TestCase
{
    private const CHAVE = '31305072212345678000195000000000000226096821694069';

    private function nfse(): NfseData
    {
        $xml = (string) file_get_contents(base_path('tests/Fixtures/nfse_nacional_autorizada.xml'));
        $dados = (new NfseXmlParser)->parse($xml);
        $dados->nfseXml = $xml;

        return $dados;
    }

    public function test_chave_de_50_digitos_sem_o_prefixo_nfs(): void
    {
        $i = new InterpretadorDeNfse;

        $this->assertSame(self::CHAVE, $i->chaveDe('NFS'.self::CHAVE));
        $this->assertSame(self::CHAVE, $i->chaveDe(self::CHAVE));
        $this->assertNull($i->chaveDe('NFS123'));
        $this->assertNull($i->chaveDe(null));
    }

    public function test_emissao_autorizada_devolve_chave_numero_e_xml(): void
    {
        $r = (new InterpretadorDeNfse)->emissao($this->nfse());

        $this->assertSame(StatusNota::Autorizada, $r->status);
        $this->assertSame(self::CHAVE, $r->chave);
        $this->assertSame(50, strlen((string) $r->chave));
        $this->assertSame('100', $r->cstat);
        $this->assertNotNull($r->numeroNfse);
        $this->assertStringContainsString('<NFSe', (string) $r->xmlAutorizado);
    }

    public function test_emissao_sem_xml_ou_sem_chave_valida_vira_erro_e_nunca_autorizada(): void
    {
        $semXml = $this->nfse();
        $semXml->nfseXml = null;

        $this->assertSame(StatusNota::Erro, (new InterpretadorDeNfse)->emissao($semXml)->status);
    }

    public function test_status_nao_reconhecido_vira_erro(): void
    {
        $dados = $this->nfse();
        $dados->infNfse->codigoStatus = null;

        $r = (new InterpretadorDeNfse)->emissao($dados);

        $this->assertSame(StatusNota::Erro, $r->status);
        $this->assertStringContainsString('status', (string) $r->mensagemErro);
    }

    public function test_consulta_nula_vira_erro_nunca_nao_encontrada(): void
    {
        $r = (new InterpretadorDeNfse)->consulta(null, false);

        $this->assertSame(SituacaoNaSefaz::Erro, $r->situacao);
    }

    public function test_consulta_autorizada_e_cancelada(): void
    {
        $i = new InterpretadorDeNfse;

        $autorizada = $i->consulta($this->nfse(), false);
        $this->assertSame(SituacaoNaSefaz::Autorizada, $autorizada->situacao);
        $this->assertSame(self::CHAVE, $autorizada->chave);

        $this->assertSame(SituacaoNaSefaz::Cancelada, $i->consulta($this->nfse(), true)->situacao);
    }

    public function test_rejeicao_so_quando_a_adn_devolveu_erros(): void
    {
        $i = new InterpretadorDeNfse;
        $erro = new \Nfse\Dto\Http\MensagemProcessamentoDto(mensagem: 'IM não deve ser informado', codigo: 'E0120', descricao: 'IM do prestador');

        $r = $i->rejeicao(NfseApiException::requestError('HTTP 400', 400, '{}', [$erro]));

        $this->assertSame(StatusNota::Rejeitada, $r?->status);
        $this->assertSame('E0120', $r?->cstat);
        $this->assertStringContainsString('E0120', (string) $r?->motivo);
        $this->assertNull($i->rejeicao(NfseApiException::requestError('timeout', 0)));
    }

    public function test_evento_de_cancelamento_existe_inexistente_e_incerto(): void
    {
        $i = new InterpretadorDeNfse;

        $this->assertTrue($i->existeEventoDeCancelamento(fn () => 'ok'));
        $this->assertFalse($i->existeEventoDeCancelamento(fn () => throw NfseApiException::requestError('x', 404, '{"mensagem":"nenhum evento"}')));

        foreach ([[404, '<html>nao existe</html>'], [404, null], [500, '{"x":1}']] as [$codigo, $corpo]) {
            try {
                $i->existeEventoDeCancelamento(fn () => throw NfseApiException::requestError('x', $codigo, $corpo));
                $this->fail('Deveria propagar a incerteza.');
            } catch (NfseApiException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cancelamento_exige_o_xml_do_evento(): void
    {
        $i = new InterpretadorDeNfse;
        $ok = $i->cancelamento(new RegistroEventoResponse(eventoXmlGZipB64: base64_encode((string) gzencode('<evento>x</evento>'))));

        $this->assertTrue($ok->aceito);
        $this->assertNull($ok->protocolo);
        $this->assertSame('101101', $ok->cstat);
        $this->assertSame('<evento>x</evento>', $ok->xmlCancelamento);

        $this->expectException(RuntimeException::class);
        $i->cancelamento(new RegistroEventoResponse(eventoXmlGZipB64: null));
    }

    public function test_classifica_o_motivo_do_cancelamento(): void
    {
        $i = new InterpretadorDeNfse;

        $this->assertSame('1', $i->codigoDoMotivo('Erro na emissão da nota'));
        $this->assertSame('2', $i->codigoDoMotivo('SERVIÇO NÃO PRESTADO ao cliente'));
        $this->assertSame('2', $i->codigoDoMotivo('servico nao realizado'));
        $this->assertSame('9', $i->codigoDoMotivo('O cliente desistiu da compra'));
    }
}
```

`backend/tests/Unit/Fiscal/Nfse/MontadorDeEventoDeCancelamentoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use App\Modules\Fiscal\Infrastructure\Nfse\MontadorDeEventoDeCancelamento;
use Nfse\Xml\EventosXmlBuilder;
use Tests\TestCase;

class MontadorDeEventoDeCancelamentoTest extends TestCase
{
    private const CHAVE = '31305072212345678000195000000000000226096821694069';

    public function test_evento_101101_com_chave_de_50_digitos_autor_e_motivo(): void
    {
        $evento = (new MontadorDeEventoDeCancelamento)->montar(self::CHAVE, 'Erro na emissão da nota fiscal', 'HOMOLOGACAO', '11444777000161');

        $xml = (new EventosXmlBuilder)->buildPedRegEvento($evento);

        $this->assertStringContainsString('<chNFSe>'.self::CHAVE.'</chNFSe>', $xml);
        $this->assertStringContainsString('<CNPJAutor>11444777000161</CNPJAutor>', $xml);
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<xDesc>Cancelamento de NFS-e</xDesc>', $xml);
        $this->assertStringContainsString('<cMotivo>1</cMotivo>', $xml);
        $this->assertStringContainsString('<xMotivo>Erro na emissão da nota fiscal</xMotivo>', $xml);
    }

    public function test_aceita_a_chave_com_prefixo_e_usa_producao(): void
    {
        $evento = (new MontadorDeEventoDeCancelamento)->montar('NFS'.self::CHAVE, 'O cliente desistiu da compra', 'PRODUCAO', '11444777000161');

        $xml = (new EventosXmlBuilder)->buildPedRegEvento($evento);

        $this->assertStringContainsString('<chNFSe>'.self::CHAVE.'</chNFSe>', $xml);
        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $xml);
        $this->assertStringContainsString('<cMotivo>9</cMotivo>', $xml);
    }
}
```

`backend/tests/Unit/Fiscal/CertificadoStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Infrastructure\NfePhp\CertificadoStore;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class CertificadoStoreTest extends TestCase
{
    public function test_devolve_o_pfx_e_a_senha_decifrados(): void
    {
        $emitente = new Emitente([
            'certificado_pfx_encrypted' => Crypt::encryptString(base64_encode('BYTES-DO-PFX')),
            'certificado_senha_encrypted' => Crypt::encryptString('segredo'),
        ]);
        $emitente->forceFill([
            'certificado_pfx_encrypted' => Crypt::encryptString(base64_encode('BYTES-DO-PFX')),
            'certificado_senha_encrypted' => Crypt::encryptString('segredo'),
        ]);

        $this->assertSame(['pfx' => 'BYTES-DO-PFX', 'senha' => 'segredo'], (new CertificadoStore)->pfxESenha($emitente));
    }

    public function test_sem_certificado_bloqueia(): void
    {
        $this->expectException(EmissaoBloqueadaException::class);

        (new CertificadoStore)->pfxESenha(new Emitente);
    }
}
```

> Se `Emitente` não tiver essas colunas em `$fillable`, o `forceFill` do teste já as preenche (o primeiro argumento do construtor é redundante e inofensivo).

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="InterpretadorDeNfseTest|MontadorDeEventoDeCancelamentoTest|CertificadoStoreTest"`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`CertificadoStore.php`: trocar o corpo de `paraEmitente` e acrescentar `pfxESenha`:

```php
    /** @return array{pfx: string, senha: string} */
    public function pfxESenha(Emitente $emitente): array
    {
        if ($emitente->certificado_pfx_encrypted === null || $emitente->certificado_senha_encrypted === null) {
            throw new EmissaoBloqueadaException('FISCAL_CERTIFICADO_INVALIDO', 'Envie o certificado A1 em Configurações > Fiscal.');
        }

        $pfx = base64_decode(Crypt::decryptString($emitente->certificado_pfx_encrypted), true);
        if ($pfx === false) {
            throw new EmissaoBloqueadaException('FISCAL_CERTIFICADO_INVALIDO', 'O certificado armazenado está corrompido. Envie-o novamente.');
        }

        return ['pfx' => $pfx, 'senha' => Crypt::decryptString($emitente->certificado_senha_encrypted)];
    }

    public function paraEmitente(Emitente $emitente): Certificate
    {
        ['pfx' => $pfx, 'senha' => $senha] = $this->pfxESenha($emitente);

        return Certificate::readPfx($pfx, $senha);
    }
```

`backend/app/Modules/Fiscal/Infrastructure/Nfse/InterpretadorDeNfse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Nfse;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use Illuminate\Support\Str;
use Nfse\Dto\Http\RegistroEventoResponse;
use Nfse\Dto\Nfse\NfseData;
use Nfse\Enums\CodigoStatus;
use Nfse\Http\Exceptions\NfseApiException;
use RuntimeException;

/** Traduz as respostas da `nfse-php` para os resultados do domínio. Nunca chuta AUTORIZADA nem "não encontrada". */
final class InterpretadorDeNfse
{
    /** cStat 100 a 107 (gerada, substituição, decisão judicial, avulsa, MEI): os únicos que valem como autorizada. */
    private const AUTORIZADOS = [
        CodigoStatus::NfseGerada, CodigoStatus::NfseSubstituicaoGerada, CodigoStatus::NfseDecisaoJudicial,
        CodigoStatus::NfseAvulsa, CodigoStatus::NfseMei,
    ];

    /** O `Id` do infNFSe vem com o prefixo "NFS" (um ID XML começa com letra); a chave são os 50 dígitos depois dele. */
    public function chaveDe(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (preg_match('/^NFS(\d{50})$/', $id, $m) === 1) {
            return $m[1];
        }

        return preg_match('/^\d{50}$/', $id) === 1 ? $id : null;
    }

    public function emissao(NfseData $r): ResultadoDeEmissaoNfse
    {
        $status = $r->infNfse?->codigoStatus;
        if ($status === null || ! in_array($status, self::AUTORIZADOS, true)) {
            return ResultadoDeEmissaoNfse::erro('NFS-e em status não reconhecido (cStat='.($status === null ? 'ausente' : (string) $status->value).'); não é possível classificar com segurança como autorizada.');
        }
        $chave = $this->chaveDe($r->infNfse?->id);
        $numero = $r->infNfse?->numeroNfse;
        if ($chave === null || $numero === null || $r->nfseXml === null) {
            return ResultadoDeEmissaoNfse::erro('A ADN autorizou a NFS-e, mas a resposta veio sem a chave de 50 dígitos, o número ou o XML. Consulte a nota no portal nacional antes de emitir de novo.');
        }

        return ResultadoDeEmissaoNfse::autorizada($chave, $numero, $r->nfseXml, (string) $status->value);
    }

    /** `consultar` da lib devolve nulo tanto para "não encontrada" quanto para falha de API: aqui isso é incerteza (Erro). */
    public function consulta(?NfseData $r, bool $cancelada): ResultadoDeConsultaNfse
    {
        if ($r === null) {
            return ResultadoDeConsultaNfse::erro('NFS-e não encontrada ou falha ao consultar (a biblioteca não distingue os dois casos).');
        }
        if ($cancelada) {
            return ResultadoDeConsultaNfse::cancelada($this->chaveDe($r->infNfse?->id));
        }
        $emissao = $this->emissao($r);

        return $emissao->status->value === 'AUTORIZADA'
            ? ResultadoDeConsultaNfse::autorizada((string) $emissao->chave, (string) $emissao->numeroNfse, (string) $emissao->xmlAutorizado, (string) $emissao->cstat)
            : ResultadoDeConsultaNfse::erro((string) $emissao->mensagemErro);
    }

    /** Só é rejeição quando a ADN respondeu com `erros`; o resto (rede, timeout) é incerteza e quem chama decide. */
    public function rejeicao(NfseApiException $e): ?ResultadoDeEmissaoNfse
    {
        $erros = $e->getErrors();
        if ($erros === []) {
            return null;
        }
        $codigo = trim((string) ($erros[0]->codigo ?? ''));
        $motivo = implode(' | ', array_map(
            static fn ($m): string => trim(($m->codigo !== null && $m->codigo !== '' ? $m->codigo.': ' : '').($m->descricao ?? $m->mensagem ?? '')),
            $erros,
        ));

        return ResultadoDeEmissaoNfse::rejeitada($codigo === '' ? 'ADN' : $codigo, mb_substr($motivo, 0, 500));
    }

    /**
     * `$consultarEvento` faz GET /nfse/{chave}/eventos/101101/1 e lança `NfseApiException` fora de 2xx.
     * 2xx = há evento; 404 COM corpo JSON da API = não há; qualquer outra coisa (404 em HTML, sem corpo, 5xx) é incerteza e sobe.
     *
     * @param  callable(): mixed  $consultarEvento
     */
    public function existeEventoDeCancelamento(callable $consultarEvento): bool
    {
        try {
            $consultarEvento();

            return true;
        } catch (NfseApiException $e) {
            $corpo = $e->getRawResponse();
            if ($e->getCode() === 404 && $corpo !== null && is_array(json_decode($corpo, true))) {
                return false;
            }

            throw $e;
        }
    }

    /** Sem o XML do evento o cancelamento NÃO está confirmado: a nota segue CANCELANDO e a reconciliação decide. */
    public function cancelamento(RegistroEventoResponse $r): ResultadoDeCancelamento
    {
        if ($r->eventoXmlGZipB64 === null || $r->eventoXmlGZipB64 === '') {
            throw new RuntimeException('Cancelamento não confirmado: resposta da ADN sem o XML do evento.');
        }
        $xml = gzdecode((string) base64_decode($r->eventoXmlGZipB64, true));

        return new ResultadoDeCancelamento(true, '101101', 'Cancelamento de NFS-e registrado', null, $xml === false ? $r->eventoXmlGZipB64 : $xml);
    }

    /** TSCodJustCanc: 1 erro na emissão, 2 serviço não prestado, 9 outros (padrão seguro; o texto inteiro vai em xMotivo). */
    public function codigoDoMotivo(string $texto): string
    {
        // ascii() antes de lower(): strtolower não lida com "Ç" e "Ã" maiúsculos.
        $m = strtolower(Str::ascii($texto));
        if (str_contains($m, 'erro') || str_contains($m, 'engano') || str_contains($m, 'equivoco')) {
            return '1';
        }
        if (str_contains($m, 'nao prestado') || str_contains($m, 'nao realizado') || str_contains($m, 'nao executado')) {
            return '2';
        }

        return '9';
    }
}
```

`backend/app/Modules/Fiscal/Infrastructure/Nfse/MontadorDeEventoDeCancelamento.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Nfse;

use Nfse\Dto\Nfse\PedRegEventoData;

/** Pedido de registro do evento 101101 (cancelamento), versão 1.01. */
final class MontadorDeEventoDeCancelamento
{
    public function __construct(private readonly InterpretadorDeNfse $interpretador = new InterpretadorDeNfse) {}

    public function montar(string $chave, string $justificativa, string $ambiente, string $cnpjAutor): PedRegEventoData
    {
        $chave50 = preg_replace('/^NFS/', '', $chave) ?? $chave;

        return new PedRegEventoData([
            'versao' => '1.01',
            'infPedReg' => [
                'tpAmb' => $ambiente === 'PRODUCAO' ? 1 : 2,
                'verAplic' => (string) config('fiscal.ver_proc'),
                'dhEvento' => now()->setTimezone('America/Sao_Paulo')->format('c'),
                'chNFSe' => $chave50,
                'CNPJAutor' => $cnpjAutor,
                'tipoEvento' => '101101',
                'e101101' => [
                    'xDesc' => 'Cancelamento de NFS-e',
                    'cMotivo' => $this->interpretador->codigoDoMotivo($justificativa),
                    'xMotivo' => $justificativa,
                ],
            ],
        ]);
    }
}
```

`backend/app/Modules/Fiscal/Infrastructure/Nfse/NfseNacionalEmissor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Nfse;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Infrastructure\NfePhp\CertificadoStore;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Nfse\Enums\TipoAmbiente;
use Nfse\Http\Exceptions\NfseApiException;
use Nfse\Http\NfseContext;
use Nfse\Nfse;
use Throwable;

/**
 * Fala com a ADN (Sistema Nacional NFS-e) pela `nfse-nacional/nfse-php`, com o certificado A1 do próprio CNPJ.
 * Sem teste automatizado contra a ADN real (exige certificado e município aderido): coberto pelo roteiro de homologação.
 * ATENÇÃO: a lib desliga a verificação TLS nos próprios clientes HTTP (pendência de segurança, ver TAREFAS.md).
 */
final class NfseNacionalEmissor implements EmissorDeNfse
{
    public function __construct(
        private readonly CertificadoStore $certificados,
        private readonly MontadorDeDps $montador,
        private readonly InterpretadorDeNfse $interpretador,
        private readonly MontadorDeEventoDeCancelamento $eventos,
    ) {}

    public function consultarDps(Emitente $emitente, NotaParaNfse $nota): ResultadoDeConsultaNfse
    {
        $contribuinte = $this->nfse($emitente, $nota->ambiente)->contribuinte();
        $idDps = MontadorDeDps::idDaDps($nota);
        try {
            if (! $contribuinte->verificarDps($idDps)) {
                return ResultadoDeConsultaNfse::naoEncontrada();
            }
            $chave = $this->interpretador->chaveDe($contribuinte->consultarDps($idDps)->chaveAcesso);
        } catch (Throwable $e) {
            return ResultadoDeConsultaNfse::erro('Não foi possível confirmar se a DPS já existe na ADN: '.mb_substr($e->getMessage(), 0, 300));
        }
        if ($chave === null) {
            return ResultadoDeConsultaNfse::erro('A ADN conhece a DPS, mas não devolveu uma chave de acesso de 50 dígitos.');
        }

        return $this->consultarPorChave($emitente, $chave, $nota->ambiente);
    }

    public function emitir(Emitente $emitente, NotaParaNfse $nota): ResultadoDeEmissaoNfse
    {
        $contribuinte = $this->nfse($emitente, $nota->ambiente)->contribuinte();
        try {
            $resultado = $contribuinte->emitir($this->montador->montar($nota));
        } catch (NfseApiException $e) {
            return $this->interpretador->rejeicao($e)
                ?? ResultadoDeEmissaoNfse::erro('Falha de comunicação com a ADN ao emitir. Tente novamente: o número foi preservado. ('.mb_substr($e->getMessage(), 0, 200).')');
        }

        return $this->interpretador->emissao($resultado);
    }

    public function consultarPorChave(Emitente $emitente, string $chave, string $ambiente): ResultadoDeConsultaNfse
    {
        $contribuinte = $this->nfse($emitente, $ambiente)->contribuinte();
        try {
            $dados = $contribuinte->consultar($chave);
            if ($dados === null) {
                return $this->interpretador->consulta(null, false);
            }
            // Cancelamento é um evento separado (101101), não um cStat do corpo da NFS-e.
            $cancelada = $this->interpretador->existeEventoDeCancelamento(fn () => $contribuinte->consultarEvento($chave, 101101, 1));
        } catch (Throwable $e) {
            return ResultadoDeConsultaNfse::erro('Não foi possível confirmar se a NFS-e está cancelada: '.mb_substr($e->getMessage(), 0, 300));
        }

        return $this->interpretador->consulta($dados, $cancelada);
    }

    public function cancelar(Emitente $emitente, string $chave, string $justificativa, string $ambiente): ResultadoDeCancelamento
    {
        $tenant = Tenant::query()->findOrFail($emitente->tenant_id);
        $evento = $this->eventos->montar($chave, $justificativa, $ambiente, preg_replace('/\D/', '', $tenant->cnpj) ?? '');
        try {
            $resposta = $this->nfse($emitente, $ambiente)->contribuinte()->cancelar($evento);
        } catch (NfseApiException $e) {
            $recusa = $this->interpretador->rejeicao($e);
            if ($recusa === null) {
                throw $e; // rede/timeout: a nota permanece CANCELANDO e a reconciliação decide
            }

            return ResultadoDeCancelamento::recusado((string) $recusa->cstat, (string) $recusa->motivo);
        }

        return $this->interpretador->cancelamento($resposta);
    }

    /** O PFX decifrado fica só em memória: a lib o grava num temporário 0600 e o apaga no destrutor. */
    private function nfse(Emitente $emitente, string $ambiente): Nfse
    {
        ['pfx' => $pfx, 'senha' => $senha] = $this->certificados->pfxESenha($emitente);

        return new Nfse(new NfseContext(
            ambiente: $ambiente === 'PRODUCAO' ? TipoAmbiente::Producao : TipoAmbiente::Homologacao,
            certificatePath: null,
            certificatePassword: $senha,
            codigoMunicipio: (string) $emitente->codigo_ibge,
            certificateContent: $pfx,
        ));
    }
}
```

`FiscalServiceProvider.php`: adicionar os `use` de `EmissorDeNfse` e `NfseNacionalEmissor` e, em `register()`: `$this->app->bind(EmissorDeNfse::class, NfseNacionalEmissor::class);`.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter="InterpretadorDeNfseTest|MontadorDeEventoDeCancelamentoTest|CertificadoStoreTest"` e `vendor/bin/pint` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; Pint e PHPStan limpos. Pontos a conferir se algo falhar: (a) os nomes das propriedades de `NfseData`/`InfNfseData` (`infNfse`, `numeroNfse`, `codigoStatus`, `nfseXml`) e se são atribuíveis nos testes (se forem `readonly`, construir o DTO por array com `new NfseData([...])`); (b) o parser pode não preencher `nfseXml`, por isso o teste o atribui; (c) construtor nomeado de `RegistroEventoResponse` e `MensagemProcessamentoDto`; (d) PHPStan pode reclamar de tipos da lib: usar `@var` pontual, nunca `@phpstan-ignore` sem comentário do motivo.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Fixtures/nfse_nacional_autorizada.xml backend/tests/Unit/Fiscal
git commit -m "feat(nfse): emissor da ADN, interpretação das respostas e evento de cancelamento" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 8: Emissão pela fila, resultado e reconciliação

**Files:**
- Create: `backend/app/Modules/Fiscal/Application/Actions/AplicarResultadoDaNfse.php`, `ConciliarNfse.php`
- Modify: `backend/app/Modules/Fiscal/Application/Jobs/EmitirNfseJob.php` (preencher o esqueleto)
- Modify: `backend/app/Modules/Fiscal/Application/Actions/ConciliarNota.php`, `AplicarResultadoDoCancelamento.php`
- Modify: `backend/tests/Fixtures/CenarioDeNfse.php` (helper `notaDeNfsePronta`)
- Test: `backend/tests/Feature/Fiscal/EmissaoNfseTest.php`, `backend/tests/Feature/Fiscal/ReconciliarNfseTest.php`

**Interfaces:**
- Consumes: `EmissorDeNfse`, resultados e `EmissorDeNfseFake` (Tarefa 5); `NotaParaNfse::deNota` (Tarefa 5); `IniciarEmissao` despachando `EmitirNfseJob` (Tarefa 4).
- Produces:
  - `AplicarResultadoDaNfse::executar(string $notaId, ResultadoDeEmissaoNfse $r): Nota` (só opera em `PROCESSANDO`; grava `chave`, `numero_nfse`, `xml_autorizado`, `cstat`, `motivo`, `emitido_em` ao autorizar) e `aplicarConsulta(string $notaId, ResultadoDeConsultaNfse $r): Nota` (opera em `PROCESSANDO`/`ERRO`; `Autorizada` concilia, `Cancelada` cancela).
  - `EmitirNfseJob::handle(EmissorDeNfse $emissor, AplicarResultadoDaNfse $aplicar, TenantContext $contexto)`.
  - `ConciliarNfse::executar(Nota $nota): Nota`; `ConciliarNota` delega notas `DPS` a ele.
  - `AplicarResultadoDoCancelamento::confirmarCancelamento(string $notaId, string $detalhe): Nota`.
  - Fixture: `CenarioDeNfse::notaDeNfsePronta(StatusNota $status, array $campos = []): Nota` (rascunho preparado, com `numero=1`, `serie='1'`, `ambiente='HOMOLOGACAO'`, `iniciada_em=agora` e o status pedido).

- [ ] **Step 1: Acrescentar o helper à fixture**

Em `CenarioDeNfse.php`, adicionar `use App\Modules\Fiscal\Application\Emissao\PreparadorDaNotaDeServico;` e `use App\Modules\Fiscal\Domain\Enums\StatusNota;` e o método:

```php
    /**
     * Rascunho preparado (snapshot do tomador e do serviço) já no status pedido, com número alocado.
     *
     * @param  array<string, mixed>  $campos
     */
    protected function notaDeNfsePronta(StatusNota $status, array $campos = []): Nota
    {
        $nota = $this->rascunhoDeNfse();
        app(PreparadorDaNotaDeServico::class)->preparar($nota, $this->emitente);
        $nota->forceFill([
            'status' => $status, 'numero' => 1, 'serie' => '1', 'ambiente' => 'HOMOLOGACAO', 'iniciada_em' => now(),
        ] + $campos)->save();

        return $nota->refresh();
    }
```

- [ ] **Step 2: Escrever os testes que falham**

`backend/tests/Feature/Fiscal/EmissaoNfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use RuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\Fixtures\EmissorDeNfseFake;
use Tests\TestCase;

class EmissaoNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    private EmissorDeNfseFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
        $this->emissor = new EmissorDeNfseFake;
        $this->app->instance(EmissorDeNfse::class, $this->emissor);
    }

    private function emitir(): \App\Modules\Fiscal\Domain\Models\Nota
    {
        $nota = $this->rascunhoDeNfse();
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/emitir")->assertStatus(202);

        return $nota->refresh();
    }

    public function test_a_chave_do_fake_tem_50_digitos(): void
    {
        $this->assertSame(50, strlen(EmissorDeNfseFake::CHAVE));
        $this->assertTrue(ctype_digit(EmissorDeNfseFake::CHAVE));
    }

    public function test_emissao_autorizada_grava_chave_numero_oficial_xml_e_conta_na_cota(): void
    {
        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame(EmissorDeNfseFake::CHAVE, $nota->chave);
        $this->assertSame('1', $nota->numero_nfse);
        $this->assertSame('100', $nota->cstat);
        $this->assertNotNull($nota->emitido_em);
        $nota->makeVisible('xml_autorizado');
        $this->assertSame('<NFSe>fake</NFSe>', $nota->xml_autorizado);
        $this->assertSame(['consultarDps', 'emitir'], $this->emissor->chamadas);
        $this->assertSame(1, (new ContadorDeDocumentosMes)->contar($this->tenant));
        $this->assertSame('AUTORIZADA', $nota->eventos()->latest('id')->reorder('id', 'desc')->first()?->status_para);
    }

    public function test_rejeicao_da_adn_vira_rejeitada_e_a_nota_volta_a_ser_editavel(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissaoNfse::rejeitada('E0625', 'Alíquota não permitida'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Rejeitada, $nota->status);
        $this->assertSame('E0625', $nota->cstat);
        $this->assertSame('Alíquota não permitida', $nota->motivo);
        $this->assertTrue($nota->status->editavel());
        $this->assertSame(0, (new ContadorDeDocumentosMes)->contar($this->tenant));
    }

    public function test_falha_tecnica_vira_erro(): void
    {
        $this->emissor->falharEmEmitir = new RuntimeException('boom');

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('Falha técnica ao emitir', (string) $nota->mensagem_erro);
    }

    public function test_erro_de_comunicacao_preserva_o_numero_e_o_retry_consulta_a_dps_antes_de_reenviar(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissaoNfse::erro('Falha de comunicação com a ADN'));
        $nota = $this->emitir();
        $this->assertSame(StatusNota::Erro, $nota->status);
        $numero = $nota->numero;

        // A resposta se perdeu, mas a ADN registrou a DPS: o retry concilia sem enviar de novo.
        $this->emissor->chamadas = [];
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '55', '<NFSe>ja-estava</NFSe>'));
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/emitir")->assertStatus(202);

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame($numero, $nota->numero);
        $this->assertSame('55', $nota->numero_nfse);
        $this->assertSame(['consultarDps'], $this->emissor->chamadas);
    }

    public function test_dps_ja_existente_na_adn_nunca_vira_segunda_nota(): void
    {
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '9', '<NFSe>x</NFSe>'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('9', $nota->numero_nfse);
        $this->assertNotContains('emitir', $this->emissor->chamadas);
    }

    public function test_consulta_da_dps_com_incerteza_vira_erro_e_nao_reenvia(): void
    {
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::erro('ADN fora do ar'));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertStringContainsString('ADN fora do ar', (string) $nota->mensagem_erro);
        $this->assertNotContains('emitir', $this->emissor->chamadas);
    }

    public function test_excecao_na_consulta_da_dps_tambem_nao_reenvia(): void
    {
        $this->emissor->falharEmConsultar = new RuntimeException('timeout');

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Erro, $nota->status);
        $this->assertNotContains('emitir', $this->emissor->chamadas);
    }

    public function test_dps_encontrada_ja_cancelada_cancela_a_nota(): void
    {
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::cancelada(EmissorDeNfseFake::CHAVE));

        $nota = $this->emitir();

        $this->assertSame(StatusNota::Cancelada, $nota->status);
    }
}
```

`backend/tests/Feature/Fiscal/ReconciliarNfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\Fixtures\EmissorDeNfseFake;
use Tests\TestCase;

class ReconciliarNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    private EmissorDeNfseFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-07 12:00:00');
        $this->montarCenarioNfse();
        $this->emissor = new EmissorDeNfseFake;
        $this->app->instance(EmissorDeNfse::class, $this->emissor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function reconciliar(): void
    {
        $this->artisan('fiscal:reconciliar-processando')->assertSuccessful();
    }

    private function processando(int $minutosAtras): Nota
    {
        return $this->notaDeNfsePronta(StatusNota::Processando, ['iniciada_em' => now()->subMinutes($minutosAtras)]);
    }

    private function cancelando(int $minutosAtras): Nota
    {
        $nota = $this->notaDeNfsePronta(StatusNota::Cancelando, [
            'chave' => EmissorDeNfseFake::CHAVE, 'xml_autorizado' => '<NFSe>x</NFSe>', 'cancelamento_solicitado_em' => now()->subMinutes($minutosAtras),
            'cancelamento_justificativa' => 'Erro na emissão da nota fiscal',
        ]);

        return $nota;
    }

    public function test_dps_autorizada_na_adn_concilia_a_nota(): void
    {
        $nota = $this->processando(30);
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '31', '<NFSe>x</NFSe>'));

        $this->reconciliar();

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('31', $nota->numero_nfse);
        $this->assertSame(EmissorDeNfseFake::CHAVE, $nota->chave);
    }

    public function test_dps_nao_encontrada_vira_erro_so_depois_do_prazo(): void
    {
        $velha = $this->processando(30);
        $this->reconciliar();
        $this->assertSame(StatusNota::Erro, $velha->refresh()->status);
        $this->assertStringContainsString('A ADN não recebeu a DPS', (string) $velha->mensagem_erro);

        $nova = $this->processando(2);
        $this->reconciliar();
        $this->assertSame(StatusNota::Processando, $nova->refresh()->status);
    }

    public function test_incerteza_na_consulta_mantem_a_nota_processando(): void
    {
        $nota = $this->processando(30);
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::erro('ADN fora do ar'));

        $this->reconciliar();

        $this->assertSame(StatusNota::Processando, $nota->refresh()->status);
    }

    public function test_cancelamento_confirmado_na_adn_cancela_a_nota(): void
    {
        $nota = $this->cancelando(2);
        $this->emissor->enfileirarConsultaPorChave(ResultadoDeConsultaNfse::cancelada(EmissorDeNfseFake::CHAVE));

        $this->reconciliar();

        $this->assertSame(StatusNota::Cancelada, $nota->refresh()->status);
        $this->assertNotNull($nota->cancelado_em);
    }

    public function test_ainda_autorizada_volta_so_depois_do_prazo_e_incerteza_mantem(): void
    {
        $velha = $this->cancelando(30);
        $this->reconciliar();
        $this->assertSame(StatusNota::Autorizada, $velha->refresh()->status);
        $this->assertStringContainsString('Cancelamento não confirmado', (string) $velha->cancelamento_motivo);

        $nova = $this->cancelando(2);
        $this->reconciliar();
        $this->assertSame(StatusNota::Cancelando, $nova->refresh()->status);

        $incerta = $this->cancelando(30);
        $this->emissor->enfileirarConsultaPorChave(ResultadoDeConsultaNfse::erro('ADN fora do ar'));
        $this->reconciliar();
        $this->assertSame(StatusNota::Cancelando, $incerta->refresh()->status);
    }
}
```

> O teste `test_ainda_autorizada...` roda duas reconciliações; a segunda reprocessa a nota `$velha` já `AUTORIZADA` (ignorada) e a `$nova`. Se a ordem das consultas enfileiradas no fake se misturar entre notas, enfileire por nota logo antes de cada `reconciliar()` ou rode uma nota por vez (apague as anteriores com `Nota::query()->delete()`).

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --filter="EmissaoNfseTest|ReconciliarNfseTest"`
Expected: FAIL (a nota fica `PROCESSANDO`: o job é um esqueleto).

- [ ] **Step 4: Implementar**

`AplicarResultadoDaNfse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use Illuminate\Support\Facades\DB;

/** Única porta de saída de uma emissão de NFS-e: toda mudança de status vinda da ADN passa por aqui, com lock. */
final class AplicarResultadoDaNfse
{
    public function executar(string $notaId, ResultadoDeEmissaoNfse $r): Nota
    {
        return DB::transaction(function () use ($notaId, $r): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Processando) {
                return $nota;
            }
            $anterior = $nota->status;
            $campos = ['status' => $r->status, 'cstat' => $r->cstat, 'motivo' => $r->motivo];
            if ($r->status === StatusNota::Autorizada) {
                $campos += ['chave' => $r->chave, 'numero_nfse' => $r->numeroNfse, 'xml_autorizado' => $r->xmlAutorizado, 'emitido_em' => now(), 'mensagem_erro' => null];
            }
            if ($r->status === StatusNota::Erro) {
                $campos['mensagem_erro'] = $r->mensagemErro;
            }
            $nota->forceFill($campos)->save();
            NotaEvento::registrar($nota, $anterior, $r->status, $r->mensagemErro ?? ($r->cstat !== null ? "cStat {$r->cstat}: {$r->motivo}" : null));

            return $nota;
        });
    }

    /** Reconciliação e retry: a ADN já tem a nota (autorizada ou cancelada), mesmo que a resposta do nosso envio tenha se perdido. */
    public function aplicarConsulta(string $notaId, ResultadoDeConsultaNfse $r): Nota
    {
        return DB::transaction(function () use ($notaId, $r): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if (! in_array($nota->status, [StatusNota::Processando, StatusNota::Erro], true)) {
                return $nota;
            }
            $anterior = $nota->status;

            if ($r->situacao === SituacaoNaSefaz::Autorizada) {
                $nota->forceFill([
                    'status' => StatusNota::Autorizada, 'chave' => $r->chave, 'numero_nfse' => $r->numeroNfse, 'cstat' => $r->cstat,
                    'motivo' => 'NFS-e gerada', 'xml_autorizado' => $r->xmlAutorizado, 'emitido_em' => $nota->emitido_em ?? now(), 'mensagem_erro' => null,
                ])->save();
                NotaEvento::registrar($nota, $anterior, StatusNota::Autorizada, 'Conciliada com a ADN');
            } elseif ($r->situacao === SituacaoNaSefaz::Cancelada) {
                $nota->forceFill(['status' => StatusNota::Cancelada, 'chave' => $r->chave ?? $nota->chave, 'cancelado_em' => now()])->save();
                NotaEvento::registrar($nota, $anterior, StatusNota::Cancelada, 'Cancelamento encontrado na ADN');
            }

            return $nota;
        });
    }
}
```

`EmitirNfseJob.php` (substituir o esqueleto):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use App\Modules\Fiscal\Application\Actions\AplicarResultadoDaNfse;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class EmitirNfseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $notaId, public readonly string $tenantId) {}

    public function handle(EmissorDeNfse $emissor, AplicarResultadoDaNfse $aplicar, TenantContext $contexto): void
    {
        // Num worker o contexto anterior é nulo; com fila `sync` restauramos o do chamador.
        $anterior = $contexto->id();
        $contexto->set($this->tenantId);
        try {
            $nota = Nota::query()->find($this->notaId);
            if ($nota === null || $nota->status !== StatusNota::Processando) {
                return;
            }
            $emitente = Emitente::query()->findOrFail($nota->emitente_id);
            $dto = NotaParaNfse::deNota($nota, $emitente, Tenant::query()->findOrFail($nota->tenant_id));

            // Idempotência: a DPS pode já existir na ADN (resposta perdida). Incerteza NUNCA reenvia.
            $consulta = $emissor->consultarDps($emitente, $dto);
            match ($consulta->situacao) {
                SituacaoNaSefaz::Autorizada, SituacaoNaSefaz::Cancelada => $aplicar->aplicarConsulta($nota->id, $consulta),
                SituacaoNaSefaz::Erro => $aplicar->executar($nota->id, ResultadoDeEmissaoNfse::erro(
                    ($consulta->mensagem ?? 'Não foi possível confirmar se a DPS já existe na ADN.').' Tente de novo: o número foi preservado.',
                )),
                SituacaoNaSefaz::NaoEncontrada => $aplicar->executar($nota->id, $emissor->emitir($emitente, $dto)),
            };
        } catch (Throwable $e) {
            report($e);
            $aplicar->executar($this->notaId, ResultadoDeEmissaoNfse::erro('Falha técnica ao emitir: '.mb_substr($e->getMessage(), 0, 300)));
        } finally {
            $contexto->set($anterior);
        }
    }
}
```

`AplicarResultadoDoCancelamento.php`: acrescentar o método (junto de `aplicarConsulta`):

```php
    /** NFS-e: a ADN confirmou o evento de cancelamento (consulta de eventos), mas a resposta do nosso pedido se perdeu. */
    public function confirmarCancelamento(string $notaId, string $detalhe): Nota
    {
        return DB::transaction(function () use ($notaId, $detalhe): Nota {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();
            if ($nota->status !== StatusNota::Cancelando) {
                return $nota;
            }
            $nota->forceFill(['status' => StatusNota::Cancelada, 'cancelado_em' => now()])->save();
            NotaEvento::registrar($nota, StatusNota::Cancelando, StatusNota::Cancelada, $detalhe);

            return $nota;
        });
    }
```

`ConciliarNfse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** Reconciliação da NFS-e com a ADN. Incerteza (consulta com erro) nunca muda o status. */
final class ConciliarNfse
{
    public function __construct(
        private readonly EmissorDeNfse $emissor,
        private readonly AplicarResultadoDaNfse $aplicar,
        private readonly AplicarResultadoDoCancelamento $cancelamento,
    ) {}

    public function executar(Nota $nota): Nota
    {
        if ($nota->status === StatusNota::Cancelando) {
            return $this->conciliarCancelamento($nota);
        }
        if ($nota->status !== StatusNota::Processando) {
            return $nota;
        }

        $vencida = $nota->iniciada_em === null
            || $nota->iniciada_em->lt(now()->subMinutes((int) config('fiscal.minutos_para_nunca_enviada')));
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        $dto = NotaParaNfse::deNota($nota, $emitente, Tenant::query()->findOrFail($nota->tenant_id));

        $consulta = $this->emissor->consultarDps($emitente, $dto);

        return match ($consulta->situacao) {
            SituacaoNaSefaz::Autorizada, SituacaoNaSefaz::Cancelada => $this->aplicar->aplicarConsulta($nota->id, $consulta),
            SituacaoNaSefaz::NaoEncontrada => $vencida
                ? $this->aplicar->executar($nota->id, ResultadoDeEmissaoNfse::erro('A ADN não recebeu a DPS. Emita novamente: o número foi preservado.'))
                : $nota,
            SituacaoNaSefaz::Erro => $nota,
        };
    }

    private function conciliarCancelamento(Nota $nota): Nota
    {
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        $consulta = $this->emissor->consultarPorChave($emitente, (string) $nota->chave, (string) $nota->ambiente);
        $vencida = $nota->cancelamento_solicitado_em === null
            || $nota->cancelamento_solicitado_em->lt(now()->subMinutes((int) config('fiscal.minutos_para_nunca_enviada')));

        return match ($consulta->situacao) {
            SituacaoNaSefaz::Cancelada => $this->cancelamento->confirmarCancelamento($nota->id, 'Cancelamento confirmado na ADN'),
            SituacaoNaSefaz::Autorizada => $vencida ? $this->cancelamento->naoConfirmado($nota->id) : $nota,
            SituacaoNaSefaz::NaoEncontrada, SituacaoNaSefaz::Erro => $nota,
        };
    }
}
```

`ConciliarNota.php`: adicionar `use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;`, o parâmetro `private readonly ConciliarNfse $nfse,` ao construtor (depois de `$cancelamento`) e, como primeira linha de `executar`:

```php
        if ($nota->modelo === ModeloDocumento::Dps) {
            return $this->nfse->executar($nota);
        }
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter="EmissaoNfseTest|ReconciliarNfseTest|ReconciliarNotasTest|EmitirNotaJobTest|IniciarEmissaoNfseTest"` e `vendor/bin/pint` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; Pint e PHPStan limpos. Se `EmissaoNfseTest::...conta_na_cota` falhar por `ContadorDeDocumentosMes` filtrar por modelo, abrir o contador e confirmar que conta qualquer modelo (só filtra por status e mês); não mudar a regra sem ledger.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Fixtures/CenarioDeNfse.php backend/tests/Feature/Fiscal/EmissaoNfseTest.php backend/tests/Feature/Fiscal/ReconciliarNfseTest.php
git commit -m "feat(nfse): emissão pela fila, resultado da ADN e reconciliação" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 9: Cancelamento da NFS-e

**Files:**
- Modify: `backend/app/Modules/Fiscal/Application/Actions/IniciarCancelamento.php`
- Modify: `backend/app/Modules/Fiscal/Application/Jobs/CancelarNotaJob.php`
- Test: `backend/tests/Feature/Fiscal/CancelamentoNfseTest.php`

**Interfaces:**
- Consumes: `EmissorDeNfse::cancelar`, `ResultadoDeCancelamento` (aceito sem protocolo), `AplicarResultadoDoCancelamento` (Tarefa 8), `EmissorDeNfseFake`.
- Produces: `IniciarCancelamento` aceita nota `DPS` autorizada (módulo `FISCAL_NFSE`; exige `chave` e `xml_autorizado`, **não** exige protocolo); `CancelarNotaJob::handle(EmissorDeNfe $emissor, EmissorDeNfse $emissorNfse, AplicarResultadoDoCancelamento $aplicar, TenantContext $contexto)` escolhe o emissor pelo modelo.

- [ ] **Step 1: Escrever o teste que falha**

`backend/tests/Feature/Fiscal/CancelamentoNfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeNfse;
use Tests\Fixtures\EmissorDeNfseFake;
use Tests\TestCase;

class CancelamentoNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Erro na emissão da nota fiscal';

    private EmissorDeNfseFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
        $this->emissor = new EmissorDeNfseFake;
        $this->app->instance(EmissorDeNfse::class, $this->emissor);
    }

    private function autorizada(): Nota
    {
        return $this->notaDeNfsePronta(StatusNota::Autorizada, [
            'chave' => EmissorDeNfseFake::CHAVE, 'numero_nfse' => '1', 'xml_autorizado' => '<NFSe>x</NFSe>', 'emitido_em' => now(), 'cstat' => '100',
        ]);
    }

    private function cancelar(Nota $nota, string $justificativa = self::JUSTIFICATIVA): \Illuminate\Testing\TestResponse
    {
        return $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/cancelar", ['justificativa' => $justificativa]);
    }

    public function test_cancela_a_nfse_pelo_evento_sem_exigir_protocolo(): void
    {
        $nota = $this->autorizada();
        $this->assertNull($nota->protocolo);

        $this->cancelar($nota)->assertStatus(202);

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertSame(self::JUSTIFICATIVA, $nota->cancelamento_justificativa);
        $this->assertSame('101101', $nota->cancelamento_cstat);
        $this->assertNull($nota->cancelamento_protocolo);
        $nota->makeVisible('xml_cancelamento');
        $this->assertSame('<evento>fake</evento>', $nota->xml_cancelamento);
        $this->assertSame(['cancelar'], $this->emissor->chamadas);
    }

    public function test_recusa_da_adn_volta_a_autorizada_com_o_motivo(): void
    {
        $this->emissor->enfileirarCancelamento(ResultadoDeCancelamento::recusado('E0301', 'Prazo de cancelamento expirado'));
        $nota = $this->autorizada();

        $this->cancelar($nota)->assertStatus(202);

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('E0301', $nota->cancelamento_cstat);
        $this->assertSame('Prazo de cancelamento expirado', $nota->cancelamento_motivo);
    }

    public function test_falha_de_rede_mantem_cancelando_para_a_reconciliacao(): void
    {
        $this->emissor->falharEmCancelar = new RuntimeException('timeout');
        $nota = $this->autorizada();

        $this->cancelar($nota)->assertStatus(202);

        $this->assertSame(StatusNota::Cancelando, $nota->refresh()->status);
    }

    public function test_justificativa_curta_da_422(): void
    {
        $this->cancelar($this->autorizada(), 'curta')->assertStatus(422);
    }

    public function test_so_cancela_nota_autorizada_com_chave_e_xml(): void
    {
        $this->cancelar($this->notaDeNfsePronta(StatusNota::Rascunho))->assertStatus(409)->assertJsonPath('codigo', 'CANCELAMENTO_NAO_PERMITIDO');

        $semXml = $this->notaDeNfsePronta(StatusNota::Autorizada, ['chave' => EmissorDeNfseFake::CHAVE]);
        $this->cancelar($semXml)->assertStatus(409)->assertJsonPath('codigo', 'CANCELAMENTO_NAO_PERMITIDO');
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_sem_modulo_nfse_bloqueia_o_cancelamento(): void
    {
        $nota = $this->autorizada();
        $this->tenant->update(['plano_id' => Plano::factory()->comModulos(\App\Modules\Platform\Domain\Enums\Modulo::FiscalNfe)->create()->id]);

        $this->cancelar($nota)->assertStatus(403)->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_nfe_continua_exigindo_protocolo(): void
    {
        // A regra do protocolo é só da NF-e: uma nota DPS sem protocolo é cancelável, e este teste fixa a diferença.
        $nota = $this->autorizada();

        $this->assertNull($nota->protocolo);
        $this->cancelar($nota)->assertStatus(202);
    }
}
```

> Conferir em `IniciarCancelamentoTest` o código exato do 409 (`CANCELAMENTO_NAO_PERMITIDO`) e do 403 do módulo antes de rodar; se divergirem, alinhar as asserções, não o código.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter=CancelamentoNfseTest`
Expected: FAIL (a nota DPS exige o módulo e o protocolo da NF-e).

- [ ] **Step 3: Implementar**

`IniciarCancelamento.php`: dentro da transação, substituir o trecho de `$nfce`/módulo/status/protocolo por:

```php
            $nfce = $nota->modelo === ModeloDocumento::Nfce;
            $servico = $nota->modelo === ModeloDocumento::Dps;
            $this->planos->garantirModulo($tenant, match (true) {
                $servico => Modulo::FiscalNfse,
                $nfce => Modulo::FiscalNfce,
                default => Modulo::FiscalNfe,
            });
            $rotulo = match (true) {
                $servico => 'NFS-e',
                $nfce => 'NFC-e',
                default => 'NF-e',
            };

            if ($nota->status !== StatusNota::Autorizada) {
                throw new CancelamentoNaoPermitidoException("Só é possível cancelar uma {$rotulo} autorizada. Status atual: {$nota->status->value}.");
            }
            $nota->makeVisible('xml_autorizado');
            // A NFS-e não tem protocolo: o evento 101101 usa só a chave de 50 dígitos.
            if ($nota->chave === null || (! $servico && $nota->protocolo === null) || $nota->xml_autorizado === null) {
                throw new CancelamentoNaoPermitidoException('A nota não tem chave, '.($servico ? '' : 'protocolo ').'ou XML autorizado: concilie-a antes de cancelar.');
            }
```

`CancelarNotaJob.php`: adicionar `use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;` e `use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;`; trocar a assinatura e o trecho do cancelamento por:

```php
    public function handle(EmissorDeNfe $emissor, EmissorDeNfse $emissorNfse, AplicarResultadoDoCancelamento $aplicar, TenantContext $contexto): void
    {
        // ...contexto igual ao existente...
            $emitente = Emitente::query()->findOrFail($nota->emitente_id);
            $nota->makeVisible('xml_autorizado');

            $resultado = $nota->modelo === ModeloDocumento::Dps
                ? $emissorNfse->cancelar($emitente, (string) $nota->chave, (string) $nota->cancelamento_justificativa, (string) $nota->ambiente)
                : $emissor->cancelar(
                    $emitente, (string) $nota->chave, (string) $nota->protocolo, (string) $nota->cancelamento_justificativa,
                    (string) $nota->ambiente, (string) $nota->xml_autorizado,
                );
            $aplicar->executar($nota->id, $resultado);
```

(manter o `try/catch/finally` e o `registrarFalhaTecnica` como estão).

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter="CancelamentoNfseTest|CancelarNotaJobTest|IniciarCancelamentoTest|CancelamentoApiTest"` e `vendor/bin/pint` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; Pint e PHPStan limpos. Os testes existentes de `CancelarNotaJob` constroem o job com o container: se algum instanciar `handle(...)` manualmente, acrescentar o `EmissorDeNfseFake` como segundo argumento.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal/CancelamentoNfseTest.php backend/tests/Feature/Fiscal
git commit -m "feat(nfse): cancelamento da NFS-e por evento 101101" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 10: DANFSe v2.0

**Files:**
- Create (cópia da skill): `backend/resources/images/danfse-logo.png`, `backend/resources/views/pdf/danfse.blade.php`, `backend/app/Modules/Fiscal/Infrastructure/Pdf/Danfse/DanfseRenderer.php`
- Create: `backend/app/Modules/Fiscal/Domain/Contracts/GeradorDeDanfse.php`, `backend/app/Modules/Fiscal/Infrastructure/Pdf/DompdfGeradorDeDanfse.php`
- Modify: `backend/app/Modules/Fiscal/Application/Actions/GerarDanfe.php`, `backend/app/Modules/Fiscal/Providers/FiscalServiceProvider.php`
- Test: `backend/tests/Unit/Fiscal/Nfse/DanfseRendererTest.php`, `backend/tests/Feature/Fiscal/GeradorDeDanfseTest.php`, `backend/tests/Feature/Fiscal/DanfseApiTest.php`

**Interfaces:**
- Consumes: fixture `tests/Fixtures/nfse_nacional_autorizada.xml` (Tarefa 7), `PdfIndisponivelException(Throwable)`, `NotaSemPdfException`, `ArquivoPdf(nome, conteudo)`, `Tests\Fixtures\TextoDePdf` (`textoDoPdf(string $pdf): string`).
- Produces: `DanfseRenderer::dadosParaTemplate(string $xml, bool $cancelada, ?string $qrCodeDataUri, string $logoDataUri): array`; `GeradorDeDanfse::gerar(string $xml, bool $cancelada): string` (bytes do PDF A4 595x842); `GerarDanfe` gera `DANFSE-<numero_nfse>.pdf` para nota `DPS` `AUTORIZADA`/`CANCELANDO` (flag `false`) ou `CANCELADA` (flag `true`).

- [ ] **Step 1: Copiar os arquivos da skill**

Run (PowerShell, na raiz do repositório):

```powershell
$d = "$HOME\.claude\skills\br-fiscal-note-emission\assets\pdf-layouts\danfse"
New-Item -ItemType Directory -Force backend\resources\images, backend\resources\views\pdf, backend\app\Modules\Fiscal\Infrastructure\Pdf\Danfse | Out-Null
Copy-Item "$d\danfse-logo.png" backend\resources\images\danfse-logo.png
Copy-Item "$d\danfse.blade.php" backend\resources\views\pdf\danfse.blade.php
Copy-Item "$d\DanfseRenderer.php" backend\app\Modules\Fiscal\Infrastructure\Pdf\Danfse\DanfseRenderer.php
```

- [ ] **Step 2: Escrever os testes que falham**

`backend/tests/Unit/Fiscal/Nfse/DanfseRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use App\Modules\Fiscal\Infrastructure\Pdf\Danfse\DanfseRenderer;
use RuntimeException;
use Tests\TestCase;

/** A fixture é uma NFS-e nacional autorizada ANONIMIZADA (dados fictícios, sem assinatura); a estrutura é a real. */
class DanfseRendererTest extends TestCase
{
    private const CHAVE = '31305072212345678000195000000000000226096821694069';

    private function xml(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/nfse_nacional_autorizada.xml'));
    }

    /** @return list<string> */
    private function textos(string $xml, bool $cancelada = false): array
    {
        $d = (new DanfseRenderer)->dadosParaTemplate($xml, $cancelada, null, 'data:image/png;base64,AA==');

        return array_column($d['textos'], 't');
    }

    public function test_campos_saem_do_xml_como_no_danfse_oficial(): void
    {
        $t = $this->textos($this->xml());

        foreach (['DANFSe v2.0', self::CHAVE, 'Prestador', 'NFS-e Gerada', 'Operação Tributável', 'Não Retido'] as $esperado) {
            $this->assertContains($esperado, $t, "faltou: {$esperado}");
        }
    }

    public function test_a_chave_sai_sem_o_prefixo_nfs(): void
    {
        $this->assertNotContains('NFS'.self::CHAVE, $this->textos($this->xml()));
    }

    public function test_nota_cancelada_mostra_a_situacao_cancelada(): void
    {
        $this->assertContains('NFS-e Cancelada', $this->textos($this->xml(), true));
        $this->assertNotContains('NFS-e Cancelada', $this->textos($this->xml(), false));
    }

    public function test_descricao_longa_quebra_em_linhas_e_empurra_o_resto_da_pagina(): void
    {
        $longa = str_repeat('troca de oleo e filtro com revisao completa do motor ', 30);
        $xml = (string) preg_replace('#<xDescServ>.*?</xDescServ>#s', '<xDescServ>'.$longa.'</xDescServ>', $this->xml());
        $r = new DanfseRenderer;

        $normal = $r->dadosParaTemplate($this->xml(), false, null, 'x');
        $comLonga = $r->dadosParaTemplate($xml, false, null, 'x');

        $yTotal = static fn (array $d): float => (float) array_values(array_filter($d['textos'], static fn (array $t): bool => $t['t'] === 'VALOR TOTAL DA NFS-e'))[0]['y'];
        $this->assertGreaterThan($yTotal($normal) + 15, $yTotal($comLonga), 'o bloco de valores deve descer quando a descrição ocupa mais linhas');
    }

    public function test_desconto_incondicional_sai_do_xml(): void
    {
        $xml = (string) str_replace('</vServPrest>', '</vServPrest><vDescCondIncond><vDescIncond>10.00</vDescIncond></vDescCondIncond>', $this->xml());

        $this->assertContains('R$ 10,00', $this->textos($xml));
    }

    public function test_xml_que_nao_e_nfse_nacional_lanca(): void
    {
        $this->expectException(RuntimeException::class);

        (new DanfseRenderer)->dadosParaTemplate('<qualquer-coisa/>', false, null, 'x');
    }
}
```

> A fixture é a da skill e seus valores vêm do teste original (`R$ 100,00` de serviço, etc.). Se `test_desconto_incondicional_sai_do_xml` falhar porque a fixture não tem `</vServPrest>` com esse texto exato, abrir o XML (uma linha) e usar o ponto de inserção correto antes de `<trib>`.

`backend/tests/Feature/Fiscal/GeradorDeDanfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfse;
use App\Modules\Fiscal\Domain\Exceptions\PdfIndisponivelException;
use Tests\Fixtures\TextoDePdf;
use Tests\TestCase;

class GeradorDeDanfseTest extends TestCase
{
    use TextoDePdf;

    private function xml(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/nfse_nacional_autorizada.xml'));
    }

    private function gerador(): GeradorDeDanfse
    {
        return $this->app->make(GeradorDeDanfse::class);
    }

    public function test_gera_o_pdf_a4_do_danfse_com_a_chave_de_50_digitos(): void
    {
        $pdf = $this->gerador()->gerar($this->xml(), false);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+595(\.0+)?\s+842(\.0+)?\s*\]#', $pdf);
        $texto = $this->textoDoPdf($pdf);
        $this->assertStringContainsString('DANFSe', $texto);
        $this->assertStringContainsString('31305072212345678000195000000000000226096821694069', $texto);
        $this->assertStringNotContainsString('NFS31305072212345678000195000000000000226096821694069', $texto);
    }

    public function test_cancelada_imprime_a_situacao(): void
    {
        $this->assertStringContainsString('Cancelada', $this->textoDoPdf($this->gerador()->gerar($this->xml(), true)));
    }

    public function test_xml_invalido_vira_pdf_indisponivel(): void
    {
        $this->expectException(PdfIndisponivelException::class);

        $this->gerador()->gerar('<isto nao e uma nfse', false);
    }
}
```

`backend/tests/Feature/Fiscal/DanfseApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfse;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class DanfseApiTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    /** @var list<array{string, bool}> */
    public array $chamadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
        $teste = $this;
        $this->app->instance(GeradorDeDanfse::class, new class($teste) implements GeradorDeDanfse
        {
            public function __construct(private readonly DanfseApiTest $teste) {}

            public function gerar(string $xml, bool $cancelada): string
            {
                $this->teste->chamadas[] = [$xml, $cancelada];

                return '%PDF-teste';
            }
        });
    }

    private function nota(StatusNota $status, array $campos = []): \App\Modules\Fiscal\Domain\Models\Nota
    {
        return $this->notaDeNfsePronta($status, ['chave' => str_repeat('1', 50), 'numero_nfse' => '42', 'xml_autorizado' => '<NFSe>autorizada</NFSe>'] + $campos);
    }

    public function test_autorizada_gera_o_danfse_com_o_numero_oficial_no_nome(): void
    {
        $nota = $this->nota(StatusNota::Autorizada);

        $r = $this->spaComo($this->admin)->get("/api/app/notas/{$nota->id}/pdf")->assertOk();

        $this->assertStringContainsString('DANFSE-42.pdf', (string) $r->headers->get('Content-Disposition'));
        $this->assertSame([['<NFSe>autorizada</NFSe>', false]], $this->chamadas);
    }

    public function test_cancelando_usa_o_xml_autorizado_sem_a_marca_e_cancelada_com_a_marca(): void
    {
        $this->spaComo($this->admin)->get('/api/app/notas/'.$this->nota(StatusNota::Cancelando)->id.'/pdf')->assertOk();
        $this->spaComo($this->admin)->get('/api/app/notas/'.$this->nota(StatusNota::Cancelada)->id.'/pdf')->assertOk();

        $this->assertSame([['<NFSe>autorizada</NFSe>', false], ['<NFSe>autorizada</NFSe>', true]], $this->chamadas);
    }

    public function test_rascunho_e_nota_sem_xml_nao_geram_pdf(): void
    {
        $this->spaComo($this->admin)->get('/api/app/notas/'.$this->notaDeNfsePronta(StatusNota::Rascunho)->id.'/pdf')->assertStatus(409);
        $this->spaComo($this->admin)->get('/api/app/notas/'.$this->nota(StatusNota::Autorizada, ['xml_autorizado' => null])->id.'/pdf')->assertStatus(409);
        $this->assertSame([], $this->chamadas);
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --filter="DanfseRendererTest|GeradorDeDanfseTest|DanfseApiTest"`
Expected: FAIL (a classe do renderer ainda tem o namespace e as dependências do projeto de origem).

- [ ] **Step 4: Adaptar o renderer (edições exatas em `Infrastructure/Pdf/Danfse/DanfseRenderer.php`)**

1. `namespace App\Services\Fiscal\Pdf;` → `namespace App\Modules\Fiscal\Infrastructure\Pdf\Danfse;`.
2. Remover `use App\Models\NotaFiscal;`; acrescentar `use RuntimeException;` (os `use Dompdf\...` podem sair se não forem usados; manter só o que `FontMetrics` precisar).
3. Trocar a assinatura e o início de `dadosParaTemplate` por:

```php
    /**
     * @return array{textos: list<array<string,mixed>>, retangulos: list<array<string,mixed>>, filetes: list<array<string,float>>, imagens: array<string,mixed>, borda: array<string,float>, titulo: string}
     */
    public function dadosParaTemplate(string $xml, bool $cancelada, ?string $qrCodeDataUri, string $logoDataUri): array
    {
        $d = $this->extrair($xml, $cancelada);
```

   (o corpo restante do método fica igual, sem mais referências a `$nota` ou `$empresa`).
4. Substituir os métodos `extrair` e `carregarXmlNacional` por:

```php
    /** @return array<string, string> */
    private function extrair(string $xml, bool $cancelada): array
    {
        $x = @simplexml_load_string($xml);
        if ($x === false || $x->getName() !== 'NFSe' || ! isset($x->infNFSe)) {
            throw new RuntimeException('O XML da NFS-e não é um documento nacional válido (esperado NFSe/infNFSe).');
        }

        return $this->dadosDoXml($x, $cancelada);
    }
```

5. `dadosDoXml(NotaFiscal $nota, \SimpleXMLElement $x)` → `dadosDoXml(\SimpleXMLElement $x, bool $cancelada)`; trocar `if ($nota->status === 'CANCELADA') {` por `if ($cancelada) {`.
6. Apagar o método `dadosDoBanco` inteiro (do docblock "NFS-e de outros provedores…" até a chave de fechamento que antecede `private function ou`). A NFS-e só imprime a partir do XML autorizado.
7. No array de `dadosDoXml`, trocar `'desc_incond'  => '-',` por:

```php
            'desc_incond'  => $s($dps->valores->vDescCondIncond->vDescIncond) !== '' ? $this->moeda($s($dps->valores->vDescCondIncond->vDescIncond)) : '-',
```

`backend/app/Modules/Fiscal/Domain/Contracts/GeradorDeDanfse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Contracts;

/** Desenha o DANFSe v2.0 a partir do XML autorizado da NFS-e. `$cancelada` troca a situação impressa. */
interface GeradorDeDanfse
{
    /** @return string bytes do PDF (A4, 595x842 pt) */
    public function gerar(string $xml, bool $cancelada): string;
}
```

`backend/app/Modules/Fiscal/Infrastructure/Pdf/DompdfGeradorDeDanfse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Pdf;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfse;
use App\Modules\Fiscal\Domain\Exceptions\PdfIndisponivelException;
use App\Modules\Fiscal\Infrastructure\Pdf\Danfse\DanfseRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Throwable;

final class DompdfGeradorDeDanfse implements GeradorDeDanfse
{
    public function __construct(private readonly DanfseRenderer $renderer) {}

    public function gerar(string $xml, bool $cancelada): string
    {
        try {
            $dados = $this->renderer->dadosParaTemplate($xml, $cancelada, $this->qrCode($xml), $this->logo());

            return Pdf::loadView('pdf.danfse', $dados)->setPaper([0, 0, 595, 842], 'portrait')->output();
        } catch (Throwable $e) {
            throw new PdfIndisponivelException($e);
        }
    }

    /** Mesma URL do QR do DANFSe oficial; a zona de silêncio de 22 px reproduz o quadro de 45 pt do original. */
    private function qrCode(string $xml): ?string
    {
        if (preg_match('/<infNFSe[^>]*\sId="NFS(\d{50})"/', $xml, $m) !== 1) {
            return null;
        }

        return (new Builder(
            writer: new PngWriter,
            data: 'https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave='.$m[1],
            size: 450,
            margin: 22,
        ))->build()->getDataUri();
    }

    private function logo(): string
    {
        return 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('images/danfse-logo.png')));
    }
}
```

`FiscalServiceProvider.php`: `use` de `GeradorDeDanfse` e `DompdfGeradorDeDanfse`; em `register()`: `$this->app->bind(GeradorDeDanfse::class, DompdfGeradorDeDanfse::class);`.

`GerarDanfe.php`: o construtor passa a ser `public function __construct(private readonly GeradorDeDanfe $gerador, private readonly GeradorDeDanfse $danfse) {}` (+ `use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfse;`) e, no começo de `executar`, depois do `makeVisible`:

```php
        if ($nota->modelo === ModeloDocumento::Dps) {
            return $this->danfse($nota);
        }
```

e o método:

```php
    /** A NFS-e imprime sempre o XML autorizado; cancelada só liga a marca (o evento 101101 não é um nfeProc). */
    private function danfse(Nota $nota): ArquivoPdf
    {
        [$xml, $cancelada] = match ($nota->status) {
            StatusNota::Autorizada, StatusNota::Cancelando => [$nota->xml_autorizado, false],
            StatusNota::Cancelada => [$nota->xml_autorizado, true],
            default => throw new NotaSemPdfException,
        };
        if (! is_string($xml) || $xml === '') {
            throw new NotaSemPdfException('O XML desta nota não está disponível para gerar o DANFSe.');
        }

        return new ArquivoPdf('DANFSE-'.($nota->numero_nfse ?? 'sem-numero').'.pdf', $this->danfse->gerar($xml, $cancelada));
    }
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --filter="DanfseRendererTest|GeradorDeDanfseTest|DanfseApiTest|GerarDanfeTest|GeradorDeDanfeTest|DanfeApiTest|DanfeEpecApiTest"` e `vendor/bin/pint` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; Pint e PHPStan limpos. O `GerarDanfeTest` constrói `GerarDanfe` pelo container (o novo parâmetro é resolvido); se algum teste instanciar manualmente, acrescentar o gerador de DANFSe. Se o PDF real falhar por falta da extensão `gd` (QR) ou fontes, anotar o erro e usar `extension_loaded('gd')` no `markTestSkipped` apenas para os testes de PDF real, nunca para o renderer.

- [ ] **Step 6: Commit**

```bash
git add backend/resources backend/app/Modules/Fiscal backend/tests
git commit -m "feat(nfse): DANFSe v2.0 a partir do XML autorizado" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 11: API e fronteiras do modelo (nomes de arquivo, dashboard, inutilização, isolamento)

**Files:**
- Modify: `backend/app/Modules/Fiscal/Http/Controllers/NotaController.php` (nome dos downloads)
- Modify: `backend/app/Modules/Fiscal/Http/Controllers/DashboardFiscalController.php`
- Test: `backend/tests/Feature/Fiscal/FronteirasDaNfseTest.php`, `backend/tests/Feature/Fiscal/NfseIsolamentoTest.php`

**Interfaces:**
- Consumes: fixtures e `EmissorDeNfseFake`; rotas existentes.
- Produces: downloads `NFSe-<numero_nfse>.xml` e `NFSe-<numero_nfse>-cancelamento.xml` para nota `DPS`; o dashboard fiscal aceita `FISCAL_NFSE`.

- [ ] **Step 1: Escrever os testes que falham**

`backend/tests/Feature/Fiscal/FronteirasDaNfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class FronteirasDaNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
    }

    public function test_downloads_levam_o_numero_oficial_da_nfse(): void
    {
        $nota = $this->notaDeNfsePronta(StatusNota::Cancelada, [
            'chave' => str_repeat('1', 50), 'numero_nfse' => '42', 'xml_autorizado' => '<NFSe>a</NFSe>', 'xml_cancelamento' => '<evento>c</evento>',
        ]);

        $xml = $this->spaComo($this->admin)->get("/api/app/notas/{$nota->id}/xml");
        $cancelamento = $this->spaComo($this->admin)->get("/api/app/notas/{$nota->id}/xml-cancelamento");

        // O download do XML autorizado só vale para nota AUTORIZADA; CANCELADA responde 409 e o do cancelamento é 200.
        $cancelamento->assertOk();
        $this->assertStringContainsString('NFSe-42-cancelamento.xml', (string) $cancelamento->headers->get('Content-Disposition'));
        $this->assertSame(409, $xml->status());

        $autorizada = $this->notaDeNfsePronta(StatusNota::Autorizada, ['chave' => str_repeat('1', 50), 'numero_nfse' => '43', 'xml_autorizado' => '<NFSe>a</NFSe>']);
        $r = $this->spaComo($this->admin)->get("/api/app/notas/{$autorizada->id}/xml")->assertOk();
        $this->assertStringContainsString('NFSe-43.xml', (string) $r->headers->get('Content-Disposition'));
    }

    public function test_inutilizacao_nao_aceita_o_modelo_dps(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', [
            'modelo' => 'DPS', 'serie' => '1', 'numero_inicial' => 1, 'numero_final' => 1, 'justificativa' => 'Numeração perdida na queda do sistema',
        ])->assertStatus(422)->assertJsonValidationErrors('modelo');
    }

    public function test_retransmitir_nota_dps_da_409(): void
    {
        $nota = $this->notaDeNfsePronta(StatusNota::Autorizada);

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/retransmitir")->assertStatus(409);
    }

    public function test_dashboard_fiscal_aceita_o_modulo_nfse_e_recusa_quem_nao_tem_nenhum(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/dashboard/fiscal')->assertOk();

        $this->tenant->update(['plano_id' => Plano::factory()->comModulos(Modulo::Crm)->create()->id]);
        $this->spaComo($this->admin)->getJson('/api/app/dashboard/fiscal')->assertStatus(403);
    }

    public function test_nota_dps_autorizada_conta_na_cota_e_no_resumo(): void
    {
        $this->notaDeNfsePronta(StatusNota::Autorizada, ['emitido_em' => now(), 'chave' => str_repeat('1', 50), 'numero_nfse' => '1']);

        $this->assertSame(1, (new ContadorDeDocumentosMes)->contar($this->tenant));
        $r = $this->spaComo($this->admin)->getJson('/api/app/dashboard/fiscal')->assertOk();
        $this->assertSame(15000, $r->json('data.mes.valor_emitido_centavos'));
    }
}
```

`backend/tests/Feature/Fiscal/NfseIsolamentoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class NfseIsolamentoTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    public function test_nota_dps_de_outro_tenant_e_invisivel_em_todas_as_rotas(): void
    {
        $this->montarCenarioNfse();
        $dela = $this->notaDeNfsePronta(StatusNota::Autorizada, ['chave' => str_repeat('1', 50), 'numero_nfse' => '1', 'xml_autorizado' => '<NFSe>a</NFSe>']);

        $outro = Tenant::factory()->for(Plano::factory()->comModulos(\App\Modules\Platform\Domain\Enums\Modulo::FiscalNfse))->create();
        $intruso = \App\Modules\Identity\Domain\Models\Usuario::factory()->for($outro)->create(['papel' => \App\Modules\Identity\Domain\Enums\Papel::Admin]);
        app(TenantContext::class)->set($outro->id);

        foreach ([['get', ''], ['get', '/status'], ['get', '/xml'], ['get', '/pdf'], ['post', '/emitir'], ['post', '/cancelar']] as [$metodo, $rota]) {
            $this->spaComo($intruso)->json($metodo, "/api/app/notas/{$dela->id}{$rota}", $rota === '/cancelar' ? ['justificativa' => 'Erro na emissão da nota fiscal'] : [])
                ->assertStatus(404);
        }
        $this->assertSame(StatusNota::Autorizada, Nota::withoutTenantScope()->findOrFail($dela->id)->status);
    }
}
```

> Se `Nota::withoutTenantScope()` não existir com esse nome fora do `ReconciliarNotasCommand`, usar o mesmo helper que ele usa. O helper `spaComo($usuario)` já troca de usuário sem o 401 espúrio.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="FronteirasDaNfseTest|NfseIsolamentoTest"`
Expected: FAIL (nomes `NFe-…`, dashboard exige NFE/NFCE).

- [ ] **Step 3: Implementar**

`NotaController.php`: adicionar `use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;`, os helpers abaixo e trocar os dois `filename`:

```php
    private function nomeDoXml(Nota $nota, string $sufixo = ''): string
    {
        return $nota->modelo === ModeloDocumento::Dps
            ? 'NFSe-'.($nota->numero_nfse ?? $nota->numero).$sufixo.'.xml'
            : 'NFe-'.$nota->numero.$sufixo.'.xml';
    }
```

Em `xml()`: `'Content-Disposition' => 'attachment; filename="'.$this->nomeDoXml($nota).'"'`; em `xmlCancelamento()`: `'Content-Disposition' => 'attachment; filename="'.$this->nomeDoXml($nota, '-cancelamento').'"'`.

`DashboardFiscalController.php`: trocar a condição por:

```php
        $temFiscal = $planos->temModulo($tenant, Modulo::FiscalNfe) || $planos->temModulo($tenant, Modulo::FiscalNfce) || $planos->temModulo($tenant, Modulo::FiscalNfse);
        if (! $temFiscal) {
            throw ModuloNaoContratadoException::para(Modulo::FiscalNfe);
        }
```

A inutilização já recusa `DPS` (`in:NFE,NFCE` em `InutilizarNumeracaoRequest`); o teste só fixa o comportamento.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter="FronteirasDaNfseTest|NfseIsolamentoTest|DashboardFiscalApiTest|NotaIsolamentoTest|InutilizacaoIsolamentoTest"` e `vendor/bin/pint` e `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: PASS; Pint e PHPStan limpos.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Fiscal backend/tests/Feature/Fiscal
git commit -m "feat(nfse): downloads, dashboard e fronteiras do modelo da NFS-e" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 12: Frontend da NFS-e

**Files:**
- Modify: `frontend/features/notas/types.ts`, `frontend/features/notas/schemas.ts`
- Create: `frontend/features/notas/components/NotaDeServicoForm.tsx`, `frontend/features/notas/components/FormularioDeNota.tsx`
- Modify: `frontend/features/notas/components/DetalheDaNota.tsx`
- Modify: `frontend/app/(app)/notas/nova/page.tsx`, `frontend/app/(app)/notas/page.tsx`, `frontend/app/(app)/dashboard/page.tsx`
- Test: `frontend/features/notas/components/Notas.test.tsx` (novos `describe`), `frontend/features/notas/schemas.test.ts`

**Interfaces:**
- Consumes: API das Tarefas 2, 3, 8 e 10 (`modelo=DPS`, `itens.*.servico_id`, `numero_nfse`, `?modelo=`); `servicosApi.listar`, `clientesApi.listar`; `Campo`, `SelectNativo`, `TextareaNativo`, `Input`, `Banner`, `Button`; `useAssinatura` (módulo `FISCAL_NFSE`).
- Produces: `ModeloDaNota = 'NFE' | 'NFCE' | 'DPS'` com `ROTULO_MODELO.DPS = 'NFS-e'`; `notaDeServicoSchema`, `paraPayloadDeServico`; `NotaDeServicoForm`; `FormularioDeNota` (escolhe entre `NotaForm` e `NotaDeServicoForm`); detalhe da NFS-e com "Abrir DANFSe (PDF)", número oficial da NFS-e e sem protocolo/pagamentos.

- [ ] **Step 1: Atualizar os tipos e escrever os testes que falham**

`types.ts`: `ModeloDaNota` vira `'NFE' | 'NFCE' | 'DPS'`; `ROTULO_MODELO` ganha `DPS: 'NFS-e'`; em `NotaItem`: `produto_id: string | null;` e, depois de `csosn`, `servico_id: string | null; c_trib_nac: string | null;`; em `Nota`, depois de `protocolo`: `numero_nfse: string | null;`; e o payload vira uma união:

```ts
export interface NotaDeProdutosPayload {
  modelo: Exclude<ModeloDaNota, 'DPS'>;
  cliente_id: string | null;
  consumidor_final: boolean | null;
  desconto_centavos: number;
  informacoes_complementares: string | null;
  itens: { produto_id: string; quantidade: string; valor_unitario_centavos: number | null }[];
  pagamentos: { tpag: FormaPagamento; xpag: string | null; valor_centavos: number }[];
}

export interface NotaDeServicosPayload {
  modelo: 'DPS';
  cliente_id: string;
  consumidor_final: null;
  desconto_centavos: number;
  informacoes_complementares: string | null;
  itens: { servico_id: string; quantidade: string; valor_unitario_centavos: number | null }[];
}

export type NotaPayload = NotaDeProdutosPayload | NotaDeServicosPayload;
```

Em `schemas.ts`: `paraPayload` passa a devolver `NotaDeProdutosPayload` (trocar o tipo no import e na assinatura) e, no `notaSchema`, o `modelo` continua `z.enum(['NFE', 'NFCE'])`; acrescentar no fim do bloco de notas:

```ts
const servicoItemSchema = z.object({
  servico_id: z.string().uuid('Escolha um serviço.'),
  quantidade: z
    .string()
    .regex(QUANTIDADE, 'Use até 3 casas decimais.')
    .refine((v) => Number(v.replace(',', '.')) > 0, 'A quantidade deve ser maior que zero.'),
  valor_unitario: z.string().refine((v) => v === '' || DINHEIRO.test(v), 'Use o formato 10,50.'),
});

export const notaDeServicoSchema = z.object({
  cliente_id: z.string().uuid('Escolha um cliente.'),
  desconto: z.string().regex(DINHEIRO, 'Use o formato 10,50.'),
  // O limite de 255 é do campo de informações complementares da DPS.
  informacoes_complementares: z.string().max(255, 'No máximo 255 caracteres.'),
  itens: z.array(servicoItemSchema).min(1, 'Adicione ao menos um serviço.'),
});

export type NotaDeServicoDados = z.infer<typeof notaDeServicoSchema>;

export function paraPayloadDeServico(d: NotaDeServicoDados): NotaDeServicosPayload {
  return {
    modelo: 'DPS',
    cliente_id: d.cliente_id,
    consumidor_final: null,
    desconto_centavos: reaisParaCentavos(d.desconto),
    informacoes_complementares: d.informacoes_complementares.trim() === '' ? null : d.informacoes_complementares,
    itens: d.itens.map((i) => ({
      servico_id: i.servico_id,
      quantidade: i.quantidade.replace(',', '.'),
      valor_unitario_centavos: i.valor_unitario === '' ? null : reaisParaCentavos(i.valor_unitario),
    })),
  };
}
```

(importar `NotaDeProdutosPayload` e `NotaDeServicosPayload` de `./types`).

Em `schemas.test.ts`, acrescentar:

```ts
import { notaDeServicoSchema, paraPayloadDeServico } from './schemas';

describe('notaDeServicoSchema', () => {
  const base = { cliente_id: '11111111-1111-4111-8111-111111111111', desconto: '0,00', informacoes_complementares: '', itens: [{ servico_id: '22222222-2222-4222-8222-222222222222', quantidade: '1', valor_unitario: '' }] };

  it('aceita a nota de serviço e monta o payload sem pagamentos', () => {
    const dados = notaDeServicoSchema.parse({ ...base, desconto: '10,00', informacoes_complementares: 'Placa ABC1D23' });

    expect(paraPayloadDeServico(dados)).toEqual({
      modelo: 'DPS', cliente_id: base.cliente_id, consumidor_final: null, desconto_centavos: 1000, informacoes_complementares: 'Placa ABC1D23',
      itens: [{ servico_id: base.itens[0].servico_id, quantidade: '1', valor_unitario_centavos: null }],
    });
  });

  it('exige cliente e ao menos um serviço, e limita as informações a 255', () => {
    expect(notaDeServicoSchema.safeParse({ ...base, cliente_id: '' }).success).toBe(false);
    expect(notaDeServicoSchema.safeParse({ ...base, itens: [] }).success).toBe(false);
    expect(notaDeServicoSchema.safeParse({ ...base, informacoes_complementares: 'x'.repeat(256) }).success).toBe(false);
  });
});
```

Em `Notas.test.tsx`: (a) no topo, junto dos outros mocks: `import { servicosApi } from '@/features/servicos/api';`, `import { FormularioDeNota } from './FormularioDeNota';` e `vi.mock('@/features/servicos/api', () => ({ QUERY_KEY_SERVICOS: ['servicos'], servicosApi: { listar: vi.fn() } }));`; (b) no objeto `NOTA`, acrescentar `numero_nfse: null,` e, onde houver itens tipados como `NotaItem`, `servico_id: null, c_trib_nac: null`; (c) no fim do arquivo, os blocos:

```tsx
describe('NFS-e', () => {
  const CLIENTE = { id: '11111111-1111-4111-8111-111111111111', tipo: 'PF', nome: 'Maria Teste', estagio: 'CLIENTE' };
  const SERVICO = { id: '55555555-5555-4555-8555-555555555555', nome: 'Troca de óleo', preco_centavos: 15000 };
  const NFSE: Nota = {
    ...NOTA, modelo: 'DPS', status: 'AUTORIZADA', protocolo: null, numero_nfse: '42', chave: '1'.repeat(50),
    itens: [{ id: 1, produto_id: null, servico_id: SERVICO.id, c_trib_nac: '140101', ordem: 1, sku: null, descricao: 'Troca de óleo', unidade: null, quantidade: '1.000', valor_unitario_centavos: 15000, desconto_centavos: 0, total_centavos: 15000, cfop: null, csosn: null }],
    pagamentos: [],
  };

  function preparar() {
    vi.mocked(clientesApi.listar).mockResolvedValue({ data: [CLIENTE] as never });
    vi.mocked(servicosApi.listar).mockResolvedValue({ data: [SERVICO] as never });
  }

  it('FormularioDeNota só oferece NFS-e quando o plano tem o módulo', async () => {
    preparar();
    renderizar(<FormularioDeNota />);
    await screen.findByLabelText('Cliente');
    expect(screen.queryByLabelText('Tipo de nota')).not.toBeInTheDocument();
  });

  it('com o módulo, escolher NFS-e mostra o formulário de serviços e envia modelo DPS sem pagamentos', async () => {
    comModulos(['FISCAL_NFE', 'FISCAL_NFSE']);
    preparar();
    vi.mocked(notasApi.criar).mockResolvedValue({ data: { ...NFSE, id: 'novo', status: 'RASCUNHO' } });
    renderizar(<FormularioDeNota />);

    await userEvent.selectOptions(await screen.findByLabelText('Tipo de nota'), 'SERVICOS');
    await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE.id);
    await userEvent.selectOptions(screen.getByLabelText('Serviço 1'), SERVICO.id);
    expect(screen.queryByLabelText('Forma de pagamento 1')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Salvar rascunho' }));

    await waitFor(() =>
      expect(notasApi.criar).toHaveBeenCalledWith({
        modelo: 'DPS', cliente_id: CLIENTE.id, consumidor_final: null, desconto_centavos: 0, informacoes_complementares: null,
        itens: [{ servico_id: SERVICO.id, quantidade: '1', valor_unitario_centavos: null }],
      }),
    );
  });

  it('mostra o erro de bloqueio fiscal que vem da API', async () => {
    comModulos(['FISCAL_NFE', 'FISCAL_NFSE']);
    preparar();
    vi.mocked(notasApi.criar).mockRejectedValue(new ApiError(422, 'O serviço "Troca de óleo" está sem: alíquota do ISS.', {}, { codigo: 'FISCAL_SERVICO_INCOMPLETO' }));
    renderizar(<NotaDeServicoForm />);

    await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE.id);
    await userEvent.selectOptions(screen.getByLabelText('Serviço 1'), SERVICO.id);
    await userEvent.click(screen.getByRole('button', { name: 'Salvar rascunho' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('alíquota do ISS');
  });

  it('o detalhe da NFS-e autorizada mostra o número oficial, o DANFSe e não mostra protocolo nem pagamentos', async () => {
    comPapel('LEITURA');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NFSE });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByRole('link', { name: 'Abrir DANFSe (PDF)' })).toHaveAttribute('href', 'http://api.test/api/app/notas/n1/pdf');
    expect(screen.getByText(/NFS-e nº 42/)).toBeInTheDocument();
    expect(screen.queryByText(/Protocolo:/)).not.toBeInTheDocument();
    expect(screen.queryByText('Itens e pagamentos')).not.toBeInTheDocument();
    expect(screen.getByText('Serviços')).toBeInTheDocument();
  });

  it('quem emite cancela a NFS-e e o texto fala em NFS-e', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NFSE });
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar NFS-e' }));

    expect(screen.getByText('Cancelar NFS-e', { selector: '[data-slot="card-title"], h2, h3, div' })).toBeInTheDocument();
  });
});
```

> Os helpers `comPapel`, `comModulos`, `renderizar`, `NOTA` e os imports (`ApiError`, `NotaForm`) já existem no arquivo; importar `NotaDeServicoForm` de `./NotaDeServicoForm`. O último teste usa um seletor genérico para o título do card; se o componente `CardTitle` não expõe `data-slot`, trocar por `screen.getAllByText('Cancelar NFS-e').length > 1`.

- [ ] **Step 2: Rodar e ver falhar**

Run (em `frontend/`): `npx vitest run features/notas`
Expected: FAIL (componentes e tipos novos inexistentes) e `npx tsc --noEmit` apontando os mesmos pontos.

- [ ] **Step 3: Implementar os componentes**

`frontend/features/notas/components/NotaDeServicoForm.tsx`:

```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useFieldArray, useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { SelectNativo, TextareaNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { clientesApi, QUERY_KEY_CLIENTES } from '@/features/clientes/api';
import { QUERY_KEY_SERVICOS, servicosApi } from '@/features/servicos/api';
import { ApiError } from '@/lib/api';
import { centavosParaReais, formatarCentavos, reaisParaCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { notasApi, QUERY_KEY_NOTAS } from '../api';
import { notaDeServicoSchema, paraPayloadDeServico, type NotaDeServicoDados } from '../schemas';
import type { Nota } from '../types';

const ITEM_VAZIO = { servico_id: '', quantidade: '1', valor_unitario: '' };
const DINHEIRO = /^\d+(,\d{1,2})?$/;
const QUANTIDADE = /^\d{1,9}([.,]\d{1,3})?$/;

function paraFormulario(n: Nota): NotaDeServicoDados {
  return {
    cliente_id: n.cliente?.id ?? '',
    desconto: centavosParaReais(n.desconto_centavos),
    informacoes_complementares: n.informacoes_complementares ?? '',
    itens: (n.itens ?? []).map((i) => ({
      servico_id: i.servico_id ?? '',
      quantidade: i.quantidade.replace('.', ','),
      valor_unitario: centavosParaReais(i.valor_unitario_centavos),
    })),
  };
}

/** '2,5' → 2500. Só ajuda visual: a conta que vale é a do servidor. */
function milesimos(texto: string): number {
  if (!QUANTIDADE.test(texto)) return 0;
  const [inteiro, fracao = ''] = texto.replace(',', '.').split('.');
  return Number(inteiro) * 1000 + Number(fracao.padEnd(3, '0').slice(0, 3));
}

export function NotaDeServicoForm({ nota, onSalvo }: { nota?: Nota; onSalvo?: (nota: Nota) => void }) {
  const queryClient = useQueryClient();
  const router = useRouter();
  const clientes = useQuery({ queryKey: QUERY_KEY_CLIENTES, queryFn: async () => (await clientesApi.listar()).data });
  const servicos = useQuery({ queryKey: QUERY_KEY_SERVICOS, queryFn: async () => (await servicosApi.listar()).data });

  const form = useForm<NotaDeServicoDados>({
    resolver: zodResolver(notaDeServicoSchema),
    defaultValues: nota ? paraFormulario(nota) : { cliente_id: '', desconto: '0,00', informacoes_complementares: '', itens: [ITEM_VAZIO] },
  });
  const itens = useFieldArray({ control: form.control, name: 'itens' });
  const itensValores = useWatch({ control: form.control, name: 'itens' });
  const desconto = useWatch({ control: form.control, name: 'desconto' });

  const salvar = useMutation({
    mutationFn: (d: NotaDeServicoDados) => (nota ? notasApi.editar(nota.id, paraPayloadDeServico(d)) : notasApi.criar(paraPayloadDeServico(d))),
    onSuccess: ({ data }) => {
      toast.success('Rascunho salvo.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NOTAS });
      onSalvo?.(data);
      if (!nota) router.push(`/notas/${data.id}`);
    },
    onError: (erro) => {
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Não foi possível salvar. Tente novamente.' });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  const listaClientes = (clientes.data ?? []).filter((c) => c.estagio === 'CLIENTE');
  const subtotal = (itensValores ?? []).reduce((soma, i) => {
    const preco = i.valor_unitario !== '' ? (DINHEIRO.test(i.valor_unitario) ? reaisParaCentavos(i.valor_unitario) : 0) : (servicos.data?.find((s) => s.id === i.servico_id)?.preco_centavos ?? 0);
    return soma + Math.trunc((milesimos(i.quantidade) * preco + 500) / 1000);
  }, 0);
  const total = subtotal - (DINHEIRO.test(desconto) ? reaisParaCentavos(desconto) : 0);

  if (clientes.isError || servicos.isError) {
    return <Banner tom="perigo">Não foi possível carregar clientes e serviços. Recarregue a página.</Banner>;
  }
  if (clientes.isPending || servicos.isPending) {
    return <p className="text-muted-foreground">Carregando...</p>;
  }

  return (
    <form noValidate className="space-y-4" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="nota_cliente" label="Cliente" dica="O tomador do serviço." erro={erros.cliente_id?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('cliente_id')}>
            <option value="">Escolha um cliente</option>
            {listaClientes.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
          </SelectNativo>
        )}
      </Campo>

      <fieldset className="space-y-3">
        <legend className="font-medium">Serviços</legend>
        <p className="text-sm text-muted-foreground">Todos os serviços da nota precisam ter o mesmo código de tributação e a mesma alíquota.</p>
        {itens.fields.map((campo, i) => {
          const n = i + 1;
          return (
            <div key={campo.id} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-start">
              <Campo id={`nota_item_${n}_servico`} label={`Serviço ${n}`} erro={erros.itens?.[i]?.servico_id?.message}>
                {(a11y) => (
                  <SelectNativo {...a11y} {...form.register(`itens.${i}.servico_id`)}>
                    <option value="">Escolha um serviço</option>
                    {(servicos.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
                  </SelectNativo>
                )}
              </Campo>
              <Campo id={`nota_item_${n}_quantidade`} label={`Quantidade ${n}`} erro={erros.itens?.[i]?.quantidade?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register(`itens.${i}.quantidade`)} />}
              </Campo>
              <Campo id={`nota_item_${n}_valor`} label={`Preço unitário ${n}`} dica="Vazio usa o preço do cadastro" erro={erros.itens?.[i]?.valor_unitario?.message}>
                {(a11y) => <Input {...a11y} inputMode="decimal" placeholder="150,00" {...form.register(`itens.${i}.valor_unitario`)} />}
              </Campo>
              {itens.fields.length > 1 ? (
                <Button type="button" variant="outline" aria-label={`Remover serviço ${n}`} onClick={() => itens.remove(i)}>Remover</Button>
              ) : null}
            </div>
          );
        })}
        {typeof erros.itens?.message === 'string' ? <p className="text-sm text-danger">{erros.itens.message}</p> : null}
        <Button type="button" variant="outline" onClick={() => itens.append(ITEM_VAZIO)}>Adicionar serviço</Button>
      </fieldset>

      <Campo id="nota_desconto" label="Desconto (R$)" erro={erros.desconto?.message}>
        {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register('desconto')} />}
      </Campo>

      <Campo id="nota_info" label="Informações complementares" dica="Até 255 caracteres." erro={erros.informacoes_complementares?.message}>
        {(a11y) => <TextareaNativo {...a11y} rows={3} {...form.register('informacoes_complementares')} />}
      </Campo>

      <p className="text-sm font-medium">Total: {formatarCentavos(total)}</p>

      {erros.root ? <Banner tom="perigo">{erros.root.message}</Banner> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>Salvar rascunho</Button>
    </form>
  );
}
```

`frontend/features/notas/components/FormularioDeNota.tsx`:

```tsx
'use client';

import { useState } from 'react';
import { Campo } from '@/components/form/Campo';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import type { Nota } from '../types';
import { NotaDeServicoForm } from './NotaDeServicoForm';
import { NotaForm } from './NotaForm';

type Tipo = 'PRODUTOS' | 'SERVICOS';

/** Escolhe o formulário: produtos (NF-e/NFC-e) ou serviços (NFS-e). Nota existente usa o modelo que ela já tem. */
export function FormularioDeNota({ nota, onSalvo }: { nota?: Nota; onSalvo?: (nota: Nota) => void }) {
  const assinatura = useAssinatura();
  const temNfse = assinatura.data?.plano?.modulos.includes('FISCAL_NFSE') ?? false;
  const [tipo, setTipo] = useState<Tipo>('PRODUTOS');
  const servico = nota ? nota.modelo === 'DPS' : tipo === 'SERVICOS';

  return (
    <div className="space-y-4">
      {!nota && temNfse ? (
        <div className="max-w-xs">
          <Campo id="nota_tipo" label="Tipo de nota">
            {(a11y) => (
              <SelectNativo {...a11y} value={tipo} onChange={(e) => setTipo(e.target.value as Tipo)}>
                <option value="PRODUTOS">Produtos (NF-e ou NFC-e)</option>
                <option value="SERVICOS">Serviços (NFS-e)</option>
              </SelectNativo>
            )}
          </Campo>
        </div>
      ) : null}
      {servico ? <NotaDeServicoForm nota={nota} onSalvo={onSalvo} /> : <NotaForm nota={nota} onSalvo={onSalvo} />}
    </div>
  );
}
```

`DetalheDaNota.tsx` (edições exatas):
- Trocar `import { NotaForm } from './NotaForm';` por `import { FormularioDeNota } from './FormularioDeNota';` e, na seção de edição, `<NotaForm nota={nota} onSalvo={() => setEditando(false)} />` por `<FormularioDeNota nota={nota} onSalvo={() => setEditando(false)} />`.
- O cabeçalho passa a usar o número oficial da NFS-e: dentro do `h1`, trocar a expressão por:

```tsx
{nota.modelo === 'DPS' && nota.numero_nfse !== null
  ? `${ROTULO_MODELO.DPS} nº ${nota.numero_nfse}`
  : nota.numero !== null ? `${ROTULO_MODELO[nota.modelo]} nº ${nota.numero} · série ${nota.serie}` : `${ROTULO_MODELO[nota.modelo]} (rascunho)`}
```

- O link do PDF: trocar o texto por `{nota.modelo === 'NFCE' ? 'Abrir cupom (PDF)' : nota.modelo === 'DPS' ? 'Abrir DANFSe (PDF)' : 'Abrir DANFE (PDF)'}`.
- No cartão "Autorização", trocar `<p>Protocolo: ...</p>` por `{nota.modelo !== 'DPS' ? <p>Protocolo: <span className="font-mono">{nota.protocolo}</span></p> : null}`.
- No cartão "Cancelamento", mesma troca para `cancelamento_protocolo`.
- Mensagens de espera: `Aguardando resposta da SEFAZ.` → `Aguardando resposta {nota.modelo === 'DPS' ? 'da ADN' : 'da SEFAZ'}.` e `Aguardando a SEFAZ confirmar o cancelamento.` → `Aguardando {nota.modelo === 'DPS' ? 'a ADN' : 'a SEFAZ'} confirmar o cancelamento.`
- O cartão de itens: o título vira `{nota.modelo === 'DPS' ? 'Serviços' : 'Itens e pagamentos'}` e o `<ul>` de pagamentos só renderiza quando `nota.modelo !== 'DPS'`.
- Conferir a lista de status que mostram o PDF: `DPS` nunca está em `CONTINGENCIA`, então o link existente cobre `AUTORIZADA`, `CANCELANDO` e `CANCELADA`.

Páginas: `app/(app)/notas/nova/page.tsx`: título `Nova nota` e `<FormularioDeNota />` no lugar de `<NotaForm />` (ajustar o import); `app/(app)/notas/page.tsx`: o botão `Nova NF-e` vira `Nova nota` e o título do cartão `NF-e` vira `Notas`; `app/(app)/dashboard/page.tsx` linha 13: `m === 'FISCAL_NFE' || m === 'FISCAL_NFCE' || m === 'FISCAL_NFSE'`.

- [ ] **Step 4: Rodar e ver passar**

Run: `npx vitest run features/notas features/dashboard-fiscal app` e `npx tsc --noEmit` e `npm run lint`
Expected: PASS; `tsc` sem erros (se algum literal de `Nota`/`NotaItem` em outros testes acusar campo faltando, acrescentar `numero_nfse: null` / `servico_id: null, c_trib_nac: null`); lint só com o warning antigo do `CadastroForm`. Procurar testes que dependiam dos textos antigos: `Get-ChildItem -Recurse features,app -Include *.test.tsx | Select-String 'Nova NF-e|NF-e</'` e atualizar as asserções para `Nova nota` e `Notas`.

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(notas): telas da NFS-e (formulário de serviços, detalhe e DANFSe)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

---

### Task 13: Documentação, verificação completa e fechamento

**Files:**
- Modify: `docs/superpowers/specs/2026-10-07-f4a-nfse-nacional-design.md` (corrigir §4 e §9 pelos rulings), `docs/referencia-fiscal/02-nfephp-nfse.md`
- Modify: `TAREFAS.md`, `PROGRESSO.md`
- Memory: `C:\Users\dougl\.claude\projects\C--Users-dougl-workspace-crm\memory\project_estado_atual.md` e `MEMORY.md`

**Interfaces:**
- Consumes: tudo das Tarefas 1 a 12.
- Produces: projeto verde, documentação fechada, roteiro de homologação e a próxima fase (F4b) indicada.

- [ ] **Step 1: Verificação completa (evidência antes de afirmar)**

Run (em `backend/`): `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`.
Run (em `frontend/`): `npx vitest run`, `npx tsc --noEmit`, `npm run lint`, `npm run build`.
Expected: tudo verde (linha de base: backend 607, frontend 274; anotar os números novos). Corrigir qualquer falha antes de seguir. Se o teste `CadastroForm` estourar 5 s na suíte cheia, rodar isolado e registrar (pendência antiga).

- [ ] **Step 2: Corrigir o spec e a referência**

No spec, §4: trocar "emitente sem inscrição municipal, código IBGE, regime tributário ou certificado A1" por "emitente sem código IBGE, regime tributário (Simples/MEI) ou certificado A1; a inscrição municipal NÃO é exigida porque a DPS nunca a envia (E0120)"; e o último item (desconto) por "desconto incondicional enviado em `vDescCondIncond/vDescIncond`; a lib o expõe". No §9, trocar "XML da DPS validado contra o XSD da lib" por "estrutura do XML da DPS conferida elemento a elemento (o `DPS_v1.01.xsd` não compila no libxml, por regex com lookahead, e o `DpsValidator` da lib exige endereço do tomador, que a ADN aceita sem)". Em `docs/referencia-fiscal/02-nfephp-nfse.md`, acrescentar ao fim:

```markdown
## Neste projeto (F4a, 2026-10-07)

- `MontadorDeDps` monta a DPS a partir do retrato `NotaParaNfse`; `NfseNacionalEmissor` usa `NfseContext(certificateContent: <bytes do PFX>)` (a lib grava o temporário e o apaga no destrutor). Idempotência: `verificarDps(idDps)` (404 = não existe; outra falha = incerteza) e `consultarDps(idDps)` (devolve a chave) antes de qualquer envio; incerteza vira ERRO e nunca reenvia.
- Desconto: `valores.vDescCondIncond.vDescIncond`, entre `vServPrest` e `trib`.
- A lib desliga a verificação TLS (`SSL_VERIFYPEER=0`, `VERIFYHOST=0`) nos clientes HTTP: pendência de segurança para o go-live.
- O XSD `DPS_v1.01.xsd` não compila no libxml e o `DpsValidator` é mais rígido que a ADN: os testes conferem a estrutura do XML, não o XSD.
```

- [ ] **Step 3: Atualizar `TAREFAS.md` e `PROGRESSO.md`**

`TAREFAS.md`: marcar a F4 como dividida: F4a concluída (13 de 13, com a data) e F4b pendente; acrescentar a seção abaixo (usar a ferramenta de edição ou `@'...'@`, nunca aspas duplas):

```markdown
## Pendências da F4a (2026-10-07)
- [ ] **Segurança (go-live, F8):** a `nfse-php` desliga a verificação TLS nos clientes HTTP (`CURLOPT_SSL_VERIFYPEER=0` e `VERIFYHOST=0`), sem opção de configurar. Avaliar um fork/patch, proxy com pinning ou aceitar o risco documentado.
- [ ] **Emissão real da NFS-e em homologação** (roteiro no `PROGRESSO.md`): a comunicação com a ADN, a conversão de falha de rede em ERRO e o formato da resposta 200 de nota cancelada nunca foram exercitados.
- [ ] Município não aderido ao padrão nacional: hoje a recusa da ADN vira mensagem; `consultarParametrosConvenio(codigoIbge)` da lib pode checar a adesão antes de emitir.
- [ ] Alíquota do ISS é exigida no cadastro do serviço mas não é enviada no Simples (E0625): revisar quando houver Regime Normal.
- [ ] `cNBS` obrigatório no XSD da lib, mas a ADN aceitou notas sem ele em 09/2026: confirmar na homologação.
- [ ] ISS retido, Regime Normal, município legado (provider REST), substituição de NFS-e, e-mail ao tomador (F6/F7).
- [ ] `NfseNacionalEmissor` sem teste automatizado (precisa de certificado e ADN); interpretação, montagem da DPS e do evento são cobertas offline.
- [ ] Conferência visual do formulário de serviços, do detalhe da NFS-e e do DANFSe (temas e celular), não feita; DANFSe com Helvetica no lugar da Microsoft Sans Serif (diferença conhecida do clone).
```

`PROGRESSO.md`: substituir o parágrafo "F4a em andamento" por "F4a concluída em 2026-10-07 (13 tarefas); próxima: F4b (venda mista), sem spec: `superpowers:brainstorming`"; acrescentar a seção "Fechamento da F4a" no formato das anteriores: **Feito** (um item por tarefa), **Verificado** (números do passo 1), **NÃO verificado** (ADN real, TLS, visual), **Decisões** (rulings do plano e do ledger) e o **Roteiro de homologação real** (usuário, com A1 e município aderido): (1) cadastrar série DPS, cadastrar um serviço com `c_trib_nac` de 6 dígitos e alíquota; (2) emitir uma NFS-e para um tomador PF e conferir autorização, chave de 50 dígitos e DANFSe contra o PDF oficial; (3) consultar a nota no portal nacional; (4) cancelar com justificativa e conferir o evento 101101; (5) tentar emitir com serviço sem `c_trib_nac` (deve bloquear) e com dois serviços de códigos diferentes (deve bloquear); (6) município não aderido: a recusa deve aparecer como mensagem clara.

- [ ] **Step 4: Memória e commit**

Atualizar `project_estado_atual.md` (F4a concluída; próximo F4b; pendências do usuário: homologação real com A1 e município aderido) e a linha do `MEMORY.md` (**usar `@'...'@` ou o Edit**). Depois:

```bash
git add docs TAREFAS.md PROGRESSO.md
git commit -m "docs: F4a concluída (NFS-e Nacional)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs"
```

Recomendar `/compact` ou `/clear` antes da F4b. **Não fazer push** sem confirmação do usuário.

---

## Auto-revisão

**Cobertura do spec:** §1 corte e §2 decisões → plano inteiro (itens de mesmo código na T4); §3 dados → T2 (e `DPS` reaproveitado); §4 bloqueios → T4 (IM não exigida e desconto confirmado, ver "Rulings"); §5 motor → T5 a T8; §6 consulta e cancelamento → T7, T8 e T9; §7 DANFSe → T10; §8 API e telas → T3, T11 e T12; §9 testes → em cada tarefa; §10 critério de pronto e §11 pendências → T13. Sem lacunas.

**Desvios do spec, conscientes:** (1) IM não exigida; (2) sem XSD da DPS; (3) desconto confirmado; (4) `certificateContent`; (5) o `FormularioDeNota` em vez de reformar o `NotaForm`. A T13 corrige o spec.

**Consistência de nomes:** `NotaParaNfse`/`EmitenteParaNfse`/`TomadorParaNfse`/`ServicoParaNfse` (T5) usados em T6, T7 e T8; `ResultadoDeEmissaoNfse`/`ResultadoDeConsultaNfse` (T5) em T7 e T8; `EmissorDeNfse` (T5) em T7 a T9; `EmissorDeNfseFake` (T5) em T8 a T11; `CenarioDeNfse` com `notaDeNfsePronta` (T3/T8) em T8 a T11; `GeradorDeDanfse` (T10).

**Pontos a conferir na execução (marcados nos passos):** nomes de caso em `CertificadoStatus`/`AmbienteFiscal`; coluna do plano no `Tenant` nos testes de módulo desligado; propriedades atribuíveis em `NfseData`; construtores nomeados de `RegistroEventoResponse` e `MensagemProcessamentoDto`; preservação do índice de `chave` no `->change()`; helper `withoutTenantScope`; extensão `gd` para o QR; testes antigos que citam "Nova NF-e".

