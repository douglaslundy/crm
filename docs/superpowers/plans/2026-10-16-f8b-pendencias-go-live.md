# F8b Pendências de go-live Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ligar a verificação TLS nas libs fiscais, separar o contador de numeração por ambiente e deixar a configuração de produção pronta (template, proxy HTTPS, runbook).

**Architecture:** (1) `cweagans/composer-patches` corrige 4 arquivos do `vendor` (3 clientes da `nfse-php` + `SoapCurl` da `sped-common`) e um teste de arquitetura impede regressão. (2) `emitente_series` ganha `proximo_numero_producao`; o model expõe `proximoNumeroDo(AmbienteFiscal)` e `definirProximoNumero(...)` e nenhum chamador monta nome de coluna. (3) Arquivos de deploy novos, sem tocar no staging.

**Tech Stack:** Laravel 12 / PHP 8.2, PHPUnit 11, composer-patches 1.7, Next.js + Vitest, Docker Compose, Caddy.

**Spec:** `docs/superpowers/specs/2026-10-16-f8b-pendencias-go-live-design.md`

## Global Constraints

- `declare(strict_types=1)` em todo PHP; TypeScript strict, sem `any`.
- Mudança de schema só por migration. Nenhum dado real em fixtures.
- Nunca chutar valor fiscal: número inicial de produção é informado pelo usuário (campo pré-preenchido com 1 e confirmado). Zero é inválido (`min:1`).
- Todo Model de tenant já tem Global Scope; `EmitenteSerie` usa `BelongsToTenant`.
- Backend: Pint e PHPStan limpos; testes com PHPUnit (`vendor/bin/phpunit`). Armadilhas conhecidas: o shell come barras invertidas (usar Write/Edit); CRLF; `use` esquecido em teste; `status()` é reservado do PHPUnit.
- `../mecanicapro` é só leitura (não é tocado aqui).
- Staging atual (`deploy/docker-compose.yml`, `deploy/.env.example`) não muda de comportamento.

## Review Focus

- Produção reentrante: `ConfirmarProducao` chamado de novo depois de haver nota de produção não pode reiniciar o contador de produção (testado na Task 4).
- Emitente que **já está** em PRODUCAO antes da migration: `proximo_numero_producao` herda `proximo_numero`, senão repete número na SEFAZ (testado na Task 2).
- Número inicial inválido (0, negativo, texto) na virada: 422, nada gravado (Task 4).
- Série de modelo não configurada na virada: ignorada, sem erro (Task 4).
- Patch não aplicado ou desfeito por `composer update`: o teste de arquitetura falha alto (Task 1).
- NFC-e/DPS usam o mesmo contador por modelo: os testes de inutilização e de reaproveitamento de número (539) cobrem o ambiente da nota, não o do emitente (Task 3).

---

## Task 1: TLS ligado nas libs fiscais (composer-patches)

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock`
- Create: `backend/patches/nfse-php-verificar-tls.patch`, `backend/patches/sped-common-verificar-tls.patch`
- Create: `backend/tests/Unit/Fiscal/TlsDasLibsFiscaisTest.php`
- Modify: `deploy/Dockerfile.backend`
- Modify: `backend/.env.example`, `deploy/.env.example` (documentar `FISCAL_CA_BUNDLE`)
- Modify: `backend/app/Modules/Fiscal/Infrastructure/Nfse/NfseNacionalEmissor.php:24` (tirar o aviso "desliga a verificação TLS")

**Interfaces:**
- Consumes: nada.
- Produces: variável de ambiente `FISCAL_CA_BUNDLE` (caminho de um `.pem`; vazio = bundle do sistema), lida pelas libs via `$_SERVER`/`getenv`. Usada pela Task 6 (template e runbook).

- [ ] **Step 1: Escrever o teste que falha**

`backend/tests/Unit/Fiscal/TlsDasLibsFiscaisTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * As libs fiscais nascem com a verificação TLS desligada. O patch (backend/patches) liga; este teste falha se o patch
 * não for aplicado ou se uma atualização de lib o desfizer.
 */
class TlsDasLibsFiscaisTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function arquivos(): array
    {
        return [
            'AdnClient' => ['nfse-nacional/nfse-php/src/Http/Client/AdnClient.php'],
            'CncClient' => ['nfse-nacional/nfse-php/src/Http/Client/CncClient.php'],
            'SefinClient' => ['nfse-nacional/nfse-php/src/Http/Client/SefinClient.php'],
            'SoapCurl' => ['nfephp-org/sped-common/src/Soap/SoapCurl.php'],
        ];
    }

    #[DataProvider('arquivos')]
    public function test_verificacao_tls_nao_esta_desligada(string $relativo): void
    {
        $caminho = base_path('vendor/'.$relativo);
        $this->assertFileExists($caminho);
        $codigo = (string) file_get_contents($caminho);

        $this->assertDoesNotMatchRegularExpression(
            '/CURLOPT_SSL_VERIFY(?:PEER|HOST)\s*(?:,|=>)\s*(?:0|false)\b/',
            $codigo,
            "{$relativo} desliga a verificação TLS: o patch de backend/patches não foi aplicado.",
        );
        $this->assertStringContainsString('FISCAL_CA_BUNDLE', $codigo);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Fiscal/TlsDasLibsFiscaisTest.php`
Expected: 4 FAIL ("desliga a verificação TLS").

- [ ] **Step 3: Instalar o plugin (vendor ainda sem patch)**

```bash
cd backend
composer require cweagans/composer-patches:^1.7 --no-interaction
git diff --stat composer.json   # deve ter só o require e o allow-plugins
```

Se o `allow-plugins` não for adicionado sozinho, incluir `"cweagans/composer-patches": true` em `config.allow-plugins`.

- [ ] **Step 4: Gerar os patches a partir do vendor intacto**

Rodar em bash, de dentro de `backend/`:

```bash
set -e
W=$(mktemp -d)
CA="\$_SERVER['FISCAL_CA_BUNDLE'] ?? getenv('FISCAL_CA_BUNDLE')"

# nfse-php: 3 clientes (array de opções do Guzzle)
mkdir -p "$W/n/a" "$W/n/b"
cp -r vendor/nfse-nacional/nfse-php/src "$W/n/a/src"
cp -r vendor/nfse-nacional/nfse-php/src "$W/n/b/src"
for f in AdnClient CncClient SefinClient; do
  sed -i "s|CURLOPT_SSL_VERIFYHOST => 0,|CURLOPT_SSL_VERIFYHOST => 2,|; s|CURLOPT_SSL_VERIFYPEER => 0,|CURLOPT_SSL_VERIFYPEER => 1,\n                ...((\$ca = ${CA}) ? [CURLOPT_CAINFO => \$ca] : []),|" "$W/n/b/src/Http/Client/$f.php"
done
(cd "$W/n" && diff -ruN a b) > patches/nfse-php-verificar-tls.patch || true

# sped-common: 2 pontos no SoapCurl
mkdir -p "$W/s/a" "$W/s/b"
cp -r vendor/nfephp-org/sped-common/src "$W/s/a/src"
cp -r vendor/nfephp-org/sped-common/src "$W/s/b/src"
sed -i "s|curl_setopt(\$oCurl, CURLOPT_SSL_VERIFYHOST, 0);|curl_setopt(\$oCurl, CURLOPT_SSL_VERIFYHOST, 2);|; s|curl_setopt(\$oCurl, CURLOPT_SSL_VERIFYPEER, 0);|curl_setopt(\$oCurl, CURLOPT_SSL_VERIFYPEER, 1);\n            if ((\$ca = ${CA}) \&\& is_file(\$ca)) {\n                curl_setopt(\$oCurl, CURLOPT_CAINFO, \$ca);\n            }|" "$W/s/b/src/Soap/SoapCurl.php"
(cd "$W/s" && diff -ruN a b) > patches/sped-common-verificar-tls.patch || true

grep -c "^@@" patches/*.patch   # nfse: 3 hunks; sped-common: 2 hunks
```

Conferir a olho `patches/*.patch`: os caminhos começam com `a/src/` e `b/src/`, e não há mudança além das linhas de TLS. Se o `sed` quebrar (`\&`, `\n`), editar os dois arquivos do `vendor` com Edit, regenerar o diff com `diff -ruN a b` e seguir.

- [ ] **Step 5: Declarar os patches e reaplicar**

Em `backend/composer.json`, dentro de `extra`:

```json
"patches": {
    "nfse-nacional/nfse-php": {
        "Liga a verificação TLS (VERIFYPEER=1, VERIFYHOST=2) e aceita FISCAL_CA_BUNDLE": "patches/nfse-php-verificar-tls.patch"
    },
    "nfephp-org/sped-common": {
        "Liga a verificação TLS (VERIFYPEER=1, VERIFYHOST=2) e aceita FISCAL_CA_BUNDLE": "patches/sped-common-verificar-tls.patch"
    }
}
```

```bash
cd backend
rm -rf vendor/nfse-nacional/nfse-php vendor/nfephp-org/sped-common
composer install --no-interaction
grep -n "VERIFYPEER\|VERIFYHOST\|FISCAL_CA_BUNDLE" vendor/nfse-nacional/nfse-php/src/Http/Client/AdnClient.php vendor/nfephp-org/sped-common/src/Soap/SoapCurl.php
php -l vendor/nfephp-org/sped-common/src/Soap/SoapCurl.php && php -l vendor/nfse-nacional/nfse-php/src/Http/Client/AdnClient.php
```

Expected: `composer install` imprime "Applying patches"; os greps mostram `=> 2`, `=> 1` e `FISCAL_CA_BUNDLE`; `php -l` sem erro.

- [ ] **Step 6: Rodar o teste e ver passar**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Fiscal/TlsDasLibsFiscaisTest.php`
Expected: 4 PASS.

- [ ] **Step 7: Imagem Docker aplica o patch**

Em `deploy/Dockerfile.backend`, trocar o bloco do `composer install`:

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends patch \
    && rm -rf /var/lib/apt/lists/*
COPY --chown=www-data:www-data backend/composer.json backend/composer.lock ./
COPY --chown=www-data:www-data backend/patches ./patches
RUN composer install $( [ "$INSTALL_DEV" = "1" ] || echo --no-dev ) --no-scripts --no-autoloader --prefer-dist --no-interaction
```

(A linha do `COPY backend/composer.json backend/composer.lock` já existe; o `COPY backend/patches` e o `apt-get` vão antes do `composer install`, ainda como `USER root`.)

Verificar: `docker build -f deploy/Dockerfile.backend -t crm-app:patchtest .` (da raiz). Se Docker não existir na máquina, registrar "não verificado" no `PROGRESSO.md` e deixar para o deploy.

- [ ] **Step 8: Documentar a variável e tirar o aviso do emissor**

- `backend/.env.example` e `deploy/.env.example`: acrescentar

```
# Bundle de CAs (.pem) usado na verificação TLS da SEFAZ e da ADN (cadeia ICP-Brasil). Vazio = bundle do sistema.
FISCAL_CA_BUNDLE=
```

- `NfseNacionalEmissor.php`: trocar a linha 24 `ATENÇÃO: a lib desliga a verificação TLS...` por `A verificação TLS fica ligada por patch (backend/patches); o bundle de CAs vem de FISCAL_CA_BUNDLE.`
- `docs/referencia-fiscal/02-nfephp-nfse.md:82`: trocar a linha da pendência por "Verificação TLS ligada por patch (F8b); CAs em `FISCAL_CA_BUNDLE`".

- [ ] **Step 9: Suíte fiscal e commit**

```bash
cd backend && vendor/bin/phpunit --testsuite Unit 2>&1 | tail -5
vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress | tail -3
cd .. && git add backend/composer.json backend/composer.lock backend/patches backend/tests/Unit/Fiscal/TlsDasLibsFiscaisTest.php deploy/Dockerfile.backend backend/.env.example deploy/.env.example backend/app/Modules/Fiscal/Infrastructure/Nfse/NfseNacionalEmissor.php docs/referencia-fiscal/02-nfephp-nfse.md
git commit -m "fix(fiscal): liga a verificação TLS das libs fiscais por patch" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01A1d98n59FZTKR94ucCX4Sx"
```

---

## Task 2: Coluna `proximo_numero_producao` e helpers do model

**Files:**
- Create: `backend/database/migrations/2026_10_19_000001_add_proximo_numero_producao_to_emitente_series.php`
- Modify: `backend/app/Modules/Fiscal/Domain/Models/EmitenteSerie.php`
- Modify: `backend/database/factories/EmitenteSerieFactory.php`
- Test: `backend/tests/Feature/Fiscal/ContadorPorAmbienteTest.php`

**Interfaces:**
- Consumes: `App\Modules\Fiscal\Domain\Enums\AmbienteFiscal` (`Homologacao`, `Producao`).
- Produces (usados nas Tasks 3 e 4):
  - `EmitenteSerie::colunaDoNumero(AmbienteFiscal $ambiente): string` (static)
  - `EmitenteSerie::proximoNumeroDo(AmbienteFiscal $ambiente): int`
  - `EmitenteSerie::definirProximoNumero(AmbienteFiscal $ambiente, int $numero): void`
  - propriedade `int $proximo_numero_producao`

- [ ] **Step 1: Escrever os testes que falham**

`backend/tests/Feature/Fiscal/ContadorPorAmbienteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class ContadorPorAmbienteTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function serie(): EmitenteSerie
    {
        return EmitenteSerie::query()->where('emitente_id', $this->emitente->id)->firstOrFail();
    }

    public function test_cada_ambiente_tem_o_seu_contador(): void
    {
        $serie = $this->serie();
        $serie->definirProximoNumero(AmbienteFiscal::Homologacao, 7);

        $serie->refresh();
        $this->assertSame(7, $serie->proximoNumeroDo(AmbienteFiscal::Homologacao));
        $this->assertSame(1, $serie->proximoNumeroDo(AmbienteFiscal::Producao));

        $serie->definirProximoNumero(AmbienteFiscal::Producao, 500);
        $serie->refresh();
        $this->assertSame(7, $serie->proximo_numero);
        $this->assertSame(500, $serie->proximo_numero_producao);
    }

    public function test_coluna_do_numero_por_ambiente(): void
    {
        $this->assertSame('proximo_numero', EmitenteSerie::colunaDoNumero(AmbienteFiscal::Homologacao));
        $this->assertSame('proximo_numero_producao', EmitenteSerie::colunaDoNumero(AmbienteFiscal::Producao));
    }

    public function test_serie_nova_nasce_com_producao_em_1(): void
    {
        $nova = EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfce, 'serie' => '2']);

        $this->assertSame(1, $nova->refresh()->proximo_numero_producao);
    }

    public function test_migration_herda_o_contador_de_quem_ja_estava_em_producao(): void
    {
        // Estado pré-migration: o contador único valia para os dois ambientes.
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        DB::table('emitente_series')->where('emitente_id', $this->emitente->id)->update(['proximo_numero' => 42, 'proximo_numero_producao' => 1]);

        $migration = require database_path('migrations/2026_10_19_000001_add_proximo_numero_producao_to_emitente_series.php');
        $migration->herdarContadorDeQuemJaEstaEmProducao();

        $this->assertSame(42, $this->serie()->proximo_numero_producao);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && vendor/bin/phpunit tests/Feature/Fiscal/ContadorPorAmbienteTest.php`
Expected: FAIL (coluna e métodos inexistentes).

- [ ] **Step 3: Migration**

`backend/database/migrations/2026_10_19_000001_add_proximo_numero_producao_to_emitente_series.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emitente_series', function (Blueprint $table): void {
            // `proximo_numero` passa a valer só para homologação; produção tem contador próprio.
            $table->unsignedBigInteger('proximo_numero_producao')->default(1)->after('proximo_numero');
        });

        $this->herdarContadorDeQuemJaEstaEmProducao();
    }

    /**
     * Quem já emitia em produção numerou com o contador único: sem herdar, a produção recomeçaria do 1 e repetiria
     * número na SEFAZ (539).
     */
    public function herdarContadorDeQuemJaEstaEmProducao(): void
    {
        DB::table('emitente_series')
            ->whereIn('emitente_id', DB::table('emitentes')->where('ambiente_fiscal', 'PRODUCAO')->select('id'))
            ->update(['proximo_numero_producao' => DB::raw('proximo_numero')]);
    }

    public function down(): void
    {
        Schema::table('emitente_series', function (Blueprint $table): void {
            $table->dropColumn('proximo_numero_producao');
        });
    }
};
```

- [ ] **Step 4: Model e factory**

`EmitenteSerie.php`: adicionar `use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;`, o docblock `@property int $proximo_numero_producao`, `fillable` com `'proximo_numero_producao'` e os métodos:

```php
    /** Homologação usa `proximo_numero`; produção usa `proximo_numero_producao` (contadores independentes). */
    public static function colunaDoNumero(AmbienteFiscal $ambiente): string
    {
        return $ambiente === AmbienteFiscal::Producao ? 'proximo_numero_producao' : 'proximo_numero';
    }

    public function proximoNumeroDo(AmbienteFiscal $ambiente): int
    {
        return (int) $this->getAttribute(self::colunaDoNumero($ambiente));
    }

    public function definirProximoNumero(AmbienteFiscal $ambiente, int $numero): void
    {
        $this->update([self::colunaDoNumero($ambiente) => $numero]);
    }
```

`EmitenteSerieFactory::definition()`: acrescentar `'proximo_numero_producao' => 1,`.

- [ ] **Step 5: Rodar e ver passar**

Run: `cd backend && vendor/bin/phpunit tests/Feature/Fiscal/ContadorPorAmbienteTest.php`
Expected: 4 PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database backend/app/Modules/Fiscal/Domain/Models/EmitenteSerie.php backend/tests/Feature/Fiscal/ContadorPorAmbienteTest.php
git commit -m "feat(fiscal): contador de numeração de produção separado da homologação" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01A1d98n59FZTKR94ucCX4Sx"
```

---

## Task 3: Todos os chamadores usam o contador do ambiente

**Files:**
- Modify: `backend/app/Modules/Fiscal/Application/Actions/IniciarEmissao.php:~118-125`
- Modify: `backend/app/Modules/Fiscal/Application/Actions/AplicarResultadoDaEmissao.php:~108-113`
- Modify: `backend/app/Modules/Fiscal/Application/Actions/AtualizarSerie.php`
- Modify: `backend/app/Modules/Fiscal/Application/Actions/InutilizarNumeracao.php:~58-63`
- Modify: `backend/app/Modules/Fiscal/Http/Resources/EmitenteSerieResource.php`
- Test: `backend/tests/Feature/Fiscal/ContadorPorAmbienteTest.php` (acrescentar)
- Fix: testes existentes que emitem em PRODUCAO (lista no Step 6)

**Interfaces:**
- Consumes: `EmitenteSerie::proximoNumeroDo`, `definirProximoNumero`, `colunaDoNumero` (Task 2); `AmbienteFiscal::from(string)`.
- Produces: o `proximo_numero` do resource passa a ser o contador **do ambiente atual do emitente** (contrato do frontend inalterado).

- [ ] **Step 1: Escrever os testes que falham** (acrescentar a `ContadorPorAmbienteTest`)

```php
    public function test_emissao_em_producao_usa_e_avanca_so_o_contador_de_producao(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        $this->serie()->update(['proximo_numero' => 9, 'proximo_numero_producao' => 500]);

        $nota = app(\App\Modules\Fiscal\Application\Actions\IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);

        $this->assertSame(500, $nota->numero);
        $this->assertSame('PRODUCAO', $nota->ambiente);
        $this->assertSame(9, $this->serie()->proximo_numero);
        $this->assertSame(501, $this->serie()->proximo_numero_producao);
    }

    public function test_emissao_em_homologacao_nao_move_o_contador_de_producao(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->serie()->update(['proximo_numero_producao' => 500]);

        $nota = app(\App\Modules\Fiscal\Application\Actions\IniciarEmissao::class)->executar($this->rascunho()->id, $this->admin);

        $this->assertSame(1, $nota->numero);
        $this->assertSame(2, $this->serie()->proximo_numero);
        $this->assertSame(500, $this->serie()->proximo_numero_producao);
    }

    public function test_atualizar_serie_grava_no_contador_do_ambiente_atual(): void
    {
        $acao = app(\App\Modules\Fiscal\Application\Actions\AtualizarSerie::class);

        $acao->executar($this->emitente, ModeloDocumento::Nfe, '1', 30);
        $this->assertSame(30, $this->serie()->proximo_numero);
        $this->assertSame(1, $this->serie()->proximo_numero_producao);

        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        $acao->executar($this->emitente->refresh(), ModeloDocumento::Nfe, '1', 1);
        $this->assertSame(30, $this->serie()->proximo_numero);
        $this->assertSame(1, $this->serie()->proximo_numero_producao);
    }

    public function test_resource_da_serie_mostra_o_contador_do_ambiente_atual(): void
    {
        $this->serie()->update(['proximo_numero' => 9, 'proximo_numero_producao' => 500]);
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);

        $json = (new \App\Modules\Fiscal\Http\Resources\EmitenteSerieResource($this->serie()->refresh()))->resolve();

        $this->assertSame(500, $json['proximo_numero']);
    }

    private function rascunho(): \App\Modules\Fiscal\Domain\Models\Nota
    {
        return app(\App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota::class)->executar([
            'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0,
            'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $this->admin);
    }
```

(Importar os nomes com `use` no topo em vez dos nomes completos, ao colar.)

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && vendor/bin/phpunit tests/Feature/Fiscal/ContadorPorAmbienteTest.php`
Expected: os 4 novos FAIL.

- [ ] **Step 3: `IniciarEmissao`**

Trocar o bloco `if ($numeroNovo)` (usa `$emitente->ambiente_fiscal`, que é o enum):

```php
            if ($numeroNovo) {
                $serie = EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', $nota->modelo)->lockForUpdate()->firstOrFail();
                $nota->numero = $serie->proximoNumeroDo($emitente->ambiente_fiscal);
                $nota->serie = $serie->serie;
                $serie->definirProximoNumero($emitente->ambiente_fiscal, $nota->numero + 1);
            }
```

- [ ] **Step 4: `AplicarResultadoDaEmissao::tratarNumeroOcupado`**

O ambiente é o da **nota** (já enviada), não o do emitente:

```php
            $ambiente = AmbienteFiscal::from((string) $nota->ambiente);
            $serie = EmitenteSerie::query()->where('emitente_id', $emitente->id)->where('modelo', ModeloDocumento::Nfe)->lockForUpdate()->firstOrFail();
            $numero = $serie->proximoNumeroDo($ambiente);
            $nota->forceFill([
                'numero' => $numero, 'tentativas_numero' => $nota->tentativas_numero + 1,
                'chave' => null, 'xml_enviado' => null, 'cstat' => '539', 'motivo' => $r->motivo,
            ])->save();
            $serie->definirProximoNumero($ambiente, $numero + 1);
```

Adicionar `use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;` se faltar.

- [ ] **Step 5: `AtualizarSerie`, `InutilizarNumeracao`, resource**

`AtualizarSerie`: no `updateOrCreate`, a coluna do ambiente:

```php
            return EmitenteSerie::query()->updateOrCreate(
                ['emitente_id' => $emitente->id, 'modelo' => $modelo],
                ['serie' => $serie, EmitenteSerie::colunaDoNumero($emitente->ambiente_fiscal) => $proximoNumero],
            );
```

Atualizar o docblock da classe: "Cada ambiente tem o seu contador; a guarda vale para o ambiente do emitente."

`InutilizarNumeracao`: mover `$ambiente = $emitente->ambiente_fiscal->value;` para antes da guarda e usar o enum:

```php
        $proximo = $serie->proximoNumeroDo($emitente->ambiente_fiscal);
        if ($final >= $proximo) {
            throw new EmissaoBloqueadaException('FISCAL_FAIXA_NAO_ALOCADA', 'Só é possível inutilizar números já alocados (até o '.($proximo - 1).').');
        }

        $ambiente = $emitente->ambiente_fiscal->value;
```

`EmitenteSerieResource`: `'proximo_numero' => $this->proximoNumeroDo($this->emitente->ambiente_fiscal),` e o `@mixin`/docblock continua.

- [ ] **Step 6: Rodar tudo e consertar testes antigos em produção**

```bash
cd backend && vendor/bin/phpunit tests/Feature/Fiscal tests/Feature/Api 2>&1 | tail -30
```

Todo teste que põe o emitente em `PRODUCAO` e semeia `proximo_numero: N` precisa semear também `'proximo_numero_producao' => N` (candidatos: `EmitirNotaV1Test`, `RevisaoFinalF6bTest`, `ConfiguracaoFiscalTest`, `EmitirVendaMistaTest`, `FluxoNfceTest`, `IniciarEmissaoTest`, `InutilizarNumeracaoTest`, fixtures `CenarioDeNota`/`CenarioDeNfse`). Para cada falha, entender se o teste espera o contador de homologação ou de produção e corrigir o setup, nunca o código. Repetir até verde.

Expected: sem falhas.

- [ ] **Step 7: Qualidade e commit**

```bash
cd backend && vendor/bin/pint && vendor/bin/phpstan analyse --no-progress | tail -3
cd .. && git add -A backend && git commit -m "feat(fiscal): emissão, série e inutilização usam o contador do ambiente" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01A1d98n59FZTKR94ucCX4Sx"
```

---

## Task 4: "Ir para produção" com número inicial

**Files:**
- Modify: `backend/app/Modules/Fiscal/Http/Requests/ConfirmarProducaoRequest.php`
- Modify: `backend/app/Modules/Fiscal/Http/Controllers/ConfiguracaoFiscalController.php:39-44`
- Modify: `backend/app/Modules/Fiscal/Application/Actions/ConfirmarProducao.php`
- Test: `backend/tests/Feature/Fiscal/ConfiguracaoFiscalTest.php` (acrescentar)

**Interfaces:**
- Consumes: `EmitenteSerie::definirProximoNumero` (Task 2).
- Produces: `POST /api/app/emitente/ambiente/producao` com `{confirmo: true, numeros_iniciais?: {NFE?: int, NFCE?: int, DPS?: int}}`; usado pelo frontend (Task 5). `ConfirmarProducao::executar(Emitente $emitente, Usuario $autor, array $numerosIniciais = []): Emitente` com `$numerosIniciais` indexado por valor de `ModeloDocumento`.

- [ ] **Step 1: Escrever os testes que falham** (em `ConfiguracaoFiscalTest`, ao lado de `test_confirmar_producao_muda_o_ambiente_e_audita`; reaproveitar `nfeDeTesteAutorizada()`)

```php
    public function test_confirmar_producao_grava_o_numero_inicial_de_producao_por_serie(): void
    {
        $this->nfeDeTesteAutorizada();
        EmitenteSerie::query()->update(['proximo_numero' => 12]);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['NFE' => 500]])
            ->assertOk();

        $serie = EmitenteSerie::query()->firstOrFail();
        $this->assertSame(500, $serie->proximo_numero_producao);
        $this->assertSame(12, $serie->proximo_numero);
    }

    public function test_confirmar_producao_sem_numero_inicial_comeca_do_1(): void
    {
        $this->nfeDeTesteAutorizada();
        EmitenteSerie::query()->update(['proximo_numero' => 12, 'proximo_numero_producao' => 77]);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])->assertOk();

        $this->assertSame(1, EmitenteSerie::query()->firstOrFail()->proximo_numero_producao);
    }

    public function test_numero_inicial_invalido_e_recusado_e_nada_muda(): void
    {
        $this->nfeDeTesteAutorizada();

        foreach ([0, -3, 'abc'] as $ruim) {
            $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['NFE' => $ruim]])
                ->assertStatus(422);
        }
        $this->spa()->actingAs($this->admin)->getJson('/api/app/emitente')->assertJsonPath('data.ambiente_fiscal', 'HOMOLOGACAO');
    }

    public function test_serie_de_modelo_nao_configurado_na_virada_e_ignorada(): void
    {
        $this->nfeDeTesteAutorizada();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['DPS' => 9]])
            ->assertOk();
    }

    public function test_reentrada_nao_reinicia_o_contador_de_producao(): void
    {
        $this->nfeDeTesteAutorizada();
        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['NFE' => 500]])->assertOk();
        $nota = Nota::query()->firstOrFail()->replicate();
        $nota->forceFill(['ambiente' => 'PRODUCAO', 'numero' => 500, 'status' => StatusNota::Autorizada])->save();
        EmitenteSerie::query()->update(['proximo_numero_producao' => 501]);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['NFE' => 1]])->assertOk();

        $this->assertSame(501, EmitenteSerie::query()->firstOrFail()->proximo_numero_producao);
    }
```

Se a fixture de `ConfiguracaoFiscalTest` não criar série, criar uma no `setUp` do teste (`EmitenteSerie::factory()->create([... 'modelo' => ModeloDocumento::Nfe])` com `emitente_id`) e importar `EmitenteSerie`, `ModeloDocumento`, `StatusNota`, `Nota` se faltarem. O `replicate()` de `Nota` pode exigir campos únicos (chave): se violar índice, criar a nota com `Nota::factory()->create([...])`.

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && vendor/bin/phpunit --filter "numero_inicial|reentrada|nao_configurado|comeca_do_1" tests/Feature/Fiscal/ConfiguracaoFiscalTest.php`
Expected: FAIL.

- [ ] **Step 3: Request**

```php
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirmo' => ['required', 'accepted'],
            'numeros_iniciais' => ['sometimes', 'array'],
            'numeros_iniciais.*' => ['required', 'integer', 'min:1', 'max:999999999'],
        ];
    }

    /** @return array<string, int> modelo (valor do enum) => número inicial de produção */
    public function numerosIniciais(): array
    {
        /** @var array<string, int> $numeros */
        $numeros = array_map('intval', (array) $this->validated('numeros_iniciais', []));

        return $numeros;
    }
```

- [ ] **Step 4: Controller**

```php
        return new EmitenteResource(
            $acao->executar($this->emitente(), $this->autor($request), $request->numerosIniciais())->load('series'),
        );
```

- [ ] **Step 5: Action**

```php
    /** @param array<string, int> $numerosIniciais número inicial de produção por modelo (valor do enum); ausente = 1 */
    public function executar(Emitente $emitente, Usuario $autor, array $numerosIniciais = []): Emitente
    {
        $temTeste = /* inalterado */;
        if (! $temTeste) { /* inalterado */ }

        return DB::transaction(function () use ($emitente, $autor, $numerosIniciais): Emitente {
            // Reentrada: com nota numerada em produção o contador é a verdade, nunca se reinicia.
            $jaNumerouEmProducao = Nota::query()->where('ambiente', AmbienteFiscal::Producao->value)->whereNotNull('numero')->exists();
            if (! $jaNumerouEmProducao) {
                foreach (EmitenteSerie::query()->where('emitente_id', $emitente->id)->lockForUpdate()->get() as $serie) {
                    $serie->definirProximoNumero(AmbienteFiscal::Producao, $numerosIniciais[$serie->modelo->value] ?? 1);
                }
            }

            $emitente->update(['ambiente_fiscal' => AmbienteFiscal::Producao]);

            activity('fiscal')->performedOn($emitente)->causedBy($autor)->event('ambiente_producao_confirmado')
                ->log('Ambiente fiscal alterado para produção');

            return $emitente;
        });
    }
```

Imports: `Illuminate\Support\Facades\DB`, `App\Modules\Fiscal\Domain\Models\EmitenteSerie`.

- [ ] **Step 6: Rodar e ver passar; commit**

```bash
cd backend && vendor/bin/phpunit tests/Feature/Fiscal/ConfiguracaoFiscalTest.php | tail -5
vendor/bin/pint && vendor/bin/phpstan analyse --no-progress | tail -3
cd .. && git add -A backend && git commit -m "feat(fiscal): ir para produção com número inicial por série" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01A1d98n59FZTKR94ucCX4Sx"
```

Atualizar também `docs/api/openapi.yaml` só se a rota `ambiente/producao` constar nele (ela é `api/app`, normalmente fora do OpenAPI da API pública; conferir com `grep -n "ambiente/producao" docs/api/openapi.yaml`).

---

## Task 5: Frontend: número inicial na virada

**Files:**
- Modify: `frontend/features/emitente/api.ts:37`
- Modify: `frontend/features/emitente/components/ConfirmarProducao.tsx`
- Test: `frontend/features/emitente/components/Emitente.test.tsx` (bloco `ConfirmarProducao`, ~linha 81)

**Interfaces:**
- Consumes: `POST /api/app/emitente/ambiente/producao` com `numeros_iniciais` (Task 4); `Emitente.series: EmitenteSerie[]` (`modelo`, `serie`, `proximo_numero`).
- Produces: nada para outras tarefas.

- [ ] **Step 1: Teste que falha** (em `Emitente.test.tsx`, junto dos testes de `ConfirmarProducao`; seguir o estilo dos testes vizinhos para `renderizar`, mock de `fetch`/`emitenteApi`)

```tsx
  it('envia o número inicial de produção de cada série (padrão 1)', async () => {
    const confirmarProducao = vi.spyOn(emitenteApi, 'confirmarProducao').mockResolvedValue({ data: EMITENTE_VAZIO });
    renderizar(
      <ConfirmarProducao
        emitente={{ ...EMITENTE_VAZIO, emissao_de_teste_autorizada: true, series: [{ modelo: 'NFE', serie: '1', proximo_numero: 12 }] }}
      />,
    );

    const campo = screen.getByLabelText('Primeiro número em produção (NF-e)');
    expect(campo).toHaveValue(1);
    await userEvent.clear(campo);
    await userEvent.type(campo, '500');
    await userEvent.click(screen.getByLabelText(/Entendo que as notas/));
    await userEvent.click(screen.getByRole('button', { name: 'Ir para produção' }));

    await waitFor(() => expect(confirmarProducao).toHaveBeenCalledWith({ NFE: 500 }));
  });
```

(Adaptar os imports reais do arquivo: `userEvent`, `waitFor`, `vi`, `emitenteApi`.)

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd frontend && npx vitest run features/emitente/components/Emitente.test.tsx`
Expected: FAIL (campo inexistente).

- [ ] **Step 3: API**

```ts
  confirmarProducao: (numerosIniciais: Partial<Record<ModeloDocumento, number>> = {}) =>
    api<{ data: Emitente }>('/api/app/emitente/ambiente/producao', {
      method: 'POST',
      body: JSON.stringify({ confirmo: true, numeros_iniciais: numerosIniciais }),
    }),
```

Importar `ModeloDocumento` de `./types` se faltar.

- [ ] **Step 4: Componente**

Em `ConfirmarProducao.tsx`:

```tsx
const ROTULO_DO_MODELO: Record<ModeloDocumento, string> = { NFE: 'NF-e', NFCE: 'NFC-e', DPS: 'NFS-e' };
```

No componente: `const [numeros, setNumeros] = useState<Partial<Record<ModeloDocumento, string>>>({});`; `mutationFn: (n: Partial<Record<ModeloDocumento, number>>) => emitenteApi.confirmarProducao(n)`; no submit montar o objeto convertendo cada série com `Number(numeros[s.modelo] ?? '1')` e bloqueando (setErro) se algum não for inteiro >= 1; dentro do `<form>`, antes do checkbox:

```tsx
          {emitente.series.map((s) => (
            <div key={s.modelo} className="space-y-1">
              <label htmlFor={`inicial_${s.modelo}`} className="text-sm">{`Primeiro número em produção (${ROTULO_DO_MODELO[s.modelo]})`}</label>
              <Input
                id={`inicial_${s.modelo}`}
                type="number"
                min={1}
                value={numeros[s.modelo] ?? '1'}
                onChange={(e) => setNumeros((n) => ({ ...n, [s.modelo]: e.target.value }))}
              />
            </div>
          ))}
```

Adicionar `import { Input } from '@/components/ui/input';` e o texto de ajuda: "A numeração de produção é independente da de homologação. Informe o próximo número livre da sua série na SEFAZ."

- [ ] **Step 5: Verificar e commit**

```bash
cd frontend && npx vitest run features/emitente && npx tsc --noEmit && npx eslint features/emitente | tail -5
cd .. && git add frontend/features/emitente && git commit -m "feat(emitente): número inicial de produção na virada de ambiente" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01A1d98n59FZTKR94ucCX4Sx"
```

Expected: testes e tsc verdes. O `eslint` pode mostrar o warning antigo do `CadastroForm`/mudanças locais de lint não commitadas (fora do escopo; não tocar).

---

## Task 6: Configuração de produção (template, Caddy, runbook)

**Files:**
- Create: `deploy/.env.production.example`
- Create: `deploy/Caddyfile`
- Create: `deploy/docker-compose.producao.yml`
- Create: `docs/go-live/checklist-producao.md`
- Modify: `deploy/README.md` (link para o runbook), `docs/auditoria/roteiro-homologacao-real.md` (passo de TLS/CA)
- Test: `backend/tests/Unit/Deploy/EnvDeProducaoTest.php`

**Interfaces:**
- Consumes: `FISCAL_CA_BUNDLE` (Task 1); `TRUSTED_PROXIES` (já lido em `backend/bootstrap/app.php:50`).
- Produces: nada para outras tarefas.

- [ ] **Step 1: Teste que falha**

`backend/tests/Unit/Deploy/EnvDeProducaoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use Tests\TestCase;

class EnvDeProducaoTest extends TestCase
{
    /** @return array<string, string> */
    private function env(): array
    {
        $arquivo = base_path('../deploy/.env.production.example');
        $this->assertFileExists($arquivo);
        $valores = [];
        foreach (preg_split('/\R/', (string) file_get_contents($arquivo)) ?: [] as $linha) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($linha), $m) === 1) {
                $valores[$m[1]] = $m[2];
            }
        }

        return $valores;
    }

    public function test_template_de_producao_e_seguro(): void
    {
        $env = $this->env();

        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('0', $env['INSTALL_DEV']);
        $this->assertStringStartsWith('https://', $env['APP_URL']);
        $this->assertStringStartsWith('https://', $env['FRONTEND_URL']);
        $this->assertStringStartsWith('https://', $env['NEXT_PUBLIC_API_URL']);
        $this->assertSame('', $env['APP_KEY']);
        $this->assertSame('trocar', $env['DB_PASSWORD']);
    }
}
```

Run: `cd backend && vendor/bin/phpunit tests/Unit/Deploy/EnvDeProducaoTest.php`. Expected: FAIL (arquivo ausente).

- [ ] **Step 2: `deploy/.env.production.example`**

```
# Modelo do deploy/.env de PRODUÇÃO (o .env real NÃO vai para o git). Copie, preencha e rode o runbook
# docs/go-live/checklist-producao.md. Domínios: crie dois registros DNS apontando para a VPS.
APP_DOMAIN=app.exemplo.com.br
API_DOMAIN=api.exemplo.com.br

# --- Banco ---
DB_DATABASE=plataforma
DB_USERNAME=plataforma
DB_PASSWORD=trocar

# --- Frontend (assado no build) ---
NEXT_PUBLIC_API_URL=https://api.exemplo.com.br

# --- Laravel ---
APP_NAME="Plataforma Fiscal"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://api.exemplo.com.br
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432

# Front e API em subdomínios do mesmo domínio: cookie no domínio pai, só HTTPS.
FRONTEND_URL=https://app.exemplo.com.br
SANCTUM_STATEFUL_DOMAINS=app.exemplo.com.br
SESSION_DRIVER=database
SESSION_DOMAIN=.exemplo.com.br
SESSION_SECURE_COOKIE=true
SESSION_LIFETIME=120

# O Caddy é o único que fala com a API (as portas do app e do web não são publicadas).
TRUSTED_PROXIES=*

QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
BRASILAPI_URL=https://brasilapi.com.br

# Bundle de CAs (.pem) da SEFAZ e da ADN (cadeia ICP-Brasil). Vazio = bundle do sistema. Ver o runbook.
FISCAL_CA_BUNDLE=

AUTORUN_ENABLED=false
PHP_OPCACHE_ENABLE=1
SSL_MODE=off

# Produção: sem dependências de desenvolvimento e sem seeder de demonstração.
INSTALL_DEV=0
```

- [ ] **Step 3: `deploy/Caddyfile`**

```
# TLS automático (Let's Encrypt) para os dois domínios. APP_DOMAIN e API_DOMAIN vêm do deploy/.env.
(seguranca) {
	encode zstd gzip
	header {
		Strict-Transport-Security "max-age=31536000"
		X-Content-Type-Options "nosniff"
		Referrer-Policy "strict-origin-when-cross-origin"
		-Server
	}
}

{$APP_DOMAIN} {
	import seguranca
	reverse_proxy web:3000
}

{$API_DOMAIN} {
	import seguranca
	request_body {
		max_size 25MB
	}
	reverse_proxy app:8080
}
```

- [ ] **Step 4: `deploy/docker-compose.producao.yml`**

```yaml
# Camada de produção sobre o docker-compose.yml: remove as portas do host (só o Caddy fica exposto) e sobe o proxy HTTPS.
# Uso (dentro de deploy/):  docker compose -f docker-compose.yml -f docker-compose.producao.yml up -d
services:
  app:
    ports: !reset []
  web:
    ports: !reset []
  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    env_file: .env
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      - app
      - web

volumes:
  caddy_data:
  caddy_config:
```

Validar: `cd deploy && docker compose -f docker-compose.yml -f docker-compose.producao.yml --env-file .env.production.example config > /dev/null`. Sem Docker na máquina: registrar "não verificado". (`!reset` exige Compose >= 2.24.)

- [ ] **Step 5: Runbook `docs/go-live/checklist-producao.md`**

Conteúdo (pt-BR, em passos numerados e com comandos): 1. DNS dos dois domínios; 2. `cp .env.production.example .env`, preencher `APP_DOMAIN`, `API_DOMAIN`, URLs, `SESSION_DOMAIN`, gerar `DB_PASSWORD` (`openssl rand -hex 16`) e `APP_KEY` (`base64:` + `openssl rand -base64 32`); `chmod 600 .env`; 3. bundle de CAs: obter a cadeia ICP-Brasil e as CAs da SEFAZ/ADN, salvar em um `.pem` montado no container e apontar `FISCAL_CA_BUNDLE`; conferir com `openssl s_client -connect <host-da-sefaz>:443 -CAfile <bundle> </dev/null | grep "Verify return code"` (esperado `0 (ok)`); 4. subir: `docker compose -f docker-compose.yml -f docker-compose.producao.yml build && ... run --rm app php artisan migrate --force && ... up -d`; 5. NÃO rodar `--seed-demo`; criar o primeiro admin pelo fluxo de cadastro; 6. trocar a senha do usuário SSH, desativar login por senha do SSH, remover contas de demonstração se existirem; 7. verificações: `curl -I https://API_DOMAIN/up` (200), cookie com `Secure`, `https://APP_DOMAIN` carrega o login; 8. "Ir para produção" na tela de Configurações > Fiscal informando o **primeiro número livre** de cada série; 9. reversão: voltar ao compose base. Citar que `deploy.sh` é do staging e não conhece o arquivo de produção.

- [ ] **Step 6: `deploy/README.md` e roteiro de homologação**

- `deploy/README.md`: uma seção "Produção" com link para `docs/go-live/checklist-producao.md`.
- `docs/auditoria/roteiro-homologacao-real.md`: acrescentar o passo "Verificação TLS: com `FISCAL_CA_BUNDLE` apontando para o bundle, emitir uma NF-e e uma NFS-e em homologação; erro `SSL certificate problem` indica CA faltando no bundle (não desligar a verificação)".

- [ ] **Step 7: Rodar e commitar**

```bash
cd backend && vendor/bin/phpunit tests/Unit/Deploy/EnvDeProducaoTest.php | tail -3
vendor/bin/pint && vendor/bin/phpstan analyse --no-progress | tail -3
cd .. && git add deploy docs/go-live docs/auditoria/roteiro-homologacao-real.md backend/tests/Unit/Deploy && git commit -m "feat(deploy): configuração de produção com Caddy, template e runbook" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01A1d98n59FZTKR94ucCX4Sx"
```

---

## Task 7: Fechamento e verificação

**Files:**
- Modify: `PROGRESSO.md`, `TAREFAS.md`, `docs/superpowers/specs/2026-10-16-f8b-pendencias-go-live-design.md` (nota de ajustes)
- Modify: `docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md` só se citar o contador único como pendência

**Interfaces:** nenhuma.

- [ ] **Step 1: Suítes completas**

```bash
cd backend && vendor/bin/phpunit 2>&1 | tail -8
vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress | tail -3
cd ../frontend && npx vitest run 2>&1 | tail -8 && npx tsc --noEmit && npx next build 2>&1 | tail -5
```

Expected: tudo verde. Dois testes do frontend falharam sob carga na F7d (`aba Vendedores`, `CadastroForm`): se repetirem, rodar isolados antes de reportar.

- [ ] **Step 2: Atualizar a documentação**

- Spec: acrescentar seção "Ajustes na implementação": (a) a migration herda `proximo_numero` de quem já está em PRODUCAO (o spec dizia backfill 1); (b) camada `docker-compose.producao.yml` em vez de perfil (perfil não remove portas); (c) `proximo_numero` do resource = contador do ambiente atual.
- `TAREFAS.md`: marcar F8b `[x]` com data; remover/fechar a pendência "Segurança (go-live, F8): a nfse-php desliga a verificação TLS" (linha ~113) e nomear que a `sped-common` também foi corrigida.
- `PROGRESSO.md`: nova seção "Fechamento da F8b" (1 a 3 linhas por fatia, arquivos, decisões não óbvias, **não verificado:** SEFAZ/ADN reais com TLS ligado e a cadeia de CAs, `docker build` e Caddy/HTTPS reais, migration em PostgreSQL), atualizar "Tarefa em andamento" para "Próxima: F8c (backup e restauração), começando por brainstorming", e a "Contexto necessário" da F8c.
- Memória do projeto: atualizar `project_estado_atual.md` (F8b concluída; próxima F8c).

- [ ] **Step 3: Commit final**

```bash
git add PROGRESSO.md TAREFAS.md docs
git commit -m "docs: F8b concluída (TLS, contador por ambiente, produção)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01A1d98n59FZTKR94ucCX4Sx"
```

- [ ] **Step 4: Recomendar `/compact` ou `/clear`** e a próxima tarefa (F8c).
