# F1: Plataforma e Planos, plano de implementação

> **Para agentes:** use a sub-skill `superpowers:subagent-driven-development` (recomendada) ou `superpowers:executing-plans` para executar este plano tarefa por tarefa. Os passos usam checkbox (`- [ ]`).

**Objetivo:** entregar o admin da plataforma (planos, empresas, situação da assinatura e métricas), o cadastro público com teste grátis, o controle de limites do plano (`EntitlementService`), a gestão de usuários da empresa e as pendências da F0.

**Arquitetura:**
- O monólito modular da F0 ganha o módulo `Platform` (planos, situação, entitlements, cadastro e admin).
- `Identity` ganha a gestão de usuários. `Shared` ganha os value objects `Cnpj` e `Dinheiro` e a base de erros de negócio.
- Toda regra fica em Actions (`Application/Actions`). Os controllers só traduzem HTTP.
- Os erros de negócio estendem `ErroDeNegocio` e viram JSON `{message, codigo, ...}` num único ponto (`bootstrap/app.php`).
- O frontend ganha três áreas: pública (`/planos`, `/cadastro`), empresa (`(app)`) e plataforma (`/admin`). As três reaproveitam o `AppShell`.

**Stack:**
- Laravel 12, PHP 8.2, Sanctum SPA, `spatie/laravel-activitylog` ^4.
- Next.js 16, React 19, TanStack Query 5, RHF, zod 4, Vitest.

**Spec:** `docs/superpowers/specs/2026-09-26-f1-plataforma-e-planos-design.md` (spec mestre: `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`).

## Restrições globais

- `declare(strict_types=1)` em todo PHP. TypeScript strict, sem `any`.
- Mudança de schema só por **migration nova**. As migrations da F0 não são editadas.
- Valores monetários em **centavos inteiros** (`*_centavos`, `integer`).
- `plano_limites`: `-1` = ilimitado. Recurso sem linha = **0** (fail-closed), nunca ilimitado.
- Transição de situação fora da lista do §4 lança exceção. Não há estado implícito.
- Escrita bloqueada: `403` com `{"message": "...", "codigo": "ASSINATURA_SEM_ESCRITA"}`.
- Limite atingido: `422` com `codigo: LIMITE_DO_PLANO` e a mensagem "Seu plano permite até N usuários ativos."
- Módulo não contratado: `403` com `codigo: MODULO_NAO_CONTRATADO`.
- Checagem de limite **nas Actions**, nunca só no controller.
- `Usuario` **não** usa `BelongsToTenant`. Toda listagem ou busca de usuário da empresa filtra `tenant_id` explicitamente (ADR 0002).
- BrasilAPI: timeout de 5 s, cache de 24 h, `throttle:10,1`. Cadastro com `throttle:5,60`.
- O convite vale **72 h**.
- Expiração do teste: `assinaturas:expirar-testes`, diária às `00:10`, fuso `America/Sao_Paulo`.
- Testes do backend: SQLite em memória, fila `sync`. O CI roda também em PostgreSQL 16.
- Mensagens ao usuário em pt-BR.
- Comandos do backend rodam em `backend/` (`php artisan test`, `vendor/bin/pint`, `composer analyse`). Os do frontend rodam em `frontend/` (`npm test`, `npm run typecheck`, `npm run lint`).
- Commits em pt-BR, no padrão `tipo: descrição`, terminando com:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01M5qhhsKrDRjpoF1AfA228K
  ```

## Foco da revisão

Cinco pontos que a spec implica e que, se não tiverem teste, podem afetar o usuário. Cada um tem teste na tarefa indicada.

1. **Cadastro concorrente com o mesmo CNPJ:** dois POSTs simultâneos passam pela validação e um deles bate no índice único. O esperado é `422` com a mensagem de CNPJ já cadastrado, nunca `500` (Tarefa 9, `test_violacao_de_unicidade_vira_422`).
2. **CNPJ digitado com máscara e em minúsculas:** `12.abc.345/01de-35` precisa ser aceito, gravado como `12ABC34501DE35` e reconhecido como duplicado de si mesmo (Tarefas 2 e 9).
3. **Plano sem limite cadastrado para um recurso:** convidar usuário precisa ser barrado (limite 0), e a mensagem precisa dizer "até 0" (Tarefa 5, `test_recurso_sem_linha_vale_zero`).
4. **Usuário da empresa A tentando editar, desativar ou reativar usuário da empresa B pelo id:** deve receber `404`, sem vazar a existência do usuário (Tarefa 10).
5. **Teste que termina hoje:** a conta continua `TESTE` durante todo o último dia e só vira `SUSPENSA` no dia seguinte. Rodar o comando duas vezes não gera auditoria duplicada (Tarefa 4).

## Mapa de arquivos

**Backend** (`backend/`):

| Caminho | Responsabilidade |
|---|---|
| `app/Modules/Shared/Domain/Exceptions/ErroDeNegocio.php` | Base de erro com `status()`, `codigo()` e `extras()` |
| `app/Modules/Shared/Domain/Cnpj.php`, `Dinheiro.php` | Value objects |
| `app/Modules/Shared/Http/Rules/CnpjValido.php` | Regra de validação |
| `app/Modules/Tenancy/Domain/Enums/SituacaoAssinatura.php` | Estados, transições e permissão de escrita |
| `app/Modules/Platform/Domain/Enums/{Modulo,Recurso,PoliticaExcedente}.php` | Catálogos |
| `app/Modules/Platform/Domain/Models/{Plano,PlanoModulo,PlanoLimite}.php` | Planos |
| `app/Modules/Platform/Domain/Contracts/ContadorDeUso.php` | Contrato do contador por recurso |
| `app/Modules/Platform/Domain/Exceptions/*.php` | Erros de negócio do módulo |
| `app/Modules/Platform/Application/EntitlementService.php`, `RegistroDeContadores.php` | Limites e uso |
| `app/Modules/Platform/Application/Actions/*.php` | Regras de planos, empresas e cadastro |
| `app/Modules/Platform/Infrastructure/ConsultaCnpj.php` | Cliente da BrasilAPI |
| `app/Modules/Platform/Console/ExpirarTestesCommand.php` | Comando agendado |
| `app/Modules/Platform/Http/{Controllers,Requests,Resources,Middleware}/*` | HTTP |
| `app/Modules/Platform/Http/routes-{admin,publico,app}.php` | Rotas |
| `app/Modules/Platform/Providers/PlatformServiceProvider.php` | Registro |
| `app/Modules/Identity/Application/{ContadorDeUsuariosAtivos,PoliticaDeUsuarios}.php` | Contador e permissões |
| `app/Modules/Identity/Application/Actions/*.php` | Convite, edição, desativação, reativação, aceite do convite |
| `app/Modules/Identity/Http/Middleware/GarantirUsuarioAtivo.php` | Derruba a sessão de usuário desativado |
| `app/Modules/Identity/Notifications/ConviteDeUsuario.php` | E-mail de convite |

**Frontend** (`frontend/`):

| Caminho | Responsabilidade |
|---|---|
| `lib/cnpj.ts`, `lib/formatos.ts`, `lib/sessao.ts` | Utilitários puros |
| `components/form/Campo.tsx` | Label, erro, `aria-invalid` e `aria-describedby` |
| `components/ui/campos-nativos.tsx` | `<select>` e `<textarea>` nativos estilizados |
| `components/layout/*` | `AppShell` com itens de navegação por área |
| `features/assinatura/*` | Situação, faixas e consumo |
| `features/cadastro/*` | Planos públicos e cadastro em duas etapas |
| `features/admin/*` | Painel, planos e empresas |
| `features/usuarios/*` | Usuários da empresa |
| `app/(publico)/*`, `app/(admin)/admin/*`, `app/(app)/configuracoes/usuarios`, `app/(auth)/definir-senha` | Páginas |

## Pré-voo de interfaces

- **T1 → T6, T10:** `GarantirUsuarioAtivo` entra nos grupos `empresa` e `plataforma` e protege as rotas de usuários.
- **T2 → T4 a T10:** `ErroDeNegocio` e `AcessoNegadoException` são a base de todos os erros com `codigo`.
- **T2 → T9:** `Cnpj::de()` e a regra `CnpjValido` são usados no cadastro.
- **T3 → todas:** `Plano`, `Tenant::plano()`, `SituacaoAssinatura`, `TenantFactory` com `plano_id`.
- **T5 → T8, T10:** `EntitlementService::garantirCapacidade/consumo/excessos`.
- **T6 → T8, T9, T10:** grupos de middleware `empresa` e `plataforma`.
- **T12 → T13 a T17:** o tipo `Usuario.tenant` passa a ter `razao_social`, `nome_fantasia`, `situacao` e `teste_termina_em`. `Campo` e `senhaForte` também vêm da T12.
- **T13 → T14 a T17:** `Plano`, `RECURSOS`, `MODULOS`, `ROTULO_*`, `formatos` e `ConsumoDoPlano`.
- **T15 → T16, T17:** `adminApi`, `SITUACOES`, `campos-nativos` e `itensVisiveis`.

---
## Backend

### Tarefa 1: Pendências de identidade da F0

Cobre os itens 1, 2, 3, 6, 7 (`.env.example`) e 8 do §10 da spec.

**Arquivos:**
- Modificar: `backend/app/Modules/Identity/Domain/Models/Usuario.php` (`$fillable`)
- Criar: `backend/app/Modules/Identity/Http/Middleware/GarantirUsuarioAtivo.php`
- Modificar: `backend/app/Modules/Identity/Http/routes.php` (grupo autenticado)
- Modificar: `backend/app/Modules/Identity/Http/Requests/LoginRequest.php` (limite por IP)
- Modificar: `backend/bootstrap/app.php` (prioridade do `DefinirTenantDoUsuario`)
- Modificar: `backend/.env.example` (`QUEUE_CONNECTION=sync`)
- Testes:
  - `backend/tests/Feature/Identity/UsuarioTest.php`
  - `backend/tests/Feature/Identity/UsuarioDesativadoTest.php`
  - `backend/tests/Feature/Tenancy/BindingComTenantTest.php`
  - `backend/tests/Feature/Identity/LoginTest.php`
  - `backend/tests/Feature/Identity/RecuperacaoSenhaTest.php`

**Interfaces:**
- Produz: `App\Modules\Identity\Http\Middleware\GarantirUsuarioAtivo`. Responde `401` `{"message": "Sua conta foi desativada. Fale com o administrador da empresa."}` e encerra a sessão.

- [ ] **Passo 1: testes que falham**

`backend/tests/Feature/Identity/UsuarioTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use Tests\TestCase;

class UsuarioTest extends TestCase
{
    public function test_tenant_id_e_papel_nao_sao_atribuiveis_em_massa(): void
    {
        $usuario = new Usuario([
            'nome' => 'Ana', 'email' => 'ana@x.com',
            'tenant_id' => 'qualquer', 'papel' => Papel::Superadmin,
        ]);

        $this->assertSame('Ana', $usuario->nome);
        $this->assertArrayNotHasKey('tenant_id', $usuario->getAttributes());
        $this->assertArrayNotHasKey('papel', $usuario->getAttributes());
    }
}
```

`backend/tests/Feature/Identity/UsuarioDesativadoTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuarioDesativadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_desativado_perde_o_acesso_na_proxima_requisicao(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create();
        $this->spa()->actingAs($usuario)->getJson('/api/app/auth/me')->assertOk();

        $usuario->forceFill(['ativo' => false])->save();

        $this->spa()->getJson('/api/app/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sua conta foi desativada. Fale com o administrador da empresa.');
        $this->assertGuest('web');
    }
}
```

`backend/tests/Feature/Tenancy/BindingComTenantTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\RegistroDeTeste;
use Tests\TestCase;

/** Route model binding de Model de tenant precisa do contexto já definido. */
class BindingComTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('registros_de_teste', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('descricao');
            $table->timestamps();
        });
        Route::middleware(['api', 'auth:sanctum', DefinirTenantDoUsuario::class])
            ->get('/api/teste/registros/{registro}', fn (RegistroDeTeste $registro) => ['id' => $registro->id]);
    }

    private function registroDe(Tenant $tenant): RegistroDeTeste
    {
        $contexto = app(TenantContext::class);
        $contexto->set($tenant->id);
        $registro = RegistroDeTeste::create(['descricao' => 'x']);
        $contexto->clear();

        return $registro;
    }

    public function test_binding_resolve_registro_do_proprio_tenant(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create();
        $registro = $this->registroDe($usuario->tenant);

        $this->spa()->actingAs($usuario)->getJson("/api/teste/registros/{$registro->id}")
            ->assertOk()
            ->assertJsonPath('id', $registro->id);
    }

    public function test_binding_nao_resolve_registro_de_outro_tenant(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create();
        $registro = $this->registroDe(Tenant::factory()->create());

        $this->spa()->actingAs($usuario)->getJson("/api/teste/registros/{$registro->id}")->assertNotFound();
    }
}
```

Acrescentar em `backend/tests/Feature/Identity/LoginTest.php`:
```php
    public function test_limite_por_ip_soma_tentativas_de_emails_diferentes(): void
    {
        $this->usuario();

        for ($i = 0; $i < 20; $i++) {
            $this->spa()->postJson('/api/app/auth/login', ['email' => "x{$i}@teste.com", 'password' => 'errada'])
                ->assertStatus(422);
        }

        $this->spa()->postJson('/api/app/auth/login', ['email' => 'ana@empresa.com', 'password' => 'Senha123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn (string $m): bool => str_starts_with($m, 'Muitas tentativas'));
        $this->assertGuest('web');
    }
```

Acrescentar em `backend/tests/Feature/Identity/RecuperacaoSenhaTest.php` (usar `Illuminate\Support\Facades\Password`):
```php
    private function redefinir(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->spa()->postJson('/api/app/auth/redefinir-senha', [
            'token' => $token, 'email' => 'ana@empresa.com',
            'password' => 'NovaSenha1', 'password_confirmation' => 'NovaSenha1',
        ]);
    }

    public function test_token_expirado_e_recusado(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create(['email' => 'ana@empresa.com']);
        $token = Password::broker()->createToken($usuario);

        $this->travel(61)->minutes();

        $this->redefinir($token)->assertStatus(422)->assertJsonPath('errors.email.0', 'Link inválido ou expirado.');
    }

    public function test_token_nao_pode_ser_reutilizado(): void
    {
        $usuario = Usuario::factory()->for(Tenant::factory())->create(['email' => 'ana@empresa.com']);
        $token = Password::broker()->createToken($usuario);

        $this->redefinir($token)->assertOk();
        $this->redefinir($token)->assertStatus(422)->assertJsonPath('errors.email.0', 'Link inválido ou expirado.');
    }
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `php artisan test --filter="UsuarioTest|UsuarioDesativadoTest|BindingComTenantTest|LoginTest|RecuperacaoSenhaTest"`

Esperado:
- **FALHAM:**
  - `test_tenant_id_e_papel_nao_sao_atribuiveis_em_massa` (os atributos existem).
  - `test_usuario_desativado_perde_o_acesso...` (200 em vez de 401).
  - `test_binding_resolve_registro_do_proprio_tenant` (404, porque o binding roda antes do contexto).
  - `test_limite_por_ip...` (o 21º login entra).
- **PASSAM:** os dois testes de token. Eles só fixam um comportamento que já existe; está correto que passem.

- [ ] **Passo 3: implementar**

`Usuario.php`: trocar a linha do `$fillable` por:
```php
    /** tenant_id e papel são atribuídos só pelas Actions, via forceFill. */
    protected $fillable = ['nome', 'email', 'password', 'ativo'];
```

`backend/app/Modules/Identity/Http/Middleware/GarantirUsuarioAtivo.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Usuário desativado com sessão aberta perde o acesso na próxima requisição. */
final class GarantirUsuarioAtivo
{
    public const MENSAGEM = 'Sua conta foi desativada. Fale com o administrador da empresa.';

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario instanceof Usuario && ! $usuario->ativo) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => self::MENSAGEM], 401);
        }

        return $next($request);
    }
}
```

`Identity/Http/routes.php`: o grupo autenticado passa a ser:
```php
    Route::middleware(['auth:sanctum', GarantirUsuarioAtivo::class, DefinirTenantDoUsuario::class])->group(function (): void {
```
Acrescentar também `use App\Modules\Identity\Http\Middleware\GarantirUsuarioAtivo;`.

`LoginRequest.php`: substituir `autenticar()` e `chaveLimite()` por:
```php
    private const MAX_TENTATIVAS_POR_IP = 20;

    public function autenticar(): void
    {
        $chaveEmail = 'login:'.$this->string('email')->toString().'|'.$this->ip();
        $chaveIp = 'login-ip:'.$this->ip();

        foreach ([$chaveEmail => self::MAX_TENTATIVAS, $chaveIp => self::MAX_TENTATIVAS_POR_IP] as $chave => $maximo) {
            if (RateLimiter::tooManyAttempts($chave, $maximo)) {
                $segundos = RateLimiter::availableIn($chave);
                throw ValidationException::withMessages([
                    'email' => "Muitas tentativas. Tente novamente em {$segundos} segundos.",
                ]);
            }
        }

        $credenciais = [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'ativo' => true,
        ];

        if (! Auth::guard('web')->attempt($credenciais)) {
            RateLimiter::hit($chaveEmail, 15 * 60);
            // Soma entre todos os e-mails: barra quem testa senhas comuns em muitas contas.
            RateLimiter::hit($chaveIp, 60);
            throw ValidationException::withMessages(['email' => self::MENSAGEM_GENERICA]);
        }

        RateLimiter::clear($chaveEmail);
    }
```

`bootstrap/app.php`: dentro de `withMiddleware`, depois de `statefulApi()`:
```php
        // Route model binding de Model de tenant precisa do contexto já definido;
        // sem isso o TenantScope (fail-closed) responde 404 para o próprio registro.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario::class,
        );
```

`backend/.env.example`: trocar `QUEUE_CONNECTION=database` por `QUEUE_CONNECTION=sync`.

- [ ] **Passo 4: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam (os 27 da F0 mais os 7 novos).

- [ ] **Passo 5: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "fix: pendências de identidade da F0 (fillable, usuário desativado, binding com tenant, limite por IP)"
```

---

### Tarefa 2: Shared (erros de negócio, Cnpj e Dinheiro)

**Arquivos:**
- Criar:
  - `backend/app/Modules/Shared/Domain/Exceptions/ErroDeNegocio.php`
  - `backend/app/Modules/Shared/Domain/Exceptions/AcessoNegadoException.php`
  - `backend/app/Modules/Shared/Domain/Exceptions/CnpjInvalidoException.php`
  - `backend/app/Modules/Shared/Domain/Cnpj.php`
  - `backend/app/Modules/Shared/Domain/Dinheiro.php`
  - `backend/app/Modules/Shared/Http/Rules/CnpjValido.php`
- Modificar: `backend/bootstrap/app.php` (render do `ErroDeNegocio`)
- Testes:
  - `backend/tests/Unit/Shared/CnpjTest.php`
  - `backend/tests/Unit/Shared/DinheiroTest.php`
  - `backend/tests/Feature/Shared/ErroDeNegocioTest.php`

**Interfaces:**
- Produz:
  - `abstract class ErroDeNegocio extends \RuntimeException` com os métodos `status(): int`, `codigo(): string` e `extras(): array<string, mixed>` (este último devolve `[]` por padrão).
  - Um `ErroDeNegocio` vira JSON `{"message", "codigo", ...extras}` com o status do erro.
  - `AcessoNegadoException(string $mensagem = 'Você não tem permissão para esta ação.')`: `403`, `ACESSO_NEGADO`.
  - `Cnpj::de(string): Cnpj` (lança `CnpjInvalidoException`), `Cnpj::tentar(string): ?Cnpj`, `->valor` (14 caracteres, sem máscara) e `->formatado()`.
  - `new CnpjValido` (regra), com a mensagem "Informe um CNPJ válido."
  - `Dinheiro::deCentavos(int)->formatado()`, que devolve por exemplo `R$ 1.234,56`.

- [ ] **Passo 1: testes que falham**

`backend/tests/Unit/Shared/CnpjTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Domain\Exceptions\CnpjInvalidoException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CnpjTest extends TestCase
{
    public function test_numerico_valido_com_mascara(): void
    {
        $cnpj = Cnpj::de('11.222.333/0001-81');

        $this->assertSame('11222333000181', $cnpj->valor);
        $this->assertSame('11.222.333/0001-81', $cnpj->formatado());
    }

    public function test_alfanumerico_do_exemplo_oficial_da_receita(): void
    {
        // Exemplo da Receita Federal (IN RFB 2.229/2024): 12.ABC.345/01DE-35.
        $this->assertSame('12ABC34501DE35', Cnpj::de('12.ABC.345/01DE-35')->valor);
    }

    public function test_alfanumerico_em_minusculas_e_normalizado(): void
    {
        $this->assertSame('12ABC34501DE35', Cnpj::de('12.abc.345/01de-35')->valor);
    }

    /** @return array<string, array{string}> */
    public static function invalidos(): array
    {
        return [
            'dv errado' => ['11222333000182'],
            'todos iguais' => ['00000000000000'],
            'curto' => ['1122233300018'],
            'longo' => ['112223330001811'],
            'letra no dv' => ['12ABC34501DE3A'],
            'símbolo' => ['12ABC34501D*35'],
            'vazio' => [''],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_invalidos_lancam_excecao(string $entrada): void
    {
        $this->expectException(CnpjInvalidoException::class);
        Cnpj::de($entrada);
    }

    public function test_tentar_devolve_null_quando_invalido(): void
    {
        $this->assertNull(Cnpj::tentar('11222333000182'));
        $this->assertNotNull(Cnpj::tentar('11222333000181'));
    }
}
```

`backend/tests/Unit/Shared/DinheiroTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\Dinheiro;
use PHPUnit\Framework\TestCase;

class DinheiroTest extends TestCase
{
    public function test_formata_em_reais(): void
    {
        $this->assertSame('R$ 0,00', Dinheiro::deCentavos(0)->formatado());
        $this->assertSame('R$ 9,90', Dinheiro::deCentavos(990)->formatado());
        $this->assertSame('R$ 1.234.567,89', Dinheiro::deCentavos(123456789)->formatado());
        $this->assertSame('-R$ 1,50', Dinheiro::deCentavos(-150)->formatado());
    }
}
```

`backend/tests/Feature/Shared/ErroDeNegocioTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErroDeNegocioTest extends TestCase
{
    public function test_erro_de_negocio_vira_json_com_codigo_e_extras(): void
    {
        Route::get('/api/teste/erro', function (): never {
            throw new class('Algo conflitou.') extends ErroDeNegocio
            {
                public function status(): int
                {
                    return 409;
                }

                public function codigo(): string
                {
                    return 'CONFLITO_TESTE';
                }

                public function extras(): array
                {
                    return ['detalhe' => 1];
                }
            };
        });

        $this->getJson('/api/teste/erro')
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Algo conflitou.', 'codigo' => 'CONFLITO_TESTE', 'detalhe' => 1]);
    }

    public function test_acesso_negado_tem_mensagem_padrao(): void
    {
        Route::get('/api/teste/negado', fn () => throw new AcessoNegadoException);

        $this->getJson('/api/teste/negado')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Você não tem permissão para esta ação.', 'codigo' => 'ACESSO_NEGADO']);
    }
}
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `php artisan test --filter="CnpjTest|DinheiroTest|ErroDeNegocioTest"`
Esperado: FALHAM com "Class ... not found".

- [ ] **Passo 3: implementar**

`backend/app/Modules/Shared/Domain/Exceptions/ErroDeNegocio.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

use RuntimeException;

/**
 * Erro esperado de regra de negócio. Vira JSON {message, codigo, ...extras}
 * num único ponto (bootstrap/app.php) e não é reportado como falha.
 */
abstract class ErroDeNegocio extends RuntimeException
{
    abstract public function status(): int;

    abstract public function codigo(): string;

    /** @return array<string, mixed> */
    public function extras(): array
    {
        return [];
    }
}
```

`backend/app/Modules/Shared/Domain/Exceptions/AcessoNegadoException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class AcessoNegadoException extends ErroDeNegocio
{
    public function __construct(string $mensagem = 'Você não tem permissão para esta ação.')
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'ACESSO_NEGADO';
    }
}
```

`backend/app/Modules/Shared/Domain/Exceptions/CnpjInvalidoException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class CnpjInvalidoException extends ErroDeNegocio
{
    public static function para(string $entrada): self
    {
        return new self("CNPJ inválido: {$entrada}.");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'CNPJ_INVALIDO';
    }
}
```

`backend/app/Modules/Shared/Domain/Cnpj.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use App\Modules\Shared\Domain\Exceptions\CnpjInvalidoException;
use Stringable;

/**
 * CNPJ numérico ou alfanumérico (IN RFB 2.229/2024): 12 posições [0-9A-Z]
 * e 2 dígitos verificadores numéricos. O DV é o módulo 11 de sempre,
 * com o valor de cada caractere = código ASCII − 48.
 */
final class Cnpj implements Stringable
{
    private const PESOS_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private const PESOS_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private function __construct(public readonly string $valor) {}

    public static function de(string $entrada): self
    {
        $valor = strtoupper(str_replace(['.', '/', '-', ' '], '', trim($entrada)));

        if (! self::ehValido($valor)) {
            throw CnpjInvalidoException::para($entrada);
        }

        return new self($valor);
    }

    public static function tentar(string $entrada): ?self
    {
        try {
            return self::de($entrada);
        } catch (CnpjInvalidoException) {
            return null;
        }
    }

    public function formatado(): string
    {
        $v = $this->valor;

        return substr($v, 0, 2).'.'.substr($v, 2, 3).'.'.substr($v, 5, 3).'/'.substr($v, 8, 4).'-'.substr($v, 12, 2);
    }

    public function __toString(): string
    {
        return $this->valor;
    }

    private static function ehValido(string $valor): bool
    {
        if (preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $valor) !== 1 || preg_match('/^(.)\1{13}$/', $valor) === 1) {
            return false;
        }

        $base = substr($valor, 0, 12);
        $dv1 = self::digito($base, self::PESOS_DV1);
        $dv2 = self::digito($base.$dv1, self::PESOS_DV2);

        return substr($valor, 12) === $dv1.$dv2;
    }

    /** @param list<int> $pesos */
    private static function digito(string $base, array $pesos): string
    {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += (ord($base[$i]) - 48) * $peso;
        }
        $resto = $soma % 11;

        return (string) ($resto < 2 ? 0 : 11 - $resto);
    }
}
```

`backend/app/Modules/Shared/Domain/Dinheiro.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

/** Valor monetário em centavos inteiros: nunca float. */
final class Dinheiro
{
    private function __construct(public readonly int $centavos) {}

    public static function deCentavos(int $centavos): self
    {
        return new self($centavos);
    }

    public function formatado(): string
    {
        $sinal = $this->centavos < 0 ? '-' : '';
        $absoluto = abs($this->centavos);
        $reais = number_format(intdiv($absoluto, 100), 0, ',', '.');

        return sprintf('%sR$ %s,%02d', $sinal, $reais, $absoluto % 100);
    }
}
```

`backend/app/Modules/Shared/Http/Rules/CnpjValido.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Rules;

use App\Modules\Shared\Domain\Cnpj;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class CnpjValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Cnpj::tentar($value) === null) {
            $fail('Informe um CNPJ válido.');
        }
    }
}
```

`bootstrap/app.php`: dentro de `withExceptions`, antes do handler de 429:
```php
        $exceptions->dontReport(\App\Modules\Shared\Domain\Exceptions\ErroDeNegocio::class);
        $exceptions->render(fn (\App\Modules\Shared\Domain\Exceptions\ErroDeNegocio $e) => response()->json(
            ['message' => $e->getMessage(), 'codigo' => $e->codigo()] + $e->extras(),
            $e->status(),
        ));
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam.

- [ ] **Passo 5: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "feat: erros de negócio, value objects Cnpj (alfanumérico) e Dinheiro"
```

---

### Tarefa 3: Modelo de dados (planos, assinatura no tenant, auditoria)

**Arquivos:**
- `composer require spatie/laravel-activitylog:^4.10`. Publicar e editar as migrations do pacote.
- Criar:
  - `backend/database/migrations/2026_09_27_000001_create_planos_tables.php`
  - `backend/database/migrations/2026_09_27_000002_add_assinatura_to_tenants_table.php`
  - `backend/app/Modules/Platform/Domain/Enums/{Modulo,Recurso,PoliticaExcedente}.php`
  - `backend/app/Modules/Platform/Domain/Models/{Plano,PlanoModulo,PlanoLimite}.php`
  - `backend/app/Modules/Tenancy/Domain/Enums/SituacaoAssinatura.php` (só os casos; as regras vêm na Tarefa 4)
  - `backend/database/factories/PlanoFactory.php`
- Modificar:
  - `backend/app/Modules/Tenancy/Domain/Models/Tenant.php`
  - `backend/database/factories/TenantFactory.php`
  - `backend/database/seeders/DatabaseSeeder.php`
  - `backend/app/Modules/Identity/Http/Resources/UsuarioResource.php`
- Testes:
  - `backend/tests/Feature/Platform/PlanoTest.php`
  - `backend/tests/Feature/Tenancy/TenantAssinaturaTest.php`

**Interfaces:**
- Produz:
  - `Plano` com as propriedades da spec §3, as relações `modulos()`, `limites()` e `tenants()`, e os métodos `temModulo(Modulo): bool` e `limiteDe(Recurso): int` (ausente = 0). Constante `Plano::ILIMITADO = -1`.
  - `Modulo`, `Recurso` e `PoliticaExcedente`, com `::valores(): list<string>`. `Modulo::rotulo()` e `Recurso::descricaoDoLimite()`.
  - `SituacaoAssinatura` (PENDENTE, TESTE, ATIVA, INADIMPLENTE, SUSPENSA, CANCELADA) com `::valores()`.
  - `Tenant`:
    - Campos `razao_social`, `nome_fantasia`, `cnpj`, `plano_id`, `situacao` (cast para o enum), `teste_termina_em` (`date`) e `situacao_alterada_em`.
    - `plano(): BelongsTo` e `nomeDeExibicao(): string`.
    - O `$fillable` é só `razao_social`, `nome_fantasia` e `cnpj`. `plano_id` e `situacao` são atribuídos via `forceFill`.
  - `TenantFactory` cria um `plano` por padrão, com situação `ATIVA`. Estados: `->situacao(SituacaoAssinatura)` e `->emTeste(string $terminaEm)`.
  - `PlanoFactory`: `->comLimites(array<string,int>)` (chaves = valor do `Recurso`) e `->comModulos(Modulo ...)`.
  - O `tenant` do `UsuarioResource` passa a ser `{id, razao_social, nome_fantasia, cnpj, situacao, teste_termina_em}`.

- [ ] **Passo 1: instalar a auditoria**

```bash
composer require spatie/laravel-activitylog:^4.10
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
```
Na migration publicada `*_create_activity_log_table.php`:
- Trocar `$table->nullableMorphs('subject', 'subject');` por `$table->nullableUuidMorphs('subject', 'subject');`.
- Trocar `$table->nullableMorphs('causer', 'causer');` por `$table->nullableUuidMorphs('causer', 'causer');`.

O motivo é que usuários, tenants e planos usam uuid. No SQLite um bigint aceita texto em silêncio, mas o PostgreSQL recusa. O job de CI em PostgreSQL (Tarefa 11) cobre isso.

Se `^4.10` não resolver com o Laravel 12, use a maior versão compatível e registre um `Ruling` no ledger.

- [ ] **Passo 2: testes que falham**

`backend/tests/Feature/Platform/PlanoTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanoTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurso_sem_linha_vale_zero(): void
    {
        $plano = Plano::factory()->create();

        $this->assertSame(0, $plano->limiteDe(Recurso::Usuarios));
    }

    public function test_menos_um_e_preservado_como_ilimitado(): void
    {
        $plano = Plano::factory()->comLimites([Recurso::Usuarios->value => -1, Recurso::Clientes->value => 500])->create();

        $this->assertSame(Plano::ILIMITADO, $plano->limiteDe(Recurso::Usuarios));
        $this->assertSame(500, $plano->limiteDe(Recurso::Clientes));
    }

    public function test_tem_modulo(): void
    {
        $plano = Plano::factory()->comModulos(Modulo::FiscalNfe, Modulo::Crm)->create();

        $this->assertTrue($plano->temModulo(Modulo::Crm));
        $this->assertFalse($plano->temModulo(Modulo::FiscalNfse));
    }

    public function test_modulo_duplicado_no_mesmo_plano_e_recusado_pelo_banco(): void
    {
        $plano = Plano::factory()->comModulos(Modulo::Crm)->create();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $plano->modulos()->create(['modulo' => Modulo::Crm]);
    }
}
```

`backend/tests/Feature/Tenancy/TenantAssinaturaTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TenantAssinaturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_cria_tenant_ativo_com_plano(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(SituacaoAssinatura::Ativa, $tenant->situacao);
        $this->assertInstanceOf(Plano::class, $tenant->plano);
    }

    public function test_plano_id_e_situacao_nao_sao_atribuiveis_em_massa(): void
    {
        $tenant = new Tenant(['razao_social' => 'X', 'plano_id' => 'p', 'situacao' => 'ATIVA']);

        $this->assertArrayNotHasKey('plano_id', $tenant->getAttributes());
        $this->assertArrayNotHasKey('situacao', $tenant->getAttributes());
    }

    public function test_me_devolve_os_dados_da_assinatura(): void
    {
        $tenant = Tenant::factory()->emTeste('2026-10-15')->create([
            'razao_social' => 'Padaria Pão Bom Ltda', 'nome_fantasia' => 'Pão Bom',
        ]);
        $usuario = Usuario::factory()->for($tenant)->create();

        $this->spa()->actingAs($usuario)->getJson('/api/app/auth/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.razao_social', 'Padaria Pão Bom Ltda')
            ->assertJsonPath('data.tenant.nome_fantasia', 'Pão Bom')
            ->assertJsonPath('data.tenant.situacao', 'TESTE')
            ->assertJsonPath('data.tenant.teste_termina_em', '2026-10-15');
    }

    public function test_auditoria_grava_causador_e_alvo_uuid(): void
    {
        $tenant = Tenant::factory()->create();
        $usuario = Usuario::factory()->for($tenant)->create();

        activity('teste')->performedOn($tenant)->causedBy($usuario)->log('x');

        $registro = Activity::query()->firstOrFail();
        $this->assertSame($tenant->id, $registro->subject_id);
        $this->assertSame($usuario->id, $registro->causer_id);
    }
}
```

- [ ] **Passo 3: rodar e ver falhar**

Comando: `php artisan test --filter="PlanoTest|TenantAssinaturaTest"`
Esperado: FALHAM ("Class Plano not found" e "SituacaoAssinatura not found").

- [ ] **Passo 4: implementar**

`backend/database/migrations/2026_09_27_000001_create_planos_tables.php`:
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
        Schema::create('planos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nome', 60)->unique();
            $table->text('descricao')->nullable();
            $table->integer('preco_mensal_centavos');
            $table->integer('preco_anual_centavos')->nullable();
            $table->unsignedSmallInteger('dias_teste')->default(0);
            $table->string('politica_excedente', 10)->default('BLOQUEAR');
            $table->integer('preco_documento_excedente_centavos')->nullable();
            $table->boolean('ativo')->default(true);
            $table->boolean('visivel')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('plano_modulos', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('plano_id')->constrained('planos')->cascadeOnDelete();
            $table->string('modulo', 20);
            $table->unique(['plano_id', 'modulo']);
        });

        Schema::create('plano_limites', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('plano_id')->constrained('planos')->cascadeOnDelete();
            $table->string('recurso', 30);
            $table->integer('limite'); // -1 = ilimitado; recurso sem linha = 0
            $table->unique(['plano_id', 'recurso']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_limites');
        Schema::dropIfExists('plano_modulos');
        Schema::dropIfExists('planos');
    }
};
```

`backend/database/migrations/2026_09_27_000002_add_assinatura_to_tenants_table.php`:
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
        Schema::table('tenants', function (Blueprint $table): void {
            $table->renameColumn('nome', 'razao_social');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('nome_fantasia', 150)->nullable();
            $table->foreignUuid('plano_id')->nullable()->constrained('planos');
            $table->string('situacao', 20)->default('PENDENTE')->index();
            $table->date('teste_termina_em')->nullable();
            $table->timestamp('situacao_alterada_em')->nullable();
        });

        // O único status da F0 era ATIVO.
        DB::table('tenants')->where('status', 'ATIVO')->update(['situacao' => 'ATIVA']);

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('status', 20)->default('ATIVO');
        });
        DB::table('tenants')->where('situacao', '!=', 'ATIVA')->update(['status' => 'INATIVO']);

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropForeign(['plano_id']);
            $table->dropIndex(['situacao']);
            $table->dropColumn(['nome_fantasia', 'plano_id', 'situacao', 'teste_termina_em', 'situacao_alterada_em']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->renameColumn('razao_social', 'nome');
        });
    }
};
```

`backend/app/Modules/Platform/Domain/Enums/Modulo.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum Modulo: string
{
    case FiscalNfe = 'FISCAL_NFE';
    case FiscalNfce = 'FISCAL_NFCE';
    case FiscalNfse = 'FISCAL_NFSE';
    case Crm = 'CRM';
    case Api = 'API';

    public function rotulo(): string
    {
        return match ($this) {
            self::FiscalNfe => 'NF-e',
            self::FiscalNfce => 'NFC-e',
            self::FiscalNfse => 'NFS-e',
            self::Crm => 'CRM',
            self::Api => 'API pública',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`backend/app/Modules/Platform/Domain/Enums/Recurso.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum Recurso: string
{
    case Usuarios = 'USUARIOS';
    case Clientes = 'CLIENTES';
    case Produtos = 'PRODUTOS';
    case Servicos = 'SERVICOS';
    case DocumentosMes = 'DOCUMENTOS_MES';
    case ApiRequisicoesMin = 'API_REQUISICOES_MIN';

    /** Completa a frase "Seu plano permite até N ...". */
    public function descricaoDoLimite(): string
    {
        return match ($this) {
            self::Usuarios => 'usuários ativos',
            self::Clientes => 'clientes',
            self::Produtos => 'produtos',
            self::Servicos => 'serviços',
            self::DocumentosMes => 'documentos por mês',
            self::ApiRequisicoesMin => 'requisições por minuto',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`backend/app/Modules/Platform/Domain/Enums/PoliticaExcedente.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum PoliticaExcedente: string
{
    case Bloquear = 'BLOQUEAR';
    case Cobrar = 'COBRAR';
}
```

`backend/app/Modules/Tenancy/Domain/Enums/SituacaoAssinatura.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Enums;

enum SituacaoAssinatura: string
{
    case Pendente = 'PENDENTE';
    case Teste = 'TESTE';
    case Ativa = 'ATIVA';
    case Inadimplente = 'INADIMPLENTE';
    case Suspensa = 'SUSPENSA';
    case Cancelada = 'CANCELADA';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`backend/app/Modules/Platform/Domain/Models/Plano.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Models;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\PoliticaExcedente;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Database\Factories\PlanoFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $nome
 * @property ?string $descricao
 * @property int $preco_mensal_centavos
 * @property ?int $preco_anual_centavos
 * @property int $dias_teste
 * @property PoliticaExcedente $politica_excedente
 * @property ?int $preco_documento_excedente_centavos
 * @property bool $ativo
 * @property bool $visivel
 * @property int $ordem
 * @property-read Collection<int, PlanoModulo> $modulos
 * @property-read Collection<int, PlanoLimite> $limites
 */
class Plano extends Model
{
    /** @use HasFactory<PlanoFactory> */
    use HasFactory;

    use HasUuids;

    public const ILIMITADO = -1;

    protected $table = 'planos';

    protected $fillable = [
        'nome', 'descricao', 'preco_mensal_centavos', 'preco_anual_centavos', 'dias_teste',
        'politica_excedente', 'preco_documento_excedente_centavos', 'ativo', 'visivel', 'ordem',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'preco_mensal_centavos' => 'integer',
            'preco_anual_centavos' => 'integer',
            'dias_teste' => 'integer',
            'politica_excedente' => PoliticaExcedente::class,
            'preco_documento_excedente_centavos' => 'integer',
            'ativo' => 'boolean',
            'visivel' => 'boolean',
            'ordem' => 'integer',
        ];
    }

    /** @return HasMany<PlanoModulo, $this> */
    public function modulos(): HasMany
    {
        return $this->hasMany(PlanoModulo::class);
    }

    /** @return HasMany<PlanoLimite, $this> */
    public function limites(): HasMany
    {
        return $this->hasMany(PlanoLimite::class);
    }

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function temModulo(Modulo $modulo): bool
    {
        return $this->modulos->contains(fn (PlanoModulo $m): bool => $m->modulo === $modulo);
    }

    /** Fail-closed: recurso sem linha vale 0 (bloqueado), nunca ilimitado. */
    public function limiteDe(Recurso $recurso): int
    {
        return $this->limites->first(fn (PlanoLimite $l): bool => $l->recurso === $recurso)?->limite ?? 0;
    }

    protected static function newFactory(): PlanoFactory
    {
        return PlanoFactory::new();
    }
}
```

`backend/app/Modules/Platform/Domain/Models/PlanoModulo.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Models;

use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $plano_id
 * @property Modulo $modulo
 */
class PlanoModulo extends Model
{
    public $timestamps = false;

    protected $table = 'plano_modulos';

    protected $fillable = ['modulo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['modulo' => Modulo::class];
    }
}
```

`backend/app/Modules/Platform/Domain/Models/PlanoLimite.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Models;

use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $plano_id
 * @property Recurso $recurso
 * @property int $limite
 */
class PlanoLimite extends Model
{
    public $timestamps = false;

    protected $table = 'plano_limites';

    protected $fillable = ['recurso', 'limite'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recurso' => Recurso::class, 'limite' => 'integer'];
    }
}
```

`backend/app/Modules/Tenancy/Domain/Models/Tenant.php` (substitui o arquivo):
```php
<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Carbon\CarbonInterface;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $razao_social
 * @property ?string $nome_fantasia
 * @property string $cnpj
 * @property ?string $plano_id
 * @property SituacaoAssinatura $situacao
 * @property ?CarbonInterface $teste_termina_em
 * @property ?CarbonInterface $situacao_alterada_em
 * @property CarbonInterface $created_at
 * @property-read ?Plano $plano
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;

    /** plano_id e situacao mudam só pelas Actions (forceFill), nunca por fill(). */
    protected $fillable = ['razao_social', 'nome_fantasia', 'cnpj'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'situacao' => SituacaoAssinatura::class,
            'teste_termina_em' => 'date',
            'situacao_alterada_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plano, $this> */
    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    public function nomeDeExibicao(): string
    {
        return $this->nome_fantasia ?? $this->razao_social;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
```

`backend/database/factories/PlanoFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\PoliticaExcedente;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plano> */
class PlanoFactory extends Factory
{
    protected $model = Plano::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nome' => 'Plano '.fake()->unique()->numerify('#####'),
            'descricao' => null,
            'preco_mensal_centavos' => 9900,
            'preco_anual_centavos' => null,
            'dias_teste' => 0,
            'politica_excedente' => PoliticaExcedente::Bloquear,
            'preco_documento_excedente_centavos' => null,
            'ativo' => true,
            'visivel' => true,
            'ordem' => 0,
        ];
    }

    /** @param array<string, int> $limites chave = valor de Recurso */
    public function comLimites(array $limites): static
    {
        return $this->afterCreating(function (Plano $plano) use ($limites): void {
            foreach ($limites as $recurso => $limite) {
                $plano->limites()->create(['recurso' => $recurso, 'limite' => $limite]);
            }
            $plano->unsetRelation('limites');
        });
    }

    public function comModulos(Modulo ...$modulos): static
    {
        return $this->afterCreating(function (Plano $plano) use ($modulos): void {
            foreach ($modulos as $modulo) {
                $plano->modulos()->create(['modulo' => $modulo]);
            }
            $plano->unsetRelation('modulos');
        });
    }
}
```

`backend/database/factories/TenantFactory.php` (substitui `definition()` e acrescenta os estados):
```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'razao_social' => fake()->company(),
            'nome_fantasia' => null,
            'cnpj' => fake()->unique()->numerify('##############'),
            'plano_id' => Plano::factory(),
            'situacao' => SituacaoAssinatura::Ativa,
            'teste_termina_em' => null,
        ];
    }

    public function situacao(SituacaoAssinatura $situacao): static
    {
        return $this->state(['situacao' => $situacao]);
    }

    public function emTeste(string $terminaEm): static
    {
        return $this->state(['situacao' => SituacaoAssinatura::Teste, 'teste_termina_em' => $terminaEm]);
    }
}
```

`UsuarioResource.php`: substituir o bloco `tenant` por:
```php
            'tenant' => $this->tenant === null ? null : [
                'id' => $this->tenant->id,
                'razao_social' => $this->tenant->razao_social,
                'nome_fantasia' => $this->tenant->nome_fantasia,
                'cnpj' => $this->tenant->cnpj,
                'situacao' => $this->tenant->situacao->value,
                'teste_termina_em' => $this->tenant->teste_termina_em?->toDateString(),
            ],
```

`DatabaseSeeder.php` (a parte depois do admin):
```php
        $essencial = Plano::factory()
            ->comModulos(Modulo::FiscalNfe, Modulo::FiscalNfce)
            ->comLimites([Recurso::Usuarios->value => 3, Recurso::Clientes->value => 500, Recurso::Produtos->value => 500, Recurso::DocumentosMes->value => 200])
            ->create(['nome' => 'Essencial', 'preco_mensal_centavos' => 9900, 'dias_teste' => 14, 'ordem' => 1]);

        Plano::factory()
            ->comModulos(...Modulo::cases())
            ->comLimites([
                Recurso::Usuarios->value => 10, Recurso::Clientes->value => -1, Recurso::Produtos->value => -1,
                Recurso::Servicos->value => -1, Recurso::DocumentosMes->value => 2000, Recurso::ApiRequisicoesMin->value => 120,
            ])
            ->create(['nome' => 'Profissional', 'preco_mensal_centavos' => 24900, 'dias_teste' => 14, 'ordem' => 2]);

        $tenant = Tenant::factory()->create([
            'razao_social' => 'Empresa Demonstração Ltda', 'nome_fantasia' => 'Empresa Demonstração',
            'cnpj' => '11222333000181', 'plano_id' => $essencial->id,
        ]);
```
Os `use` necessários são `Modulo`, `Recurso` e `Plano`.

- [ ] **Passo 5: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam.

Se `LoginTest` ou outro teste da F0 quebrar por causa do campo `nome` do tenant, troque para `razao_social`.

Rode também `php artisan migrate:fresh --seed` no banco local, para confirmar que as migrations sobem no SQLite em arquivo.

- [ ] **Passo 6: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "feat: planos, módulos, limites, assinatura no tenant e auditoria"
```

---

### Tarefa 4: Máquina de estados da assinatura e expiração do teste

**Arquivos:**
- Modificar:
  - `backend/app/Modules/Tenancy/Domain/Enums/SituacaoAssinatura.php` (`podeIrPara`, `permiteEscrita`)
  - `backend/config/app.php` (`fuso_negocio`)
  - `backend/routes/console.php` (agendamento)
  - `backend/bootstrap/providers.php`
- Criar:
  - `backend/app/Modules/Platform/Domain/Exceptions/TransicaoDeSituacaoInvalidaException.php`
  - `backend/app/Modules/Platform/Application/Actions/MudarSituacaoDaEmpresa.php`
  - `backend/app/Modules/Platform/Console/ExpirarTestesCommand.php`
  - `backend/app/Modules/Platform/Providers/PlatformServiceProvider.php`
- Testes:
  - `backend/tests/Unit/Tenancy/SituacaoAssinaturaTest.php`
  - `backend/tests/Feature/Platform/MudarSituacaoDaEmpresaTest.php`
  - `backend/tests/Feature/Platform/ExpirarTestesTest.php`

**Interfaces:**
- Produz:
  - `SituacaoAssinatura::podeIrPara(self): bool` e `permiteEscrita(): bool`.
  - `MudarSituacaoDaEmpresa::executar(Tenant $tenant, SituacaoAssinatura $destino, string $motivo, ?Usuario $autor): Tenant`:
    - Lança `TransicaoDeSituacaoInvalidaException` (`422`, `TRANSICAO_INVALIDA`).
    - Audita com o evento `situacao_alterada` e as propriedades `de`, `para` e `motivo`.
  - `config('app.fuso_negocio')` = `'America/Sao_Paulo'`.
  - `PlatformServiceProvider`. As Tarefas 5, 6, 7, 8 e 9 acrescentam registros a ele.

- [ ] **Passo 1: testes que falham**

`backend/tests/Unit/Tenancy/SituacaoAssinaturaTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SituacaoAssinaturaTest extends TestCase
{
    /** @return array<string, array{S, S}> */
    public static function validas(): array
    {
        return [
            'pendente → ativa' => [S::Pendente, S::Ativa],
            'teste → ativa' => [S::Teste, S::Ativa],
            'teste → suspensa' => [S::Teste, S::Suspensa],
            'ativa → suspensa' => [S::Ativa, S::Suspensa],
            'inadimplente → suspensa' => [S::Inadimplente, S::Suspensa],
            'suspensa → ativa' => [S::Suspensa, S::Ativa],
            'pendente → cancelada' => [S::Pendente, S::Cancelada],
            'teste → cancelada' => [S::Teste, S::Cancelada],
            'ativa → cancelada' => [S::Ativa, S::Cancelada],
            'inadimplente → cancelada' => [S::Inadimplente, S::Cancelada],
            'suspensa → cancelada' => [S::Suspensa, S::Cancelada],
        ];
    }

    #[DataProvider('validas')]
    public function test_transicoes_validas(S $de, S $para): void
    {
        $this->assertTrue($de->podeIrPara($para));
    }

    /** @return array<string, array{S, S}> */
    public static function invalidas(): array
    {
        return [
            'pendente → suspensa' => [S::Pendente, S::Suspensa],
            'ativa → teste' => [S::Ativa, S::Teste],
            'cancelada → ativa' => [S::Cancelada, S::Ativa],
            'cancelada → cancelada' => [S::Cancelada, S::Cancelada],
            'ativa → ativa' => [S::Ativa, S::Ativa],
            'suspensa → inadimplente' => [S::Suspensa, S::Inadimplente],
        ];
    }

    #[DataProvider('invalidas')]
    public function test_transicoes_invalidas(S $de, S $para): void
    {
        $this->assertFalse($de->podeIrPara($para));
    }

    public function test_escrita_so_em_teste_ativa_e_inadimplente(): void
    {
        $comEscrita = array_values(array_filter(S::cases(), fn (S $s): bool => $s->permiteEscrita()));

        $this->assertSame([S::Teste, S::Ativa, S::Inadimplente], $comEscrita);
    }
}
```

`backend/tests/Feature/Platform/MudarSituacaoDaEmpresaTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\MudarSituacaoDaEmpresa;
use App\Modules\Platform\Domain\Exceptions\TransicaoDeSituacaoInvalidaException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MudarSituacaoDaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    public function test_transicao_valida_altera_e_audita_com_motivo(): void
    {
        $admin = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Superadmin]);
        $tenant = Tenant::factory()->situacao(SituacaoAssinatura::Pendente)->create();

        app(MudarSituacaoDaEmpresa::class)->executar($tenant, SituacaoAssinatura::Ativa, 'Pagamento conferido.', $admin);

        $tenant->refresh();
        $this->assertSame(SituacaoAssinatura::Ativa, $tenant->situacao);
        $this->assertNotNull($tenant->situacao_alterada_em);

        $registro = Activity::query()->where('event', 'situacao_alterada')->firstOrFail();
        $this->assertSame($tenant->id, $registro->subject_id);
        $this->assertSame($admin->id, $registro->causer_id);
        $this->assertSame('PENDENTE', $registro->getExtraProperty('de'));
        $this->assertSame('ATIVA', $registro->getExtraProperty('para'));
        $this->assertSame('Pagamento conferido.', $registro->getExtraProperty('motivo'));
    }

    public function test_transicao_invalida_lanca_e_nao_altera(): void
    {
        $tenant = Tenant::factory()->situacao(SituacaoAssinatura::Cancelada)->create();

        try {
            app(MudarSituacaoDaEmpresa::class)->executar($tenant, SituacaoAssinatura::Ativa, 'x', null);
            $this->fail('Deveria lançar.');
        } catch (TransicaoDeSituacaoInvalidaException $e) {
            $this->assertSame('TRANSICAO_INVALIDA', $e->codigo());
            $this->assertSame('Não é possível mudar a situação de CANCELADA para ATIVA.', $e->getMessage());
        }

        $this->assertSame(SituacaoAssinatura::Cancelada, $tenant->refresh()->situacao);
        $this->assertSame(0, Activity::query()->count());
    }
}
```

`backend/tests/Feature/Platform/ExpirarTestesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ExpirarTestesTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspende_teste_vencido_e_mantem_o_que_termina_hoje(): void
    {
        $this->travelTo('2026-10-10 12:00:00'); // UTC = 09:00 em São Paulo
        $vencido = Tenant::factory()->emTeste('2026-10-09')->create();
        $terminaHoje = Tenant::factory()->emTeste('2026-10-10')->create();
        $ativa = Tenant::factory()->create();

        $this->artisan('assinaturas:expirar-testes')->assertSuccessful();

        $this->assertSame(SituacaoAssinatura::Suspensa, $vencido->refresh()->situacao);
        $this->assertSame(SituacaoAssinatura::Teste, $terminaHoje->refresh()->situacao);
        $this->assertSame(SituacaoAssinatura::Ativa, $ativa->refresh()->situacao);
    }

    public function test_usa_o_dia_de_sao_paulo_e_nao_o_utc(): void
    {
        // 01:00 UTC do dia 11 = 22:00 do dia 10 em São Paulo: o teste que termina dia 10 ainda vale.
        $this->travelTo('2026-10-11 01:00:00');
        $tenant = Tenant::factory()->emTeste('2026-10-10')->create();

        $this->artisan('assinaturas:expirar-testes')->assertSuccessful();

        $this->assertSame(SituacaoAssinatura::Teste, $tenant->refresh()->situacao);
    }

    public function test_e_idempotente(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        Tenant::factory()->emTeste('2026-10-01')->create();

        $this->artisan('assinaturas:expirar-testes')->assertSuccessful();
        $this->artisan('assinaturas:expirar-testes')->assertSuccessful();

        $this->assertSame(1, Activity::query()->where('event', 'situacao_alterada')->count());
    }

    public function test_agendado_diariamente_as_00_10_de_sao_paulo(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'assinaturas:expirar-testes'));

        $this->assertNotNull($evento);
        $this->assertSame('10 0 * * *', $evento->expression);
        $this->assertSame('America/Sao_Paulo', $evento->timezone);
    }
}
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `php artisan test --filter="SituacaoAssinaturaTest|MudarSituacaoDaEmpresaTest|ExpirarTestesTest"`
Esperado: FALHAM (método `podeIrPara` inexistente, classes inexistentes e comando inexistente).

- [ ] **Passo 3: implementar**

Em `SituacaoAssinatura.php`, acrescentar:
```php
    /** Transições permitidas na F1 (spec F1 §4). Não há estado implícito. */
    public function podeIrPara(self $destino): bool
    {
        if ($destino === self::Cancelada) {
            return $this !== self::Cancelada;
        }

        $permitidos = match ($this) {
            self::Pendente => [self::Ativa],
            self::Teste => [self::Ativa, self::Suspensa],
            self::Ativa, self::Inadimplente => [self::Suspensa],
            self::Suspensa => [self::Ativa],
            self::Cancelada => [],
        };

        return in_array($destino, $permitidos, true);
    }

    /** Leitura continua liberada em SUSPENSA/CANCELADA: o cliente precisa baixar seus dados. */
    public function permiteEscrita(): bool
    {
        return in_array($this, [self::Teste, self::Ativa, self::Inadimplente], true);
    }
```

`backend/app/Modules/Platform/Domain/Exceptions/TransicaoDeSituacaoInvalidaException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;

final class TransicaoDeSituacaoInvalidaException extends ErroDeNegocio
{
    public static function de(SituacaoAssinatura $origem, SituacaoAssinatura $destino): self
    {
        return new self("Não é possível mudar a situação de {$origem->value} para {$destino->value}.");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'TRANSICAO_INVALIDA';
    }
}
```

`backend/app/Modules/Platform/Application/Actions/MudarSituacaoDaEmpresa.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\TransicaoDeSituacaoInvalidaException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class MudarSituacaoDaEmpresa
{
    /** $autor null = sistema (ex.: expiração automática do teste). */
    public function executar(Tenant $tenant, SituacaoAssinatura $destino, string $motivo, ?Usuario $autor): Tenant
    {
        $origem = $tenant->situacao;

        if (! $origem->podeIrPara($destino)) {
            throw TransicaoDeSituacaoInvalidaException::de($origem, $destino);
        }

        return DB::transaction(function () use ($tenant, $origem, $destino, $motivo, $autor): Tenant {
            $tenant->forceFill(['situacao' => $destino, 'situacao_alterada_em' => now()])->save();

            $registro = activity('assinatura')
                ->performedOn($tenant)
                ->event('situacao_alterada')
                ->withProperties(['de' => $origem->value, 'para' => $destino->value, 'motivo' => $motivo]);
            if ($autor !== null) {
                $registro->causedBy($autor);
            }
            $registro->log('Situação da assinatura alterada');

            return $tenant;
        });
    }
}
```

`backend/app/Modules/Platform/Console/ExpirarTestesCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Actions\MudarSituacaoDaEmpresa;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;

final class ExpirarTestesCommand extends Command
{
    protected $signature = 'assinaturas:expirar-testes';

    protected $description = 'Suspende as contas cujo período de teste terminou.';

    public function handle(MudarSituacaoDaEmpresa $mudarSituacao): int
    {
        // O teste vale até o fim do dia teste_termina_em, no fuso do negócio.
        $hoje = today((string) config('app.fuso_negocio'))->toDateString();
        $total = 0;

        // lazyById: seguro para alterar a coluna filtrada durante a iteração.
        Tenant::query()
            ->where('situacao', SituacaoAssinatura::Teste->value)
            ->whereDate('teste_termina_em', '<', $hoje)
            ->lazyById(100)
            ->each(function (Tenant $tenant) use ($mudarSituacao, &$total): void {
                $mudarSituacao->executar($tenant, SituacaoAssinatura::Suspensa, 'Período de teste encerrado.', null);
                $total++;
            });

        $this->info("{$total} conta(s) suspensa(s).");

        return self::SUCCESS;
    }
}
```

`backend/app/Modules/Platform/Providers/PlatformServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Console\ExpirarTestesCommand;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpirarTestesCommand::class]);
        }
    }
}
```

`bootstrap/providers.php`: acrescentar `App\Modules\Platform\Providers\PlatformServiceProvider::class` **antes** de `IdentityServiceProvider`. O Identity registra contadores no Platform na Tarefa 5.

`config/app.php`: logo depois de `'timezone' => 'UTC',`:
```php
    // Fuso das regras de negócio (vencimento de teste, "hoje"). O banco continua em UTC.
    'fuso_negocio' => 'America/Sao_Paulo',
```

`routes/console.php`: acrescentar:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('assinaturas:expirar-testes')->dailyAt('00:10')->timezone('America/Sao_Paulo');
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam.

- [ ] **Passo 5: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "feat: máquina de estados da assinatura e expiração diária do teste"
```

---

### Tarefa 5: EntitlementService

**Arquivos:**
- Criar:
  - `backend/app/Modules/Platform/Domain/Contracts/ContadorDeUso.php`
  - `backend/app/Modules/Platform/Domain/Exceptions/{LimiteDoPlanoAtingidoException,ModuloNaoContratadoException,RecursoSemContadorException}.php`
  - `backend/app/Modules/Platform/Application/{RegistroDeContadores,EntitlementService}.php`
  - `backend/app/Modules/Identity/Application/ContadorDeUsuariosAtivos.php`
- Modificar:
  - `PlatformServiceProvider.php` (singleton)
  - `IdentityServiceProvider.php` (registra o contador)
- Teste: `backend/tests/Feature/Platform/EntitlementServiceTest.php`

**Interfaces:**
- Produz:
  - `interface ContadorDeUso { recurso(): Recurso; contar(Tenant): int; }`
  - `RegistroDeContadores::registrar(ContadorDeUso)`, `para(Recurso): ContadorDeUso` (lança `RecursoSemContadorException`, um `LogicException`) e `recursos(): list<Recurso>`.
  - `EntitlementService`:
    - `temModulo(Tenant, Modulo): bool`
    - `garantirModulo(Tenant, Modulo): void`: lança `ModuloNaoContratadoException` (`403`, `MODULO_NAO_CONTRATADO`).
    - `limite(Tenant, Recurso): int`
    - `uso(Tenant, Recurso): int`
    - `garantirCapacidade(Tenant, Recurso, int $adicionar = 1): void`: lança `LimiteDoPlanoAtingidoException` (`422`, `LIMITE_DO_PLANO`, com os extras `recurso` e `limite`).
    - `consumo(Tenant, ?Plano = null): list<array{recurso: string, uso: int, limite: int}>`: só os recursos que têm contador.
    - `excessos(Tenant, Plano): list<array{recurso: string, uso: int, limite: int}>`
  - Tenant sem plano: todo limite vale 0 e todo módulo vale `false`.

- [ ] **Passo 1: teste que falha**

`backend/tests/Feature/Platform/EntitlementServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Platform\Domain\Exceptions\RecursoSemContadorException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): EntitlementService
    {
        return app(EntitlementService::class);
    }

    /** @param array<string, int> $limites */
    private function tenantCom(array $limites, int $ativos = 0, int $inativos = 0): Tenant
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comLimites($limites))->create();
        Usuario::factory()->count($ativos)->for($tenant)->create();
        Usuario::factory()->count($inativos)->for($tenant)->create(['ativo' => false]);

        return $tenant;
    }

    public function test_recurso_sem_linha_vale_zero(): void
    {
        $tenant = $this->tenantCom([]);

        $this->assertSame(0, $this->servico()->limite($tenant, Recurso::Usuarios));
        try {
            $this->servico()->garantirCapacidade($tenant, Recurso::Usuarios);
            $this->fail('Deveria barrar.');
        } catch (LimiteDoPlanoAtingidoException $e) {
            $this->assertSame('Seu plano permite até 0 usuários ativos.', $e->getMessage());
            $this->assertSame('LIMITE_DO_PLANO', $e->codigo());
            $this->assertSame(422, $e->status());
            $this->assertSame(['recurso' => 'USUARIOS', 'limite' => 0], $e->extras());
        }
    }

    public function test_menos_um_e_ilimitado(): void
    {
        $tenant = $this->tenantCom([Recurso::Usuarios->value => -1], ativos: 5);

        $this->servico()->garantirCapacidade($tenant, Recurso::Usuarios, 100);
        $this->addToAssertionCount(1);
    }

    public function test_limite_conta_so_usuarios_ativos(): void
    {
        $tenant = $this->tenantCom([Recurso::Usuarios->value => 2], ativos: 1, inativos: 3);

        $this->assertSame(1, $this->servico()->uso($tenant, Recurso::Usuarios));
        $this->servico()->garantirCapacidade($tenant, Recurso::Usuarios);

        Usuario::factory()->for($tenant)->create();
        $this->expectException(LimiteDoPlanoAtingidoException::class);
        $this->servico()->garantirCapacidade($tenant, Recurso::Usuarios);
    }

    public function test_recurso_sem_contador_lanca_em_vez_de_devolver_zero(): void
    {
        $tenant = $this->tenantCom([Recurso::Clientes->value => 10]);

        $this->expectException(RecursoSemContadorException::class);
        $this->servico()->uso($tenant, Recurso::Clientes);
    }

    public function test_tenant_sem_plano_nao_tem_modulo_nem_limite(): void
    {
        $tenant = Tenant::factory()->create(['plano_id' => null]);

        $this->assertFalse($this->servico()->temModulo($tenant, Modulo::Crm));
        $this->assertSame(0, $this->servico()->limite($tenant, Recurso::Usuarios));
    }

    public function test_garantir_modulo_nao_contratado(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::FiscalNfe))->create();

        $this->servico()->garantirModulo($tenant, Modulo::FiscalNfe);
        try {
            $this->servico()->garantirModulo($tenant, Modulo::FiscalNfse);
            $this->fail('Deveria barrar.');
        } catch (ModuloNaoContratadoException $e) {
            $this->assertSame('MODULO_NAO_CONTRATADO', $e->codigo());
            $this->assertSame(403, $e->status());
            $this->assertSame('O módulo NFS-e não faz parte do seu plano.', $e->getMessage());
        }
    }

    public function test_consumo_e_excessos(): void
    {
        $tenant = $this->tenantCom([Recurso::Usuarios->value => 5], ativos: 3);
        $menor = Plano::factory()->comLimites([Recurso::Usuarios->value => 2])->create();
        $ilimitado = Plano::factory()->comLimites([Recurso::Usuarios->value => -1])->create();

        $this->assertSame([['recurso' => 'USUARIOS', 'uso' => 3, 'limite' => 5]], $this->servico()->consumo($tenant));
        $this->assertSame([['recurso' => 'USUARIOS', 'uso' => 3, 'limite' => 2]], $this->servico()->excessos($tenant, $menor));
        $this->assertSame([], $this->servico()->excessos($tenant, $ilimitado));
    }
}
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `php artisan test --filter=EntitlementServiceTest`
Esperado: FALHA ("Class EntitlementService not found").

- [ ] **Passo 3: implementar**

`backend/app/Modules/Platform/Domain/Contracts/ContadorDeUso.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** Cada módulo registra o contador do recurso que ele controla. */
interface ContadorDeUso
{
    public function recurso(): Recurso;

    public function contar(Tenant $tenant): int;
}
```

`backend/app/Modules/Platform/Domain/Exceptions/LimiteDoPlanoAtingidoException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class LimiteDoPlanoAtingidoException extends ErroDeNegocio
{
    private function __construct(string $mensagem, private readonly Recurso $recurso, private readonly int $limite)
    {
        parent::__construct($mensagem);
    }

    public static function para(Recurso $recurso, int $limite): self
    {
        return new self("Seu plano permite até {$limite} {$recurso->descricaoDoLimite()}.", $recurso, $limite);
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'LIMITE_DO_PLANO';
    }

    public function extras(): array
    {
        return ['recurso' => $this->recurso->value, 'limite' => $this->limite];
    }
}
```

`backend/app/Modules/Platform/Domain/Exceptions/ModuloNaoContratadoException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class ModuloNaoContratadoException extends ErroDeNegocio
{
    public static function para(Modulo $modulo): self
    {
        return new self("O módulo {$modulo->rotulo()} não faz parte do seu plano.");
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'MODULO_NAO_CONTRATADO';
    }
}
```

`backend/app/Modules/Platform/Domain/Exceptions/RecursoSemContadorException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Platform\Domain\Enums\Recurso;
use LogicException;

/** Erro de programação: o módulo dono do recurso não registrou o contador. */
final class RecursoSemContadorException extends LogicException
{
    public static function para(Recurso $recurso): self
    {
        return new self("Nenhum contador de uso registrado para {$recurso->value}.");
    }
}
```

`backend/app/Modules/Platform/Application/RegistroDeContadores.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\RecursoSemContadorException;

final class RegistroDeContadores
{
    /** @var array<string, ContadorDeUso> */
    private array $contadores = [];

    public function registrar(ContadorDeUso $contador): void
    {
        $this->contadores[$contador->recurso()->value] = $contador;
    }

    public function para(Recurso $recurso): ContadorDeUso
    {
        return $this->contadores[$recurso->value] ?? throw RecursoSemContadorException::para($recurso);
    }

    /** @return list<Recurso> */
    public function recursos(): array
    {
        return array_values(array_filter(Recurso::cases(), fn (Recurso $r): bool => isset($this->contadores[$r->value])));
    }
}
```

`backend/app/Modules/Platform/Application/EntitlementService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;

/**
 * O que o plano do tenant permite. Chamado pelas Actions (nunca só pelo
 * controller), para valer igual no frontend e na API pública.
 */
final class EntitlementService
{
    public function __construct(private readonly RegistroDeContadores $contadores) {}

    public function temModulo(Tenant $tenant, Modulo $modulo): bool
    {
        return $tenant->plano?->temModulo($modulo) ?? false;
    }

    public function garantirModulo(Tenant $tenant, Modulo $modulo): void
    {
        if (! $this->temModulo($tenant, $modulo)) {
            throw ModuloNaoContratadoException::para($modulo);
        }
    }

    /** -1 = ilimitado; sem plano ou sem linha = 0. */
    public function limite(Tenant $tenant, Recurso $recurso): int
    {
        return $tenant->plano?->limiteDe($recurso) ?? 0;
    }

    public function uso(Tenant $tenant, Recurso $recurso): int
    {
        return $this->contadores->para($recurso)->contar($tenant);
    }

    public function garantirCapacidade(Tenant $tenant, Recurso $recurso, int $adicionar = 1): void
    {
        $limite = $this->limite($tenant, $recurso);

        if ($limite === Plano::ILIMITADO) {
            return;
        }

        if ($this->uso($tenant, $recurso) + $adicionar > $limite) {
            throw LimiteDoPlanoAtingidoException::para($recurso, $limite);
        }
    }

    /** @return list<array{recurso: string, uso: int, limite: int}> */
    public function consumo(Tenant $tenant, ?Plano $plano = null): array
    {
        $plano ??= $tenant->plano;

        return array_map(fn (Recurso $recurso): array => [
            'recurso' => $recurso->value,
            'uso' => $this->uso($tenant, $recurso),
            'limite' => $plano?->limiteDe($recurso) ?? 0,
        ], $this->contadores->recursos());
    }

    /** @return list<array{recurso: string, uso: int, limite: int}> */
    public function excessos(Tenant $tenant, Plano $plano): array
    {
        return array_values(array_filter(
            $this->consumo($tenant, $plano),
            fn (array $item): bool => $item['limite'] !== Plano::ILIMITADO && $item['uso'] > $item['limite'],
        ));
    }
}
```

`backend/app/Modules/Identity/Application/ContadorDeUsuariosAtivos.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class ContadorDeUsuariosAtivos implements ContadorDeUso
{
    public function recurso(): Recurso
    {
        return Recurso::Usuarios;
    }

    public function contar(Tenant $tenant): int
    {
        return Usuario::query()->where('tenant_id', $tenant->id)->where('ativo', true)->count();
    }
}
```

`PlatformServiceProvider`: acrescentar
```php
    public function register(): void
    {
        $this->app->singleton(\App\Modules\Platform\Application\RegistroDeContadores::class);
    }
```

`IdentityServiceProvider::boot()`: no início, acrescentar
```php
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeUsuariosAtivos);
```
Os `use` necessários são `App\Modules\Platform\Application\RegistroDeContadores` e `App\Modules\Identity\Application\ContadorDeUsuariosAtivos`.

- [ ] **Passo 4: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam.

- [ ] **Passo 5: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "feat: EntitlementService com contadores de uso por módulo"
```

---
### Tarefa 6: Áreas de acesso, situação do tenant e `/api/app/assinatura`

**Arquivos:**
- Criar:
  - `backend/app/Modules/Platform/Http/Middleware/{ExigirUsuarioDaEmpresa,SomentePlataforma,VerificarSituacaoDoTenant}.php`
  - `backend/app/Modules/Platform/Domain/Exceptions/{AssinaturaSemEscritaException,AssinaturaPendenteException}.php`
  - `backend/app/Modules/Platform/Http/Resources/PlanoResource.php`
  - `backend/app/Modules/Platform/Http/Controllers/AssinaturaController.php`
  - `backend/app/Modules/Platform/Http/routes-app.php`
- Modificar:
  - `backend/bootstrap/app.php` (grupos `empresa` e `plataforma`)
  - `PlatformServiceProvider.php` (rotas)
- Testes:
  - `backend/tests/Feature/Platform/AcessoPorSituacaoTest.php`
  - `backend/tests/Feature/Platform/SomentePlataformaTest.php`
  - `backend/tests/Feature/Platform/AssinaturaTest.php`

**Interfaces:**
- Consome: `SituacaoAssinatura::permiteEscrita()` (T4), `EntitlementService::consumo()` (T5), `GarantirUsuarioAtivo` (T1) e `AcessoNegadoException` (T2).
- Produz:
  - O grupo de middleware **`empresa`**: `auth:sanctum`, `GarantirUsuarioAtivo`, `DefinirTenantDoUsuario`, `ExigirUsuarioDaEmpresa` e `VerificarSituacaoDoTenant`.
  - O grupo **`plataforma`**: `auth:sanctum`, `GarantirUsuarioAtivo`, `DefinirTenantDoUsuario` e `SomentePlataforma`.
  - Com esses grupos, as rotas de empresa ficam em `Route::middleware('empresa')` e as de admin em `Route::middleware('plataforma')`.
  - `VerificarSituacaoDoTenant::LIBERADAS_EM_PENDENTE = ['app.assinatura']`: nomes de rota que podem ser lidos em `PENDENTE`.
  - `PlanoResource` com o JSON `{id, nome, descricao, preco_mensal_centavos, preco_anual_centavos, dias_teste, politica_excedente, preco_documento_excedente_centavos, modulos: string[], limites: {RECURSO: int} (todos os recursos, ausente = 0), ativo, visivel, ordem, empresas?}`. `empresas` só aparece com `withCount('tenants')`.
  - `GET /api/app/assinatura` (nome `app.assinatura`) → `{data: {situacao, teste_termina_em, plano: PlanoResource|null, consumo: [...]}}`.

**Ruling já tomado neste plano:** leitura bloqueada em `PENDENTE` responde `403` com o código `ASSINATURA_PENDENTE` e a mensagem "Sua conta está aguardando ativação.". A spec só nomeia o código de escrita. Usar `ASSINATURA_SEM_ESCRITA` para uma leitura seria enganoso.

- [ ] **Passo 1: testes que falham**

`backend/tests/Feature/Platform/AcessoPorSituacaoTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura as S;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AcessoPorSituacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['api', 'empresa'])->prefix('api/app')->group(function (): void {
            Route::get('teste-leitura', fn () => ['ok' => true]);
            Route::post('teste-escrita', fn () => ['ok' => true]);
        });
    }

    private function usuarioEm(S $situacao): Usuario
    {
        return Usuario::factory()->for(Tenant::factory()->situacao($situacao))->create();
    }

    /** @return array<string, array{S}> */
    public static function semEscrita(): array
    {
        return ['pendente' => [S::Pendente], 'suspensa' => [S::Suspensa], 'cancelada' => [S::Cancelada]];
    }

    #[DataProvider('semEscrita')]
    public function test_escrita_bloqueada(S $situacao): void
    {
        $this->spa()->actingAs($this->usuarioEm($situacao))->postJson('/api/app/teste-escrita')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ASSINATURA_SEM_ESCRITA');
    }

    /** @return array<string, array{S}> */
    public static function comEscrita(): array
    {
        return ['teste' => [S::Teste], 'ativa' => [S::Ativa], 'inadimplente' => [S::Inadimplente]];
    }

    #[DataProvider('comEscrita')]
    public function test_escrita_liberada(S $situacao): void
    {
        $this->spa()->actingAs($this->usuarioEm($situacao))->postJson('/api/app/teste-escrita')->assertOk();
    }

    public function test_leitura_liberada_em_suspensa_e_cancelada(): void
    {
        $this->spa()->actingAs($this->usuarioEm(S::Suspensa))->getJson('/api/app/teste-leitura')->assertOk();
        $this->spa()->actingAs($this->usuarioEm(S::Cancelada))->getJson('/api/app/teste-leitura')->assertOk();
    }

    public function test_pendente_so_le_a_assinatura(): void
    {
        $usuario = $this->usuarioEm(S::Pendente);

        $this->spa()->actingAs($usuario)->getJson('/api/app/teste-leitura')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ASSINATURA_PENDENTE');
        $this->spa()->actingAs($usuario)->getJson('/api/app/assinatura')->assertOk();
        $this->spa()->actingAs($usuario)->getJson('/api/app/auth/me')->assertOk();
    }

    public function test_admin_da_plataforma_nao_acessa_rota_de_empresa(): void
    {
        $admin = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Superadmin]);

        $this->spa()->actingAs($admin)->getJson('/api/app/teste-leitura')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_sem_sessao_responde_401(): void
    {
        $this->spa()->getJson('/api/app/teste-leitura')->assertUnauthorized();
    }
}
```

`backend/tests/Feature/Platform/SomentePlataformaTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SomentePlataformaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['api', 'plataforma'])->prefix('api/admin')->group(function (): void {
            Route::get('teste', fn () => ['ok' => true]);
            Route::post('teste', fn () => ['ok' => true]);
        });
    }

    private function daPlataforma(Papel $papel): Usuario
    {
        return Usuario::factory()->create(['tenant_id' => null, 'papel' => $papel]);
    }

    public function test_superadmin_le_e_escreve(): void
    {
        $admin = $this->daPlataforma(Papel::Superadmin);

        $this->spa()->actingAs($admin)->getJson('/api/admin/teste')->assertOk();
        $this->spa()->actingAs($admin)->postJson('/api/admin/teste')->assertOk();
    }

    public function test_suporte_so_le(): void
    {
        $suporte = $this->daPlataforma(Papel::Suporte);

        $this->spa()->actingAs($suporte)->getJson('/api/admin/teste')->assertOk();
        $this->spa()->actingAs($suporte)->postJson('/api/admin/teste')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_usuario_de_empresa_nao_acessa_o_admin(): void
    {
        $dono = Usuario::factory()->for(Tenant::factory())->create(['papel' => Papel::Proprietario]);

        $this->spa()->actingAs($dono)->getJson('/api/admin/teste')->assertForbidden();
    }
}
```

`backend/tests/Feature/Platform/AssinaturaTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssinaturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_devolve_situacao_plano_e_consumo(): void
    {
        $plano = Plano::factory()
            ->comModulos(Modulo::FiscalNfe)
            ->comLimites([Recurso::Usuarios->value => 3, Recurso::Clientes->value => -1])
            ->create(['nome' => 'Essencial']);
        $tenant = Tenant::factory()->for($plano)->emTeste('2026-10-15')->create();
        $usuario = Usuario::factory()->for($tenant)->create();

        $this->spa()->actingAs($usuario)->getJson('/api/app/assinatura')
            ->assertOk()
            ->assertJsonPath('data.situacao', 'TESTE')
            ->assertJsonPath('data.teste_termina_em', '2026-10-15')
            ->assertJsonPath('data.plano.nome', 'Essencial')
            ->assertJsonPath('data.plano.modulos', ['FISCAL_NFE'])
            ->assertJsonPath('data.plano.limites.USUARIOS', 3)
            ->assertJsonPath('data.plano.limites.CLIENTES', -1)
            ->assertJsonPath('data.plano.limites.PRODUTOS', 0)
            ->assertJsonPath('data.consumo', [['recurso' => 'USUARIOS', 'uso' => 1, 'limite' => 3]])
            ->assertJsonMissingPath('data.plano.empresas');
    }
}
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `php artisan test --filter="AcessoPorSituacaoTest|SomentePlataformaTest|AssinaturaTest"`
Esperado: FALHAM ("Target class [empresa] does not exist" e 404 em `/api/app/assinatura`).

- [ ] **Passo 3: implementar**

`backend/app/Modules/Platform/Domain/Exceptions/AssinaturaSemEscritaException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;

final class AssinaturaSemEscritaException extends ErroDeNegocio
{
    public static function para(SituacaoAssinatura $situacao): self
    {
        return new self(match ($situacao) {
            SituacaoAssinatura::Pendente => 'Sua conta está aguardando ativação.',
            SituacaoAssinatura::Cancelada => 'Sua conta está cancelada. Você ainda pode consultar e baixar seus dados.',
            default => 'Sua conta está suspensa. Você ainda pode consultar e baixar seus dados.',
        });
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'ASSINATURA_SEM_ESCRITA';
    }
}
```

`backend/app/Modules/Platform/Domain/Exceptions/AssinaturaPendenteException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class AssinaturaPendenteException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Sua conta está aguardando ativação.');
    }

    public function status(): int
    {
        return 403;
    }

    public function codigo(): string
    {
        return 'ASSINATURA_PENDENTE';
    }
}
```

`backend/app/Modules/Platform/Http/Middleware/ExigirUsuarioDaEmpresa.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExigirUsuarioDaEmpresa
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->tenant_id === null) {
            throw new AcessoNegadoException('Esta área é exclusiva das empresas.');
        }

        return $next($request);
    }
}
```

`backend/app/Modules/Platform/Http/Middleware/SomentePlataforma.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** SUPERADMIN faz tudo; SUPORTE só consulta. */
final class SomentePlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof Usuario || ! $usuario->papel->ehDaPlataforma()) {
            throw new AcessoNegadoException('Esta área é exclusiva da administração da plataforma.');
        }

        if ($usuario->papel === Papel::Suporte && ! $request->isMethodSafe()) {
            throw new AcessoNegadoException('O perfil de suporte só pode consultar.');
        }

        return $next($request);
    }
}
```

`backend/app/Modules/Platform/Http/Middleware/VerificarSituacaoDoTenant.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\AssinaturaPendenteException;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Regras de acesso por situação da assinatura (spec F1 §4). */
final class VerificarSituacaoDoTenant
{
    /** Rotas que uma conta PENDENTE pode ler. O /me fica fora deste grupo. */
    public const LIBERADAS_EM_PENDENTE = ['app.assinatura'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $situacao = $usuario->tenant?->situacao ?? SituacaoAssinatura::Pendente;

        if (! $request->isMethodSafe() && ! $situacao->permiteEscrita()) {
            throw AssinaturaSemEscritaException::para($situacao);
        }

        if ($situacao === SituacaoAssinatura::Pendente && ! $request->routeIs(...self::LIBERADAS_EM_PENDENTE)) {
            throw new AssinaturaPendenteException;
        }

        return $next($request);
    }
}
```

`bootstrap/app.php`: em `withMiddleware`, depois do `prependToPriorityList`:
```php
        $middleware->group('empresa', [
            'auth:sanctum',
            \App\Modules\Identity\Http\Middleware\GarantirUsuarioAtivo::class,
            \App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario::class,
            \App\Modules\Platform\Http\Middleware\ExigirUsuarioDaEmpresa::class,
            \App\Modules\Platform\Http\Middleware\VerificarSituacaoDoTenant::class,
        ]);
        $middleware->group('plataforma', [
            'auth:sanctum',
            \App\Modules\Identity\Http\Middleware\GarantirUsuarioAtivo::class,
            \App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario::class,
            \App\Modules\Platform\Http\Middleware\SomentePlataforma::class,
        ]);
```

`backend/app/Modules/Platform/Http/Resources/PlanoResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Domain\Models\PlanoModulo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plano */
final class PlanoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $limites = [];
        foreach (Recurso::cases() as $recurso) {
            $limites[$recurso->value] = $this->limiteDe($recurso);
        }

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'preco_mensal_centavos' => $this->preco_mensal_centavos,
            'preco_anual_centavos' => $this->preco_anual_centavos,
            'dias_teste' => $this->dias_teste,
            'politica_excedente' => $this->politica_excedente->value,
            'preco_documento_excedente_centavos' => $this->preco_documento_excedente_centavos,
            'modulos' => $this->modulos->map(fn (PlanoModulo $m): string => $m->modulo->value)->values()->all(),
            'limites' => $limites,
            'ativo' => $this->ativo,
            'visivel' => $this->visivel,
            'ordem' => $this->ordem,
            'empresas' => $this->whenCounted('tenants'),
        ];
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/AssinaturaController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Http\Resources\PlanoResource;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssinaturaController
{
    public function __invoke(Request $request, EntitlementService $entitlements): JsonResponse
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        /** @var Tenant $tenant */
        $tenant = $usuario->tenant()->with(['plano.modulos', 'plano.limites'])->firstOrFail();

        return response()->json(['data' => [
            'situacao' => $tenant->situacao->value,
            'teste_termina_em' => $tenant->teste_termina_em?->toDateString(),
            'plano' => $tenant->plano === null ? null : (new PlanoResource($tenant->plano))->resolve($request),
            'consumo' => $entitlements->consumo($tenant),
        ]]);
    }
}
```

`backend/app/Modules/Platform/Http/routes-app.php`:
```php
<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\AssinaturaController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('assinatura', AssinaturaController::class)->name('app.assinatura');
});
```

`PlatformServiceProvider::boot()`: acrescentar
```php
        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
```
com `use Illuminate\Support\Facades\Route;`.

- [ ] **Passo 4: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam.

- [ ] **Passo 5: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "feat: áreas de acesso (empresa e plataforma), bloqueio por situação e consumo da assinatura"
```

---

### Tarefa 7: Admin de planos (API)

**Arquivos:**
- Criar:
  - `backend/app/Modules/Platform/Application/Actions/{SalvarPlano,DesativarPlano}.php`
  - `backend/app/Modules/Platform/Http/Requests/SalvarPlanoRequest.php`
  - `backend/app/Modules/Platform/Http/Controllers/Admin/{PlanosController,DesativarPlanoController}.php`
  - `backend/app/Modules/Platform/Http/routes-admin.php`
- Modificar: `PlatformServiceProvider.php` (rotas de admin)
- Teste: `backend/tests/Feature/Platform/AdminPlanosTest.php`

**Interfaces:**
- Consome: o grupo `plataforma` e o `PlanoResource` (T6).
- Produz:
  - Endpoints:
    - `GET /api/admin/planos`: lista com `empresas`, ordenada por `ordem` e depois por `nome`.
    - `POST /api/admin/planos` → `201`.
    - `GET /api/admin/planos/{plano}`.
    - `PUT /api/admin/planos/{plano}`.
    - `POST /api/admin/planos/{plano}/desativar`.
  - Payload de entrada: `{nome, descricao?, preco_mensal_centavos, preco_anual_centavos?, dias_teste, politica_excedente, preco_documento_excedente_centavos?, ativo, visivel, ordem, modulos: string[], limites: {RECURSO: int>=-1}}`.
  - `SalvarPlano::executar(?Plano $plano, array $dados, Usuario $autor): Plano`, que audita com os eventos `plano_criado` e `plano_editado`.
  - `DesativarPlano::executar(Plano, Usuario): Plano`, que audita com o evento `plano_desativado`.
  - Não existe rota de exclusão.

- [ ] **Passo 1: teste que falha**

`backend/tests/Feature/Platform/AdminPlanosTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminPlanosTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Superadmin]);
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Essencial',
            'descricao' => 'Para quem está começando.',
            'preco_mensal_centavos' => 9900,
            'preco_anual_centavos' => 99000,
            'dias_teste' => 14,
            'politica_excedente' => 'BLOQUEAR',
            'preco_documento_excedente_centavos' => null,
            'ativo' => true,
            'visivel' => true,
            'ordem' => 1,
            'modulos' => ['FISCAL_NFE', 'CRM'],
            'limites' => ['USUARIOS' => 3, 'CLIENTES' => -1],
        ], $extra);
    }

    public function test_superadmin_cria_plano_com_modulos_e_limites(): void
    {
        $resposta = $this->spa()->actingAs($this->admin)->postJson('/api/admin/planos', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Essencial')
            ->assertJsonPath('data.modulos', ['FISCAL_NFE', 'CRM'])
            ->assertJsonPath('data.limites.USUARIOS', 3)
            ->assertJsonPath('data.limites.CLIENTES', -1)
            ->assertJsonPath('data.limites.PRODUTOS', 0)
            ->assertJsonPath('data.empresas', 0);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'plano_criado', 'subject_id' => $resposta->json('data.id'), 'causer_id' => $this->admin->id,
        ]);
    }

    public function test_editar_substitui_modulos_e_limites_e_informa_quantas_empresas_usam(): void
    {
        $plano = Plano::factory()->create();
        Tenant::factory()->count(2)->for($plano)->create();

        $this->spa()->actingAs($this->admin)
            ->putJson("/api/admin/planos/{$plano->id}", $this->payload(['nome' => $plano->nome, 'modulos' => ['API'], 'limites' => []]))
            ->assertOk()
            ->assertJsonPath('data.modulos', ['API'])
            ->assertJsonPath('data.limites.USUARIOS', 0)
            ->assertJsonPath('data.empresas', 2);

        $this->assertSame(1, Activity::query()->where('event', 'plano_editado')->count());
    }

    public function test_validacoes(): void
    {
        Plano::factory()->create(['nome' => 'Essencial']);
        $admin = $this->spa()->actingAs($this->admin);

        $admin->postJson('/api/admin/planos', $this->payload())->assertJsonValidationErrors('nome');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'B', 'politica_excedente' => 'COBRAR']))
            ->assertJsonValidationErrors('preco_documento_excedente_centavos');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'C', 'limites' => ['INVENTADO' => 1]]))
            ->assertJsonValidationErrors('limites');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'D', 'limites' => ['USUARIOS' => -2]]))
            ->assertJsonValidationErrors('limites.USUARIOS');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'E', 'modulos' => ['NFE']]))
            ->assertJsonValidationErrors('modulos.0');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'F', 'preco_mensal_centavos' => 99.9]))
            ->assertJsonValidationErrors('preco_mensal_centavos');
    }

    public function test_desativar_mantem_as_empresas_no_plano(): void
    {
        $plano = Plano::factory()->create();
        $tenant = Tenant::factory()->for($plano)->create();

        $this->spa()->actingAs($this->admin)->postJson("/api/admin/planos/{$plano->id}/desativar")
            ->assertOk()
            ->assertJsonPath('data.ativo', false);

        $this->assertSame($plano->id, $tenant->refresh()->plano_id);
        $this->assertSame(1, Activity::query()->where('event', 'plano_desativado')->count());
    }

    public function test_nao_existe_exclusao(): void
    {
        $plano = Plano::factory()->create();

        $this->spa()->actingAs($this->admin)->deleteJson("/api/admin/planos/{$plano->id}")->assertStatus(405);
    }

    public function test_suporte_le_mas_nao_escreve(): void
    {
        $suporte = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Suporte]);

        $this->spa()->actingAs($suporte)->getJson('/api/admin/planos')->assertOk();
        $this->spa()->actingAs($suporte)->postJson('/api/admin/planos', $this->payload())->assertForbidden();
    }

    public function test_usuario_de_empresa_nao_acessa(): void
    {
        $dono = Usuario::factory()->for(Tenant::factory())->create();

        $this->spa()->actingAs($dono)->getJson('/api/admin/planos')->assertForbidden();
    }
}
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `php artisan test --filter=AdminPlanosTest`
Esperado: FALHA (404 nas rotas).

- [ ] **Passo 3: implementar**

`backend/app/Modules/Platform/Http/Requests/SalvarPlanoRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\PoliticaExcedente;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SalvarPlanoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:60', Rule::unique('planos', 'nome')->ignore($this->route('plano'))],
            'descricao' => ['nullable', 'string', 'max:500'],
            'preco_mensal_centavos' => ['required', 'integer', 'min:0'],
            'preco_anual_centavos' => ['nullable', 'integer', 'min:0'],
            'dias_teste' => ['required', 'integer', 'min:0', 'max:90'],
            'politica_excedente' => ['required', Rule::enum(PoliticaExcedente::class)],
            'preco_documento_excedente_centavos' => ['nullable', 'required_if:politica_excedente,COBRAR', 'integer', 'min:1'],
            'ativo' => ['required', 'boolean'],
            'visivel' => ['required', 'boolean'],
            'ordem' => ['required', 'integer', 'min:0', 'max:1000'],
            'modulos' => ['present', 'array'],
            'modulos.*' => ['distinct', Rule::enum(Modulo::class)],
            'limites' => ['present', 'array:'.implode(',', Recurso::valores())],
            'limites.*' => ['integer', 'min:-1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'preco_documento_excedente_centavos.required_if' => 'Informe o preço por documento excedente.',
            'limites.array' => 'Há recurso desconhecido nos limites.',
            'limites.*.min' => 'Use -1 para ilimitado ou um número a partir de 0.',
        ];
    }

    /**
     * @return array{nome: string, descricao: ?string, preco_mensal_centavos: int, preco_anual_centavos: ?int,
     *     dias_teste: int, politica_excedente: string, preco_documento_excedente_centavos: ?int, ativo: bool,
     *     visivel: bool, ordem: int, modulos: list<string>, limites: array<string, int>}
     */
    public function dados(): array
    {
        /** @var array{nome: string, descricao: ?string, preco_mensal_centavos: int, preco_anual_centavos: ?int, dias_teste: int, politica_excedente: string, preco_documento_excedente_centavos: ?int, ativo: bool, visivel: bool, ordem: int, modulos: list<string>, limites: array<string, int>} $dados */
        $dados = array_merge(
            ['descricao' => null, 'preco_anual_centavos' => null, 'preco_documento_excedente_centavos' => null],
            $this->validated(),
        );

        return $dados;
    }
}
```

`backend/app/Modules/Platform/Application/Actions/SalvarPlano.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Cria ou edita um plano. Editar vale para todas as empresas que o usam (spec F1 §8). */
final class SalvarPlano
{
    /**
     * @param array{nome: string, descricao: ?string, preco_mensal_centavos: int, preco_anual_centavos: ?int,
     *     dias_teste: int, politica_excedente: string, preco_documento_excedente_centavos: ?int, ativo: bool,
     *     visivel: bool, ordem: int, modulos: list<string>, limites: array<string, int>} $dados
     */
    public function executar(?Plano $plano, array $dados, Usuario $autor): Plano
    {
        return DB::transaction(function () use ($plano, $dados, $autor): Plano {
            $novo = $plano === null;
            $plano ??= new Plano;
            $plano->fill(Arr::except($dados, ['modulos', 'limites']))->save();

            $plano->modulos()->delete();
            foreach ($dados['modulos'] as $modulo) {
                $plano->modulos()->create(['modulo' => $modulo]);
            }

            $plano->limites()->delete();
            foreach ($dados['limites'] as $recurso => $limite) {
                $plano->limites()->create(['recurso' => $recurso, 'limite' => $limite]);
            }

            activity('plataforma')
                ->performedOn($plano)
                ->causedBy($autor)
                ->event($novo ? 'plano_criado' : 'plano_editado')
                ->withProperties(['dados' => $dados])
                ->log($novo ? 'Plano criado' : 'Plano editado');

            return $plano->load(['modulos', 'limites'])->loadCount('tenants');
        });
    }
}
```

`backend/app/Modules/Platform/Application/Actions/DesativarPlano.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;

/** Plano nunca é excluído: sai da venda, e as empresas que o usam continuam nele. */
final class DesativarPlano
{
    public function executar(Plano $plano, Usuario $autor): Plano
    {
        $plano->forceFill(['ativo' => false])->save();

        activity('plataforma')->performedOn($plano)->causedBy($autor)->event('plano_desativado')->log('Plano desativado');

        return $plano->load(['modulos', 'limites'])->loadCount('tenants');
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/Admin/PlanosController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\SalvarPlano;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Http\Requests\SalvarPlanoRequest;
use App\Modules\Platform\Http\Resources\PlanoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlanosController
{
    public function index(): AnonymousResourceCollection
    {
        return PlanoResource::collection(
            Plano::query()->with(['modulos', 'limites'])->withCount('tenants')->orderBy('ordem')->orderBy('nome')->get(),
        );
    }

    public function show(Plano $plano): PlanoResource
    {
        return new PlanoResource($plano->load(['modulos', 'limites'])->loadCount('tenants'));
    }

    public function store(SalvarPlanoRequest $request, SalvarPlano $salvar): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return (new PlanoResource($salvar->executar(null, $request->dados(), $autor)))->response()->setStatusCode(201);
    }

    public function update(SalvarPlanoRequest $request, Plano $plano, SalvarPlano $salvar): PlanoResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return new PlanoResource($salvar->executar($plano, $request->dados(), $autor));
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/Admin/DesativarPlanoController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\DesativarPlano;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Http\Resources\PlanoResource;
use Illuminate\Http\Request;

final class DesativarPlanoController
{
    public function __invoke(Request $request, Plano $plano, DesativarPlano $desativar): PlanoResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return new PlanoResource($desativar->executar($plano, $autor));
    }
}
```

`backend/app/Modules/Platform/Http/routes-admin.php`:
```php
<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Admin\DesativarPlanoController;
use App\Modules\Platform\Http\Controllers\Admin\PlanosController;
use Illuminate\Support\Facades\Route;

Route::middleware('plataforma')->group(function (): void {
    Route::get('planos', [PlanosController::class, 'index']);
    Route::post('planos', [PlanosController::class, 'store']);
    Route::get('planos/{plano}', [PlanosController::class, 'show']);
    Route::put('planos/{plano}', [PlanosController::class, 'update']);
    Route::post('planos/{plano}/desativar', DesativarPlanoController::class);
});
```

`PlatformServiceProvider::boot()`: acrescentar
```php
        Route::middleware('api')->prefix('api/admin')->group(__DIR__.'/../Http/routes-admin.php');
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam.

- [ ] **Passo 5: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "feat: admin de planos (CRUD sem exclusão, módulos, limites e auditoria)"
```

---

### Tarefa 8: Admin de empresas e métricas (API)

**Arquivos:**
- Criar:
  - `backend/app/Modules/Platform/Application/Actions/TrocarPlanoDaEmpresa.php`
  - `backend/app/Modules/Platform/Domain/Exceptions/{PlanoInsuficienteException,PlanoIndisponivelException}.php`
  - `backend/app/Modules/Platform/Http/Requests/{MudarSituacaoRequest,TrocarPlanoRequest}.php`
  - `backend/app/Modules/Platform/Http/Resources/EmpresaResource.php`
  - `backend/app/Modules/Platform/Http/Controllers/Admin/{EmpresasController,MudarSituacaoController,TrocarPlanoController,MetricasController}.php`
- Modificar: `routes-admin.php`
- Teste: `backend/tests/Feature/Platform/AdminEmpresasTest.php`

**Interfaces:**
- Consome: `MudarSituacaoDaEmpresa` (T4), `EntitlementService::consumo/excessos` (T5) e o grupo `plataforma` (T6).
- Produz:
  - `GET /api/admin/empresas?situacao=&plano_id=&busca=&cursor=`: 20 por página. JSON do `cursorPaginate`: `data` e `meta.next_cursor`.
  - `GET /api/admin/empresas/{tenant}` → `data` com `consumo`.
  - `POST /api/admin/empresas/{tenant}/situacao` `{situacao, motivo}`.
  - `POST /api/admin/empresas/{tenant}/plano` `{plano_id}`. Com excesso, responde `422` `{codigo: "PLANO_EXCEDIDO", excessos: [...]}`. Com plano desativado, `422` `{codigo: "PLANO_INDISPONIVEL"}`.
  - `GET /api/admin/metricas` → `{data: {por_situacao: {PENDENTE: n, ...}, novas_30_dias: n, mrr_centavos: n}}`.
  - `EmpresaResource`: `{id, cnpj, razao_social, nome_fantasia, situacao, teste_termina_em, situacao_alterada_em, plano: {id, nome, preco_mensal_centavos}|null, criada_em, consumo?}`.

- [ ] **Passo 1: teste que falha**

`backend/tests/Feature/Platform/AdminEmpresasTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura as S;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminEmpresasTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Superadmin]);
    }

    public function test_lista_filtra_por_situacao_e_busca_por_cnpj_ou_nome(): void
    {
        Tenant::factory()->situacao(S::Suspensa)->create(['razao_social' => 'Padaria Pão Bom Ltda', 'cnpj' => '11222333000181']);
        Tenant::factory()->create(['razao_social' => 'Mercado Central']);
        $admin = $this->spa()->actingAs($this->admin);

        $admin->getJson('/api/admin/empresas?situacao=SUSPENSA')->assertOk()->assertJsonCount(1, 'data');
        $admin->getJson('/api/admin/empresas?busca=pão bom')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.razao_social', 'Padaria Pão Bom Ltda');
        $admin->getJson('/api/admin/empresas?busca=11.222.333')->assertJsonCount(1, 'data');
        $admin->getJson('/api/admin/empresas?situacao=INVENTADA')->assertJsonValidationErrors('situacao');
    }

    public function test_paginacao_por_cursor(): void
    {
        Tenant::factory()->count(25)->create();

        $primeira = $this->spa()->actingAs($this->admin)->getJson('/api/admin/empresas')->assertOk()->assertJsonCount(20, 'data');
        $cursor = $primeira->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $this->spa()->actingAs($this->admin)->getJson('/api/admin/empresas?cursor='.$cursor)->assertJsonCount(5, 'data');
    }

    public function test_detalhe_tem_consumo(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Usuarios->value => 3]))->create();
        Usuario::factory()->count(2)->for($tenant)->create();

        $this->spa()->actingAs($this->admin)->getJson("/api/admin/empresas/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id)
            ->assertJsonPath('data.consumo', [['recurso' => 'USUARIOS', 'uso' => 2, 'limite' => 3]]);
    }

    public function test_mudar_situacao_exige_motivo_e_segue_a_maquina_de_estados(): void
    {
        $tenant = Tenant::factory()->situacao(S::Pendente)->create();
        $admin = $this->spa()->actingAs($this->admin);

        $admin->postJson("/api/admin/empresas/{$tenant->id}/situacao", ['situacao' => 'ATIVA'])
            ->assertJsonValidationErrors('motivo');
        $admin->postJson("/api/admin/empresas/{$tenant->id}/situacao", ['situacao' => 'SUSPENSA', 'motivo' => 'Teste'])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'TRANSICAO_INVALIDA');
        $admin->postJson("/api/admin/empresas/{$tenant->id}/situacao", ['situacao' => 'ATIVA', 'motivo' => 'Cliente validado'])
            ->assertOk()
            ->assertJsonPath('data.situacao', 'ATIVA');

        $this->assertSame(1, Activity::query()->where('event', 'situacao_alterada')->count());
    }

    public function test_troca_de_plano_com_excesso_e_recusada_e_nao_troca(): void
    {
        $atual = Plano::factory()->comLimites([Recurso::Usuarios->value => 5])->create();
        $menor = Plano::factory()->comLimites([Recurso::Usuarios->value => 1])->create();
        $tenant = Tenant::factory()->for($atual)->create();
        Usuario::factory()->count(3)->for($tenant)->create();

        $this->spa()->actingAs($this->admin)->postJson("/api/admin/empresas/{$tenant->id}/plano", ['plano_id' => $menor->id])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'PLANO_EXCEDIDO')
            ->assertJsonPath('excessos', [['recurso' => 'USUARIOS', 'uso' => 3, 'limite' => 1]]);

        $this->assertSame($atual->id, $tenant->refresh()->plano_id);
    }

    public function test_troca_de_plano_sem_excesso_e_auditada(): void
    {
        $maior = Plano::factory()->comLimites([Recurso::Usuarios->value => -1])->create(['nome' => 'Maior']);
        $tenant = Tenant::factory()->create();

        $this->spa()->actingAs($this->admin)->postJson("/api/admin/empresas/{$tenant->id}/plano", ['plano_id' => $maior->id])
            ->assertOk()
            ->assertJsonPath('data.plano.nome', 'Maior');

        $this->assertSame(1, Activity::query()->where('event', 'plano_trocado')->count());
    }

    public function test_troca_para_plano_desativado_e_recusada(): void
    {
        $inativo = Plano::factory()->create(['ativo' => false]);
        $tenant = Tenant::factory()->create();

        $this->spa()->actingAs($this->admin)->postJson("/api/admin/empresas/{$tenant->id}/plano", ['plano_id' => $inativo->id])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'PLANO_INDISPONIVEL');
    }

    public function test_metricas(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        $caro = Plano::factory()->create(['preco_mensal_centavos' => 9900]);
        $barato = Plano::factory()->create(['preco_mensal_centavos' => 5000]);
        Tenant::factory()->for($caro)->create();
        Tenant::factory()->for($barato)->situacao(S::Inadimplente)->create();
        Tenant::factory()->for($caro)->situacao(S::Suspensa)->create(['created_at' => now()->subDays(40)]);

        $this->spa()->actingAs($this->admin)->getJson('/api/admin/metricas')
            ->assertOk()
            ->assertJsonPath('data.por_situacao.ATIVA', 1)
            ->assertJsonPath('data.por_situacao.INADIMPLENTE', 1)
            ->assertJsonPath('data.por_situacao.SUSPENSA', 1)
            ->assertJsonPath('data.por_situacao.PENDENTE', 0)
            ->assertJsonPath('data.novas_30_dias', 2)
            ->assertJsonPath('data.mrr_centavos', 14900);
    }

    public function test_suporte_nao_muda_situacao(): void
    {
        $suporte = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Suporte]);
        $tenant = Tenant::factory()->create();

        $this->spa()->actingAs($suporte)->getJson("/api/admin/empresas/{$tenant->id}")->assertOk();
        $this->spa()->actingAs($suporte)
            ->postJson("/api/admin/empresas/{$tenant->id}/situacao", ['situacao' => 'SUSPENSA', 'motivo' => 'x'])
            ->assertForbidden();
    }
}
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `php artisan test --filter=AdminEmpresasTest`
Esperado: FALHA (404).

- [ ] **Passo 3: implementar**

`backend/app/Modules/Platform/Domain/Exceptions/PlanoInsuficienteException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class PlanoInsuficienteException extends ErroDeNegocio
{
    /** @param list<array{recurso: string, uso: int, limite: int}> $excessos */
    public function __construct(private readonly array $excessos)
    {
        parent::__construct('O uso atual da empresa passa os limites do plano escolhido.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'PLANO_EXCEDIDO';
    }

    public function extras(): array
    {
        return ['excessos' => $this->excessos];
    }
}
```

`backend/app/Modules/Platform/Domain/Exceptions/PlanoIndisponivelException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class PlanoIndisponivelException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Este plano está desativado.');
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'PLANO_INDISPONIVEL';
    }
}
```

`backend/app/Modules/Platform/Application/Actions/TrocarPlanoDaEmpresa.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Exceptions\PlanoIndisponivelException;
use App\Modules\Platform\Domain\Exceptions\PlanoInsuficienteException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class TrocarPlanoDaEmpresa
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function executar(Tenant $tenant, Plano $novo, Usuario $autor): Tenant
    {
        if (! $novo->ativo) {
            throw new PlanoIndisponivelException;
        }

        $excessos = $this->entitlements->excessos($tenant, $novo);
        if ($excessos !== []) {
            throw new PlanoInsuficienteException($excessos);
        }

        return DB::transaction(function () use ($tenant, $novo, $autor): Tenant {
            $anterior = $tenant->plano_id;
            $tenant->forceFill(['plano_id' => $novo->id])->save();

            activity('assinatura')
                ->performedOn($tenant)
                ->causedBy($autor)
                ->event('plano_trocado')
                ->withProperties(['de' => $anterior, 'para' => $novo->id])
                ->log('Plano da empresa trocado');

            return $tenant->load('plano');
        });
    }
}
```

`backend/app/Modules/Platform/Http/Requests/MudarSituacaoRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MudarSituacaoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'situacao' => ['required', Rule::enum(SituacaoAssinatura::class)],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function situacao(): SituacaoAssinatura
    {
        return SituacaoAssinatura::from($this->string('situacao')->toString());
    }
}
```

`backend/app/Modules/Platform/Http/Requests/TrocarPlanoRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TrocarPlanoRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['plano_id' => ['required', 'uuid', 'exists:planos,id']];
    }
}
```

`backend/app/Modules/Platform/Http/Resources/EmpresaResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
final class EmpresaResource extends JsonResource
{
    /** @param list<array{recurso: string, uso: int, limite: int}>|null $consumo */
    public function __construct(Tenant $resource, private readonly ?array $consumo = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cnpj' => $this->cnpj,
            'razao_social' => $this->razao_social,
            'nome_fantasia' => $this->nome_fantasia,
            'situacao' => $this->situacao->value,
            'teste_termina_em' => $this->teste_termina_em?->toDateString(),
            'situacao_alterada_em' => $this->situacao_alterada_em?->toIso8601String(),
            'plano' => $this->plano === null ? null : [
                'id' => $this->plano->id,
                'nome' => $this->plano->nome,
                'preco_mensal_centavos' => $this->plano->preco_mensal_centavos,
            ],
            'criada_em' => $this->created_at->toIso8601String(),
            'consumo' => $this->when($this->consumo !== null, $this->consumo),
        ];
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/Admin/EmpresasController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Http\Resources\EmpresaResource;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class EmpresasController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'situacao' => ['nullable', Rule::enum(SituacaoAssinatura::class)],
            'plano_id' => ['nullable', 'uuid'],
            'busca' => ['nullable', 'string', 'max:100'],
        ]);

        $empresas = Tenant::query()
            ->with('plano')
            ->when($filtros['situacao'] ?? null, fn (Builder $q, string $s) => $q->where('situacao', $s))
            ->when($filtros['plano_id'] ?? null, fn (Builder $q, string $p) => $q->where('plano_id', $p))
            ->when($filtros['busca'] ?? null, function (Builder $q, string $busca): void {
                $termo = '%'.mb_strtolower($busca).'%';
                $cnpj = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $busca));
                // lower() + like: o mesmo comportamento no SQLite e no PostgreSQL.
                $q->where(function (Builder $w) use ($termo, $cnpj): void {
                    $w->whereRaw('lower(razao_social) like ?', [$termo])
                        ->orWhereRaw('lower(nome_fantasia) like ?', [$termo]);
                    if ($cnpj !== '') {
                        $w->orWhere('cnpj', 'like', $cnpj.'%');
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(20);

        return EmpresaResource::collection($empresas);
    }

    public function show(Tenant $tenant, EntitlementService $entitlements): EmpresaResource
    {
        $tenant->load(['plano.modulos', 'plano.limites']);

        return new EmpresaResource($tenant, $entitlements->consumo($tenant));
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/Admin/MudarSituacaoController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\MudarSituacaoDaEmpresa;
use App\Modules\Platform\Http\Requests\MudarSituacaoRequest;
use App\Modules\Platform\Http\Resources\EmpresaResource;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class MudarSituacaoController
{
    public function __invoke(MudarSituacaoRequest $request, Tenant $tenant, MudarSituacaoDaEmpresa $mudar): EmpresaResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $mudar->executar($tenant, $request->situacao(), $request->string('motivo')->toString(), $autor);

        return new EmpresaResource($tenant->load('plano'));
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/Admin/TrocarPlanoController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\TrocarPlanoDaEmpresa;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Http\Requests\TrocarPlanoRequest;
use App\Modules\Platform\Http\Resources\EmpresaResource;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class TrocarPlanoController
{
    public function __invoke(TrocarPlanoRequest $request, Tenant $tenant, TrocarPlanoDaEmpresa $trocar): EmpresaResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $novo = Plano::query()->with('limites')->findOrFail($request->string('plano_id')->toString());

        return new EmpresaResource($trocar->executar($tenant, $novo, $autor));
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/Admin/MetricasController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Http\JsonResponse;

final class MetricasController
{
    public function __invoke(): JsonResponse
    {
        $contagem = Tenant::query()->toBase()
            ->selectRaw('situacao, count(*) as total')
            ->groupBy('situacao')
            ->pluck('total', 'situacao');

        $porSituacao = [];
        foreach (SituacaoAssinatura::cases() as $situacao) {
            $porSituacao[$situacao->value] = (int) ($contagem[$situacao->value] ?? 0);
        }

        // MRR estimado: soma do preço mensal das empresas que estão pagando (ou deveriam).
        $mrr = (int) Tenant::query()->toBase()
            ->join('planos', 'planos.id', '=', 'tenants.plano_id')
            ->whereIn('tenants.situacao', [SituacaoAssinatura::Ativa->value, SituacaoAssinatura::Inadimplente->value])
            ->sum('planos.preco_mensal_centavos');

        return response()->json(['data' => [
            'por_situacao' => $porSituacao,
            'novas_30_dias' => Tenant::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'mrr_centavos' => $mrr,
        ]]);
    }
}
```

`routes-admin.php`: dentro do grupo, acrescentar
```php
    Route::get('empresas', [EmpresasController::class, 'index']);
    Route::get('empresas/{tenant}', [EmpresasController::class, 'show']);
    Route::post('empresas/{tenant}/situacao', MudarSituacaoController::class);
    Route::post('empresas/{tenant}/plano', TrocarPlanoController::class);
    Route::get('metricas', MetricasController::class);
```
Acrescentar também os `use` dos quatro controllers.

- [ ] **Passo 4: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam.

- [ ] **Passo 5: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "feat: admin de empresas (lista, detalhe, situação, troca de plano) e métricas"
```

---

### Tarefa 9: Cadastro público (API)

**Arquivos:**
- Criar:
  - `backend/app/Modules/Platform/Infrastructure/ConsultaCnpj.php`
  - `backend/app/Modules/Platform/Domain/Exceptions/ConsultaCnpjIndisponivelException.php`
  - `backend/app/Modules/Platform/Application/Actions/CadastrarEmpresa.php`
  - `backend/app/Modules/Platform/Http/Requests/CadastroRequest.php`
  - `backend/app/Modules/Platform/Http/Controllers/Publico/{PlanosPublicosController,ConsultarCnpjController,CadastroController}.php`
  - `backend/app/Modules/Platform/Http/routes-publico.php`
- Modificar:
  - `backend/config/services.php` (`brasilapi.url`)
  - `backend/.env.example` (`BRASILAPI_URL`)
  - `PlatformServiceProvider.php`
- Teste: `backend/tests/Feature/Platform/CadastroPublicoTest.php`

**Interfaces:**
- Consome: `Cnpj` e `CnpjValido` (T2), `PlanoResource` (T6) e `UsuarioResource` (T3).
- Produz:
  - `GET /api/publico/planos` → `{data: PlanoResource[]}` (só `ativo && visivel`, ordenados por `ordem`).
  - `GET /api/publico/cnpj/{cnpj}`:
    - Encontrado: `{data: {razao_social, nome_fantasia}}`.
    - Não encontrado: `404`.
    - BrasilAPI fora: `503` com o código `CONSULTA_INDISPONIVEL`.
    - CNPJ inválido: `422`.
  - `POST /api/publico/cadastro` → `201` `{data: Usuario}`, já com a sessão aberta.
  - Constantes `CadastrarEmpresa::CNPJ_DUPLICADO` e `CadastrarEmpresa::EMAIL_DUPLICADO`.

- [ ] **Passo 1: teste que falha**

`backend/tests/Feature/Platform/CadastroPublicoTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\CadastrarEmpresa;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CadastroPublicoTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function dados(Plano $plano, array $extra = []): array
    {
        return array_merge([
            'cnpj' => '11.222.333/0001-81',
            'razao_social' => 'Padaria Pão Bom Ltda',
            'nome_fantasia' => 'Pão Bom',
            'plano_id' => $plano->id,
            'responsavel_nome' => 'Ana Souza',
            'email' => 'Ana@PaoBom.com',
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
            'aceite_termos' => true,
        ], $extra);
    }

    public function test_planos_publicos_so_ativos_e_visiveis_em_ordem(): void
    {
        Plano::factory()->create(['nome' => 'B', 'ordem' => 2]);
        Plano::factory()->create(['nome' => 'A', 'ordem' => 1]);
        Plano::factory()->create(['nome' => 'Oculto', 'visivel' => false]);
        Plano::factory()->create(['nome' => 'Inativo', 'ativo' => false]);

        $this->getJson('/api/publico/planos')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nome', 'A')
            ->assertJsonPath('data.1.nome', 'B');
    }

    public function test_consulta_cnpj_encontrado_com_cache(): void
    {
        Http::fake(['*/api/cnpj/v1/11222333000181' => Http::response(['razao_social' => 'PADARIA PAO BOM LTDA', 'nome_fantasia' => ''])]);

        $this->getJson('/api/publico/cnpj/11222333000181')
            ->assertOk()
            ->assertExactJson(['data' => ['razao_social' => 'PADARIA PAO BOM LTDA', 'nome_fantasia' => null]]);
        $this->getJson('/api/publico/cnpj/11222333000181')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_consulta_cnpj_nao_encontrado(): void
    {
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

        $this->getJson('/api/publico/cnpj/11222333000181')->assertNotFound();
    }

    public function test_consulta_cnpj_com_brasilapi_fora_responde_503(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->getJson('/api/publico/cnpj/11222333000181')
            ->assertStatus(503)
            ->assertJsonPath('codigo', 'CONSULTA_INDISPONIVEL');
    }

    public function test_consulta_cnpj_invalido_nem_chama_a_brasilapi(): void
    {
        Http::fake();

        $this->getJson('/api/publico/cnpj/11222333000182')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_cadastro_com_teste_nasce_em_teste_com_a_data_certa(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $plano = Plano::factory()->create(['dias_teste' => 14]);

        $resposta = $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano))
            ->assertCreated()
            ->assertJsonPath('data.email', 'ana@paobom.com')
            ->assertJsonPath('data.papel', 'PROPRIETARIO')
            ->assertJsonPath('data.tenant.situacao', 'TESTE')
            ->assertJsonPath('data.tenant.teste_termina_em', '2026-10-15')
            ->assertJsonPath('data.tenant.cnpj', '11222333000181');

        $usuario = Usuario::query()->findOrFail($resposta->json('data.id'));
        $this->assertSame(Papel::Proprietario, $usuario->papel);
        $this->assertAuthenticatedAs($usuario, 'web');
    }

    public function test_cadastro_sem_teste_nasce_pendente(): void
    {
        $plano = Plano::factory()->create(['dias_teste' => 0]);

        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano))
            ->assertCreated()
            ->assertJsonPath('data.tenant.situacao', 'PENDENTE')
            ->assertJsonPath('data.tenant.teste_termina_em', null);
    }

    public function test_cnpj_alfanumerico_e_normalizado_e_duplicado_detectado_com_outra_mascara(): void
    {
        $plano = Plano::factory()->create();

        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['cnpj' => '12.abc.345/01de-35']))
            ->assertCreated()
            ->assertJsonPath('data.tenant.cnpj', '12ABC34501DE35');

        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['cnpj' => '12ABC34501DE35', 'email' => 'outro@x.com']))
            ->assertStatus(422)
            ->assertJsonPath('errors.cnpj.0', CadastrarEmpresa::CNPJ_DUPLICADO);
    }

    public function test_validacoes_do_cadastro(): void
    {
        $plano = Plano::factory()->create();
        $oculto = Plano::factory()->create(['visivel' => false]);
        Usuario::factory()->for(Tenant::factory())->create(['email' => 'ja@existe.com']);

        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['email' => 'JA@existe.com']))
            ->assertJsonPath('errors.email.0', CadastrarEmpresa::EMAIL_DUPLICADO);
        $this->spa()->postJson('/api/publico/cadastro', $this->dados($oculto))
            ->assertJsonPath('errors.plano_id.0', 'Este plano não está disponível.');
        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['aceite_termos' => false]))
            ->assertJsonValidationErrors('aceite_termos');
        $this->spa()->postJson('/api/publico/cadastro', $this->dados($plano, ['cnpj' => '11222333000182']))
            ->assertJsonPath('errors.cnpj.0', 'Informe um CNPJ válido.');

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_violacao_de_unicidade_vira_422(): void
    {
        // Simula a corrida: a validação passou, mas outro cadastro gravou o mesmo CNPJ antes.
        $plano = Plano::factory()->create();
        Tenant::factory()->create(['cnpj' => '11222333000181']);

        try {
            app(CadastrarEmpresa::class)->executar([
                'cnpj' => '11222333000181', 'razao_social' => 'X', 'nome_fantasia' => null, 'plano_id' => $plano->id,
                'responsavel_nome' => 'Ana', 'email' => 'ana@x.com', 'password' => 'Senha123',
            ]);
            $this->fail('Deveria lançar.');
        } catch (ValidationException $e) {
            $this->assertSame([CadastrarEmpresa::CNPJ_DUPLICADO], $e->errors()['cnpj']);
        }

        $this->assertSame(0, Usuario::query()->count());
        $this->assertSame(SituacaoAssinatura::Ativa, Tenant::query()->firstOrFail()->situacao);
    }
}
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `php artisan test --filter=CadastroPublicoTest`
Esperado: FALHA (404 nas rotas e classe `CadastrarEmpresa` inexistente).

- [ ] **Passo 3: implementar**

`config/services.php`: acrescentar a chave
```php
    'brasilapi' => [
        'url' => env('BRASILAPI_URL', 'https://brasilapi.com.br'),
    ],
```
`.env.example`: acrescentar `BRASILAPI_URL=https://brasilapi.com.br`.

`backend/app/Modules/Platform/Domain/Exceptions/ConsultaCnpjIndisponivelException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class ConsultaCnpjIndisponivelException extends ErroDeNegocio
{
    public function __construct()
    {
        parent::__construct('Não foi possível consultar o CNPJ agora. Preencha os dados manualmente.');
    }

    public function status(): int
    {
        return 503;
    }

    public function codigo(): string
    {
        return 'CONSULTA_INDISPONIVEL';
    }
}
```

`backend/app/Modules/Platform/Infrastructure/ConsultaCnpj.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Platform\Domain\Exceptions\ConsultaCnpjIndisponivelException;
use App\Modules\Shared\Domain\Cnpj;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Conveniência de preenchimento, nunca validação obrigatória: a BrasilAPI é
 * comunitária e sem SLA. Só respostas encontradas vão para o cache.
 */
final class ConsultaCnpj
{
    private const TIMEOUT_SEGUNDOS = 5;

    private const CACHE_SEGUNDOS = 86400;

    /** @return array{razao_social: string, nome_fantasia: ?string}|null */
    public function buscar(Cnpj $cnpj): ?array
    {
        $chave = 'brasilapi:cnpj:'.$cnpj->valor;
        /** @var array{razao_social: string, nome_fantasia: ?string}|null $emCache */
        $emCache = Cache::get($chave);
        if ($emCache !== null) {
            return $emCache;
        }

        try {
            $resposta = Http::baseUrl((string) config('services.brasilapi.url'))
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->acceptJson()
                ->get('/api/cnpj/v1/'.$cnpj->valor);
        } catch (ConnectionException) {
            throw new ConsultaCnpjIndisponivelException;
        }

        if ($resposta->status() === 404) {
            return null;
        }
        if (! $resposta->successful()) {
            throw new ConsultaCnpjIndisponivelException;
        }

        $fantasia = trim((string) $resposta->json('nome_fantasia'));
        $dados = [
            'razao_social' => trim((string) $resposta->json('razao_social')),
            'nome_fantasia' => $fantasia === '' ? null : $fantasia,
        ];
        Cache::put($chave, $dados, self::CACHE_SEGUNDOS);

        return $dados;
    }
}
```

`backend/app/Modules/Platform/Application/Actions/CadastrarEmpresa.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Autoatendimento: plano com teste → TESTE; sem teste → PENDENTE (spec F1 §1). */
final class CadastrarEmpresa
{
    public const CNPJ_DUPLICADO = 'Este CNPJ já possui conta. Entre ou recupere a senha.';

    public const EMAIL_DUPLICADO = 'Este e-mail já está em uso.';

    /**
     * @param array{cnpj: string, razao_social: string, nome_fantasia: ?string, plano_id: string,
     *     responsavel_nome: string, email: string, password: string} $dados
     */
    public function executar(array $dados): Usuario
    {
        $plano = Plano::query()->whereKey($dados['plano_id'])->where('ativo', true)->where('visivel', true)->firstOrFail();
        $cnpj = Cnpj::de($dados['cnpj']);

        try {
            return DB::transaction(function () use ($dados, $plano, $cnpj): Usuario {
                $emTeste = $plano->dias_teste > 0;

                $tenant = new Tenant([
                    'razao_social' => $dados['razao_social'],
                    'nome_fantasia' => $dados['nome_fantasia'],
                    'cnpj' => $cnpj->valor,
                ]);
                $tenant->forceFill([
                    'plano_id' => $plano->id,
                    'situacao' => $emTeste ? SituacaoAssinatura::Teste : SituacaoAssinatura::Pendente,
                    'teste_termina_em' => $emTeste
                        ? today((string) config('app.fuso_negocio'))->addDays($plano->dias_teste)->toDateString()
                        : null,
                    'situacao_alterada_em' => now(),
                ])->save();

                $usuario = new Usuario([
                    'nome' => $dados['responsavel_nome'],
                    'email' => $dados['email'],
                    'password' => $dados['password'],
                ]);
                $usuario->forceFill(['tenant_id' => $tenant->id, 'papel' => Papel::Proprietario, 'ativo' => true])->save();

                activity('assinatura')
                    ->performedOn($tenant)
                    ->causedBy($usuario)
                    ->event('empresa_cadastrada')
                    ->withProperties(['plano' => $plano->nome, 'situacao' => $tenant->situacao->value])
                    ->log('Empresa cadastrada');

                return $usuario;
            });
        } catch (UniqueConstraintViolationException) {
            // Corrida entre dois cadastros: a validação passou nos dois.
            $campo = Tenant::query()->where('cnpj', $cnpj->valor)->exists() ? 'cnpj' : 'email';
            throw ValidationException::withMessages([
                $campo => $campo === 'cnpj' ? self::CNPJ_DUPLICADO : self::EMAIL_DUPLICADO,
            ]);
        }
    }
}
```

`backend/app/Modules/Platform/Http/Requests/CadastroRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Application\Actions\CadastrarEmpresa;
use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Http\Rules\CnpjValido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CadastroRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $cnpj = Cnpj::tentar((string) $this->input('cnpj'));
        $fantasia = trim((string) $this->input('nome_fantasia'));

        $this->merge([
            // Normalizado antes do unique: "12.abc..." e "12ABC..." são o mesmo CNPJ.
            'cnpj' => $cnpj !== null ? $cnpj->valor : $this->input('cnpj'),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'nome_fantasia' => $fantasia === '' ? null : $fantasia,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'cnpj' => ['required', 'string', new CnpjValido, Rule::unique('tenants', 'cnpj')],
            'razao_social' => ['required', 'string', 'max:150'],
            'nome_fantasia' => ['nullable', 'string', 'max:150'],
            'plano_id' => ['required', 'uuid', Rule::exists('planos', 'id')->where('ativo', true)->where('visivel', true)],
            'responsavel_nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'aceite_termos' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cnpj.unique' => CadastrarEmpresa::CNPJ_DUPLICADO,
            'email.unique' => CadastrarEmpresa::EMAIL_DUPLICADO,
            'plano_id.exists' => 'Este plano não está disponível.',
            'aceite_termos.accepted' => 'Você precisa aceitar os termos de uso.',
        ];
    }

    /**
     * @return array{cnpj: string, razao_social: string, nome_fantasia: ?string, plano_id: string,
     *     responsavel_nome: string, email: string, password: string}
     */
    public function dados(): array
    {
        return [
            'cnpj' => $this->string('cnpj')->toString(),
            'razao_social' => trim($this->string('razao_social')->toString()),
            'nome_fantasia' => $this->input('nome_fantasia'),
            'plano_id' => $this->string('plano_id')->toString(),
            'responsavel_nome' => trim($this->string('responsavel_nome')->toString()),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/Publico/PlanosPublicosController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Publico;

use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Http\Resources\PlanoResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlanosPublicosController
{
    public function __invoke(): AnonymousResourceCollection
    {
        return PlanoResource::collection(
            Plano::query()->with(['modulos', 'limites'])
                ->where('ativo', true)->where('visivel', true)
                ->orderBy('ordem')->orderBy('nome')
                ->get(),
        );
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/Publico/ConsultarCnpjController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Publico;

use App\Modules\Platform\Infrastructure\ConsultaCnpj;
use App\Modules\Shared\Domain\Cnpj;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class ConsultarCnpjController
{
    public function __invoke(string $cnpj, ConsultaCnpj $consulta): JsonResponse
    {
        $valido = Cnpj::tentar($cnpj) ?? throw ValidationException::withMessages(['cnpj' => 'Informe um CNPJ válido.']);

        $dados = $consulta->buscar($valido);
        if ($dados === null) {
            return response()->json(['message' => 'CNPJ não encontrado. Preencha os dados manualmente.'], 404);
        }

        return response()->json(['data' => $dados]);
    }
}
```

`backend/app/Modules/Platform/Http/Controllers/Publico/CadastroController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Publico;

use App\Modules\Identity\Http\Resources\UsuarioResource;
use App\Modules\Platform\Application\Actions\CadastrarEmpresa;
use App\Modules\Platform\Http\Requests\CadastroRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class CadastroController
{
    public function __invoke(CadastroRequest $request, CadastrarEmpresa $cadastrar): JsonResponse
    {
        $usuario = $cadastrar->executar($request->dados());

        Auth::guard('web')->login($usuario);
        $request->session()->regenerate();

        return (new UsuarioResource($usuario->load('tenant')))->response()->setStatusCode(201);
    }
}
```

`backend/app/Modules/Platform/Http/routes-publico.php`:
```php
<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Publico\CadastroController;
use App\Modules\Platform\Http\Controllers\Publico\ConsultarCnpjController;
use App\Modules\Platform\Http\Controllers\Publico\PlanosPublicosController;
use Illuminate\Support\Facades\Route;

Route::get('planos', PlanosPublicosController::class);
Route::get('cnpj/{cnpj}', ConsultarCnpjController::class)->middleware('throttle:10,1');
Route::post('cadastro', CadastroController::class)->middleware('throttle:5,60');
```

`PlatformServiceProvider::boot()`: acrescentar
```php
        Route::middleware('api')->prefix('api/publico')->group(__DIR__.'/../Http/routes-publico.php');
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam.

Se `Http::failedConnection()` não chegar como `ConnectionException` ao `ConsultaCnpj`, registre um `Ruling` e capture também `Illuminate\Http\Client\RequestException`.

- [ ] **Passo 5: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "feat: cadastro público com teste grátis e consulta de CNPJ na BrasilAPI"
```

---

### Tarefa 10: Usuários da empresa e convite (API)

**Arquivos:**
- Criar:
  - `backend/database/migrations/2026_09_27_000003_create_convite_tokens_table.php`
  - `backend/app/Modules/Identity/Application/PoliticaDeUsuarios.php`
  - `backend/app/Modules/Identity/Application/Actions/{ConvidarUsuario,EditarUsuario,DesativarUsuario,ReativarUsuario}.php`
  - `backend/app/Modules/Identity/Notifications/ConviteDeUsuario.php`
  - `backend/app/Modules/Identity/Http/Requests/{ConvidarUsuarioRequest,EditarUsuarioRequest}.php`
  - `backend/app/Modules/Identity/Http/Resources/UsuarioDaEmpresaResource.php`
  - `backend/app/Modules/Identity/Http/Controllers/{UsuariosController,DesativarUsuarioController,ReativarUsuarioController,AceitarConviteController}.php`
- Modificar:
  - `backend/config/auth.php` (broker `convites`)
  - `Papel.php` (`daEmpresa()`, `valoresDaEmpresa()`)
  - `Usuario.php` (`scopeDaEmpresa`)
  - `Identity/Http/routes.php`
- Teste: `backend/tests/Feature/Identity/UsuariosDaEmpresaTest.php`

**Interfaces:**
- Consome: `EntitlementService::garantirCapacidade` (T5), o grupo `empresa` (T6) e `AcessoNegadoException` (T2).
- Produz:
  - Endpoints:
    - `GET /api/app/usuarios`.
    - `POST /api/app/usuarios` `{nome, email, papel}` → `201`.
    - `PUT /api/app/usuarios/{id}` `{nome, papel}`.
    - `POST /api/app/usuarios/{id}/desativar`.
    - `POST /api/app/usuarios/{id}/reativar`.
    - `POST /api/app/auth/aceitar-convite` `{token, email, password, password_confirmation}`.
  - `UsuarioDaEmpresaResource`: `{id, nome, email, papel, ativo}`.
  - O link do convite é `FRONTEND_URL/definir-senha?token=...&email=...` e vale 72 h.

- [ ] **Passo 1: teste que falha**

`backend/tests/Feature/Identity/UsuariosDaEmpresaTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Notifications\ConviteDeUsuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UsuariosDaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $dono;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Usuarios->value => 3]))->create();
        $this->dono = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Proprietario]);
    }

    private function membro(Papel $papel = Papel::Vendedor, bool $ativo = true): Usuario
    {
        return Usuario::factory()->for($this->tenant)->create(['papel' => $papel, 'ativo' => $ativo]);
    }

    public function test_lista_so_usuarios_da_propria_empresa(): void
    {
        $this->membro();
        Usuario::factory()->for(Tenant::factory())->create();

        $this->spa()->actingAs($this->dono)->getJson('/api/app/usuarios')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_vendedor_nao_gerencia_usuarios(): void
    {
        $this->spa()->actingAs($this->membro())->getJson('/api/app/usuarios')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_convite_cria_usuario_e_envia_link_de_72_horas(): void
    {
        $resposta = $this->spa()->actingAs($this->dono)
            ->postJson('/api/app/usuarios', ['nome' => 'Bia', 'email' => 'Bia@X.com', 'papel' => 'FISCAL'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'bia@x.com')
            ->assertJsonPath('data.papel', 'FISCAL');

        $convidado = Usuario::query()->findOrFail($resposta->json('data.id'));
        $this->assertSame($this->tenant->id, $convidado->tenant_id);

        $token = null;
        Notification::assertSentTo($convidado, ConviteDeUsuario::class, function (ConviteDeUsuario $n) use (&$token): bool {
            $token = $n->token;

            return true;
        });
        $this->assertIsString($token);

        $this->travel(71)->hours();
        $this->spa()->postJson('/api/app/auth/aceitar-convite', [
            'token' => $token, 'email' => 'bia@x.com', 'password' => 'Senha123', 'password_confirmation' => 'Senha123',
        ])->assertOk();

        $this->spa()->postJson('/api/app/auth/login', ['email' => 'bia@x.com', 'password' => 'Senha123'])->assertOk();
    }

    public function test_convite_expira_depois_de_72_horas(): void
    {
        $this->spa()->actingAs($this->dono)
            ->postJson('/api/app/usuarios', ['nome' => 'Bia', 'email' => 'bia@x.com', 'papel' => 'FISCAL'])
            ->assertCreated();
        $token = null;
        Notification::assertSentTo(Usuario::query()->where('email', 'bia@x.com')->firstOrFail(), ConviteDeUsuario::class,
            function (ConviteDeUsuario $n) use (&$token): bool {
                $token = $n->token;

                return true;
            });

        $this->travel(73)->hours();
        $this->spa()->postJson('/api/app/auth/aceitar-convite', [
            'token' => $token, 'email' => 'bia@x.com', 'password' => 'Senha123', 'password_confirmation' => 'Senha123',
        ])->assertStatus(422);
    }

    public function test_convite_respeita_o_limite_do_plano(): void
    {
        $this->membro();
        $this->membro();
        $this->membro(ativo: false); // inativo não conta

        $this->spa()->actingAs($this->dono)
            ->postJson('/api/app/usuarios', ['nome' => 'Bia', 'email' => 'bia@x.com', 'papel' => 'FISCAL'])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'LIMITE_DO_PLANO')
            ->assertJsonPath('message', 'Seu plano permite até 3 usuários ativos.');

        $this->assertDatabaseMissing('usuarios', ['email' => 'bia@x.com']);
    }

    public function test_reativar_respeita_o_limite_do_plano(): void
    {
        $this->membro();
        $this->membro();
        $inativo = $this->membro(ativo: false);

        $this->spa()->actingAs($this->dono)->postJson("/api/app/usuarios/{$inativo->id}/reativar")
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'LIMITE_DO_PLANO');
    }

    public function test_desativar_e_reativar(): void
    {
        $membro = $this->membro();

        $this->spa()->actingAs($this->dono)->postJson("/api/app/usuarios/{$membro->id}/desativar")
            ->assertOk()
            ->assertJsonPath('data.ativo', false);
        $this->spa()->actingAs($this->dono)->postJson("/api/app/usuarios/{$membro->id}/reativar")
            ->assertOk()
            ->assertJsonPath('data.ativo', true);

        $this->assertSame(2, Activity::query()->whereIn('event', ['usuario_desativado', 'usuario_reativado'])->count());
    }

    public function test_admin_nao_concede_papel_de_proprietario_mas_o_proprietario_pode(): void
    {
        $admin = $this->membro(Papel::Admin);
        $membro = $this->membro();

        $this->spa()->actingAs($admin)->putJson("/api/app/usuarios/{$membro->id}", ['nome' => 'X', 'papel' => 'PROPRIETARIO'])
            ->assertForbidden();
        $this->spa()->actingAs($this->dono)->putJson("/api/app/usuarios/{$membro->id}", ['nome' => 'X', 'papel' => 'PROPRIETARIO'])
            ->assertOk()
            ->assertJsonPath('data.papel', 'PROPRIETARIO');
    }

    public function test_ninguem_altera_o_proprietario_nem_desativa_a_si_mesmo(): void
    {
        $admin = $this->membro(Papel::Admin);

        $this->spa()->actingAs($admin)->putJson("/api/app/usuarios/{$this->dono->id}", ['nome' => 'X', 'papel' => 'LEITURA'])
            ->assertForbidden();
        $this->spa()->actingAs($admin)->postJson("/api/app/usuarios/{$this->dono->id}/desativar")->assertForbidden();
        $this->spa()->actingAs($admin)->postJson("/api/app/usuarios/{$admin->id}/desativar")->assertForbidden();
    }

    public function test_papel_da_plataforma_e_recusado(): void
    {
        $this->spa()->actingAs($this->dono)
            ->postJson('/api/app/usuarios', ['nome' => 'Bia', 'email' => 'bia@x.com', 'papel' => 'SUPERADMIN'])
            ->assertJsonValidationErrors('papel');
    }

    public function test_edicao_de_papel_e_auditada(): void
    {
        $membro = $this->membro();

        $this->spa()->actingAs($this->dono)->putJson("/api/app/usuarios/{$membro->id}", ['nome' => 'Novo Nome', 'papel' => 'ADMIN'])
            ->assertOk()
            ->assertJsonPath('data.nome', 'Novo Nome');

        $registro = Activity::query()->where('event', 'usuario_editado')->firstOrFail();
        $this->assertSame('VENDEDOR', $registro->getExtraProperty('papel_de'));
        $this->assertSame('ADMIN', $registro->getExtraProperty('papel_para'));
    }

    public function test_usuario_de_outra_empresa_responde_404(): void
    {
        $alheio = Usuario::factory()->for(Tenant::factory())->create(['papel' => Papel::Vendedor]);
        $comoDono = $this->spa()->actingAs($this->dono);

        $comoDono->putJson("/api/app/usuarios/{$alheio->id}", ['nome' => 'X', 'papel' => 'LEITURA'])->assertNotFound();
        $comoDono->postJson("/api/app/usuarios/{$alheio->id}/desativar")->assertNotFound();
        $comoDono->postJson("/api/app/usuarios/{$alheio->id}/reativar")->assertNotFound();
        $this->assertTrue($alheio->refresh()->ativo);
    }
}
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `php artisan test --filter=UsuariosDaEmpresaTest`
Esperado: FALHA (404 nas rotas e classe `ConviteDeUsuario` inexistente).

- [ ] **Passo 3: implementar**

`backend/database/migrations/2026_09_27_000003_create_convite_tokens_table.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tabela separada: um "esqueci a senha" não pode invalidar o convite, e vice-versa. */
    public function up(): void
    {
        Schema::create('convite_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convite_tokens');
    }
};
```

`config/auth.php`: em `passwords`, acrescentar
```php
        'convites' => [
            'provider' => 'users',
            'table' => 'convite_tokens',
            'expire' => 72 * 60,
            'throttle' => 0,
        ],
```
Confira o nome do provider que aponta para `Usuario` (na F0, `users`).

`Papel.php`: acrescentar
```php
    /** @return list<self> */
    public static function daEmpresa(): array
    {
        return [self::Proprietario, self::Admin, self::Fiscal, self::Vendedor, self::Leitura];
    }

    /** @return list<string> */
    public static function valoresDaEmpresa(): array
    {
        return array_map(fn (self $p): string => $p->value, self::daEmpresa());
    }
```

`Usuario.php`: acrescentar (com `use Illuminate\Database\Eloquent\Builder;`)
```php
    /**
     * Usuario não tem TenantScope (ver ADR 0002): toda busca de usuário da empresa passa por aqui.
     *
     * @param  Builder<Usuario>  $query
     */
    public function scopeDaEmpresa(Builder $query, string $tenantId): void
    {
        $query->where('tenant_id', $tenantId);
    }
```

`backend/app/Modules/Identity/Application/PoliticaDeUsuarios.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;

/** Quem pode gerenciar quem, dentro da empresa (spec F1 §9). */
final class PoliticaDeUsuarios
{
    public function garantirPodeGerenciar(Usuario $autor): void
    {
        if (! in_array($autor->papel, [Papel::Proprietario, Papel::Admin], true)) {
            throw new AcessoNegadoException('Somente o proprietário ou um administrador podem gerenciar usuários.');
        }
    }

    public function garantirPodeAtribuir(Usuario $autor, Papel $papel): void
    {
        $this->garantirPodeGerenciar($autor);

        if ($papel === Papel::Proprietario && $autor->papel !== Papel::Proprietario) {
            throw new AcessoNegadoException('Somente o proprietário pode conceder o papel de proprietário.');
        }
    }

    public function garantirPodeAlterar(Usuario $autor, Usuario $alvo): void
    {
        $this->garantirPodeGerenciar($autor);

        if ($alvo->papel === Papel::Proprietario) {
            throw new AcessoNegadoException('O proprietário da conta não pode ser alterado.');
        }
    }

    public function garantirPodeDesativar(Usuario $autor, Usuario $alvo): void
    {
        if ($autor->is($alvo)) {
            throw new AcessoNegadoException('Você não pode desativar a si mesmo.');
        }

        $this->garantirPodeAlterar($autor, $alvo);
    }
}
```

`backend/app/Modules/Identity/Notifications/ConviteDeUsuario.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ConviteDeUsuario extends Notification
{
    public function __construct(public readonly string $token, public readonly string $empresa) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Usuario $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/definir-senha?token='.$this->token.'&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject("Convite para {$this->empresa}")
            ->greeting('Olá!')
            ->line("Você foi convidado para {$this->empresa}. Defina sua senha para entrar.")
            ->action('Definir senha', $url)
            ->line('O link expira em 72 horas.');
    }
}
```

`backend/app/Modules/Identity/Application/Actions/ConvidarUsuario.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Notifications\ConviteDeUsuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class ConvidarUsuario
{
    public function __construct(
        private readonly PoliticaDeUsuarios $politica,
        private readonly EntitlementService $entitlements,
    ) {}

    /** @param array{nome: string, email: string, papel: string} $dados */
    public function executar(Usuario $autor, array $dados): Usuario
    {
        $papel = Papel::from($dados['papel']);
        $this->politica->garantirPodeAtribuir($autor, $papel);

        [$usuario, $tenant] = DB::transaction(function () use ($autor, $dados, $papel): array {
            // Trava a linha do tenant: dois convites simultâneos não passam juntos do limite.
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($autor->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Usuarios);

            // Senha aleatória nunca revelada: o convidado define a dele pelo link.
            $usuario = new Usuario(['nome' => $dados['nome'], 'email' => $dados['email'], 'password' => Str::password(32)]);
            $usuario->forceFill(['tenant_id' => $tenant->id, 'papel' => $papel, 'ativo' => true])->save();

            activity('usuarios')->performedOn($usuario)->causedBy($autor)->event('usuario_convidado')
                ->withProperties(['papel' => $papel->value])->log('Usuário convidado');

            return [$usuario, $tenant];
        });

        $token = Password::broker('convites')->createToken($usuario);
        $usuario->notify(new ConviteDeUsuario($token, $tenant->nomeDeExibicao()));

        return $usuario;
    }
}
```

`backend/app/Modules/Identity/Application/Actions/EditarUsuario.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;

final class EditarUsuario
{
    public function __construct(private readonly PoliticaDeUsuarios $politica) {}

    /** @param array{nome: string, papel: string} $dados */
    public function executar(Usuario $autor, Usuario $alvo, array $dados): Usuario
    {
        $papel = Papel::from($dados['papel']);
        $this->politica->garantirPodeAlterar($autor, $alvo);
        $this->politica->garantirPodeAtribuir($autor, $papel);

        $papelAnterior = $alvo->papel;
        $alvo->forceFill(['nome' => $dados['nome'], 'papel' => $papel])->save();

        activity('usuarios')->performedOn($alvo)->causedBy($autor)->event('usuario_editado')
            ->withProperties(['papel_de' => $papelAnterior->value, 'papel_para' => $papel->value])
            ->log('Usuário editado');

        return $alvo;
    }
}
```

`backend/app/Modules/Identity/Application/Actions/DesativarUsuario.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Models\Usuario;

/** O desativado perde o acesso na próxima requisição (GarantirUsuarioAtivo). */
final class DesativarUsuario
{
    public function __construct(private readonly PoliticaDeUsuarios $politica) {}

    public function executar(Usuario $autor, Usuario $alvo): Usuario
    {
        $this->politica->garantirPodeDesativar($autor, $alvo);

        $alvo->forceFill(['ativo' => false])->save();
        activity('usuarios')->performedOn($alvo)->causedBy($autor)->event('usuario_desativado')->log('Usuário desativado');

        return $alvo;
    }
}
```

`backend/app/Modules/Identity/Application/Actions/ReativarUsuario.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class ReativarUsuario
{
    public function __construct(
        private readonly PoliticaDeUsuarios $politica,
        private readonly EntitlementService $entitlements,
    ) {}

    public function executar(Usuario $autor, Usuario $alvo): Usuario
    {
        $this->politica->garantirPodeAlterar($autor, $alvo);

        return DB::transaction(function () use ($autor, $alvo): Usuario {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($alvo->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Usuarios);

            $alvo->forceFill(['ativo' => true])->save();
            activity('usuarios')->performedOn($alvo)->causedBy($autor)->event('usuario_reativado')->log('Usuário reativado');

            return $alvo;
        });
    }
}
```

`backend/app/Modules/Identity/Http/Requests/ConvidarUsuarioRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Enums\Papel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConvidarUsuarioRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'papel' => ['required', Rule::in(Papel::valoresDaEmpresa())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.unique' => 'Este e-mail já está em uso.'];
    }

    /** @return array{nome: string, email: string, papel: string} */
    public function dados(): array
    {
        return [
            'nome' => trim($this->string('nome')->toString()),
            'email' => $this->string('email')->toString(),
            'papel' => $this->string('papel')->toString(),
        ];
    }
}
```

`backend/app/Modules/Identity/Http/Requests/EditarUsuarioRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Enums\Papel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EditarUsuarioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:120'],
            'papel' => ['required', Rule::in(Papel::valoresDaEmpresa())],
        ];
    }

    /** @return array{nome: string, papel: string} */
    public function dados(): array
    {
        return ['nome' => trim($this->string('nome')->toString()), 'papel' => $this->string('papel')->toString()];
    }
}
```

`backend/app/Modules/Identity/Http/Resources/UsuarioDaEmpresaResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Usuario */
final class UsuarioDaEmpresaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'email' => $this->email,
            'papel' => $this->papel->value,
            'ativo' => $this->ativo,
        ];
    }
}
```

`backend/app/Modules/Identity/Http/Controllers/UsuariosController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\ConvidarUsuario;
use App\Modules\Identity\Application\Actions\EditarUsuario;
use App\Modules\Identity\Application\PoliticaDeUsuarios;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Requests\ConvidarUsuarioRequest;
use App\Modules\Identity\Http\Requests\EditarUsuarioRequest;
use App\Modules\Identity\Http\Resources\UsuarioDaEmpresaResource;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UsuariosController
{
    public function __construct(private readonly TenantContext $contexto) {}

    public function index(Request $request, PoliticaDeUsuarios $politica): AnonymousResourceCollection
    {
        $politica->garantirPodeGerenciar($this->autor($request));

        return UsuarioDaEmpresaResource::collection(
            Usuario::query()->daEmpresa($this->contexto->require())->orderBy('nome')->get(),
        );
    }

    public function store(ConvidarUsuarioRequest $request, ConvidarUsuario $convidar): JsonResponse
    {
        $usuario = $convidar->executar($this->autor($request), $request->dados());

        return (new UsuarioDaEmpresaResource($usuario))->response()->setStatusCode(201);
    }

    public function update(EditarUsuarioRequest $request, string $id, EditarUsuario $editar): UsuarioDaEmpresaResource
    {
        $alvo = Usuario::query()->daEmpresa($this->contexto->require())->findOrFail($id);

        return new UsuarioDaEmpresaResource($editar->executar($this->autor($request), $alvo, $request->dados()));
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
```

`backend/app/Modules/Identity/Http/Controllers/DesativarUsuarioController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\DesativarUsuario;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Resources\UsuarioDaEmpresaResource;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Http\Request;

final class DesativarUsuarioController
{
    public function __invoke(Request $request, string $id, TenantContext $contexto, DesativarUsuario $desativar): UsuarioDaEmpresaResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $alvo = Usuario::query()->daEmpresa($contexto->require())->findOrFail($id);

        return new UsuarioDaEmpresaResource($desativar->executar($autor, $alvo));
    }
}
```

`backend/app/Modules/Identity/Http/Controllers/ReativarUsuarioController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\ReativarUsuario;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Resources\UsuarioDaEmpresaResource;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Http\Request;

final class ReativarUsuarioController
{
    public function __invoke(Request $request, string $id, TenantContext $contexto, ReativarUsuario $reativar): UsuarioDaEmpresaResource
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        $alvo = Usuario::query()->daEmpresa($contexto->require())->findOrFail($id);

        return new UsuarioDaEmpresaResource($reativar->executar($autor, $alvo));
    }
}
```

`backend/app/Modules/Identity/Http/Controllers/AceitarConviteController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Http\Requests\RedefinirSenhaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Mesmas regras de senha da redefinição, com o broker de convites (72 h). */
final class AceitarConviteController
{
    public function __invoke(RedefinirSenhaRequest $request): JsonResponse
    {
        $status = Password::broker('convites')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Usuario $usuario, string $senha): void {
                $usuario->forceFill(['password' => $senha, 'remember_token' => Str::random(60)])->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Convite inválido ou expirado. Peça um novo convite ao administrador.',
            ]);
        }

        return response()->json(['message' => 'Senha definida. Faça login para entrar.']);
    }
}
```

`Identity/Http/routes.php`: dentro de `Route::prefix('auth')`, acrescentar
```php
    Route::post('aceitar-convite', AceitarConviteController::class)->middleware('throttle:10,60');
```
Fora do prefixo `auth`, acrescentar:
```php
Route::middleware('empresa')->prefix('usuarios')->group(function (): void {
    Route::get('/', [UsuariosController::class, 'index']);
    Route::post('/', [UsuariosController::class, 'store']);
    Route::put('{id}', [UsuariosController::class, 'update'])->whereUuid('id');
    Route::post('{id}/desativar', DesativarUsuarioController::class)->whereUuid('id');
    Route::post('{id}/reativar', ReativarUsuarioController::class)->whereUuid('id');
});
```
Acrescentar os `use` correspondentes.

- [ ] **Passo 4: rodar e ver passar**

Comando: `php artisan test`
Esperado: todos passam.

- [ ] **Passo 5: qualidade e commit**

```bash
vendor/bin/pint && composer analyse
git add -A backend && git commit -m "feat: usuários da empresa com convite de 72 h, papéis e limite do plano"
```

---

### Tarefa 11: CI com PostgreSQL, README de produção e ADR 0004

**Arquivos:**
- Modificar:
  - `.github/workflows/ci.yml`
  - `README.md` (seções "Produção" e "Endpoints da F1")
- Criar: `docs/adr/0004-planos-situacao-e-limites.md`

- [ ] **Passo 1: job de CI em PostgreSQL**

Acrescentar em `.github/workflows/ci.yml`, depois do job `backend`:
```yaml
  backend-pgsql:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_USER: crm
          POSTGRES_PASSWORD: crm
          POSTGRES_DB: crm_test
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U crm"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10
    defaults:
      run:
        working-directory: backend
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_USERNAME: crm
      DB_PASSWORD: crm
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, pdo_pgsql, openssl, soap, dom, zip, intl
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      # phpunit.xml fixa SQLite em memória; aqui a conexão é forçada para o PostgreSQL do serviço.
      - run: >-
          sed -e 's|name="DB_CONNECTION" value="sqlite"|name="DB_CONNECTION" value="pgsql" force="true"|'
          -e 's|name="DB_DATABASE" value=":memory:"|name="DB_DATABASE" value="crm_test" force="true"|'
          phpunit.xml > phpunit.pgsql.xml
      - run: php artisan test --configuration=phpunit.pgsql.xml
```

- [ ] **Passo 2: README**

Acrescentar em `README.md`:
```markdown
## Produção

| Variável | Valor | Por quê |
|---|---|---|
| `SESSION_DOMAIN` | domínio pai comum ao front e à API (ex.: `.suaempresa.com.br`) | o cookie de sessão precisa valer nos dois |
| `SESSION_SECURE_COOKIE` | `true` | cookie só em HTTPS |
| `SANCTUM_STATEFUL_DOMAINS` | domínio do frontend (ex.: `app.suaempresa.com.br`) | libera a sessão do SPA |
| `FRONTEND_URL` | URL do frontend | CORS e links dos e-mails (senha e convite) |
| `TRUSTED_PROXIES` | IPs/CIDRs do balanceador, ou `*` só atrás de proxy controlado | IP real do cliente nos limites de tentativa |
| `QUEUE_CONNECTION` | `redis` (ou `database`) com um worker rodando | e-mails e jobs fora da requisição |
| `BRASILAPI_URL` | `https://brasilapi.com.br` | consulta de CNPJ no cadastro (opcional) |

O agendador precisa rodar a cada minuto (`* * * * * php artisan schedule:run`). É ele que suspende as contas com teste vencido às 00:10 (America/Sao_Paulo).

## Endpoints da F1

- **Público:** `GET /api/publico/planos`, `GET /api/publico/cnpj/{cnpj}`, `POST /api/publico/cadastro`.
- **Empresa** (sessão, grupo `empresa`):
  - `GET /api/app/assinatura`.
  - `GET|POST /api/app/usuarios`, `PUT /api/app/usuarios/{id}`, `POST /api/app/usuarios/{id}/desativar|reativar`.
- **Convite:** `POST /api/app/auth/aceitar-convite`.
- **Admin** (sessão, grupo `plataforma`: SUPERADMIN escreve, SUPORTE lê):
  - `/api/admin/planos` (+ `{id}`, `{id}/desativar`).
  - `/api/admin/empresas` (+ `{id}`, `{id}/situacao`, `{id}/plano`).
  - `/api/admin/metricas`.

Erros de negócio sempre vêm como `{"message": "...", "codigo": "..."}`:

| Código | Status |
|---|---|
| `ASSINATURA_SEM_ESCRITA` | 403 |
| `ASSINATURA_PENDENTE` | 403 |
| `ACESSO_NEGADO` | 403 |
| `MODULO_NAO_CONTRATADO` | 403 |
| `LIMITE_DO_PLANO` | 422 |
| `TRANSICAO_INVALIDA` | 422 |
| `PLANO_EXCEDIDO` | 422 |
| `PLANO_INDISPONIVEL` | 422 |
| `CONSULTA_INDISPONIVEL` | 503 |
```

- [ ] **Passo 3: ADR 0004**

`docs/adr/0004-planos-situacao-e-limites.md`:
```markdown
# ADR 0004: Planos, situação da assinatura e limites

- **Status:** aceito (F1, 2026-09-27)

## Contexto

O admin cria planos com preço, módulos e limites. Cada empresa tem um plano e uma situação de assinatura. Os módulos (fiscal, CRM, API) precisam consultar o que o plano permite sem conhecer uns aos outros.

## Decisão

- **Limites fail-closed.** Cada limite é uma linha em `plano_limites`. `-1` = ilimitado. **Recurso sem linha vale 0**, então esquecer de configurar bloqueia em vez de liberar.
- **`EntitlementService`** (módulo Platform):
  - É o único ponto que responde "tem o módulo?", "qual o limite?" e "cabe mais um?".
  - O uso vem de **contadores registrados pelo módulo dono do recurso** (`ContadorDeUso`). A Identity registra `USUARIOS`, e cada fase registra os seus.
  - Pedir o uso de um recurso sem contador lança exceção. Nunca devolve 0 em silêncio.
- **A checagem fica nas Actions**, não no controller, para valer igual no frontend e na API pública. Onde há corrida (convite, reativação), a Action trava a linha do tenant (`lockForUpdate`) antes de contar.
- **Situação é máquina de estados explícita** (`SituacaoAssinatura::podeIrPara`):
  - Toda mudança passa por `MudarSituacaoDaEmpresa`, que audita com o motivo.
  - O middleware `VerificarSituacaoDoTenant` bloqueia a escrita em `PENDENTE`, `SUSPENSA` e `CANCELADA`.
  - A leitura continua liberada para o cliente baixar seus dados.
- **O "hoje" do negócio** usa `config('app.fuso_negocio')` (`America/Sao_Paulo`). O banco continua em UTC.
- **Erros de negócio** estendem `ErroDeNegocio` e viram JSON `{message, codigo}` num único ponto.
- **Auditoria** com `spatie/laravel-activitylog`, com morphs em uuid, cobrindo planos, situação, troca de plano e usuários.

## Consequências

- Editar um plano muda o que todas as empresas nele podem fazer. O admin vê quantas empresas usam o plano antes de salvar. Versionar planos fica para a F5, se for preciso.
- Um módulo novo precisa registrar o contador do seu recurso. Se não registrar, a primeira checagem falha de forma barulhenta.
```

- [ ] **Passo 4: verificar e commitar**

```bash
cd backend && php artisan test && vendor/bin/pint --test && composer analyse
cd .. && git add -A .github README.md docs/adr && git commit -m "docs: ADR 0004, README de produção e endpoints; ci: suíte do backend em PostgreSQL 16"
```
Esperado: a suíte local verde. O job em PostgreSQL só roda no GitHub (o repositório não tem remoto ainda): registre isso no ledger.

---
## Frontend

Todos os comandos rodam em `frontend/`. Para verificar uma tarefa, rode `npm test && npm run typecheck && npm run lint`.

### Tarefa 12: Pendências da F0 no frontend e o tipo `Usuario` da F1

Cobre os itens 4 e 5 do §10 da spec, mais o novo formato de `tenant`.

**Arquivos:**
- Criar:
  - `frontend/lib/sessao.ts` e `frontend/lib/sessao.test.ts`
  - `frontend/components/form/Campo.tsx` e `frontend/components/form/Campo.test.tsx`
  - `frontend/features/auth/hooks/useUsuario.test.tsx`
  - `frontend/features/auth/components/RedefinirSenhaForm.test.tsx`
- Modificar:
  - `frontend/app/providers.tsx`
  - `frontend/features/auth/hooks/useUsuario.ts`
  - `frontend/features/auth/types.ts`
  - `frontend/features/auth/schemas.ts` (exportar `senhaForte`)
  - `frontend/features/auth/components/{LoginForm,EsqueciSenhaForm,RedefinirSenhaForm}.tsx`
  - `frontend/features/auth/components/LoginForm.test.tsx`
  - `frontend/components/layout/Topbar.tsx`
  - `frontend/components/layout/AppShell.test.tsx`

**Interfaces:**
- Produz:
  - `irParaLoginSeNaoAutenticado(erro: unknown, local?: Pick<Location, 'pathname' | 'assign'>): void`
  - `<Campo id label erro? dica?>{(a11y) => ReactNode}</Campo>`, onde `a11y = {id, 'aria-invalid', 'aria-describedby'?}`.
  - `senhaForte` (schema zod).
  - Tipos:
    - `SituacaoAssinatura` = `'PENDENTE' | 'TESTE' | 'ATIVA' | 'INADIMPLENTE' | 'SUSPENSA' | 'CANCELADA'`.
    - `TenantResumo` = `{id, razao_social, nome_fantasia: string | null, cnpj, situacao, teste_termina_em: string | null}`.
    - `Usuario.tenant: TenantResumo | null`.

- [ ] **Passo 1: testes que falham**

`frontend/lib/sessao.test.ts`:
```ts
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from './api';
import { irParaLoginSeNaoAutenticado } from './sessao';

const local = (pathname: string) => ({ pathname, assign: vi.fn() });

describe('irParaLoginSeNaoAutenticado', () => {
  it('401 leva ao login', () => {
    const l = local('/dashboard');
    irParaLoginSeNaoAutenticado(new ApiError(401, 'Não autenticado.'), l);
    expect(l.assign).toHaveBeenCalledWith('/login');
  });

  it('na própria tela de login não redireciona', () => {
    const l = local('/login');
    irParaLoginSeNaoAutenticado(new ApiError(401, 'x'), l);
    expect(l.assign).not.toHaveBeenCalled();
  });

  it('outros erros não redirecionam', () => {
    const l = local('/dashboard');
    irParaLoginSeNaoAutenticado(new ApiError(500, 'x'), l);
    irParaLoginSeNaoAutenticado(new Error('rede'), l);
    expect(l.assign).not.toHaveBeenCalled();
  });
});
```

`frontend/components/form/Campo.test.tsx`:
```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Campo } from './Campo';

describe('Campo', () => {
  it('liga o erro ao campo por aria-describedby', () => {
    render(<Campo id="email" label="E-mail" erro="Informe um e-mail válido.">{(a11y) => <input {...a11y} />}</Campo>);

    const campo = screen.getByLabelText('E-mail');
    expect(campo).toHaveAttribute('aria-invalid', 'true');
    expect(campo).toHaveAccessibleDescription('Informe um e-mail válido.');
  });

  it('sem erro, só a dica descreve o campo', () => {
    render(<Campo id="senha" label="Senha" dica="Mínimo de 8 caracteres.">{(a11y) => <input {...a11y} />}</Campo>);

    const campo = screen.getByLabelText('Senha');
    expect(campo).toHaveAttribute('aria-invalid', 'false');
    expect(campo).toHaveAccessibleDescription('Mínimo de 8 caracteres.');
  });
});
```

`frontend/features/auth/hooks/useUsuario.test.tsx`:
```tsx
import { focusManager, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { authApi } from '../api';
import { useUsuario } from './useUsuario';

vi.mock('../api', () => ({ authApi: { me: vi.fn() } }));

describe('useUsuario', () => {
  afterEach(() => focusManager.setFocused(undefined));

  it('revalida o /me quando a janela volta a ter foco', async () => {
    vi.mocked(authApi.me).mockResolvedValue({
      data: { id: '1', nome: 'Ana', email: 'ana@x.com', papel: 'PROPRIETARIO', tenant: null },
    });
    const client = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );

    const { result } = renderHook(() => useUsuario(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(authApi.me).toHaveBeenCalledTimes(1);

    act(() => {
      focusManager.setFocused(false);
      focusManager.setFocused(true);
    });

    await waitFor(() => expect(authApi.me).toHaveBeenCalledTimes(2));
  });
});
```

`frontend/features/auth/components/RedefinirSenhaForm.test.tsx`:
```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { authApi } from '../api';
import { RedefinirSenhaForm } from './RedefinirSenhaForm';

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn() }) }));
vi.mock('../api', () => ({ authApi: { redefinirSenha: vi.fn() } }));

describe('RedefinirSenhaForm', () => {
  it('link com e-mail malformado mostra "link inválido" em vez de falhar calado', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <RedefinirSenhaForm token="abc" email="nao-e-email" />
      </QueryClientProvider>,
    );

    await userEvent.type(screen.getByLabelText('Nova senha'), 'Senha123');
    await userEvent.type(screen.getByLabelText('Confirmar nova senha'), 'Senha123');
    await userEvent.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Link inválido ou expirado.');
    expect(authApi.redefinirSenha).not.toHaveBeenCalled();
  });

  it('erro de senha fica ligado ao campo', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <RedefinirSenhaForm token="abc" email="ana@x.com" />
      </QueryClientProvider>,
    );

    await userEvent.type(screen.getByLabelText('Nova senha'), 'curta');
    await userEvent.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    expect(await screen.findByLabelText('Nova senha')).toHaveAccessibleDescription(
      'A senha precisa ter pelo menos 8 caracteres.',
    );
  });
});
```

Em `LoginForm.test.tsx`, no teste `valida o e-mail antes de enviar`, acrescentar antes do `expect(authApi.login)`:
```tsx
    expect(screen.getByLabelText('E-mail')).toHaveAccessibleDescription('Informe um e-mail válido.');
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `npm test`
Esperado: FALHAM `sessao`, `Campo` (módulos inexistentes), `useUsuario` (1 chamada em vez de 2, por causa do `staleTime`), os dois de `RedefinirSenhaForm` e o de `LoginForm` (sem descrição acessível).

- [ ] **Passo 3: implementar**

`frontend/lib/sessao.ts`:
```ts
import { ApiError } from './api';

/** Sessão caída (401) em qualquer query ou mutation leva ao login. `local` é injetável para teste. */
export function irParaLoginSeNaoAutenticado(
  erro: unknown,
  local: Pick<Location, 'pathname' | 'assign'> = window.location,
): void {
  if (erro instanceof ApiError && erro.status === 401 && !local.pathname.startsWith('/login')) {
    // Fora do React não há router; o reload completo também descarta o estado da sessão expirada.
    // eslint-disable-next-line @next/next/no-location-assign-relative-destination
    local.assign('/login');
  }
}
```
Se o lint reclamar que a diretiva `eslint-disable` não é usada, remova-a. Ela veio do `providers.tsx`.

`frontend/app/providers.tsx`: substituir `criarQueryClient` por:
```tsx
function criarQueryClient(): QueryClient {
  const aoErrar = (erro: unknown) => irParaLoginSeNaoAutenticado(erro);

  return new QueryClient({
    queryCache: new QueryCache({ onError: aoErrar }),
    mutationCache: new MutationCache({ onError: aoErrar }),
    defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
  });
}
```
Os imports passam a ser `MutationCache, QueryCache, QueryClient, QueryClientProvider` e `import { irParaLoginSeNaoAutenticado } from '@/lib/sessao';`.

`frontend/features/auth/hooks/useUsuario.ts`: no `useQuery`, acrescentar
```ts
    // Papel, situação da assinatura ou desativação mudam no servidor: revalida ao voltar para a aba.
    refetchOnWindowFocus: 'always',
```

`frontend/components/form/Campo.tsx`:
```tsx
import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';

export interface PropsAcessiveis {
  id: string;
  'aria-invalid': boolean;
  'aria-describedby'?: string;
}

interface Props {
  id: string;
  label: string;
  erro?: string;
  dica?: string;
  children: (a11y: PropsAcessiveis) => ReactNode;
}

/** Label + controle + dica + erro, com o erro anunciado pelo leitor de tela. */
export function Campo({ id, label, erro, dica, children }: Props) {
  const idDica = `${id}-dica`;
  const idErro = `${id}-erro`;
  const descritores = [dica ? idDica : null, erro ? idErro : null].filter(Boolean).join(' ');

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      {children({ id, 'aria-invalid': Boolean(erro), 'aria-describedby': descritores || undefined })}
      {dica ? <p id={idDica} className="text-xs text-muted-foreground">{dica}</p> : null}
      {erro ? <p id={idErro} className="text-sm text-danger">{erro}</p> : null}
    </div>
  );
}
```

`frontend/features/auth/schemas.ts`: extrair a senha e exportar:
```ts
export const senhaForte = z
  .string()
  .min(8, 'A senha precisa ter pelo menos 8 caracteres.')
  .regex(/[A-Z]/, 'Inclua pelo menos uma letra maiúscula.')
  .regex(/[a-z]/, 'Inclua pelo menos uma letra minúscula.')
  .regex(/[0-9]/, 'Inclua pelo menos um número.');
```
No `redefinirSenhaSchema`, use `password: senhaForte`.

**Formulários:** trocar cada bloco `div > Label + Input + p` por `Campo`. No `LoginForm`:
```tsx
      <Campo id="email" label="E-mail" erro={erros.email?.message}>
        {(a11y) => <Input {...a11y} type="email" autoComplete="email" {...form.register('email')} />}
      </Campo>
      <Campo id="password" label="Senha" erro={erros.password?.message}>
        {(a11y) => <Input {...a11y} type="password" autoComplete="current-password" {...form.register('password')} />}
      </Campo>
```
Faça o mesmo no `EsqueciSenhaForm` (id `email`, label "E-mail cadastrado") e no `RedefinirSenhaForm` (ids `password` e `password_confirmation`, labels "Nova senha" e "Confirmar nova senha").

Remova os imports de `Label` que ficarem sem uso.

`RedefinirSenhaForm`: logo antes do `{erros.root ? ...}`, acrescentar:
```tsx
      {erros.token || erros.email ? (
        <p role="alert" className="text-sm text-danger">
          Link inválido ou expirado. <Link href="/esqueci-senha" className="underline">Solicitar novo link</Link>
        </p>
      ) : null}
```

`frontend/features/auth/types.ts`:
```ts
export type Papel = 'SUPERADMIN' | 'SUPORTE' | 'PROPRIETARIO' | 'ADMIN' | 'FISCAL' | 'VENDEDOR' | 'LEITURA';

export type SituacaoAssinatura = 'PENDENTE' | 'TESTE' | 'ATIVA' | 'INADIMPLENTE' | 'SUSPENSA' | 'CANCELADA';

export interface TenantResumo {
  id: string;
  razao_social: string;
  nome_fantasia: string | null;
  cnpj: string;
  situacao: SituacaoAssinatura;
  teste_termina_em: string | null;
}

export interface Usuario {
  id: string;
  nome: string;
  email: string;
  papel: Papel;
  tenant: TenantResumo | null;
}
```

`Topbar.tsx`: trocar `usuario?.tenant?.nome ?? 'Administração da plataforma'` por
```tsx
usuario?.tenant ? (usuario.tenant.nome_fantasia ?? usuario.tenant.razao_social) : 'Administração da plataforma'
```

`AppShell.test.tsx`: o tenant do fixture passa a ser
```ts
      tenant: { id: 't', razao_social: 'Empresa X Ltda', nome_fantasia: 'Empresa X', cnpj: '11222333000181', situacao: 'ATIVA', teste_termina_em: null },
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `npm test && npm run typecheck && npm run lint`
Esperado: tudo verde.

- [ ] **Passo 5: commit**

```bash
git add -A frontend && git commit -m "fix: pendências do frontend da F0 (401 em mutations, /me no foco, acessibilidade dos campos, link inválido)"
```

---

### Tarefa 13: Áreas, situação da assinatura e consumo no dashboard

**Arquivos:**
- Criar:
  - `frontend/lib/formatos.ts` e `frontend/lib/formatos.test.ts`
  - `frontend/features/assinatura/types.ts`
  - `frontend/features/assinatura/api.ts`
  - `frontend/features/assinatura/hooks/useAssinatura.ts`
  - `frontend/features/assinatura/components/{FaixaSituacao,AguardandoAtivacao,ConsumoDoPlano,AreaDaEmpresa}.tsx`
  - `frontend/features/assinatura/components/Assinatura.test.tsx`
  - `frontend/components/layout/ExigirArea.tsx` e `frontend/components/layout/ExigirArea.test.tsx`
- Modificar:
  - `frontend/app/(app)/layout.tsx`
  - `frontend/app/(app)/dashboard/page.tsx`
  - `frontend/features/auth/components/LoginForm.tsx` e `LoginForm.test.tsx`

**Interfaces:**
- Consome: os tipos `Usuario` e `SituacaoAssinatura` (T12) e `GET /api/app/assinatura` (T6).
- Produz:
  - `formatarCentavos(n): string`, `formatarData('AAAA-MM-DD'): 'DD/MM/AAAA'`, `reaisParaCentavos('99,90'): number` e `centavosParaReais(9990): '99,90'`.
  - Tipos e constantes: `MODULOS`, `RECURSOS`, `Modulo`, `Recurso`, `Plano`, `ItemConsumo`, `Assinatura`, `ROTULO_MODULO`, `ROTULO_RECURSO` e `ROTULO_SITUACAO`.
  - Componentes: `<ConsumoDoPlano itens />`, `<FaixaSituacao situacao testeTerminaEm />` e `<ExigirArea area="empresa" | "plataforma">`.
  - Depois do login, o usuário da plataforma vai para `/admin` e o da empresa, para `/dashboard`.

- [ ] **Passo 1: testes que falham**

`frontend/lib/formatos.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { centavosParaReais, formatarCentavos, formatarData, reaisParaCentavos } from './formatos';

const semNbsp = (s: string) => s.replace(/\s/g, ' ');

describe('formatos', () => {
  it('formata centavos em reais', () => {
    expect(semNbsp(formatarCentavos(9990))).toBe('R$ 99,90');
    expect(semNbsp(formatarCentavos(123456789))).toBe('R$ 1.234.567,89');
  });

  it('formata data ISO sem deslocar o fuso', () => {
    expect(formatarData('2026-10-15')).toBe('15/10/2026');
  });

  it('converte reais em centavos sem float', () => {
    expect(reaisParaCentavos('99,90')).toBe(9990);
    expect(reaisParaCentavos('99,9')).toBe(9990);
    expect(reaisParaCentavos('1234')).toBe(123400);
    expect(reaisParaCentavos('0,05')).toBe(5);
    expect(centavosParaReais(9990)).toBe('99,90');
    expect(centavosParaReais(5)).toBe('0,05');
  });
});
```

`frontend/features/assinatura/components/Assinatura.test.tsx`:
```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { QUERY_KEY_USUARIO } from '@/features/auth/hooks/useUsuario';
import type { SituacaoAssinatura } from '@/features/auth/types';
import { AreaDaEmpresa } from './AreaDaEmpresa';
import { ConsumoDoPlano } from './ConsumoDoPlano';
import { FaixaSituacao } from './FaixaSituacao';

function comUsuarioEm(situacao: SituacaoAssinatura) {
  const client = new QueryClient();
  client.setQueryData(QUERY_KEY_USUARIO, {
    id: '1', nome: 'Ana', email: 'ana@x.com', papel: 'PROPRIETARIO',
    tenant: { id: 't', razao_social: 'X Ltda', nome_fantasia: null, cnpj: '11222333000181', situacao, teste_termina_em: '2026-10-15' },
  });
  render(<QueryClientProvider client={client}><AreaDaEmpresa><p>conteúdo</p></AreaDaEmpresa></QueryClientProvider>);
}

describe('FaixaSituacao', () => {
  it('teste mostra a data de término', () => {
    render(<FaixaSituacao situacao="TESTE" testeTerminaEm="2026-10-15" />);
    expect(screen.getByRole('status')).toHaveTextContent('Teste grátis até 15/10/2026');
  });

  it('suspensa avisa que o download continua liberado', () => {
    render(<FaixaSituacao situacao="SUSPENSA" testeTerminaEm={null} />);
    expect(screen.getByRole('status')).toHaveTextContent('Conta suspensa. Você ainda pode consultar e baixar seus dados.');
  });

  it('ativa não mostra faixa', () => {
    const { container } = render(<FaixaSituacao situacao="ATIVA" testeTerminaEm={null} />);
    expect(container).toBeEmptyDOMElement();
  });
});

describe('AreaDaEmpresa', () => {
  it('conta pendente vê "Aguardando ativação" no lugar do conteúdo', () => {
    comUsuarioEm('PENDENTE');
    expect(screen.getByRole('heading', { name: 'Aguardando ativação' })).toBeInTheDocument();
    expect(screen.queryByText('conteúdo')).not.toBeInTheDocument();
  });

  it('conta em teste vê a faixa e o conteúdo', () => {
    comUsuarioEm('TESTE');
    expect(screen.getByRole('status')).toHaveTextContent('Teste grátis');
    expect(screen.getByText('conteúdo')).toBeInTheDocument();
  });
});

describe('ConsumoDoPlano', () => {
  it('mostra uso, limite e ilimitado', () => {
    render(<ConsumoDoPlano itens={[
      { recurso: 'USUARIOS', uso: 1, limite: 3 },
      { recurso: 'CLIENTES', uso: 40, limite: -1 },
    ]} />);

    expect(screen.getByText('1 de 3')).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'Usuários' })).toHaveAttribute('aria-valuenow', '1');
    expect(screen.getByText('40 · ilimitado')).toBeInTheDocument();
  });
});
```

`frontend/components/layout/ExigirArea.test.tsx`:
```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QUERY_KEY_USUARIO } from '@/features/auth/hooks/useUsuario';
import { ExigirArea } from './ExigirArea';

const replace = vi.fn();
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace }) }));

function renderizar(area: 'empresa' | 'plataforma', tenant: null | { id: string }) {
  const client = new QueryClient();
  client.setQueryData(QUERY_KEY_USUARIO, { id: '1', nome: 'A', email: 'a@x.com', papel: 'SUPERADMIN', tenant });
  render(<QueryClientProvider client={client}><ExigirArea area={area}><p>área</p></ExigirArea></QueryClientProvider>);
}

describe('ExigirArea', () => {
  beforeEach(() => replace.mockClear());

  it('admin da plataforma na área da empresa vai para /admin', () => {
    renderizar('empresa', null);
    expect(replace).toHaveBeenCalledWith('/admin');
    expect(screen.queryByText('área')).not.toBeInTheDocument();
  });

  it('usuário de empresa na área da plataforma vai para /dashboard', () => {
    renderizar('plataforma', { id: 't' });
    expect(replace).toHaveBeenCalledWith('/dashboard');
  });

  it('área certa mostra o conteúdo', () => {
    renderizar('plataforma', null);
    expect(screen.getByText('área')).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });
});
```

Em `LoginForm.test.tsx`, acrescentar:
```tsx
  it('admin da plataforma vai para /admin', async () => {
    vi.mocked(authApi.login).mockResolvedValue({
      data: { id: '1', nome: 'Admin', email: 'admin@x.com', papel: 'SUPERADMIN', tenant: null },
    });
    renderizar();

    await userEvent.type(screen.getByLabelText('E-mail'), 'admin@x.com');
    await userEvent.type(screen.getByLabelText('Senha'), 'Senha123');
    await userEvent.click(screen.getByRole('button', { name: 'Entrar' }));

    await vi.waitFor(() => expect(push).toHaveBeenCalledWith('/admin'));
  });
```
No teste existente `com sucesso, vai para o dashboard`, o `tenant: null` do mock passa a ser o `TenantResumo` do `AppShell.test`, porque só usuário com tenant vai para `/dashboard`.

- [ ] **Passo 2: rodar e ver falhar**

Comando: `npm test`
Esperado: FALHAM (módulos inexistentes e o redirecionamento do admin).

- [ ] **Passo 3: implementar**

`frontend/lib/formatos.ts`:
```ts
const moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

export function formatarCentavos(centavos: number): string {
  return moeda.format(centavos / 100);
}

/** 'AAAA-MM-DD' → 'DD/MM/AAAA', sem passar por Date (que deslocaria o fuso). */
export function formatarData(iso: string): string {
  const [ano, mes, dia] = iso.slice(0, 10).split('-');
  return `${dia}/${mes}/${ano}`;
}

/** '99,90' → 9990. Aritmética inteira: nada de float com dinheiro. Entrada já validada por regex. */
export function reaisParaCentavos(texto: string): number {
  const [inteiro, fracao = ''] = texto.trim().split(',');
  return Number(inteiro) * 100 + Number(fracao.padEnd(2, '0').slice(0, 2));
}

export function centavosParaReais(centavos: number): string {
  return `${Math.trunc(centavos / 100)},${String(centavos % 100).padStart(2, '0')}`;
}
```

`frontend/features/assinatura/types.ts`:
```ts
import type { SituacaoAssinatura } from '@/features/auth/types';

export const MODULOS = ['FISCAL_NFE', 'FISCAL_NFCE', 'FISCAL_NFSE', 'CRM', 'API'] as const;
export type Modulo = (typeof MODULOS)[number];

export const RECURSOS = ['USUARIOS', 'CLIENTES', 'PRODUTOS', 'SERVICOS', 'DOCUMENTOS_MES', 'API_REQUISICOES_MIN'] as const;
export type Recurso = (typeof RECURSOS)[number];

export const ILIMITADO = -1;

export interface Plano {
  id: string;
  nome: string;
  descricao: string | null;
  preco_mensal_centavos: number;
  preco_anual_centavos: number | null;
  dias_teste: number;
  politica_excedente: 'BLOQUEAR' | 'COBRAR';
  preco_documento_excedente_centavos: number | null;
  modulos: Modulo[];
  limites: Record<Recurso, number>;
  ativo: boolean;
  visivel: boolean;
  ordem: number;
  empresas?: number;
}

export interface ItemConsumo {
  recurso: Recurso;
  uso: number;
  limite: number;
}

export interface Assinatura {
  situacao: SituacaoAssinatura;
  teste_termina_em: string | null;
  plano: Plano | null;
  consumo: ItemConsumo[];
}

export const ROTULO_MODULO: Record<Modulo, string> = {
  FISCAL_NFE: 'NF-e', FISCAL_NFCE: 'NFC-e', FISCAL_NFSE: 'NFS-e', CRM: 'CRM', API: 'API pública',
};

export const ROTULO_RECURSO: Record<Recurso, string> = {
  USUARIOS: 'Usuários', CLIENTES: 'Clientes', PRODUTOS: 'Produtos', SERVICOS: 'Serviços',
  DOCUMENTOS_MES: 'Documentos por mês', API_REQUISICOES_MIN: 'Requisições de API por minuto',
};

export const ROTULO_SITUACAO: Record<SituacaoAssinatura, string> = {
  PENDENTE: 'Pendente', TESTE: 'Em teste', ATIVA: 'Ativa', INADIMPLENTE: 'Inadimplente', SUSPENSA: 'Suspensa', CANCELADA: 'Cancelada',
};
```

`frontend/features/assinatura/api.ts`:
```ts
import { api } from '@/lib/api';
import type { Assinatura } from './types';

export const assinaturaApi = {
  buscar: () => api<{ data: Assinatura }>('/api/app/assinatura'),
};
```

`frontend/features/assinatura/hooks/useAssinatura.ts`:
```ts
import { useQuery } from '@tanstack/react-query';
import { assinaturaApi } from '../api';
import type { Assinatura } from '../types';

export const QUERY_KEY_ASSINATURA = ['assinatura'] as const;

export function useAssinatura() {
  return useQuery<Assinatura>({
    queryKey: QUERY_KEY_ASSINATURA,
    queryFn: async () => (await assinaturaApi.buscar()).data,
  });
}
```

`frontend/features/assinatura/components/FaixaSituacao.tsx`:
```tsx
import type { SituacaoAssinatura } from '@/features/auth/types';
import { formatarData } from '@/lib/formatos';
import { cn } from '@/lib/utils';

interface Props {
  situacao: SituacaoAssinatura;
  testeTerminaEm: string | null;
}

export function FaixaSituacao({ situacao, testeTerminaEm }: Props) {
  const faixa = (() => {
    switch (situacao) {
      case 'TESTE':
        return { tom: 'bg-info/10 text-info', texto: testeTerminaEm ? `Teste grátis até ${formatarData(testeTerminaEm)}.` : 'Teste grátis.' };
      case 'INADIMPLENTE':
        return { tom: 'bg-warning/10 text-warning', texto: 'Há um pagamento pendente na sua assinatura.' };
      case 'SUSPENSA':
        return { tom: 'bg-danger/10 text-danger', texto: 'Conta suspensa. Você ainda pode consultar e baixar seus dados.' };
      case 'CANCELADA':
        return { tom: 'bg-danger/10 text-danger', texto: 'Conta cancelada. Você ainda pode consultar e baixar seus dados.' };
      default:
        return null;
    }
  })();

  if (!faixa) return null;
  return <p role="status" className={cn('rounded-md px-3 py-2 text-sm', faixa.tom)}>{faixa.texto}</p>;
}
```

`frontend/features/assinatura/components/AguardandoAtivacao.tsx`:
```tsx
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export function AguardandoAtivacao() {
  return (
    <Card className="mx-auto max-w-lg">
      <CardHeader>
        <CardTitle><h1>Aguardando ativação</h1></CardTitle>
      </CardHeader>
      <CardContent className="space-y-2 text-muted-foreground">
        <p>Recebemos seu cadastro. Assim que a conta for ativada, você terá acesso completo.</p>
        <p>Se tiver dúvidas, responda ao e-mail de boas-vindas.</p>
      </CardContent>
    </Card>
  );
}
```

`frontend/features/assinatura/components/ConsumoDoPlano.tsx`:
```tsx
import { cn } from '@/lib/utils';
import { ILIMITADO, ROTULO_RECURSO, type ItemConsumo } from '../types';

export function ConsumoDoPlano({ itens }: { itens: ItemConsumo[] }) {
  return (
    <ul className="space-y-3">
      {itens.map(({ recurso, uso, limite }) => {
        const rotulo = ROTULO_RECURSO[recurso];
        if (limite === ILIMITADO) {
          return (
            <li key={recurso} className="flex justify-between text-sm">
              <span>{rotulo}</span>
              <span className="text-muted-foreground">{uso} · ilimitado</span>
            </li>
          );
        }
        const pct = limite === 0 ? 100 : Math.min(100, Math.round((uso / limite) * 100));
        return (
          <li key={recurso} className="space-y-1">
            <div className="flex justify-between text-sm">
              <span>{rotulo}</span>
              <span className="text-muted-foreground">{uso} de {limite}</span>
            </div>
            <div role="progressbar" aria-label={rotulo} aria-valuemin={0} aria-valuemax={limite} aria-valuenow={uso} className="h-2 rounded-full bg-muted">
              <div
                className={cn('h-2 rounded-full', pct >= 100 ? 'bg-danger' : pct >= 80 ? 'bg-warning' : 'bg-primary')}
                style={{ width: `${pct}%` }}
              />
            </div>
          </li>
        );
      })}
    </ul>
  );
}
```

`frontend/features/assinatura/components/AreaDaEmpresa.tsx`:
```tsx
'use client';

import type { ReactNode } from 'react';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { AguardandoAtivacao } from './AguardandoAtivacao';
import { FaixaSituacao } from './FaixaSituacao';

/** Aplica ao conteúdo da empresa a regra de exibição por situação (spec F1 §4). */
export function AreaDaEmpresa({ children }: { children: ReactNode }) {
  const { data: usuario } = useUsuario();
  const tenant = usuario?.tenant;

  if (!tenant) return null;
  if (tenant.situacao === 'PENDENTE') return <AguardandoAtivacao />;

  return (
    <div className="space-y-4">
      <FaixaSituacao situacao={tenant.situacao} testeTerminaEm={tenant.teste_termina_em} />
      {children}
    </div>
  );
}
```

`frontend/components/layout/ExigirArea.tsx`:
```tsx
'use client';

import { useRouter } from 'next/navigation';
import { useEffect, type ReactNode } from 'react';
import { useUsuario } from '@/features/auth/hooks/useUsuario';

type Area = 'empresa' | 'plataforma';

/** Fica dentro do AuthGuard: o usuário já está carregado. Área errada vai para a certa. */
export function ExigirArea({ area, children }: { area: Area; children: ReactNode }) {
  const router = useRouter();
  const { data: usuario } = useUsuario();
  const areaDoUsuario: Area | null = usuario ? (usuario.tenant ? 'empresa' : 'plataforma') : null;
  const errada = areaDoUsuario !== null && areaDoUsuario !== area;

  useEffect(() => {
    if (errada) router.replace(areaDoUsuario === 'plataforma' ? '/admin' : '/dashboard');
  }, [errada, areaDoUsuario, router]);

  if (!usuario || errada) {
    return <div className="flex min-h-dvh items-center justify-center text-muted-foreground">Carregando...</div>;
  }
  return <>{children}</>;
}
```

`frontend/app/(app)/layout.tsx`:
```tsx
import type { ReactNode } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { AuthGuard } from '@/components/layout/AuthGuard';
import { ExigirArea } from '@/components/layout/ExigirArea';
import { AreaDaEmpresa } from '@/features/assinatura/components/AreaDaEmpresa';

export default function AppLayout({ children }: { children: ReactNode }) {
  return (
    <AuthGuard>
      <ExigirArea area="empresa">
        <AppShell>
          <AreaDaEmpresa>{children}</AreaDaEmpresa>
        </AppShell>
      </ExigirArea>
    </AuthGuard>
  );
}
```

`frontend/app/(app)/dashboard/page.tsx`:
```tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ConsumoDoPlano } from '@/features/assinatura/components/ConsumoDoPlano';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import { useUsuario } from '@/features/auth/hooks/useUsuario';

export default function DashboardPage() {
  const { data: usuario } = useUsuario();
  const { data: assinatura, isError } = useAssinatura();

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle>Olá, {usuario?.nome.split(' ')[0]}</CardTitle>
        </CardHeader>
        <CardContent className="text-muted-foreground">
          Os módulos da sua assinatura vão aparecer aqui conforme forem liberados.
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Seu plano{assinatura?.plano ? `: ${assinatura.plano.nome}` : ''}</CardTitle>
        </CardHeader>
        <CardContent>
          {assinatura ? (
            <ConsumoDoPlano itens={assinatura.consumo} />
          ) : (
            <p className="text-muted-foreground">{isError ? 'Não foi possível carregar o consumo.' : 'Carregando...'}</p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
```

`LoginForm.tsx`: no `onSuccess`, trocar `router.push('/dashboard')` por
```tsx
      router.push(data.tenant ? '/dashboard' : '/admin');
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `npm test && npm run typecheck && npm run lint`
Esperado: tudo verde.

- [ ] **Passo 5: commit**

```bash
git add -A frontend && git commit -m "feat: situação da assinatura, aguardando ativação e consumo do plano no dashboard"
```

---

### Tarefa 14: Cadastro público (planos e cadastro em duas etapas)

**Arquivos:**
- Criar:
  - `frontend/lib/cnpj.ts` e `frontend/lib/cnpj.test.ts`
  - `frontend/features/cadastro/{api,schemas,forcaDaSenha}.ts` e `forcaDaSenha.test.ts`
  - `frontend/features/cadastro/hooks/usePlanosPublicos.ts`
  - `frontend/features/cadastro/components/{ListaDePlanos,CadastroForm}.tsx`
  - `frontend/features/cadastro/components/{ListaDePlanos,CadastroForm}.test.tsx` e `fixtures.ts` (dado de teste)
  - `frontend/app/(publico)/layout.tsx`
  - `frontend/app/(publico)/planos/page.tsx`
  - `frontend/app/(publico)/cadastro/page.tsx`
- Modificar: `frontend/app/(auth)/login/page.tsx` (link "Conheça os planos")

**Interfaces:**
- Consome: `Plano`, `ROTULO_*`, `formatarCentavos` (T13), `senhaForte` e `Campo` (T12), e os endpoints públicos (T9).
- Produz:
  - `normalizarCnpj`, `validarCnpj` e `mascararCnpj`.
  - `cadastroApi.{planos, consultarCnpj, cadastrar}`.
  - Páginas `/planos` e `/cadastro?plano=<id>`.

- [ ] **Passo 1: testes que falham**

`frontend/lib/cnpj.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { mascararCnpj, normalizarCnpj, validarCnpj } from './cnpj';

describe('cnpj', () => {
  it('valida numérico e alfanumérico (exemplo oficial da Receita)', () => {
    expect(validarCnpj('11.222.333/0001-81')).toBe(true);
    expect(validarCnpj('12.ABC.345/01DE-35')).toBe(true);
    expect(validarCnpj('12abc34501de35')).toBe(true);
  });

  it('recusa inválidos', () => {
    for (const invalido of ['11222333000182', '00000000000000', '1122233300018', '12ABC34501DE3A', '']) {
      expect(validarCnpj(invalido)).toBe(false);
    }
  });

  it('normaliza e mascara progressivamente', () => {
    expect(normalizarCnpj('12.abc.345/01de-35')).toBe('12ABC34501DE35');
    expect(mascararCnpj('12abc34501de35')).toBe('12.ABC.345/01DE-35');
    expect(mascararCnpj('11222')).toBe('11.222');
    expect(mascararCnpj('112223330001819999')).toBe('11.222.333/0001-81');
  });
});
```

`frontend/features/cadastro/forcaDaSenha.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { forcaDaSenha } from './forcaDaSenha';

describe('forcaDaSenha', () => {
  it('classifica', () => {
    expect(forcaDaSenha('').rotulo).toBe('');
    expect(forcaDaSenha('abc').rotulo).toBe('Fraca');
    expect(forcaDaSenha('Senha123').rotulo).toBe('Média');
    expect(forcaDaSenha('Senha123!').rotulo).toBe('Forte');
  });
});
```

`frontend/features/cadastro/components/ListaDePlanos.test.tsx`:
```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { cadastroApi } from '../api';
import { ListaDePlanos } from './ListaDePlanos';
import { planoDeTeste } from './fixtures';

vi.mock('../api', () => ({ cadastroApi: { planos: vi.fn() } }));

describe('ListaDePlanos', () => {
  it('mostra preço, teste grátis, módulos, limites e o link para começar', async () => {
    vi.mocked(cadastroApi.planos).mockResolvedValue({ data: [planoDeTeste] });
    render(<QueryClientProvider client={new QueryClient()}><ListaDePlanos /></QueryClientProvider>);

    expect(await screen.findByRole('heading', { name: 'Essencial' })).toBeInTheDocument();
    expect(screen.getByText(/99,90/)).toBeInTheDocument();
    expect(screen.getByText('14 dias grátis')).toBeInTheDocument();
    expect(screen.getByText('NF-e')).toBeInTheDocument();
    expect(screen.getByText('Usuários: 3')).toBeInTheDocument();
    expect(screen.getByText('Clientes: ilimitado')).toBeInTheDocument();
    expect(screen.queryByText(/Serviços/)).not.toBeInTheDocument(); // limite 0 não aparece
    expect(screen.getByRole('link', { name: 'Começar' })).toHaveAttribute('href', '/cadastro?plano=p1');
  });
});
```

`frontend/features/cadastro/components/fixtures.ts` (dado de teste compartilhado, **não** é código de produção):
```ts
import type { Plano } from '@/features/assinatura/types';

export const planoDeTeste: Plano = {
  id: 'p1', nome: 'Essencial', descricao: 'Para começar.', preco_mensal_centavos: 9990, preco_anual_centavos: null,
  dias_teste: 14, politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null,
  modulos: ['FISCAL_NFE'],
  limites: { USUARIOS: 3, CLIENTES: -1, PRODUTOS: 100, SERVICOS: 0, DOCUMENTOS_MES: 200, API_REQUISICOES_MIN: 0 },
  ativo: true, visivel: true, ordem: 1,
};
```

`frontend/features/cadastro/components/CadastroForm.test.tsx`:
```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { cadastroApi } from '../api';
import { CadastroForm } from './CadastroForm';
import { planoDeTeste } from './fixtures';

const push = vi.fn();
vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));
vi.mock('../api', () => ({ cadastroApi: { planos: vi.fn(), consultarCnpj: vi.fn(), cadastrar: vi.fn() } }));

const usuario = {
  id: 'u1', nome: 'Ana Souza', email: 'ana@paobom.com', papel: 'PROPRIETARIO' as const,
  tenant: { id: 't', razao_social: 'Padaria Pão Bom Ltda', nome_fantasia: 'Pão Bom', cnpj: '11222333000181', situacao: 'TESTE' as const, teste_termina_em: '2026-10-15' },
};

function renderizar() {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false }, queries: { retry: false } } });
  render(<QueryClientProvider client={client}><CadastroForm planoId="p1" /></QueryClientProvider>);
}

async function preencherResponsavel() {
  await userEvent.type(screen.getByLabelText('Seu nome'), 'Ana Souza');
  await userEvent.type(screen.getByLabelText('E-mail'), 'ana@paobom.com');
  await userEvent.type(screen.getByLabelText('Senha'), 'Senha123');
  await userEvent.type(screen.getByLabelText('Confirmar senha'), 'Senha123');
  await userEvent.click(screen.getByLabelText(/Li e aceito os termos de uso/));
}

describe('CadastroForm', () => {
  beforeEach(() => {
    push.mockClear();
    vi.mocked(cadastroApi.planos).mockResolvedValue({ data: [planoDeTeste] });
    vi.mocked(cadastroApi.consultarCnpj).mockReset();
    vi.mocked(cadastroApi.cadastrar).mockReset();
  });

  it('busca o CNPJ, avança para o responsável e cria a conta', async () => {
    vi.mocked(cadastroApi.consultarCnpj).mockResolvedValue({ data: { razao_social: 'Padaria Pão Bom Ltda', nome_fantasia: 'Pão Bom' } });
    vi.mocked(cadastroApi.cadastrar).mockResolvedValue({ data: usuario });
    renderizar();

    expect(await screen.findByText(/Plano escolhido:/)).toHaveTextContent('Plano escolhido: Essencial');
    await userEvent.type(screen.getByLabelText('CNPJ'), '11222333000181');

    await waitFor(() => expect(screen.getByLabelText('Razão social')).toHaveValue('Padaria Pão Bom Ltda'));
    expect(cadastroApi.consultarCnpj).toHaveBeenCalledWith('11222333000181');
    expect(screen.getByLabelText('CNPJ')).toHaveValue('11.222.333/0001-81');
    expect(screen.getByLabelText('Nome fantasia')).toHaveValue('Pão Bom');

    await userEvent.click(screen.getByRole('button', { name: 'Continuar' }));
    await preencherResponsavel();
    await userEvent.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => expect(cadastroApi.cadastrar).toHaveBeenCalledWith({
      cnpj: '11222333000181', razao_social: 'Padaria Pão Bom Ltda', nome_fantasia: 'Pão Bom',
      responsavel_nome: 'Ana Souza', email: 'ana@paobom.com', password: 'Senha123', password_confirmation: 'Senha123',
      aceite_termos: true, plano_id: 'p1',
    }));
    await waitFor(() => expect(push).toHaveBeenCalledWith('/dashboard'));
  });

  it('com a BrasilAPI fora, avisa e deixa preencher à mão', async () => {
    vi.mocked(cadastroApi.consultarCnpj).mockRejectedValue(new ApiError(503, 'Indisponível'));
    renderizar();

    await userEvent.type(screen.getByLabelText('CNPJ'), '11222333000181');
    expect(await screen.findByText('Não foi possível buscar os dados agora. Preencha manualmente.')).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText('Razão social'), 'Padaria Manual Ltda');
    await userEvent.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByLabelText('Seu nome')).toBeInTheDocument();
  });

  it('CNPJ inválido não avança nem consulta', async () => {
    renderizar();

    await userEvent.type(screen.getByLabelText('CNPJ'), '11222333000182');
    await userEvent.type(screen.getByLabelText('Razão social'), 'X');
    await userEvent.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByText('Informe um CNPJ válido.')).toBeInTheDocument();
    expect(cadastroApi.consultarCnpj).not.toHaveBeenCalled();
    expect(screen.queryByLabelText('Seu nome')).not.toBeInTheDocument();
  });

  it('CNPJ já cadastrado volta para a etapa da empresa com a mensagem', async () => {
    vi.mocked(cadastroApi.consultarCnpj).mockRejectedValue(new ApiError(404, 'Não encontrado'));
    vi.mocked(cadastroApi.cadastrar).mockRejectedValue(
      new ApiError(422, 'Dados inválidos.', { cnpj: ['Este CNPJ já possui conta. Entre ou recupere a senha.'] }),
    );
    renderizar();

    await userEvent.type(screen.getByLabelText('CNPJ'), '11222333000181');
    await userEvent.type(screen.getByLabelText('Razão social'), 'Padaria');
    await userEvent.click(screen.getByRole('button', { name: 'Continuar' }));
    await preencherResponsavel();
    await userEvent.click(screen.getByRole('button', { name: 'Criar conta' }));

    expect(await screen.findByText('Este CNPJ já possui conta. Entre ou recupere a senha.')).toBeInTheDocument();
    expect(screen.getByLabelText('CNPJ')).toBeInTheDocument();
  });
});
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `npm test`
Esperado: FALHAM (módulos inexistentes).

- [ ] **Passo 3: implementar**

`frontend/lib/cnpj.ts`:
```ts
/**
 * CNPJ numérico ou alfanumérico (IN RFB 2.229/2024). Mesma regra do backend
 * (App\Modules\Shared\Domain\Cnpj): valor do caractere = código ASCII − 48.
 */
const PESOS_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
const PESOS_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

export function normalizarCnpj(valor: string): string {
  return valor.toUpperCase().replace(/[^0-9A-Z]/g, '');
}

function digito(base: string, pesos: number[]): number {
  const soma = pesos.reduce((total, peso, i) => total + (base.charCodeAt(i) - 48) * peso, 0);
  const resto = soma % 11;
  return resto < 2 ? 0 : 11 - resto;
}

export function validarCnpj(valor: string): boolean {
  const cnpj = normalizarCnpj(valor);
  if (!/^[0-9A-Z]{12}[0-9]{2}$/.test(cnpj) || /^(.)\1{13}$/.test(cnpj)) return false;
  const base = cnpj.slice(0, 12);
  const dv1 = digito(base, PESOS_DV1);
  const dv2 = digito(base + dv1, PESOS_DV2);
  return cnpj.slice(12) === `${dv1}${dv2}`;
}

export function mascararCnpj(valor: string): string {
  const c = normalizarCnpj(valor).slice(0, 14);
  const partes: [string, string][] = [['', c.slice(0, 2)], ['.', c.slice(2, 5)], ['.', c.slice(5, 8)], ['/', c.slice(8, 12)], ['-', c.slice(12, 14)]];
  return partes.filter(([, trecho]) => trecho !== '').map(([sep, trecho], i) => (i === 0 ? trecho : sep + trecho)).join('');
}
```

`frontend/features/cadastro/forcaDaSenha.ts`:
```ts
export function forcaDaSenha(senha: string): { nivel: 0 | 1 | 2 | 3; rotulo: '' | 'Fraca' | 'Média' | 'Forte' } {
  if (senha === '') return { nivel: 0, rotulo: '' };
  const pontos = [
    senha.length >= 8,
    /[A-Z]/.test(senha) && /[a-z]/.test(senha),
    /\d/.test(senha),
    senha.length >= 12 || /[^A-Za-z0-9]/.test(senha),
  ].filter(Boolean).length;
  if (pontos <= 2) return { nivel: 1, rotulo: 'Fraca' };
  if (pontos === 3) return { nivel: 2, rotulo: 'Média' };
  return { nivel: 3, rotulo: 'Forte' };
}
```

`frontend/features/cadastro/schemas.ts`:
```ts
import { z } from 'zod';
import { senhaForte } from '@/features/auth/schemas';
import { validarCnpj } from '@/lib/cnpj';

export const CAMPOS_EMPRESA = ['cnpj', 'razao_social', 'nome_fantasia'] as const;

export const cadastroSchema = z
  .object({
    cnpj: z.string().refine(validarCnpj, 'Informe um CNPJ válido.'),
    razao_social: z.string().trim().min(1, 'Informe a razão social.').max(150, 'Use até 150 caracteres.'),
    nome_fantasia: z.string().trim().max(150, 'Use até 150 caracteres.'),
    responsavel_nome: z.string().trim().min(1, 'Informe seu nome.').max(120, 'Use até 120 caracteres.'),
    email: z.string().trim().email('Informe um e-mail válido.'),
    password: senhaForte,
    password_confirmation: z.string(),
    aceite_termos: z.boolean().refine((v) => v, 'Você precisa aceitar os termos de uso.'),
  })
  .refine((d) => d.password === d.password_confirmation, {
    path: ['password_confirmation'],
    message: 'As senhas não conferem.',
  });

export type CadastroDados = z.infer<typeof cadastroSchema>;
```

`frontend/features/cadastro/api.ts`:
```ts
import type { Plano } from '@/features/assinatura/types';
import type { Usuario } from '@/features/auth/types';
import { api } from '@/lib/api';
import type { CadastroDados } from './schemas';

export interface DadosCnpj {
  razao_social: string;
  nome_fantasia: string | null;
}

export type CadastroEntrada = Omit<CadastroDados, 'nome_fantasia'> & { nome_fantasia: string | null; plano_id: string };

export const cadastroApi = {
  planos: () => api<{ data: Plano[] }>('/api/publico/planos'),
  consultarCnpj: (cnpj: string) => api<{ data: DadosCnpj }>(`/api/publico/cnpj/${cnpj}`),
  cadastrar: (dados: CadastroEntrada) =>
    api<{ data: Usuario }>('/api/publico/cadastro', { method: 'POST', body: JSON.stringify(dados) }),
};
```

`frontend/features/cadastro/hooks/usePlanosPublicos.ts`:
```ts
import { useQuery } from '@tanstack/react-query';
import type { Plano } from '@/features/assinatura/types';
import { cadastroApi } from '../api';

export function usePlanosPublicos() {
  return useQuery<Plano[]>({
    queryKey: ['planos-publicos'],
    queryFn: async () => (await cadastroApi.planos()).data,
    staleTime: 10 * 60 * 1000,
  });
}
```

`frontend/features/cadastro/components/ListaDePlanos.tsx`:
```tsx
'use client';

import Link from 'next/link';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ILIMITADO, RECURSOS, ROTULO_MODULO, ROTULO_RECURSO, type Plano } from '@/features/assinatura/types';
import { formatarCentavos } from '@/lib/formatos';
import { usePlanosPublicos } from '../hooks/usePlanosPublicos';

function CartaoDePlano({ plano }: { plano: Plano }) {
  const limites = RECURSOS.filter((r) => plano.limites[r] !== 0);

  return (
    <Card className="flex flex-col">
      <CardHeader>
        <CardTitle><h2>{plano.nome}</h2></CardTitle>
        {plano.descricao ? <p className="text-sm text-muted-foreground">{plano.descricao}</p> : null}
      </CardHeader>
      <CardContent className="flex flex-1 flex-col gap-4">
        <p className="text-3xl font-semibold">
          {formatarCentavos(plano.preco_mensal_centavos)}
          <span className="text-sm font-normal text-muted-foreground">/mês</span>
        </p>
        {plano.dias_teste > 0 ? <p className="text-sm font-medium text-success">{plano.dias_teste} dias grátis</p> : null}
        <ul className="flex flex-wrap gap-2" aria-label="Módulos">
          {plano.modulos.map((m) => (
            <li key={m} className="rounded-full bg-accent px-2 py-0.5 text-xs">{ROTULO_MODULO[m]}</li>
          ))}
        </ul>
        <ul className="space-y-1 text-sm text-muted-foreground" aria-label="Limites">
          {limites.map((r) => (
            <li key={r}>{ROTULO_RECURSO[r]}: {plano.limites[r] === ILIMITADO ? 'ilimitado' : plano.limites[r]}</li>
          ))}
        </ul>
        <Link href={`/cadastro?plano=${plano.id}`} className={buttonVariants({ className: 'mt-auto w-full' })}>
          Começar
        </Link>
      </CardContent>
    </Card>
  );
}

export function ListaDePlanos() {
  const { data, isPending, isError, refetch } = usePlanosPublicos();

  if (isPending) return <p className="text-muted-foreground">Carregando planos...</p>;
  if (isError) {
    return (
      <div className="space-y-2 text-center">
        <p>Não foi possível carregar os planos.</p>
        <Button onClick={() => void refetch()}>Tentar novamente</Button>
      </div>
    );
  }
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {data.map((plano) => <CartaoDePlano key={plano.id} plano={plano} />)}
    </div>
  );
}
```

`frontend/features/cadastro/components/CadastroForm.tsx`:
```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useRef, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { QUERY_KEY_USUARIO } from '@/features/auth/hooks/useUsuario';
import { ApiError } from '@/lib/api';
import { mascararCnpj, normalizarCnpj, validarCnpj } from '@/lib/cnpj';
import { formatarCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { cadastroApi, type CadastroEntrada } from '../api';
import { forcaDaSenha } from '../forcaDaSenha';
import { usePlanosPublicos } from '../hooks/usePlanosPublicos';
import { CAMPOS_EMPRESA, cadastroSchema, type CadastroDados } from '../schemas';

const VALORES_INICIAIS: CadastroDados = {
  cnpj: '', razao_social: '', nome_fantasia: '', responsavel_nome: '', email: '',
  password: '', password_confirmation: '', aceite_termos: false,
};

export function CadastroForm({ planoId }: { planoId: string }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [etapa, setEtapa] = useState<1 | 2>(1);
  const [avisoCnpj, setAvisoCnpj] = useState<string | null>(null);
  const ultimoConsultado = useRef<string | null>(null);
  const planos = usePlanosPublicos();
  const plano = planos.data?.find((p) => p.id === planoId);
  const form = useForm<CadastroDados>({ resolver: zodResolver(cadastroSchema), defaultValues: VALORES_INICIAIS });

  const consulta = useMutation({
    mutationFn: (cnpj: string) => cadastroApi.consultarCnpj(cnpj),
    onSuccess: ({ data }) => {
      setAvisoCnpj(null);
      form.setValue('razao_social', data.razao_social, { shouldValidate: true });
      form.setValue('nome_fantasia', data.nome_fantasia ?? '');
    },
    // A consulta é só conveniência: qualquer falha libera o preenchimento manual.
    onError: (erro) =>
      setAvisoCnpj(
        erro instanceof ApiError && erro.status === 404
          ? 'CNPJ não encontrado na base pública. Preencha os dados da empresa.'
          : 'Não foi possível buscar os dados agora. Preencha manualmente.',
      ),
  });

  const cadastro = useMutation({
    mutationFn: (dados: CadastroEntrada) => cadastroApi.cadastrar(dados),
    onSuccess: ({ data }) => {
      queryClient.setQueryData(QUERY_KEY_USUARIO, data);
      router.push('/dashboard');
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError) || Object.keys(erro.errors).length === 0) {
        form.setError('root', {
          message: erro instanceof ApiError ? erro.message : 'Não foi possível concluir o cadastro. Tente novamente.',
        });
        return;
      }
      const campos = Object.keys(VALORES_INICIAIS);
      for (const [campo, mensagens] of Object.entries(erro.errors)) {
        if (campos.includes(campo)) form.setError(campo as keyof CadastroDados, { message: mensagens[0] });
        else form.setError('root', { message: mensagens[0] });
      }
      if (Object.keys(erro.errors).some((c) => (CAMPOS_EMPRESA as readonly string[]).includes(c))) setEtapa(1);
    },
  });

  function aoMudarCnpj(valor: string, onChange: (v: string) => void) {
    const mascarado = mascararCnpj(valor);
    onChange(mascarado);
    const normalizado = normalizarCnpj(mascarado);
    if (validarCnpj(normalizado) && ultimoConsultado.current !== normalizado) {
      ultimoConsultado.current = normalizado;
      consulta.mutate(normalizado);
    }
  }

  async function avancar() {
    if (await form.trigger([...CAMPOS_EMPRESA])) setEtapa(2);
  }

  const envioUnico = useEnvioUnico();
  const enviar = envioUnico(
    form.handleSubmit(async (d) => {
      await cadastro
        .mutateAsync({
          ...d,
          cnpj: normalizarCnpj(d.cnpj),
          nome_fantasia: d.nome_fantasia.trim() === '' ? null : d.nome_fantasia.trim(),
          plano_id: planoId,
        })
        .catch(() => undefined);
    }),
  );

  if (planos.isSuccess && !plano) {
    return (
      <div className="space-y-2 text-center">
        <p role="alert">Plano não encontrado ou indisponível.</p>
        <Link href="/planos" className="underline">Ver planos</Link>
      </div>
    );
  }

  const erros = form.formState.errors;
  const senha = form.watch('password');
  const forca = forcaDaSenha(senha);
  const enviando = form.formState.isSubmitting;

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      {plano ? (
        <p className="rounded-md bg-accent p-3 text-sm">
          Plano escolhido: <strong>{plano.nome}</strong>, {formatarCentavos(plano.preco_mensal_centavos)}/mês
          {plano.dias_teste > 0 ? ` · ${plano.dias_teste} dias grátis` : ''}.{' '}
          <Link href="/planos" className="underline">Trocar</Link>
        </p>
      ) : null}

      <p className="text-sm text-muted-foreground">Etapa {etapa} de 2: {etapa === 1 ? 'empresa' : 'responsável'}</p>

      {etapa === 1 ? (
        <>
          <Campo id="cnpj" label="CNPJ" erro={erros.cnpj?.message} dica={consulta.isPending ? 'Buscando dados...' : avisoCnpj ?? undefined}>
            {(a11y) => (
              <Controller
                control={form.control}
                name="cnpj"
                render={({ field }) => (
                  <Input
                    {...a11y}
                    ref={field.ref}
                    name={field.name}
                    value={field.value}
                    onBlur={field.onBlur}
                    onChange={(e) => aoMudarCnpj(e.target.value, field.onChange)}
                    autoComplete="off"
                    placeholder="00.000.000/0000-00"
                  />
                )}
              />
            )}
          </Campo>
          <Campo id="razao_social" label="Razão social" erro={erros.razao_social?.message}>
            {(a11y) => <Input {...a11y} autoComplete="organization" {...form.register('razao_social')} />}
          </Campo>
          <Campo id="nome_fantasia" label="Nome fantasia" erro={erros.nome_fantasia?.message}>
            {(a11y) => <Input {...a11y} {...form.register('nome_fantasia')} />}
          </Campo>
          <Button type="button" className="w-full" onClick={() => void avancar()}>Continuar</Button>
        </>
      ) : (
        <>
          <Campo id="responsavel_nome" label="Seu nome" erro={erros.responsavel_nome?.message}>
            {(a11y) => <Input {...a11y} autoComplete="name" {...form.register('responsavel_nome')} />}
          </Campo>
          <Campo id="email" label="E-mail" erro={erros.email?.message}>
            {(a11y) => <Input {...a11y} type="email" autoComplete="email" {...form.register('email')} />}
          </Campo>
          <Campo
            id="password"
            label="Senha"
            erro={erros.password?.message}
            dica={forca.rotulo ? `Força da senha: ${forca.rotulo}` : 'Mínimo de 8 caracteres, com maiúscula, minúscula e número.'}
          >
            {(a11y) => <Input {...a11y} type="password" autoComplete="new-password" {...form.register('password')} />}
          </Campo>
          <Campo id="password_confirmation" label="Confirmar senha" erro={erros.password_confirmation?.message}>
            {(a11y) => <Input {...a11y} type="password" autoComplete="new-password" {...form.register('password_confirmation')} />}
          </Campo>
          <div className="space-y-1">
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-1"
                aria-invalid={Boolean(erros.aceite_termos)}
                aria-describedby={erros.aceite_termos ? 'aceite_termos-erro' : undefined}
                {...form.register('aceite_termos')}
              />
              Li e aceito os termos de uso.
            </label>
            {erros.aceite_termos ? <p id="aceite_termos-erro" className="text-sm text-danger">{erros.aceite_termos.message}</p> : null}
          </div>
          <div className="flex gap-2">
            <Button type="button" variant="outline" onClick={() => setEtapa(1)}>Voltar</Button>
            <Button type="submit" className="flex-1" disabled={enviando}>{enviando ? 'Criando conta...' : 'Criar conta'}</Button>
          </div>
        </>
      )}

      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
    </form>
  );
}
```

`frontend/app/(publico)/layout.tsx`:
```tsx
import Link from 'next/link';
import type { ReactNode } from 'react';
import { ThemeToggle } from '@/components/theme/ThemeToggle';

export default function PublicoLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-dvh">
      <header className="flex h-14 items-center justify-between border-b px-4">
        <Link href="/planos" className="font-semibold">Plataforma</Link>
        <div className="flex items-center gap-2">
          <Link href="/login" className="text-sm text-muted-foreground hover:text-foreground">Entrar</Link>
          <ThemeToggle />
        </div>
      </header>
      <main className="mx-auto max-w-5xl p-4 md:p-8">{children}</main>
    </div>
  );
}
```

`frontend/app/(publico)/planos/page.tsx`:
```tsx
import { ListaDePlanos } from '@/features/cadastro/components/ListaDePlanos';

export default function PlanosPage() {
  return (
    <div className="space-y-6">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold">Escolha seu plano</h1>
        <p className="text-muted-foreground">Emita NF-e, NFC-e e NFS-e e organize seus clientes em um só lugar.</p>
      </div>
      <ListaDePlanos />
    </div>
  );
}
```

`frontend/app/(publico)/cadastro/page.tsx`:
```tsx
import Link from 'next/link';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CadastroForm } from '@/features/cadastro/components/CadastroForm';

export default async function CadastroPage({ searchParams }: { searchParams: Promise<{ plano?: string }> }) {
  const { plano } = await searchParams;

  return (
    <Card className="mx-auto max-w-md">
      <CardHeader>
        <CardTitle><h1>Criar conta</h1></CardTitle>
      </CardHeader>
      <CardContent>
        {plano ? (
          <CadastroForm planoId={plano} />
        ) : (
          <p>Escolha um plano para começar. <Link href="/planos" className="underline">Ver planos</Link></p>
        )}
      </CardContent>
    </Card>
  );
}
```

`frontend/app/(auth)/login/page.tsx`: abaixo do formulário, acrescentar
```tsx
<p className="mt-4 text-center text-sm text-muted-foreground">
  Ainda não tem conta? <Link href="/planos" className="underline">Conheça os planos</Link>
</p>
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `npm test && npm run typecheck && npm run lint && npm run build`
Esperado: tudo verde, e o build lista `/planos` e `/cadastro`.

- [ ] **Passo 5: commit**

```bash
git add -A frontend && git commit -m "feat: página de planos e cadastro em duas etapas com consulta de CNPJ"
```

---
### Tarefa 15: Área da plataforma (layout, painel e planos)

**Arquivos:**
- Criar:
  - `frontend/components/ui/campos-nativos.tsx` (`SelectNativo` e `TextareaNativo`)
  - `frontend/components/layout/nav-items.test.ts`
  - `frontend/features/admin/{types,api,planos}.ts` e `planos.test.ts`
  - `frontend/features/admin/components/{PainelMetricas,ListaDePlanosAdmin,PlanoForm,NovoPlano,EditarPlano}.tsx`
  - `frontend/features/admin/components/{PlanoForm,AdminPlanos}.test.tsx`
  - `frontend/app/(admin)/layout.tsx`
  - `frontend/app/(admin)/admin/page.tsx`
  - `frontend/app/(admin)/admin/planos/page.tsx`
  - `frontend/app/(admin)/admin/planos/novo/page.tsx`
  - `frontend/app/(admin)/admin/planos/[id]/page.tsx`
- Modificar:
  - `frontend/components/layout/{nav-items.ts,Sidebar.tsx,BottomNav.tsx,AppShell.tsx}`

**Interfaces:**
- Consome: `Plano`, `MODULOS`, `RECURSOS`, `ROTULO_*` e `formatos` (T13), `Campo` (T12) e a API de admin (T7, T8).
- Produz:
  - `NavItem.{exato?, papeis?}`, `estaAtivo(item, pathname)`, `itensVisiveis(itens, papel)` e `NAV_ITEMS_ADMIN`.
  - `<AppShell itens?>`.
  - `adminApi.{metricas, planos, plano, criarPlano, atualizarPlano, desativarPlano, empresas, empresa, mudarSituacao, trocarPlano}`.
  - Tipos `Metricas`, `Empresa`, `PaginaCursor<T>`, `PlanoEntrada`, `FiltrosEmpresas` e `Excesso`, mais a constante `SITUACOES`.
  - `planoFormSchema`, `planoParaForm(plano?)`, `formParaEntrada(form)` e `campoDoFormulario(campoDaApi)`.

- [ ] **Passo 1: testes que falham**

`frontend/components/layout/nav-items.test.ts`:
```ts
import { LayoutDashboard } from 'lucide-react';
import { describe, expect, it } from 'vitest';
import { estaAtivo, itensVisiveis, type NavItem } from './nav-items';

const painel: NavItem = { href: '/admin', label: 'Painel', icon: LayoutDashboard, exato: true };
const planos: NavItem = { href: '/admin/planos', label: 'Planos', icon: LayoutDashboard };
const restrito: NavItem = { href: '/x', label: 'X', icon: LayoutDashboard, papeis: ['ADMIN'] };

describe('nav-items', () => {
  it('item exato só fica ativo na própria rota', () => {
    expect(estaAtivo(painel, '/admin')).toBe(true);
    expect(estaAtivo(painel, '/admin/planos')).toBe(false);
    expect(estaAtivo(planos, '/admin/planos/123')).toBe(true);
  });

  it('item com papéis só aparece para esses papéis', () => {
    expect(itensVisiveis([planos, restrito], 'ADMIN')).toEqual([planos, restrito]);
    expect(itensVisiveis([planos, restrito], 'VENDEDOR')).toEqual([planos]);
    expect(itensVisiveis([planos, restrito], undefined)).toEqual([planos]);
  });
});
```

`frontend/features/admin/planos.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import type { Plano } from '@/features/assinatura/types';
import { formParaEntrada, planoFormSchema, planoParaForm } from './planos';

const plano: Plano = {
  id: 'p1', nome: 'Essencial', descricao: null, preco_mensal_centavos: 9990, preco_anual_centavos: null, dias_teste: 14,
  politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null, modulos: ['CRM'],
  limites: { USUARIOS: -1, CLIENTES: 500, PRODUTOS: 0, SERVICOS: 0, DOCUMENTOS_MES: 0, API_REQUISICOES_MIN: 0 },
  ativo: true, visivel: true, ordem: 1,
};

describe('planos (conversão do formulário)', () => {
  it('-1 vira "ilimitado" no formulário e volta como -1', () => {
    const form = planoParaForm(plano);
    expect(form.limites.USUARIOS).toEqual({ valor: 0, ilimitado: true });
    expect(form.preco_mensal).toBe('99,90');

    const entrada = formParaEntrada(form);
    expect(entrada.limites.USUARIOS).toBe(-1);
    expect(entrada.limites.CLIENTES).toBe(500);
    expect(entrada.preco_mensal_centavos).toBe(9990);
    expect(entrada.preco_anual_centavos).toBeNull();
    expect(entrada.descricao).toBeNull();
  });

  it('preço de excedente só é enviado com a política COBRAR', () => {
    const form = { ...planoParaForm(plano), preco_excedente: '0,10' };
    expect(formParaEntrada(form).preco_documento_excedente_centavos).toBeNull();
    expect(formParaEntrada({ ...form, politica_excedente: 'COBRAR' }).preco_documento_excedente_centavos).toBe(10);
  });

  it('COBRAR sem preço é inválido', () => {
    const resultado = planoFormSchema.safeParse({ ...planoParaForm(plano), politica_excedente: 'COBRAR', preco_excedente: '' });
    expect(resultado.success).toBe(false);
    expect(resultado.error?.issues[0]?.path).toEqual(['preco_excedente']);
  });
});
```

`frontend/features/admin/components/PlanoForm.test.tsx`:
```tsx
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type { PlanoEntrada } from '../types';
import { PlanoForm } from './PlanoForm';

describe('PlanoForm', () => {
  it('"Ilimitado" grava -1, o preço vira centavos e os módulos marcados vão juntos', async () => {
    const onSalvar = vi.fn().mockResolvedValue(undefined);
    render(<PlanoForm rotuloBotao="Criar plano" onSalvar={onSalvar} />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Essencial');
    await userEvent.type(screen.getByLabelText('Preço mensal (R$)'), '99,90');
    await userEvent.click(within(screen.getByRole('group', { name: 'Usuários' })).getByLabelText('Ilimitado'));
    const clientes = within(screen.getByRole('group', { name: 'Clientes' })).getByLabelText('Quantidade');
    await userEvent.clear(clientes);
    await userEvent.type(clientes, '500');
    await userEvent.click(screen.getByLabelText('NF-e'));
    await userEvent.click(screen.getByRole('button', { name: 'Criar plano' }));

    await waitFor(() => expect(onSalvar).toHaveBeenCalledTimes(1));
    const entrada = onSalvar.mock.calls[0][0] as PlanoEntrada;
    expect(entrada.preco_mensal_centavos).toBe(9990);
    expect(entrada.limites.USUARIOS).toBe(-1);
    expect(entrada.limites.CLIENTES).toBe(500);
    expect(entrada.limites.PRODUTOS).toBe(0);
    expect(entrada.modulos).toEqual(['FISCAL_NFE']);
  });

  it('cobrar excedente exige o preço por documento', async () => {
    const onSalvar = vi.fn();
    render(<PlanoForm rotuloBotao="Criar plano" onSalvar={onSalvar} />);

    await userEvent.type(screen.getByLabelText('Nome'), 'X');
    await userEvent.type(screen.getByLabelText('Preço mensal (R$)'), '10');
    await userEvent.selectOptions(screen.getByLabelText('Política de excedente'), 'COBRAR');
    await userEvent.click(screen.getByRole('button', { name: 'Criar plano' }));

    expect(await screen.findByLabelText('Preço por documento excedente (R$)')).toHaveAccessibleDescription(
      'Informe o preço por documento excedente.',
    );
    expect(onSalvar).not.toHaveBeenCalled();
  });

  it('erro de validação da API aparece no campo', async () => {
    const onSalvar = vi.fn().mockRejectedValue(new ApiError(422, 'x', { nome: ['O nome já está em uso.'] }));
    render(<PlanoForm rotuloBotao="Criar plano" onSalvar={onSalvar} />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Essencial');
    await userEvent.type(screen.getByLabelText('Preço mensal (R$)'), '10');
    await userEvent.click(screen.getByRole('button', { name: 'Criar plano' }));

    expect(await screen.findByLabelText('Nome')).toHaveAccessibleDescription('O nome já está em uso.');
  });
});
```

`frontend/features/admin/components/AdminPlanos.test.tsx`:
```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { adminApi } from '../api';
import { EditarPlano } from './EditarPlano';
import { PainelMetricas } from './PainelMetricas';

vi.mock('../api', () => ({ adminApi: { metricas: vi.fn(), plano: vi.fn() } }));

function renderizar(ui: React.ReactNode) {
  render(<QueryClientProvider client={new QueryClient()}>{ui}</QueryClientProvider>);
}

describe('área da plataforma', () => {
  it('painel mostra MRR e contagem por situação', async () => {
    vi.mocked(adminApi.metricas).mockResolvedValue({
      data: {
        por_situacao: { PENDENTE: 1, TESTE: 2, ATIVA: 3, INADIMPLENTE: 0, SUSPENSA: 4, CANCELADA: 0 },
        novas_30_dias: 5,
        mrr_centavos: 14900,
      },
    });
    renderizar(<PainelMetricas />);

    expect(await screen.findByText(/149,00/)).toBeInTheDocument();
    expect(screen.getByRole('group', { name: 'Suspensa' })).toHaveTextContent('4');
  });

  it('edição avisa quantas empresas usam o plano', async () => {
    vi.mocked(adminApi.plano).mockResolvedValue({
      data: {
        id: 'p1', nome: 'Essencial', descricao: null, preco_mensal_centavos: 9900, preco_anual_centavos: null, dias_teste: 0,
        politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null, modulos: [],
        limites: { USUARIOS: 3, CLIENTES: 0, PRODUTOS: 0, SERVICOS: 0, DOCUMENTOS_MES: 0, API_REQUISICOES_MIN: 0 },
        ativo: true, visivel: true, ordem: 0, empresas: 2,
      },
    });
    renderizar(<EditarPlano id="p1" />);

    expect(await screen.findByText('2 empresas usam este plano. As alterações valem para todas.')).toBeInTheDocument();
  });
});
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `npm test`
Esperado: FALHAM (módulos inexistentes).

- [ ] **Passo 3: implementar**

`frontend/components/layout/nav-items.ts`:
```ts
import { Building2, Gauge, LayoutDashboard, Package, type LucideIcon } from 'lucide-react';
import type { Papel } from '@/features/auth/types';

export interface NavItem {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Ativo só na própria rota (ex.: /admin não fica ativo em /admin/planos). */
  exato?: boolean;
  /** Sem papeis = visível para todos da área. */
  papeis?: Papel[];
}

/** Área da empresa. Cada fase acrescenta aqui os itens do seu módulo. */
export const NAV_ITEMS: NavItem[] = [{ href: '/dashboard', label: 'Início', icon: LayoutDashboard }];

export const NAV_ITEMS_ADMIN: NavItem[] = [
  { href: '/admin', label: 'Painel', icon: Gauge, exato: true },
  { href: '/admin/planos', label: 'Planos', icon: Package },
  { href: '/admin/empresas', label: 'Empresas', icon: Building2 },
];

export function estaAtivo(item: NavItem, pathname: string): boolean {
  return item.exato ? pathname === item.href : pathname.startsWith(item.href);
}

export function itensVisiveis(itens: NavItem[], papel: Papel | undefined): NavItem[] {
  return itens.filter((item) => !item.papeis || (papel !== undefined && item.papeis.includes(papel)));
}
```

`Sidebar.tsx` e `BottomNav.tsx`:
- Recebem `{ itens }: { itens: NavItem[] }`.
- Calculam `const { data: usuario } = useUsuario();` e `const visiveis = itensVisiveis(itens, usuario?.papel);`.
- Iteram sobre `visiveis`.
- Trocam `pathname.startsWith(href)` por `estaAtivo(item, pathname)`, desestruturando `item` no map.

`AppShell.tsx`:
```tsx
import type { ReactNode } from 'react';
import { BottomNav } from './BottomNav';
import { NAV_ITEMS, type NavItem } from './nav-items';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';

export function AppShell({ itens = NAV_ITEMS, children }: { itens?: NavItem[]; children: ReactNode }) {
  return (
    <div className="flex min-h-dvh">
      <Sidebar itens={itens} />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar />
        <main className="flex-1 p-4 pb-24 md:p-6 md:pb-6">{children}</main>
      </div>
      <BottomNav itens={itens} />
    </div>
  );
}
```

`frontend/components/ui/campos-nativos.tsx`:
```tsx
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const base =
  'w-full rounded-lg border border-input bg-transparent px-2.5 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30';

/** <select> nativo: acessível, leve e testável; o visual acompanha o Input. */
export function SelectNativo({ className, ...props }: ComponentProps<'select'>) {
  return <select className={cn(base, 'h-8', className)} {...props} />;
}

export function TextareaNativo({ className, ...props }: ComponentProps<'textarea'>) {
  return <textarea className={cn(base, 'py-1.5', className)} {...props} />;
}
```

`frontend/features/admin/types.ts`:
```ts
import type { ItemConsumo, Modulo, Recurso } from '@/features/assinatura/types';
import type { SituacaoAssinatura } from '@/features/auth/types';

export const SITUACOES = ['PENDENTE', 'TESTE', 'ATIVA', 'INADIMPLENTE', 'SUSPENSA', 'CANCELADA'] as const satisfies readonly SituacaoAssinatura[];

export interface Metricas {
  por_situacao: Record<SituacaoAssinatura, number>;
  novas_30_dias: number;
  mrr_centavos: number;
}

export interface Empresa {
  id: string;
  cnpj: string;
  razao_social: string;
  nome_fantasia: string | null;
  situacao: SituacaoAssinatura;
  teste_termina_em: string | null;
  situacao_alterada_em: string | null;
  plano: { id: string; nome: string; preco_mensal_centavos: number } | null;
  criada_em: string;
  consumo?: ItemConsumo[];
}

export interface PaginaCursor<T> {
  data: T[];
  meta: { next_cursor: string | null; prev_cursor: string | null; per_page: number };
}

export interface PlanoEntrada {
  nome: string;
  descricao: string | null;
  preco_mensal_centavos: number;
  preco_anual_centavos: number | null;
  dias_teste: number;
  politica_excedente: 'BLOQUEAR' | 'COBRAR';
  preco_documento_excedente_centavos: number | null;
  ativo: boolean;
  visivel: boolean;
  ordem: number;
  modulos: Modulo[];
  limites: Record<Recurso, number>;
}

export interface FiltrosEmpresas {
  situacao: SituacaoAssinatura | '';
  plano_id: string;
  busca: string;
}

export interface Excesso {
  recurso: Recurso;
  uso: number;
  limite: number;
}
```

`frontend/features/admin/api.ts`:
```ts
import type { Plano } from '@/features/assinatura/types';
import type { SituacaoAssinatura } from '@/features/auth/types';
import { api } from '@/lib/api';
import type { Empresa, FiltrosEmpresas, Metricas, PaginaCursor, PlanoEntrada } from './types';

const json = (metodo: string, corpo?: unknown): RequestInit => ({
  method: metodo,
  body: corpo === undefined ? undefined : JSON.stringify(corpo),
});

export const adminApi = {
  metricas: () => api<{ data: Metricas }>('/api/admin/metricas'),
  planos: () => api<{ data: Plano[] }>('/api/admin/planos'),
  plano: (id: string) => api<{ data: Plano }>(`/api/admin/planos/${id}`),
  criarPlano: (dados: PlanoEntrada) => api<{ data: Plano }>('/api/admin/planos', json('POST', dados)),
  atualizarPlano: (id: string, dados: PlanoEntrada) => api<{ data: Plano }>(`/api/admin/planos/${id}`, json('PUT', dados)),
  desativarPlano: (id: string) => api<{ data: Plano }>(`/api/admin/planos/${id}/desativar`, json('POST')),
  empresas: (filtros: FiltrosEmpresas, cursor?: string) => {
    const params = new URLSearchParams();
    for (const [chave, valor] of Object.entries({ ...filtros, cursor })) {
      if (valor) params.set(chave, valor);
    }
    const qs = params.toString();
    return api<PaginaCursor<Empresa>>(`/api/admin/empresas${qs ? `?${qs}` : ''}`);
  },
  empresa: (id: string) => api<{ data: Empresa }>(`/api/admin/empresas/${id}`),
  mudarSituacao: (id: string, dados: { situacao: SituacaoAssinatura; motivo: string }) =>
    api<{ data: Empresa }>(`/api/admin/empresas/${id}/situacao`, json('POST', dados)),
  trocarPlano: (id: string, dados: { plano_id: string }) =>
    api<{ data: Empresa }>(`/api/admin/empresas/${id}/plano`, json('POST', dados)),
};
```

`frontend/features/admin/planos.ts`:
```ts
import { z } from 'zod';
import { ILIMITADO, MODULOS, RECURSOS, type Plano, type Recurso } from '@/features/assinatura/types';
import { centavosParaReais, reaisParaCentavos } from '@/lib/formatos';
import type { PlanoEntrada } from './types';

const reais = z.string().trim().regex(/^\d{1,7}(,\d{1,2})?$/, 'Use o formato 99,90.');

const limite = z
  .object({ valor: z.number().or(z.nan()), ilimitado: z.boolean() })
  .refine((l) => l.ilimitado || (Number.isInteger(l.valor) && l.valor >= 0), {
    path: ['valor'],
    message: 'Informe um número inteiro a partir de 0.',
  });

const formaDosLimites = Object.fromEntries(RECURSOS.map((r) => [r, limite])) as Record<Recurso, typeof limite>;

export const planoFormSchema = z
  .object({
    nome: z.string().trim().min(1, 'Informe o nome.').max(60, 'Use até 60 caracteres.'),
    descricao: z.string().max(500, 'Use até 500 caracteres.'),
    preco_mensal: reais,
    preco_anual: z.union([z.literal(''), reais]),
    dias_teste: z.number({ error: 'Informe um número.' }).int().min(0).max(90, 'Use no máximo 90 dias.'),
    politica_excedente: z.enum(['BLOQUEAR', 'COBRAR']),
    preco_excedente: z.union([z.literal(''), reais]),
    ativo: z.boolean(),
    visivel: z.boolean(),
    ordem: z.number({ error: 'Informe um número.' }).int().min(0),
    modulos: z.array(z.enum(MODULOS)),
    limites: z.object(formaDosLimites),
  })
  .refine((d) => d.politica_excedente === 'BLOQUEAR' || d.preco_excedente !== '', {
    path: ['preco_excedente'],
    message: 'Informe o preço por documento excedente.',
  });

export type PlanoFormDados = z.infer<typeof planoFormSchema>;

export function planoParaForm(plano?: Plano): PlanoFormDados {
  const anual = plano?.preco_anual_centavos ?? null;
  const excedente = plano?.preco_documento_excedente_centavos ?? null;

  return {
    nome: plano?.nome ?? '',
    descricao: plano?.descricao ?? '',
    preco_mensal: plano ? centavosParaReais(plano.preco_mensal_centavos) : '',
    preco_anual: anual === null ? '' : centavosParaReais(anual),
    dias_teste: plano?.dias_teste ?? 0,
    politica_excedente: plano?.politica_excedente ?? 'BLOQUEAR',
    preco_excedente: excedente === null ? '' : centavosParaReais(excedente),
    ativo: plano?.ativo ?? true,
    visivel: plano?.visivel ?? true,
    ordem: plano?.ordem ?? 0,
    modulos: plano?.modulos ?? [],
    limites: Object.fromEntries(
      RECURSOS.map((r) => {
        const valor = plano?.limites[r] ?? 0;
        return [r, { valor: valor === ILIMITADO ? 0 : valor, ilimitado: valor === ILIMITADO }];
      }),
    ) as PlanoFormDados['limites'],
  };
}

export function formParaEntrada(form: PlanoFormDados): PlanoEntrada {
  const descricao = form.descricao.trim();

  return {
    nome: form.nome.trim(),
    descricao: descricao === '' ? null : descricao,
    preco_mensal_centavos: reaisParaCentavos(form.preco_mensal),
    preco_anual_centavos: form.preco_anual === '' ? null : reaisParaCentavos(form.preco_anual),
    dias_teste: form.dias_teste,
    politica_excedente: form.politica_excedente,
    preco_documento_excedente_centavos:
      form.politica_excedente === 'COBRAR' && form.preco_excedente !== '' ? reaisParaCentavos(form.preco_excedente) : null,
    ativo: form.ativo,
    visivel: form.visivel,
    ordem: form.ordem,
    modulos: form.modulos,
    limites: Object.fromEntries(
      RECURSOS.map((r) => [r, form.limites[r].ilimitado ? ILIMITADO : form.limites[r].valor]),
    ) as Record<Recurso, number>,
  };
}

const CAMPOS_DA_API: Record<string, keyof PlanoFormDados> = {
  nome: 'nome',
  descricao: 'descricao',
  preco_mensal_centavos: 'preco_mensal',
  preco_anual_centavos: 'preco_anual',
  dias_teste: 'dias_teste',
  preco_documento_excedente_centavos: 'preco_excedente',
  ordem: 'ordem',
};

/** Traduz o nome do campo no erro 422 da API para o campo do formulário. */
export function campoDoFormulario(campoDaApi: string): keyof PlanoFormDados | null {
  return CAMPOS_DA_API[campoDaApi] ?? null;
}
```

`frontend/features/admin/components/PlanoForm.tsx`:
```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo, TextareaNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { MODULOS, RECURSOS, ROTULO_MODULO, ROTULO_RECURSO, type Plano } from '@/features/assinatura/types';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { campoDoFormulario, formParaEntrada, planoFormSchema, planoParaForm, type PlanoFormDados } from '../planos';
import type { PlanoEntrada } from '../types';

interface Props {
  inicial?: Plano;
  rotuloBotao: string;
  onSalvar: (entrada: PlanoEntrada) => Promise<unknown>;
}

export function PlanoForm({ inicial, rotuloBotao, onSalvar }: Props) {
  const form = useForm<PlanoFormDados>({ resolver: zodResolver(planoFormSchema), defaultValues: planoParaForm(inicial) });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;
  const politica = form.watch('politica_excedente');
  const limites = form.watch('limites');

  const enviar = envioUnico(
    form.handleSubmit(async (dados) => {
      try {
        await onSalvar(formParaEntrada(dados));
      } catch (erro) {
        if (!(erro instanceof ApiError)) {
          form.setError('root', { message: 'Não foi possível salvar. Tente novamente.' });
          return;
        }
        const campos = Object.entries(erro.errors);
        if (campos.length === 0) form.setError('root', { message: erro.message });
        for (const [campo, mensagens] of campos) {
          form.setError(campoDoFormulario(campo) ?? 'root', { message: mensagens[0] });
        }
      }
    }),
  );

  return (
    <form onSubmit={enviar} noValidate className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo id="nome" label="Nome" erro={erros.nome?.message}>
          {(a11y) => <Input {...a11y} {...form.register('nome')} />}
        </Campo>
        <Campo id="ordem" label="Ordem de exibição" erro={erros.ordem?.message}>
          {(a11y) => <Input {...a11y} type="number" min={0} {...form.register('ordem', { valueAsNumber: true })} />}
        </Campo>
        <div className="sm:col-span-2">
          <Campo id="descricao" label="Descrição" erro={erros.descricao?.message}>
            {(a11y) => <TextareaNativo {...a11y} rows={2} {...form.register('descricao')} />}
          </Campo>
        </div>
        <Campo id="preco_mensal" label="Preço mensal (R$)" erro={erros.preco_mensal?.message}>
          {(a11y) => <Input {...a11y} inputMode="decimal" placeholder="99,90" {...form.register('preco_mensal')} />}
        </Campo>
        <Campo id="preco_anual" label="Preço anual (R$)" erro={erros.preco_anual?.message} dica="Opcional.">
          {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register('preco_anual')} />}
        </Campo>
        <Campo id="dias_teste" label="Dias de teste grátis" erro={erros.dias_teste?.message} dica="0 = sem teste: a conta nasce aguardando ativação.">
          {(a11y) => <Input {...a11y} type="number" min={0} max={90} {...form.register('dias_teste', { valueAsNumber: true })} />}
        </Campo>
        <Campo id="politica_excedente" label="Política de excedente" erro={erros.politica_excedente?.message}>
          {(a11y) => (
            <SelectNativo {...a11y} {...form.register('politica_excedente')}>
              <option value="BLOQUEAR">Bloquear ao atingir o limite</option>
              <option value="COBRAR">Cobrar por documento excedente</option>
            </SelectNativo>
          )}
        </Campo>
        {politica === 'COBRAR' ? (
          <Campo id="preco_excedente" label="Preço por documento excedente (R$)" erro={erros.preco_excedente?.message}>
            {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register('preco_excedente')} />}
          </Campo>
        ) : null}
      </div>

      <div className="flex flex-wrap gap-4 text-sm">
        <label className="flex items-center gap-2"><input type="checkbox" {...form.register('ativo')} /> Ativo</label>
        <label className="flex items-center gap-2"><input type="checkbox" {...form.register('visivel')} /> Visível na página de planos</label>
      </div>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Módulos</legend>
        <div className="flex flex-wrap gap-4 text-sm">
          {MODULOS.map((m) => (
            <label key={m} className="flex items-center gap-2">
              <input type="checkbox" value={m} {...form.register('modulos')} /> {ROTULO_MODULO[m]}
            </label>
          ))}
        </div>
      </fieldset>

      <fieldset className="space-y-3">
        <legend className="text-sm font-medium">Limites</legend>
        {RECURSOS.map((r) => {
          const erro = erros.limites?.[r]?.valor?.message;
          return (
            <div key={r} role="group" aria-label={ROTULO_RECURSO[r]} className="grid grid-cols-[1fr_7rem_auto] items-center gap-2">
              <span className="text-sm">{ROTULO_RECURSO[r]}</span>
              {limites[r].ilimitado ? (
                <span className="text-sm text-muted-foreground">Ilimitado</span>
              ) : (
                <Input
                  type="number"
                  min={0}
                  aria-label="Quantidade"
                  aria-invalid={Boolean(erro)}
                  {...form.register(`limites.${r}.valor`, { valueAsNumber: true })}
                />
              )}
              <label className="flex items-center gap-1 text-sm">
                <input type="checkbox" {...form.register(`limites.${r}.ilimitado`)} /> Ilimitado
              </label>
              {erro ? <p className="col-span-full text-sm text-danger">{erro}</p> : null}
            </div>
          );
        })}
        <p className="text-xs text-muted-foreground">0 bloqueia o recurso. Recursos de módulos ainda não lançados já podem ser configurados.</p>
      </fieldset>

      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? 'Salvando...' : rotuloBotao}
      </Button>
    </form>
  );
}
```

`frontend/features/admin/components/PainelMetricas.tsx`:
```tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { Card, CardContent } from '@/components/ui/card';
import { ROTULO_SITUACAO } from '@/features/assinatura/types';
import { formatarCentavos } from '@/lib/formatos';
import { adminApi } from '../api';
import { SITUACOES } from '../types';

function Metrica({ titulo, valor }: { titulo: string; valor: string }) {
  return (
    <Card role="group" aria-label={titulo}>
      <CardContent className="space-y-1 p-4">
        <p className="text-sm text-muted-foreground">{titulo}</p>
        <p className="text-2xl font-semibold">{valor}</p>
      </CardContent>
    </Card>
  );
}

export function PainelMetricas() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['admin', 'metricas'],
    queryFn: async () => (await adminApi.metricas()).data,
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar as métricas.</p>;

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Metrica titulo="MRR estimado" valor={formatarCentavos(data.mrr_centavos)} />
      <Metrica titulo="Novas em 30 dias" valor={String(data.novas_30_dias)} />
      {SITUACOES.map((s) => <Metrica key={s} titulo={ROTULO_SITUACAO[s]} valor={String(data.por_situacao[s])} />)}
    </div>
  );
}
```

`frontend/features/admin/components/ListaDePlanosAdmin.tsx`:
```tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { buttonVariants } from '@/components/ui/button';
import { formatarCentavos } from '@/lib/formatos';
import { adminApi } from '../api';

export function ListaDePlanosAdmin() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['admin', 'planos'],
    queryFn: async () => (await adminApi.planos()).data,
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <h1 className="text-xl font-semibold">Planos</h1>
        <Link href="/admin/planos/novo" className={buttonVariants()}>Novo plano</Link>
      </div>
      {isPending ? <p className="text-muted-foreground">Carregando...</p> : null}
      {isError ? <p role="alert">Não foi possível carregar os planos.</p> : null}
      {data ? (
        <ul className="divide-y rounded-lg border">
          {data.map((plano) => (
            <li key={plano.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
              <div className="min-w-0">
                <p className="font-medium">{plano.nome}</p>
                <p className="text-sm text-muted-foreground">
                  {formatarCentavos(plano.preco_mensal_centavos)}/mês · {plano.empresas ?? 0} empresa(s)
                  {plano.ativo ? '' : ' · desativado'}{plano.visivel ? '' : ' · oculto'}
                </p>
              </div>
              <Link href={`/admin/planos/${plano.id}`} className={buttonVariants({ variant: 'outline', size: 'sm' })}>
                Editar
              </Link>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
```

`frontend/features/admin/components/NovoPlano.tsx`:
```tsx
'use client';

import { useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { toast } from 'sonner';
import { adminApi } from '../api';
import { PlanoForm } from './PlanoForm';

export function NovoPlano() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">Novo plano</h1>
      <PlanoForm
        rotuloBotao="Criar plano"
        onSalvar={async (entrada) => {
          const { data } = await adminApi.criarPlano(entrada);
          void queryClient.invalidateQueries({ queryKey: ['admin', 'planos'] });
          toast.success('Plano criado.');
          router.push(`/admin/planos/${data.id}`);
        }}
      />
    </div>
  );
}
```

`frontend/features/admin/components/EditarPlano.tsx`:
```tsx
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { adminApi } from '../api';
import { PlanoForm } from './PlanoForm';

export function EditarPlano({ id }: { id: string }) {
  const queryClient = useQueryClient();
  const chave = ['admin', 'planos', id];
  const [confirmando, setConfirmando] = useState(false);
  const { data: plano, isPending, isError } = useQuery({ queryKey: chave, queryFn: async () => (await adminApi.plano(id)).data });

  const desativar = useMutation({
    mutationFn: () => adminApi.desativarPlano(id),
    onSuccess: ({ data }) => {
      queryClient.setQueryData(chave, data);
      void queryClient.invalidateQueries({ queryKey: ['admin', 'planos'], exact: true });
      toast.success('Plano desativado.');
      setConfirmando(false);
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar o plano.</p>;

  const empresas = plano.empresas ?? 0;

  return (
    <div className="max-w-3xl space-y-4">
      <h1 className="text-xl font-semibold">Editar plano</h1>
      {empresas > 0 ? (
        <p role="note" className="rounded-md bg-warning/10 p-3 text-sm text-warning">
          {empresas === 1 ? '1 empresa usa' : `${empresas} empresas usam`} este plano. As alterações valem para todas.
        </p>
      ) : null}
      <PlanoForm
        key={plano.id}
        inicial={plano}
        rotuloBotao="Salvar alterações"
        onSalvar={async (entrada) => {
          const { data } = await adminApi.atualizarPlano(id, entrada);
          queryClient.setQueryData(chave, data);
          void queryClient.invalidateQueries({ queryKey: ['admin', 'planos'], exact: true });
          toast.success('Plano salvo.');
        }}
      />
      {plano.ativo ? (
        <div className="flex flex-wrap items-center gap-2 border-t pt-4">
          {confirmando ? (
            <>
              <span className="text-sm">O plano sai da página de planos. As empresas atuais continuam nele.</span>
              <Button variant="destructive" onClick={() => desativar.mutate()} disabled={desativar.isPending}>Confirmar desativação</Button>
              <Button variant="ghost" onClick={() => setConfirmando(false)}>Cancelar</Button>
            </>
          ) : (
            <Button variant="outline" onClick={() => setConfirmando(true)}>Desativar plano</Button>
          )}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">Plano desativado: não aparece para novos clientes.</p>
      )}
    </div>
  );
}
```
Se o `Button` do projeto não tiver a variante `destructive`, use `outline` e registre um `Ruling`.

`frontend/app/(admin)/layout.tsx`:
```tsx
import type { ReactNode } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { AuthGuard } from '@/components/layout/AuthGuard';
import { ExigirArea } from '@/components/layout/ExigirArea';
import { NAV_ITEMS_ADMIN } from '@/components/layout/nav-items';

export default function AdminLayout({ children }: { children: ReactNode }) {
  return (
    <AuthGuard>
      <ExigirArea area="plataforma">
        <AppShell itens={NAV_ITEMS_ADMIN}>{children}</AppShell>
      </ExigirArea>
    </AuthGuard>
  );
}
```

`frontend/app/(admin)/admin/page.tsx`:
```tsx
import { PainelMetricas } from '@/features/admin/components/PainelMetricas';

export default function PainelPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Painel</h1>
      <PainelMetricas />
    </div>
  );
}
```

`frontend/app/(admin)/admin/planos/page.tsx`:
```tsx
import { ListaDePlanosAdmin } from '@/features/admin/components/ListaDePlanosAdmin';

export default function PlanosAdminPage() {
  return <ListaDePlanosAdmin />;
}
```

`frontend/app/(admin)/admin/planos/novo/page.tsx`:
```tsx
import { NovoPlano } from '@/features/admin/components/NovoPlano';

export default function NovoPlanoPage() {
  return <NovoPlano />;
}
```

`frontend/app/(admin)/admin/planos/[id]/page.tsx`:
```tsx
import { EditarPlano } from '@/features/admin/components/EditarPlano';

export default async function EditarPlanoPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <EditarPlano id={id} />;
}
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `npm test && npm run typecheck && npm run lint && npm run build`
Esperado: tudo verde, e o build lista `/admin`, `/admin/planos`, `/admin/planos/novo` e `/admin/planos/[id]`.

- [ ] **Passo 5: commit**

```bash
git add -A frontend && git commit -m "feat: área da plataforma com painel de métricas e cadastro de planos"
```

---

### Tarefa 16: Admin de empresas (lista, detalhe e ações)

**Arquivos:**
- Modificar: `frontend/lib/api.ts` e `frontend/lib/api.test.ts` (`ApiError.corpo` e `codigo`)
- Criar:
  - `frontend/features/admin/situacoes.ts` e `situacoes.test.ts`
  - `frontend/features/admin/components/{BadgeSituacao,ListaDeEmpresas,DetalheDaEmpresa,MudarSituacaoForm,TrocarPlanoForm}.tsx`
  - `frontend/features/admin/components/Empresas.test.tsx`
  - `frontend/app/(admin)/admin/empresas/page.tsx`
  - `frontend/app/(admin)/admin/empresas/[id]/page.tsx`

**Interfaces:**
- Consome: `adminApi`, os tipos e `SITUACOES` (T15), `ConsumoDoPlano` (T13), `mascararCnpj` (T14) e `SelectNativo`/`TextareaNativo` (T15).
- Produz:
  - `ApiError.corpo: Record<string, unknown>` e o getter `ApiError.codigo`.
  - `destinosPermitidos(situacao): SituacaoAssinatura[]`, espelho de `SituacaoAssinatura::podeIrPara`.

- [ ] **Passo 1: testes que falham**

Acrescentar em `frontend/lib/api.test.ts`:
```ts
  it('expõe o código e o corpo dos erros de negócio', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      resposta(422, { message: 'Excedido.', codigo: 'PLANO_EXCEDIDO', excessos: [{ recurso: 'USUARIOS', uso: 3, limite: 1 }] }),
    );

    const erro = await api('/api/admin/x').catch((e: unknown) => e);

    expect(erro).toBeInstanceOf(ApiError);
    expect((erro as ApiError).codigo).toBe('PLANO_EXCEDIDO');
    expect((erro as ApiError).corpo.excessos).toEqual([{ recurso: 'USUARIOS', uso: 3, limite: 1 }]);
  });
```

`frontend/features/admin/situacoes.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { destinosPermitidos } from './situacoes';

describe('destinosPermitidos (espelho da máquina de estados do backend)', () => {
  it('lista só as transições válidas', () => {
    expect(destinosPermitidos('PENDENTE')).toEqual(['ATIVA', 'CANCELADA']);
    expect(destinosPermitidos('TESTE')).toEqual(['ATIVA', 'SUSPENSA', 'CANCELADA']);
    expect(destinosPermitidos('ATIVA')).toEqual(['SUSPENSA', 'CANCELADA']);
    expect(destinosPermitidos('INADIMPLENTE')).toEqual(['SUSPENSA', 'CANCELADA']);
    expect(destinosPermitidos('SUSPENSA')).toEqual(['ATIVA', 'CANCELADA']);
    expect(destinosPermitidos('CANCELADA')).toEqual([]);
  });
});
```

`frontend/features/admin/components/Empresas.test.tsx`:
```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QUERY_KEY_USUARIO } from '@/features/auth/hooks/useUsuario';
import type { Papel } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { adminApi } from '../api';
import type { Empresa } from '../types';
import { DetalheDaEmpresa } from './DetalheDaEmpresa';
import { ListaDeEmpresas } from './ListaDeEmpresas';
import { MudarSituacaoForm } from './MudarSituacaoForm';
import { TrocarPlanoForm } from './TrocarPlanoForm';

vi.mock('../api', () => ({
  adminApi: { empresas: vi.fn(), empresa: vi.fn(), planos: vi.fn(), mudarSituacao: vi.fn(), trocarPlano: vi.fn() },
}));

const empresa: Empresa = {
  id: 'e1', cnpj: '11222333000181', razao_social: 'Padaria Pão Bom Ltda', nome_fantasia: 'Pão Bom', situacao: 'ATIVA',
  teste_termina_em: null, situacao_alterada_em: null, plano: { id: 'p1', nome: 'Essencial', preco_mensal_centavos: 9900 },
  criada_em: '2026-09-27T12:00:00+00:00', consumo: [{ recurso: 'USUARIOS', uso: 3, limite: 5 }],
};

function renderizar(ui: ReactNode, papel: Papel = 'SUPERADMIN') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  client.setQueryData(QUERY_KEY_USUARIO, { id: 'a', nome: 'Admin', email: 'a@x.com', papel, tenant: null });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('admin de empresas', () => {
  beforeEach(() => {
    vi.mocked(adminApi.planos).mockResolvedValue({ data: [] });
    vi.mocked(adminApi.empresas).mockResolvedValue({ data: [empresa], meta: { next_cursor: null, prev_cursor: null, per_page: 20 } });
  });

  it('lista empresas e filtra por situação', async () => {
    renderizar(<ListaDeEmpresas />);

    expect((await screen.findAllByText('Padaria Pão Bom Ltda')).length).toBeGreaterThan(0);
    expect(screen.getAllByText('11.222.333/0001-81').length).toBeGreaterThan(0);

    await userEvent.selectOptions(screen.getByLabelText('Situação'), 'SUSPENSA');
    await waitFor(() =>
      expect(adminApi.empresas).toHaveBeenLastCalledWith({ situacao: 'SUSPENSA', plano_id: '', busca: '' }, undefined),
    );
  });

  it('mudar situação exige motivo e envia a transição', async () => {
    vi.mocked(adminApi.mudarSituacao).mockResolvedValue({ data: { ...empresa, situacao: 'SUSPENSA' } });
    renderizar(<MudarSituacaoForm empresa={empresa} />);

    await userEvent.selectOptions(screen.getByLabelText('Nova situação'), 'SUSPENSA');
    await userEvent.click(screen.getByRole('button', { name: 'Aplicar' }));
    expect(await screen.findByLabelText('Motivo')).toHaveAccessibleDescription('Descreva o motivo (mínimo de 3 caracteres).');
    expect(adminApi.mudarSituacao).not.toHaveBeenCalled();

    await userEvent.type(screen.getByLabelText('Motivo'), 'Inadimplência');
    await userEvent.click(screen.getByRole('button', { name: 'Aplicar' }));
    await waitFor(() => expect(adminApi.mudarSituacao).toHaveBeenCalledWith('e1', { situacao: 'SUSPENSA', motivo: 'Inadimplência' }));
  });

  it('troca de plano com excesso lista o que passou do limite', async () => {
    vi.mocked(adminApi.planos).mockResolvedValue({
      data: [{
        id: 'p2', nome: 'Básico', descricao: null, preco_mensal_centavos: 4900, preco_anual_centavos: null, dias_teste: 0,
        politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null, modulos: [],
        limites: { USUARIOS: 1, CLIENTES: 0, PRODUTOS: 0, SERVICOS: 0, DOCUMENTOS_MES: 0, API_REQUISICOES_MIN: 0 },
        ativo: true, visivel: true, ordem: 0,
      }],
    });
    vi.mocked(adminApi.trocarPlano).mockRejectedValue(
      new ApiError(422, 'O uso atual da empresa passa os limites do plano escolhido.', {}, {
        codigo: 'PLANO_EXCEDIDO', excessos: [{ recurso: 'USUARIOS', uso: 3, limite: 1 }],
      }),
    );
    renderizar(<TrocarPlanoForm empresa={empresa} />);

    await userEvent.selectOptions(await screen.findByLabelText('Novo plano'), 'p2');
    await userEvent.click(screen.getByRole('button', { name: 'Trocar plano' }));

    const alerta = await screen.findByRole('alert');
    expect(alerta).toHaveTextContent('O uso atual da empresa passa os limites do plano escolhido.');
    expect(alerta).toHaveTextContent('Usuários: uso 3, limite 1');
  });

  it('suporte só consulta: não vê as ações', async () => {
    vi.mocked(adminApi.empresa).mockResolvedValue({ data: empresa });
    renderizar(<DetalheDaEmpresa id="e1" />, 'SUPORTE');

    expect(await screen.findByText('Seu perfil de suporte só permite consultar.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Aplicar' })).not.toBeInTheDocument();
    expect(screen.getByText('3 de 5')).toBeInTheDocument();
  });
});
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `npm test`
Esperado: FALHAM (`codigo` indefinido e módulos inexistentes).

- [ ] **Passo 3: implementar**

`frontend/lib/api.ts`:
- A classe `ApiError` ganha o quarto parâmetro e o getter.
- O `api()` repassa o corpo.

```ts
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: Record<string, string[]> = {},
    /** Corpo completo da resposta: erros de negócio trazem `codigo` e extras (ex.: `excessos`). */
    public readonly corpo: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  get codigo(): string | undefined {
    return typeof this.corpo.codigo === 'string' ? this.corpo.codigo : undefined;
  }

  /** Primeira mensagem de validação, ou a mensagem geral. */
  primeiraMensagem(): string {
    const primeira = Object.values(this.errors)[0]?.[0];
    return primeira ?? this.message;
  }
}
```
No `api()`:
```ts
  const corpo = (await resposta.json().catch(() => ({}))) as Record<string, unknown> & {
    message?: string;
    errors?: Record<string, string[]>;
  };
  if (!resposta.ok) {
    throw new ApiError(resposta.status, corpo.message ?? 'Erro inesperado. Tente novamente.', corpo.errors ?? {}, corpo);
  }
  return corpo as T;
```

`frontend/features/admin/situacoes.ts`:
```ts
import type { SituacaoAssinatura } from '@/features/auth/types';

/** Espelho de SituacaoAssinatura::podeIrPara (backend). A regra que vale é a do backend; aqui só se montam as opções. */
const TRANSICOES: Record<SituacaoAssinatura, SituacaoAssinatura[]> = {
  PENDENTE: ['ATIVA'],
  TESTE: ['ATIVA', 'SUSPENSA'],
  ATIVA: ['SUSPENSA'],
  INADIMPLENTE: ['SUSPENSA'],
  SUSPENSA: ['ATIVA'],
  CANCELADA: [],
};

export function destinosPermitidos(situacao: SituacaoAssinatura): SituacaoAssinatura[] {
  return situacao === 'CANCELADA' ? [] : [...TRANSICOES[situacao], 'CANCELADA'];
}
```

`frontend/features/admin/components/BadgeSituacao.tsx`:
```tsx
import { ROTULO_SITUACAO } from '@/features/assinatura/types';
import type { SituacaoAssinatura } from '@/features/auth/types';
import { cn } from '@/lib/utils';

const TOM: Record<SituacaoAssinatura, string> = {
  PENDENTE: 'bg-muted text-foreground',
  TESTE: 'bg-info/10 text-info',
  ATIVA: 'bg-success/10 text-success',
  INADIMPLENTE: 'bg-warning/10 text-warning',
  SUSPENSA: 'bg-danger/10 text-danger',
  CANCELADA: 'bg-danger/10 text-danger',
};

export function BadgeSituacao({ situacao }: { situacao: SituacaoAssinatura }) {
  return <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', TOM[situacao])}>{ROTULO_SITUACAO[situacao]}</span>;
}
```

`frontend/features/admin/components/ListaDeEmpresas.tsx`:
```tsx
'use client';

import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { useState } from 'react';
import { Button, buttonVariants } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { ROTULO_SITUACAO } from '@/features/assinatura/types';
import { mascararCnpj } from '@/lib/cnpj';
import { formatarData } from '@/lib/formatos';
import { adminApi } from '../api';
import { SITUACOES, type FiltrosEmpresas } from '../types';
import { BadgeSituacao } from './BadgeSituacao';

export function ListaDeEmpresas() {
  const [filtros, setFiltros] = useState<FiltrosEmpresas>({ situacao: '', plano_id: '', busca: '' });
  const [busca, setBusca] = useState('');
  const planos = useQuery({ queryKey: ['admin', 'planos'], queryFn: async () => (await adminApi.planos()).data });
  const consulta = useInfiniteQuery({
    queryKey: ['admin', 'empresas', filtros],
    queryFn: ({ pageParam }) => adminApi.empresas(filtros, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (pagina) => pagina.meta.next_cursor ?? undefined,
  });
  const empresas = consulta.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Empresas</h1>
      <form
        role="search"
        className="grid gap-2 sm:grid-cols-[1fr_12rem_12rem_auto]"
        onSubmit={(e) => {
          e.preventDefault();
          setFiltros((f) => ({ ...f, busca: busca.trim() }));
        }}
      >
        <Input aria-label="Buscar por CNPJ ou nome" placeholder="CNPJ ou nome" value={busca} onChange={(e) => setBusca(e.target.value)} />
        <SelectNativo
          aria-label="Situação"
          value={filtros.situacao}
          onChange={(e) => setFiltros((f) => ({ ...f, situacao: e.target.value as FiltrosEmpresas['situacao'] }))}
        >
          <option value="">Todas as situações</option>
          {SITUACOES.map((s) => <option key={s} value={s}>{ROTULO_SITUACAO[s]}</option>)}
        </SelectNativo>
        <SelectNativo aria-label="Plano" value={filtros.plano_id} onChange={(e) => setFiltros((f) => ({ ...f, plano_id: e.target.value }))}>
          <option value="">Todos os planos</option>
          {planos.data?.map((p) => <option key={p.id} value={p.id}>{p.nome}</option>)}
        </SelectNativo>
        <Button type="submit">Buscar</Button>
      </form>

      {consulta.isPending ? <p className="text-muted-foreground">Carregando...</p> : null}
      {consulta.isError ? <p role="alert">Não foi possível carregar as empresas.</p> : null}
      {consulta.isSuccess && empresas.length === 0 ? <p className="text-muted-foreground">Nenhuma empresa encontrada.</p> : null}

      {empresas.length > 0 ? (
        <>
          <table className="hidden w-full text-sm md:table">
            <thead className="text-left text-muted-foreground">
              <tr><th className="p-2">Empresa</th><th className="p-2">CNPJ</th><th className="p-2">Plano</th><th className="p-2">Situação</th><th className="p-2">Criada em</th><th className="p-2"><span className="sr-only">Ações</span></th></tr>
            </thead>
            <tbody className="divide-y">
              {empresas.map((e) => (
                <tr key={e.id}>
                  <td className="p-2"><p className="font-medium">{e.razao_social}</p>{e.nome_fantasia ? <p className="text-muted-foreground">{e.nome_fantasia}</p> : null}</td>
                  <td className="p-2">{mascararCnpj(e.cnpj)}</td>
                  <td className="p-2">{e.plano?.nome ?? '—'}</td>
                  <td className="p-2"><BadgeSituacao situacao={e.situacao} /></td>
                  <td className="p-2">{formatarData(e.criada_em)}</td>
                  <td className="p-2 text-right"><Link href={`/admin/empresas/${e.id}`} className={buttonVariants({ variant: 'outline', size: 'sm' })}>Ver</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
          <ul className="space-y-2 md:hidden">
            {empresas.map((e) => (
              <li key={e.id} className="space-y-1 rounded-lg border p-3">
                <div className="flex items-start justify-between gap-2">
                  <p className="font-medium">{e.razao_social}</p>
                  <BadgeSituacao situacao={e.situacao} />
                </div>
                <p className="text-sm text-muted-foreground">{mascararCnpj(e.cnpj)} · {e.plano?.nome ?? 'sem plano'}</p>
                <Link href={`/admin/empresas/${e.id}`} className="text-sm underline">Ver detalhes</Link>
              </li>
            ))}
          </ul>
        </>
      ) : null}

      {consulta.hasNextPage ? (
        <Button variant="outline" onClick={() => void consulta.fetchNextPage()} disabled={consulta.isFetchingNextPage}>
          {consulta.isFetchingNextPage ? 'Carregando...' : 'Carregar mais'}
        </Button>
      ) : null}
    </div>
  );
}
```

`frontend/features/admin/components/MudarSituacaoForm.tsx`:
```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo, TextareaNativo } from '@/components/ui/campos-nativos';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ROTULO_SITUACAO } from '@/features/assinatura/types';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { adminApi } from '../api';
import { destinosPermitidos } from '../situacoes';
import { SITUACOES, type Empresa } from '../types';

const schema = z.object({
  situacao: z.enum(SITUACOES, { error: 'Escolha a nova situação.' }),
  motivo: z.string().trim().min(3, 'Descreva o motivo (mínimo de 3 caracteres).').max(500, 'Use até 500 caracteres.'),
});
type Dados = z.infer<typeof schema>;

export function MudarSituacaoForm({ empresa }: { empresa: Empresa }) {
  const queryClient = useQueryClient();
  const destinos = destinosPermitidos(empresa.situacao);
  const form = useForm<Dados>({ resolver: zodResolver(schema), defaultValues: { motivo: '' } });
  const mudar = useMutation({
    mutationFn: (dados: Dados) => adminApi.mudarSituacao(empresa.id, dados),
    onSuccess: () => {
      toast.success('Situação atualizada.');
      form.reset({ motivo: '' });
      void queryClient.invalidateQueries({ queryKey: ['admin', 'empresas'] });
      void queryClient.invalidateQueries({ queryKey: ['admin', 'metricas'] });
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Tente novamente.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <Card>
      <CardHeader><CardTitle>Mudar situação</CardTitle></CardHeader>
      <CardContent>
        {destinos.length === 0 ? (
          <p className="text-sm text-muted-foreground">Conta cancelada: a situação não muda mais.</p>
        ) : (
          <form
            noValidate
            className="space-y-3"
            onSubmit={envioUnico(form.handleSubmit(async (d) => { await mudar.mutateAsync(d).catch(() => undefined); }))}
          >
            <Campo id="situacao" label="Nova situação" erro={erros.situacao?.message}>
              {(a11y) => (
                <SelectNativo {...a11y} defaultValue="" {...form.register('situacao')}>
                  <option value="" disabled>Escolha...</option>
                  {destinos.map((s) => <option key={s} value={s}>{ROTULO_SITUACAO[s]}</option>)}
                </SelectNativo>
              )}
            </Campo>
            <Campo id="motivo" label="Motivo" erro={erros.motivo?.message}>
              {(a11y) => <TextareaNativo {...a11y} rows={3} {...form.register('motivo')} />}
            </Campo>
            {erros.root ? <p role="alert" className="text-sm text-danger">{erros.root.message}</p> : null}
            <Button type="submit" disabled={form.formState.isSubmitting}>Aplicar</Button>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
```

`frontend/features/admin/components/TrocarPlanoForm.tsx`:
```tsx
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ROTULO_RECURSO } from '@/features/assinatura/types';
import { ApiError } from '@/lib/api';
import { formatarCentavos } from '@/lib/formatos';
import { adminApi } from '../api';
import type { Empresa, Excesso } from '../types';

export function TrocarPlanoForm({ empresa }: { empresa: Empresa }) {
  const queryClient = useQueryClient();
  const [planoId, setPlanoId] = useState('');
  const [falha, setFalha] = useState<{ mensagem: string; excessos: Excesso[] } | null>(null);
  const planos = useQuery({ queryKey: ['admin', 'planos'], queryFn: async () => (await adminApi.planos()).data });
  const opcoes = planos.data?.filter((p) => p.ativo && p.id !== empresa.plano?.id) ?? [];

  const trocar = useMutation({
    mutationFn: () => adminApi.trocarPlano(empresa.id, { plano_id: planoId }),
    onSuccess: () => {
      setFalha(null);
      setPlanoId('');
      toast.success('Plano trocado.');
      void queryClient.invalidateQueries({ queryKey: ['admin', 'empresas'] });
      void queryClient.invalidateQueries({ queryKey: ['admin', 'metricas'] });
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        setFalha({ mensagem: 'Tente novamente.', excessos: [] });
        return;
      }
      const excessos = erro.codigo === 'PLANO_EXCEDIDO' && Array.isArray(erro.corpo.excessos) ? (erro.corpo.excessos as Excesso[]) : [];
      setFalha({ mensagem: erro.primeiraMensagem(), excessos });
    },
  });

  return (
    <Card>
      <CardHeader><CardTitle>Trocar plano</CardTitle></CardHeader>
      <CardContent className="space-y-3">
        <Campo id="novo_plano" label="Novo plano">
          {(a11y) => (
            <SelectNativo {...a11y} value={planoId} onChange={(e) => setPlanoId(e.target.value)}>
              <option value="">Escolha...</option>
              {opcoes.map((p) => <option key={p.id} value={p.id}>{p.nome} ({formatarCentavos(p.preco_mensal_centavos)}/mês)</option>)}
            </SelectNativo>
          )}
        </Campo>
        {falha ? (
          <div role="alert" className="space-y-1 rounded-md bg-danger/10 p-2 text-sm text-danger">
            <p>{falha.mensagem}</p>
            {falha.excessos.length > 0 ? (
              <ul className="list-inside list-disc">
                {falha.excessos.map((x) => <li key={x.recurso}>{ROTULO_RECURSO[x.recurso]}: uso {x.uso}, limite {x.limite}</li>)}
              </ul>
            ) : null}
          </div>
        ) : null}
        <Button onClick={() => trocar.mutate()} disabled={!planoId || trocar.isPending}>Trocar plano</Button>
      </CardContent>
    </Card>
  );
}
```

`frontend/features/admin/components/DetalheDaEmpresa.tsx`:
```tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ConsumoDoPlano } from '@/features/assinatura/components/ConsumoDoPlano';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { mascararCnpj } from '@/lib/cnpj';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { adminApi } from '../api';
import { BadgeSituacao } from './BadgeSituacao';
import { MudarSituacaoForm } from './MudarSituacaoForm';
import { TrocarPlanoForm } from './TrocarPlanoForm';

export function DetalheDaEmpresa({ id }: { id: string }) {
  const { data: usuario } = useUsuario();
  const { data: empresa, isPending, isError } = useQuery({
    queryKey: ['admin', 'empresas', id],
    queryFn: async () => (await adminApi.empresa(id)).data,
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar a empresa.</p>;

  const podeAgir = usuario?.papel === 'SUPERADMIN';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <h1 className="text-xl font-semibold">{empresa.nome_fantasia ?? empresa.razao_social}</h1>
        <BadgeSituacao situacao={empresa.situacao} />
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>Dados</CardTitle></CardHeader>
          <CardContent>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
              <dt className="text-muted-foreground">Razão social</dt><dd>{empresa.razao_social}</dd>
              <dt className="text-muted-foreground">CNPJ</dt><dd>{mascararCnpj(empresa.cnpj)}</dd>
              <dt className="text-muted-foreground">Plano</dt>
              <dd>{empresa.plano ? `${empresa.plano.nome} (${formatarCentavos(empresa.plano.preco_mensal_centavos)}/mês)` : 'Sem plano'}</dd>
              {empresa.teste_termina_em ? (<><dt className="text-muted-foreground">Teste até</dt><dd>{formatarData(empresa.teste_termina_em)}</dd></>) : null}
              <dt className="text-muted-foreground">Criada em</dt><dd>{formatarData(empresa.criada_em)}</dd>
            </dl>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Consumo</CardTitle></CardHeader>
          <CardContent><ConsumoDoPlano itens={empresa.consumo ?? []} /></CardContent>
        </Card>
        {podeAgir ? (
          <>
            <MudarSituacaoForm empresa={empresa} />
            <TrocarPlanoForm empresa={empresa} />
          </>
        ) : (
          <p className="text-sm text-muted-foreground">Seu perfil de suporte só permite consultar.</p>
        )}
      </div>
    </div>
  );
}
```

`frontend/app/(admin)/admin/empresas/page.tsx`:
```tsx
import { ListaDeEmpresas } from '@/features/admin/components/ListaDeEmpresas';

export default function EmpresasPage() {
  return <ListaDeEmpresas />;
}
```

`frontend/app/(admin)/admin/empresas/[id]/page.tsx`:
```tsx
import { DetalheDaEmpresa } from '@/features/admin/components/DetalheDaEmpresa';

export default async function EmpresaPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <DetalheDaEmpresa id={id} />;
}
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `npm test && npm run typecheck && npm run lint && npm run build`
Esperado: tudo verde.

- [ ] **Passo 5: commit**

```bash
git add -A frontend && git commit -m "feat: admin de empresas com filtros, consumo, mudança de situação e troca de plano"
```

---

### Tarefa 17: Usuários da empresa e definição de senha pelo convite

**Arquivos:**
- Criar:
  - `frontend/features/usuarios/{types,api,schemas}.ts` e `types.test.ts`
  - `frontend/features/usuarios/components/{ListaDeUsuarios,ConviteForm,EditarUsuarioForm}.tsx`
  - `frontend/features/usuarios/components/Usuarios.test.tsx`
  - `frontend/app/(app)/configuracoes/usuarios/page.tsx`
  - `frontend/app/(auth)/definir-senha/page.tsx`
- Modificar:
  - `frontend/features/auth/api.ts` (`aceitarConvite`)
  - `frontend/features/auth/components/RedefinirSenhaForm.tsx` e `RedefinirSenhaForm.test.tsx` (`modo`)
  - `frontend/components/layout/nav-items.ts` (item "Usuários")
  - `frontend/components/layout/AppShell.test.tsx`

**Interfaces:**
- Consome: a API de usuários (T10), `Campo` (T12), `SelectNativo` e `itensVisiveis` (T15).
- Produz:
  - `papeisAtribuiveis(autor: Papel): PapelDaEmpresa[]` e `podeGerenciar(papel): boolean`.
  - `usuariosApi` e `authApi.aceitarConvite`.
  - `RedefinirSenhaForm` com a prop `modo?: 'redefinir' | 'convite'`.

- [ ] **Passo 1: testes que falham**

`frontend/features/usuarios/types.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { papeisAtribuiveis, podeGerenciar } from './types';

describe('papéis', () => {
  it('só o proprietário concede o papel de proprietário', () => {
    expect(papeisAtribuiveis('PROPRIETARIO')).toEqual(['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA']);
    expect(papeisAtribuiveis('ADMIN')).toEqual(['ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA']);
    expect(papeisAtribuiveis('VENDEDOR')).toEqual([]);
  });

  it('só proprietário e administrador gerenciam', () => {
    expect(podeGerenciar('PROPRIETARIO')).toBe(true);
    expect(podeGerenciar('ADMIN')).toBe(true);
    expect(podeGerenciar('FISCAL')).toBe(false);
  });
});
```

`frontend/features/usuarios/components/Usuarios.test.tsx`:
```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Usuario } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { usuariosApi } from '../api';
import { ConviteForm } from './ConviteForm';
import { ListaDeUsuarios } from './ListaDeUsuarios';

vi.mock('../api', () => ({
  QUERY_KEY_USUARIOS: ['usuarios'],
  usuariosApi: { listar: vi.fn(), convidar: vi.fn(), editar: vi.fn(), desativar: vi.fn(), reativar: vi.fn() },
}));

const autor: Usuario = {
  id: 'a', nome: 'Admin', email: 'admin@x.com', papel: 'ADMIN',
  tenant: { id: 't', razao_social: 'X', nome_fantasia: null, cnpj: '11222333000181', situacao: 'ATIVA', teste_termina_em: null },
};

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('usuários da empresa', () => {
  beforeEach(() => {
    vi.mocked(usuariosApi.listar).mockResolvedValue({
      data: [
        { id: 'd', nome: 'Dona', email: 'dona@x.com', papel: 'PROPRIETARIO', ativo: true },
        { id: 'a', nome: 'Admin', email: 'admin@x.com', papel: 'ADMIN', ativo: true },
        { id: 'b', nome: 'Bia', email: 'bia@x.com', papel: 'VENDEDOR', ativo: true },
      ],
    });
  });

  it('proprietário sem ações; o próprio usuário não se desativa; os demais sim', async () => {
    vi.mocked(usuariosApi.desativar).mockResolvedValue({ data: { id: 'b', nome: 'Bia', email: 'bia@x.com', papel: 'VENDEDOR', ativo: false } });
    renderizar(<ListaDeUsuarios autor={autor} />);

    const dona = (await screen.findByText('Dona')).closest('li');
    expect(dona).not.toBeNull();
    expect(within(dona as HTMLElement).queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Desativar Admin' })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Desativar Bia' }));
    await waitFor(() => expect(usuariosApi.desativar).toHaveBeenCalledWith('b'));
  });

  it('admin não vê a opção de proprietário no convite', () => {
    renderizar(<ConviteForm autor="ADMIN" />);

    const opcoes = within(screen.getByLabelText('Papel')).getAllByRole('option').map((o) => o.textContent);
    expect(opcoes).not.toContain('Proprietário');
    expect(opcoes).toContain('Fiscal');
  });

  it('convite enviado limpa o formulário', async () => {
    vi.mocked(usuariosApi.convidar).mockResolvedValue({ data: { id: 'n', nome: 'Caio', email: 'caio@x.com', papel: 'FISCAL', ativo: true } });
    renderizar(<ConviteForm autor="ADMIN" />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Caio');
    await userEvent.type(screen.getByLabelText('E-mail'), 'caio@x.com');
    await userEvent.selectOptions(screen.getByLabelText('Papel'), 'FISCAL');
    await userEvent.click(screen.getByRole('button', { name: 'Enviar convite' }));

    await waitFor(() => expect(usuariosApi.convidar).toHaveBeenCalledWith({ nome: 'Caio', email: 'caio@x.com', papel: 'FISCAL' }));
    await waitFor(() => expect(screen.getByLabelText('Nome')).toHaveValue(''));
  });

  it('limite do plano aparece como alerta', async () => {
    vi.mocked(usuariosApi.convidar).mockRejectedValue(
      new ApiError(422, 'Seu plano permite até 3 usuários ativos.', {}, { codigo: 'LIMITE_DO_PLANO' }),
    );
    renderizar(<ConviteForm autor="ADMIN" />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Caio');
    await userEvent.type(screen.getByLabelText('E-mail'), 'caio@x.com');
    await userEvent.click(screen.getByRole('button', { name: 'Enviar convite' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Seu plano permite até 3 usuários ativos.');
  });
});
```

Em `RedefinirSenhaForm.test.tsx`:
- No `vi.mock('../api', ...)`, incluir `aceitarConvite: vi.fn()`.
- Acrescentar:
```tsx
  it('no modo convite, define a senha pelo endpoint de convite', async () => {
    vi.mocked(authApi.aceitarConvite).mockResolvedValue({ message: 'Senha definida. Faça login para entrar.' });
    render(
      <QueryClientProvider client={new QueryClient()}>
        <RedefinirSenhaForm token="abc" email="bia@x.com" modo="convite" />
      </QueryClientProvider>,
    );

    await userEvent.type(screen.getByLabelText('Nova senha'), 'Senha123');
    await userEvent.type(screen.getByLabelText('Confirmar nova senha'), 'Senha123');
    await userEvent.click(screen.getByRole('button', { name: 'Definir senha' }));

    await waitFor(() => expect(authApi.aceitarConvite).toHaveBeenCalledWith({
      token: 'abc', email: 'bia@x.com', password: 'Senha123', password_confirmation: 'Senha123',
    }));
    expect(authApi.redefinirSenha).not.toHaveBeenCalled();
  });
```
(importar `waitFor`).

Em `AppShell.test.tsx`, acrescentar:
```tsx
  it('mostra "Usuários" só para proprietário e administrador', () => {
    const client = new QueryClient();
    client.setQueryData(QUERY_KEY_USUARIO, {
      id: '1', nome: 'Vera', email: 'v@x.com', papel: 'VENDEDOR',
      tenant: { id: 't', razao_social: 'X', nome_fantasia: null, cnpj: '11222333000181', situacao: 'ATIVA', teste_termina_em: null },
    });
    render(<QueryClientProvider client={client}><AppShell><p>página</p></AppShell></QueryClientProvider>);

    expect(screen.queryByRole('link', { name: /Usuários/ })).not.toBeInTheDocument();
  });
```
No teste existente (usuário `PROPRIETARIO`), acrescentar:
```tsx
    expect(within(lateral).getByRole('link', { name: /Usuários/ })).toHaveAttribute('href', '/configuracoes/usuarios');
```

- [ ] **Passo 2: rodar e ver falhar**

Comando: `npm test`
Esperado: FALHAM (módulos inexistentes, `modo` ignorado e item de navegação ausente).

- [ ] **Passo 3: implementar**

`frontend/features/usuarios/types.ts`:
```ts
import type { Papel } from '@/features/auth/types';

export type PapelDaEmpresa = Extract<Papel, 'PROPRIETARIO' | 'ADMIN' | 'FISCAL' | 'VENDEDOR' | 'LEITURA'>;

export const ROTULO_PAPEL: Record<PapelDaEmpresa, string> = {
  PROPRIETARIO: 'Proprietário', ADMIN: 'Administrador', FISCAL: 'Fiscal', VENDEDOR: 'Vendedor', LEITURA: 'Somente leitura',
};

export interface UsuarioDaEmpresa {
  id: string;
  nome: string;
  email: string;
  papel: PapelDaEmpresa;
  ativo: boolean;
}

/** Espelho da PoliticaDeUsuarios (backend), só para montar as opções. */
export function papeisAtribuiveis(autor: Papel): PapelDaEmpresa[] {
  const comuns: PapelDaEmpresa[] = ['ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'];
  if (autor === 'PROPRIETARIO') return ['PROPRIETARIO', ...comuns];
  if (autor === 'ADMIN') return comuns;
  return [];
}

export function podeGerenciar(papel: Papel): boolean {
  return papel === 'PROPRIETARIO' || papel === 'ADMIN';
}
```

`frontend/features/usuarios/schemas.ts`:
```ts
import { z } from 'zod';

const papel = z.enum(['PROPRIETARIO', 'ADMIN', 'FISCAL', 'VENDEDOR', 'LEITURA'], { error: 'Escolha o papel.' });
const nome = z.string().trim().min(1, 'Informe o nome.').max(120, 'Use até 120 caracteres.');

export const conviteSchema = z.object({ nome, email: z.string().trim().email('Informe um e-mail válido.'), papel });
export const edicaoSchema = z.object({ nome, papel });

export type ConviteDados = z.infer<typeof conviteSchema>;
export type EdicaoDados = z.infer<typeof edicaoSchema>;
```

`frontend/features/usuarios/api.ts`:
```ts
import { api } from '@/lib/api';
import type { ConviteDados, EdicaoDados } from './schemas';
import type { UsuarioDaEmpresa } from './types';

export const QUERY_KEY_USUARIOS = ['usuarios'] as const;

type Resposta = { data: UsuarioDaEmpresa };

export const usuariosApi = {
  listar: () => api<{ data: UsuarioDaEmpresa[] }>('/api/app/usuarios'),
  convidar: (dados: ConviteDados) => api<Resposta>('/api/app/usuarios', { method: 'POST', body: JSON.stringify(dados) }),
  editar: (id: string, dados: EdicaoDados) => api<Resposta>(`/api/app/usuarios/${id}`, { method: 'PUT', body: JSON.stringify(dados) }),
  desativar: (id: string) => api<Resposta>(`/api/app/usuarios/${id}/desativar`, { method: 'POST' }),
  reativar: (id: string) => api<Resposta>(`/api/app/usuarios/${id}/reativar`, { method: 'POST' }),
};
```

`frontend/features/usuarios/components/ConviteForm.tsx`:
```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import type { Papel } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { QUERY_KEY_USUARIOS, usuariosApi } from '../api';
import { conviteSchema, type ConviteDados } from '../schemas';
import { papeisAtribuiveis, ROTULO_PAPEL } from '../types';

const VAZIO: ConviteDados = { nome: '', email: '', papel: 'VENDEDOR' };

export function ConviteForm({ autor }: { autor: Papel }) {
  const queryClient = useQueryClient();
  const form = useForm<ConviteDados>({ resolver: zodResolver(conviteSchema), defaultValues: VAZIO });
  const convidar = useMutation({
    mutationFn: (dados: ConviteDados) => usuariosApi.convidar(dados),
    onSuccess: (_resposta, dados) => {
      toast.success(`Convite enviado para ${dados.email}.`);
      form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_USUARIOS });
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        form.setError('root', { message: 'Não foi possível enviar o convite. Tente novamente.' });
        return;
      }
      const campos = Object.entries(erro.errors);
      if (campos.length === 0) form.setError('root', { message: erro.message });
      for (const [campo, mensagens] of campos) {
        const alvo = campo === 'nome' || campo === 'email' || campo === 'papel' ? campo : 'root';
        form.setError(alvo, { message: mensagens[0] });
      }
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form
      noValidate
      className="space-y-3"
      onSubmit={envioUnico(form.handleSubmit(async (d) => { await convidar.mutateAsync(d).catch(() => undefined); }))}
    >
      <Campo id="convite_nome" label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} autoComplete="off" {...form.register('nome')} />}
      </Campo>
      <Campo id="convite_email" label="E-mail" erro={erros.email?.message}>
        {(a11y) => <Input {...a11y} type="email" autoComplete="off" {...form.register('email')} />}
      </Campo>
      <Campo id="convite_papel" label="Papel" erro={erros.papel?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('papel')}>
            {papeisAtribuiveis(autor).map((p) => <option key={p} value={p}>{ROTULO_PAPEL[p]}</option>)}
          </SelectNativo>
        )}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? 'Enviando...' : 'Enviar convite'}
      </Button>
      <p className="text-xs text-muted-foreground">O convidado recebe um link, válido por 72 horas, para definir a senha.</p>
    </form>
  );
}
```

`frontend/features/usuarios/components/EditarUsuarioForm.tsx`:
```tsx
'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import type { Papel } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { QUERY_KEY_USUARIOS, usuariosApi } from '../api';
import { edicaoSchema, type EdicaoDados } from '../schemas';
import { papeisAtribuiveis, ROTULO_PAPEL, type UsuarioDaEmpresa } from '../types';

interface Props {
  usuario: UsuarioDaEmpresa;
  autor: Papel;
  onFechar: () => void;
}

export function EditarUsuarioForm({ usuario, autor, onFechar }: Props) {
  const queryClient = useQueryClient();
  const form = useForm<EdicaoDados>({ resolver: zodResolver(edicaoSchema), defaultValues: { nome: usuario.nome, papel: usuario.papel } });
  const editar = useMutation({
    mutationFn: (dados: EdicaoDados) => usuariosApi.editar(usuario.id, dados),
    onSuccess: () => {
      toast.success('Usuário atualizado.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_USUARIOS });
      onFechar();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Tente novamente.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form
      noValidate
      className="mt-2 grid gap-2 sm:grid-cols-[1fr_12rem_auto_auto] sm:items-end"
      onSubmit={envioUnico(form.handleSubmit(async (d) => { await editar.mutateAsync(d).catch(() => undefined); }))}
    >
      <Campo id={`nome-${usuario.id}`} label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id={`papel-${usuario.id}`} label="Papel" erro={erros.papel?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('papel')}>
            {papeisAtribuiveis(autor).map((p) => <option key={p} value={p}>{ROTULO_PAPEL[p]}</option>)}
          </SelectNativo>
        )}
      </Campo>
      <Button type="submit" disabled={form.formState.isSubmitting}>Salvar</Button>
      <Button type="button" variant="ghost" onClick={onFechar}>Cancelar</Button>
      {erros.root ? <p role="alert" className="text-sm text-danger sm:col-span-4">{erros.root.message}</p> : null}
    </form>
  );
}
```

`frontend/features/usuarios/components/ListaDeUsuarios.tsx`:
```tsx
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import type { Usuario } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { QUERY_KEY_USUARIOS, usuariosApi } from '../api';
import { ROTULO_PAPEL, type UsuarioDaEmpresa } from '../types';
import { EditarUsuarioForm } from './EditarUsuarioForm';

export function ListaDeUsuarios({ autor }: { autor: Usuario }) {
  const queryClient = useQueryClient();
  const [editando, setEditando] = useState<string | null>(null);
  const { data, isPending, isError } = useQuery({
    queryKey: QUERY_KEY_USUARIOS,
    queryFn: async () => (await usuariosApi.listar()).data,
  });

  const alternar = useMutation({
    mutationFn: (u: UsuarioDaEmpresa) => (u.ativo ? usuariosApi.desativar(u.id) : usuariosApi.reativar(u.id)),
    onSuccess: ({ data: u }) => {
      toast.success(u.ativo ? `${u.nome} foi reativado.` : `${u.nome} foi desativado.`);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_USUARIOS });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar os usuários.</p>;

  return (
    <ul className="divide-y">
      {data.map((u) => {
        const ehProprietario = u.papel === 'PROPRIETARIO';
        const ehVoce = u.id === autor.id;
        return (
          <li key={u.id} className="py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="min-w-0">
                <p className="font-medium">{u.nome}{ehVoce ? ' (você)' : ''}</p>
                <p className="truncate text-sm text-muted-foreground">
                  {u.email} · {ROTULO_PAPEL[u.papel]}{u.ativo ? '' : ' · inativo'}
                </p>
              </div>
              {ehProprietario ? (
                <span className="text-xs text-muted-foreground">Proprietário da conta</span>
              ) : (
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" aria-label={`Editar ${u.nome}`} onClick={() => setEditando(u.id)}>Editar</Button>
                  {ehVoce ? null : (
                    <Button
                      variant="outline"
                      size="sm"
                      aria-label={`${u.ativo ? 'Desativar' : 'Reativar'} ${u.nome}`}
                      disabled={alternar.isPending}
                      onClick={() => alternar.mutate(u)}
                    >
                      {u.ativo ? 'Desativar' : 'Reativar'}
                    </Button>
                  )}
                </div>
              )}
            </div>
            {editando === u.id ? <EditarUsuarioForm usuario={u} autor={autor.papel} onFechar={() => setEditando(null)} /> : null}
          </li>
        );
      })}
    </ul>
  );
}
```

`frontend/app/(app)/configuracoes/usuarios/page.tsx`:
```tsx
'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ConviteForm } from '@/features/usuarios/components/ConviteForm';
import { ListaDeUsuarios } from '@/features/usuarios/components/ListaDeUsuarios';
import { podeGerenciar } from '@/features/usuarios/types';

export default function UsuariosPage() {
  const { data: usuario } = useUsuario();

  if (!usuario) return null;
  if (!podeGerenciar(usuario.papel)) {
    return <p role="alert">Você não tem permissão para gerenciar usuários.</p>;
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Usuários</h1>
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader><CardTitle>Equipe</CardTitle></CardHeader>
          <CardContent><ListaDeUsuarios autor={usuario} /></CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Convidar usuário</CardTitle></CardHeader>
          <CardContent><ConviteForm autor={usuario.papel} /></CardContent>
        </Card>
      </div>
    </div>
  );
}
```

`nav-items.ts`: `NAV_ITEMS` passa a ser
```ts
export const NAV_ITEMS: NavItem[] = [
  { href: '/dashboard', label: 'Início', icon: LayoutDashboard },
  { href: '/configuracoes/usuarios', label: 'Usuários', icon: Users, papeis: ['PROPRIETARIO', 'ADMIN'] },
];
```
Importar `Users` de `lucide-react`.

`frontend/features/auth/api.ts`: acrescentar em `authApi`
```ts
  aceitarConvite: (dados: { token: string; email: string; password: string; password_confirmation: string }) =>
    api<Mensagem>('/api/app/auth/aceitar-convite', { method: 'POST', body: JSON.stringify(dados) }),
```

`RedefinirSenhaForm.tsx`:
- A assinatura passa a ser `({ token, email, modo = 'redefinir' }: { token: string; email: string; modo?: 'redefinir' | 'convite' })`.
- `mutationFn: (dados: RedefinirSenhaDados) => (modo === 'convite' ? authApi.aceitarConvite(dados) : authApi.redefinirSenha(dados))`.
- O rótulo do botão fica `modo === 'convite' ? 'Definir senha' : 'Redefinir senha'`.
- Nos dois avisos de link inválido (o de `!token || !email` e o de `erros.token || erros.email`):
  - Modo convite: texto "Convite inválido ou expirado. Peça um novo convite ao administrador.", sem o link "Solicitar novo link".
  - Modo redefinir: continua como está.

`frontend/app/(auth)/definir-senha/page.tsx`:
```tsx
import { AuthCard } from '@/features/auth/components/AuthCard';
import { RedefinirSenhaForm } from '@/features/auth/components/RedefinirSenhaForm';

export default async function DefinirSenhaPage({
  searchParams,
}: {
  searchParams: Promise<{ token?: string; email?: string }>;
}) {
  const { token = '', email = '' } = await searchParams;

  return (
    <AuthCard titulo="Defina sua senha" descricao="Você foi convidado. Crie sua senha para entrar.">
      <RedefinirSenhaForm token={token} email={email} modo="convite" />
    </AuthCard>
  );
}
```

- [ ] **Passo 4: rodar e ver passar**

Comando: `npm test && npm run typecheck && npm run lint && npm run build`
Esperado: tudo verde.

- [ ] **Passo 5: commit**

```bash
git add -A frontend && git commit -m "feat: gestão de usuários da empresa e definição de senha pelo convite"
```

---

### Tarefa 18: Fechamento da F1

**Arquivos:**
- Modificar: `PROGRESSO.md`, `TAREFAS.md` e `frontend/README.md` (se ele descrever as rotas)

- [ ] **Passo 1: suítes completas**

```bash
cd backend && php artisan test && vendor/bin/pint --test && composer analyse && composer audit
cd ../frontend && npm test && npm run typecheck && npm run lint && npm run build && npm audit --audit-level=high
```
Esperado: tudo verde. Anote no ledger as contagens de testes do backend e do frontend.

- [ ] **Passo 2: fumaça ponta a ponta com curl** (critério de pronto da spec mestre)

Com `php artisan migrate:fresh --seed` e `php artisan serve` rodando:
1. Logar como `admin@plataforma.local` (fluxo CSRF + login, igual à F0) e criar um plano com `USUARIOS = 1` via `POST /api/admin/planos`.
2. `POST /api/publico/cadastro` com esse plano, com `dias_teste = 0` → `201` e situação `PENDENTE`.
3. Como admin, `POST /api/admin/empresas/{id}/situacao` com `{"situacao": "ATIVA", "motivo": "Ativação manual"}` → `200`.
4. Como o dono recém-cadastrado, `POST /api/app/usuarios` → `422` `LIMITE_DO_PLANO` (o dono já ocupa a vaga).

Registre os status obtidos no ledger. Esse roteiro é exatamente o critério de pronto: "o admin cria um plano, um tenant é ativado manualmente e um limite é respeitado".

- [ ] **Passo 3: documentação de controle**

- `TAREFAS.md`: marcar a F1 e as 8 pendências herdadas como concluídas. Registrar ali os minors adiados da revisão final.
- `PROGRESSO.md`: F1 concluída. A próxima tarefa é a spec da F2 (cadastros e configuração fiscal do emitente). Reescrever "Contexto necessário" para a F2.

```bash
git add PROGRESSO.md TAREFAS.md && git commit -m "docs: F1 concluída"
```
