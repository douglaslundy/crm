# F5a Cobrança (núcleo) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução Native, escolhida pelo usuário). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Assinatura paga pelo Mercado Pago (cartão por `preapproval`, boleto por agendamento próprio), webhook com HMAC, conciliação idempotente, inadimplência e suspensão, API e telas, tudo atrás de uma interface de gateway com falso nos testes.

**Architecture:** Novo módulo `app/Modules/Billing`. As Actions (`IniciarAssinatura`, `ConciliarPagamento`, `ReceberWebhook`, `CancelarAssinatura`) e os comandos diários (`GerarBoletosDoCiclo`, `AplicarInadimplencia`) usam `GatewayDeCobranca`. Quem ativa, reativa e suspende o tenant é o `MudarSituacaoDaEmpresa` existente.

**Tech Stack:** Laravel 12, PHPUnit, Pint, PHPStan; Next.js, React, zod, react-hook-form, TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-07-f5a-cobranca-design.md`

## Global Constraints

- Valores em centavos inteiros. Nunca chutar valor: plano sem preço do ciclo recusa o ciclo. Zero é válido (`=== null`, nunca `empty()`/`?? 0`).
- Valor e descrição do pagamento vêm sempre do servidor. Todo envio ao Mercado Pago leva `X-Idempotency-Key`.
- Webhook: HMAC com **falha fechada**; só o status consultado na API vale; evento duplicado não refaz.
- Todo Model de tenant tem `BelongsToTenant` + teste de isolamento; todo endpoint novo tem teste de isolamento. Schema só por migration. Segredos só em variável de ambiente. `declare(strict_types=1)`. TS strict, sem `any`.
- Frontend e API usam as mesmas Actions; nenhuma regra no controller.
- Comandos: backend `cd backend && php artisan test --filter=<Nome>`; suíte `php artisan test --compact`; `vendor/bin/pint`; `vendor/bin/phpstan analyse --no-progress`. Frontend `cd frontend && npx vitest run <arquivo>`, `npx tsc --noEmit`, `npm run lint`, `npm run build`.
- Os arquivos do projeto têm fim de linha CRLF: o `sed` com âncora de fim de linha falha em silêncio; para editar arquivos existentes use a ferramenta Edit. Em testes, importe toda classe usada (um `use` faltando só aparece em tempo de execução).
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs`. Sem push.
- Erros de negócio: `BillingException` (abaixo) estende `ErroDeNegocio` (`status()`, `codigo()`).

## Rulings já tomados no plano (contra o spec)

- O spec lista `criarPagamentoDeCartao`; **não existe**: o `preapproval` com `card_token_id` cobra o primeiro ciclo, então o cartão só usa `criarAssinaturaDeCartao`. O primeiro pagamento chega por webhook.
- Como o pagamento recorrente do cartão pode chegar sem o `external_reference` da nossa cobrança, a conciliação acha a cobrança nesta ordem: (1) por `gateway_pagamento_id`; (2) por `referenciaExterna` (id da cobrança); (3) pelo `assinaturaGatewayId`: a cobrança pendente mais antiga da assinatura, ou uma nova do próximo período. O que o MP realmente envia fica para o roteiro de sandbox.
- Checkout, faturas e cancelamento: só `PROPRIETARIO` e `ADMIN` (informação financeira).

## Review Focus

- Webhook com corpo "aprovado" mas gateway "pendente" não ativa o tenant (Task 6).
- Mesmo pagamento conciliado duas vezes não estende o período duas vezes (Task 4).
- Pagamento tardio de cobrança já `VENCIDA` reativa o tenant `SUSPENSA` (Task 4).
- Limites exatos das carências (1 e 10 dias) e fuso do negócio (Task 7).
- Falha do gateway em `IniciarAssinatura` não deixa assinatura `PENDENTE` órfã nem cobrança aberta (Task 5).
- Segredo do webhook vazio recusa tudo (Task 6).

## File Structure

- `backend/app/Modules/Tenancy/Domain/Enums/SituacaoAssinatura.php` (transições)
- `backend/config/billing.php`; `backend/database/migrations/2026_10_09_000001_create_billing_tables.php`
- `backend/app/Modules/Billing/Domain/{Enums,Models,Contracts,Exceptions,Gateway}/...`, `Domain/PeriodoPago.php`
- `backend/app/Modules/Billing/Application/{Actions,Console}/...`, `Application/AssinaturaDoTenant.php`
- `backend/app/Modules/Billing/Infrastructure/{MercadoPagoGateway,VerificadorDeAssinaturaDoWebhook}.php`
- `backend/app/Modules/Billing/Http/{Controllers,Requests,Resources}/...`, `routes-app.php`, `routes-webhooks.php`
- `backend/app/Modules/Billing/Providers/BillingServiceProvider.php`; `backend/bootstrap/providers.php`; `backend/routes/console.php`
- `backend/tests/Fixtures/{GatewayDeCobrancaFake,CenarioDeCobranca}.php`
- `frontend/features/cobranca/{types.ts,api.ts,schemas.ts,components/...}`, páginas `app/(app)/assinatura/{planos,faturas}/page.tsx`

---

### Task 1: Transições da situação da assinatura

**Files:**
- Modify: `backend/app/Modules/Tenancy/Domain/Enums/SituacaoAssinatura.php` (`podeIrPara`)
- Test: `backend/tests/Unit/Tenancy/SituacaoAssinaturaTest.php` (procurar o teste existente com `grep -rl "podeIrPara" backend/tests` e acrescentar nele; se não houver, criar este)

**Interfaces:**
- Produces: `SituacaoAssinatura::podeIrPara` passa a aceitar `Ativa → Inadimplente`, `Inadimplente → Ativa`, `Suspensa → Ativa` (já existe), `Teste → Ativa` (já existe), `Pendente → Ativa` (já existe). `Cancelada` continua de qualquer estado exceto dela mesma.

- [ ] **Step 1: Teste da tabela inteira (RED)**

```php
public function test_tabela_de_transicoes_da_cobranca(): void
{
    $permitidas = [
        'PENDENTE' => ['ATIVA', 'CANCELADA'],
        'TESTE' => ['ATIVA', 'SUSPENSA', 'CANCELADA'],
        'ATIVA' => ['INADIMPLENTE', 'SUSPENSA', 'CANCELADA'],
        'INADIMPLENTE' => ['ATIVA', 'SUSPENSA', 'CANCELADA'],
        'SUSPENSA' => ['ATIVA', 'CANCELADA'],
        'CANCELADA' => [],
    ];
    foreach (SituacaoAssinatura::cases() as $origem) {
        foreach (SituacaoAssinatura::cases() as $destino) {
            $this->assertSame(
                in_array($destino->value, $permitidas[$origem->value], true),
                $origem->podeIrPara($destino),
                "{$origem->value} → {$destino->value}",
            );
        }
    }
}
```

- [ ] **Step 2:** `php artisan test --filter=SituacaoAssinaturaTest`. Expected: FAIL em `ATIVA → INADIMPLENTE`.
- [ ] **Step 3:** Em `podeIrPara` trocar `self::Ativa, self::Inadimplente => [self::Suspensa]` por `self::Ativa => [self::Inadimplente, self::Suspensa], self::Inadimplente => [self::Ativa, self::Suspensa]` (com Edit; manter o resto).
- [ ] **Step 4:** `php artisan test --filter="SituacaoAssinatura|MudarSituacao|Empresa"`. Expected: PASS (nenhum teste existente dependia da proibição; se algum dependia, é o teste que codifica a regra antiga: ajuste e ledgue um Ruling).
- [ ] **Step 5:** pint, commit `feat(billing): transições ativa↔inadimplente da situação da assinatura`.

---

### Task 2: Módulo Billing: dados, Models, config e provider

**Files:**
- Create: `backend/config/billing.php`, `backend/database/migrations/2026_10_09_000001_create_billing_tables.php`, `backend/app/Modules/Billing/Domain/Enums/{CicloDeCobranca,MetodoDePagamento,StatusDaAssinatura,TipoDeCobranca,StatusDaCobranca}.php`, `Domain/Models/{Assinatura,Cobranca,WebhookRecebido}.php`, `Domain/Exceptions/BillingException.php`, `Providers/BillingServiceProvider.php`, `backend/database/factories/{AssinaturaFactory,CobrancaFactory}.php`, `backend/tests/Feature/Billing/BillingIsolamentoTest.php`
- Modify: `backend/bootstrap/providers.php`

**Interfaces:**
- Produces:
  - `CicloDeCobranca` (`Mensal='MENSAL'`, `Anual='ANUAL'`): `meses(): int`; `precoDo(Plano $plano): int` (lança `BillingException('BILLING_CICLO_INDISPONIVEL', ...)` se o preço do ciclo for `null` ou `<= 0`).
  - `MetodoDePagamento` (`Cartao='CARTAO'`, `Boleto='BOLETO'`), `StatusDaAssinatura` (`Pendente`, `Ativa`, `Cancelada`), `TipoDeCobranca` (`Assinatura='ASSINATURA'`), `StatusDaCobranca` (`Pendente`, `Paga`, `Vencida`, `Cancelada`).
  - `Assinatura` (campos do spec; `BelongsToTenant`, `HasUuids`; fillable vazio, só `forceFill`; casts de enums e `paga_ate` date; `plano(): BelongsTo`, `cobrancas(): HasMany`).
  - `Cobranca` (campos do spec; mesmos traços; `assinatura(): BelongsTo`).
  - `WebhookRecebido` (sem tenant; `gateway_evento_id` único).
  - `BillingException(string $codigo, string $mensagem, int $status = 422)`.

- [ ] **Step 1: Teste de isolamento e do ciclo (RED)**

```php
// tests/Feature/Billing/BillingIsolamentoTest.php (use RefreshDatabase; criar Tenant A com Plano mensal 10000, anual 100000 e Tenant B)
public function test_assinatura_e_cobranca_sao_isoladas_por_tenant(): void { /* cria no tenant A, troca TenantContext para B, count()==0 e find()==null, nos dois Models */ }

public function test_ciclo_define_meses_e_preco_do_plano(): void
{
    $plano = Plano::factory()->create(['preco_mensal_centavos' => 10000, 'preco_anual_centavos' => 100000]);
    $this->assertSame([1, 12], [CicloDeCobranca::Mensal->meses(), CicloDeCobranca::Anual->meses()]);
    $this->assertSame([10000, 100000], [CicloDeCobranca::Mensal->precoDo($plano), CicloDeCobranca::Anual->precoDo($plano)]);
}

public function test_plano_sem_preco_do_ciclo_recusa_o_ciclo(): void
{
    $plano = Plano::factory()->create(['preco_mensal_centavos' => 10000, 'preco_anual_centavos' => null]);
    try {
        CicloDeCobranca::Anual->precoDo($plano);
        $this->fail('deveria recusar');
    } catch (BillingException $e) {
        $this->assertSame('BILLING_CICLO_INDISPONIVEL', $e->codigo());
    }
}
```

Antes de escrever, abrir `PlanoFactory::definition` para saber se `preco_anual_centavos` é nulável e como o isolamento de `NotaIsolamentoTest` monta os dois tenants (copiar o mesmo molde).

- [ ] **Step 2:** `php artisan test --filter=BillingIsolamentoTest`. Expected: FAIL (classes inexistentes).
- [ ] **Step 3: Implementar.** Migration (`foreignUuid('tenant_id')->constrained('tenants')`, `foreignUuid('plano_id')->constrained('planos')`, índices em `(tenant_id, status)` e `gateway_pagamento_id` único, `webhooks_recebidos.gateway_evento_id` único):

```php
Schema::create('assinaturas', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('tenant_id')->constrained('tenants');
    $table->foreignUuid('plano_id')->constrained('planos');
    $table->string('ciclo', 10);
    $table->string('metodo', 10);
    $table->string('situacao', 12)->default('PENDENTE');
    $table->string('gateway_assinatura_id', 100)->nullable()->index();
    $table->date('paga_ate')->nullable();
    $table->timestamp('cancelada_em')->nullable();
    $table->timestamps();
    $table->index(['tenant_id', 'situacao']);
});
Schema::create('cobrancas', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('tenant_id')->constrained('tenants');
    $table->foreignUuid('assinatura_id')->constrained('assinaturas');
    $table->string('tipo', 12);
    $table->unsignedBigInteger('valor_centavos');
    $table->date('vence_em');
    $table->string('status', 10)->default('PENDENTE');
    $table->string('metodo', 10);
    $table->string('gateway_pagamento_id', 100)->nullable()->unique();
    $table->text('boleto_url')->nullable();
    $table->string('boleto_linha_digitavel', 100)->nullable();
    $table->timestamp('pago_em')->nullable();
    $table->date('periodo_inicio')->nullable();
    $table->date('periodo_fim')->nullable();
    $table->timestamps();
    $table->index(['tenant_id', 'status']);
    $table->index(['status', 'vence_em']);
});
Schema::create('webhooks_recebidos', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('gateway_evento_id', 100)->unique();
    $table->string('tipo', 60);
    $table->timestamp('recebido_em');
    $table->timestamp('processado_em')->nullable();
});
```

`config/billing.php`: `['gateway' => env('BILLING_GATEWAY', 'mercadopago'), 'mercadopago' => ['access_token' => env('MERCADOPAGO_ACCESS_TOKEN'), 'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'), 'public_key' => env('MERCADOPAGO_PUBLIC_KEY'), 'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com')], 'carencia_inadimplente_dias' => (int) env('BILLING_CARENCIA_INADIMPLENTE_DIAS', 1), 'carencia_suspensao_dias' => (int) env('BILLING_CARENCIA_SUSPENSAO_DIAS', 10), 'antecedencia_boleto_dias' => 5, 'dias_primeiro_boleto' => 3, 'tolerancia_webhook_segundos' => 600]`. Models no molde de `Venda`/`Nota` (HasUuids, BelongsToTenant, `@property` completos). Factories no molde de `VendaFactory`. `BillingServiceProvider` (vazio por enquanto; registra rotas e comandos nas Tasks seguintes) e inclusão em `bootstrap/providers.php` (Edit).
- [ ] **Step 4:** `php artisan test --filter=BillingIsolamentoTest`. Expected: PASS. Rodar `vendor/bin/phpstan analyse --no-progress`.
- [ ] **Step 5:** pint e commit `feat(billing): tabelas, Models, ciclo e config da cobrança`.

---

### Task 3: Contrato do gateway, DTOs, gateway falso e período pago

**Files:**
- Create: `backend/app/Modules/Billing/Domain/Contracts/GatewayDeCobranca.php`, `Domain/Gateway/{DadosDaAssinatura,DadosDoBoleto,ResultadoDePagamento,ResultadoDeAssinatura,StatusDoPagamento}.php`, `Domain/PeriodoPago.php`, `backend/tests/Fixtures/{GatewayDeCobrancaFake,CenarioDeCobranca}.php`, `backend/tests/Unit/Billing/PeriodoPagoTest.php`

**Interfaces:**
- Produces:
  - `GatewayDeCobranca`: `criarAssinaturaDeCartao(DadosDaAssinatura): ResultadoDeAssinatura`; `criarBoleto(DadosDoBoleto): ResultadoDePagamento`; `buscarPagamento(string $id): ResultadoDePagamento`; `cancelarAssinatura(string $id): void`.
  - `DadosDaAssinatura(string $cobrancaId, int $valorCentavos, string $descricao, int $meses, string $emailDoPagador, string $tokenDoCartao, string $idempotencia)`.
  - `DadosDoBoleto(string $cobrancaId, int $valorCentavos, string $descricao, string $emailDoPagador, string $nomeDoPagador, string $documento, CarbonImmutable $venceEm, string $idempotencia)`.
  - `StatusDoPagamento` enum (`Aprovado`, `Pendente`, `Recusado`, `Cancelado`, `Vencido`).
  - `ResultadoDePagamento(string $id, StatusDoPagamento $status, ?string $referenciaExterna, int $valorCentavos, ?string $assinaturaGatewayId = null, ?string $boletoUrl = null, ?string $linhaDigitavel = null)`.
  - `ResultadoDeAssinatura(string $id, bool $autorizada)`.
  - `PeriodoPago::proximo(?CarbonImmutable $pagaAte, CarbonImmutable $hoje, CicloDeCobranca $ciclo): array{inicio: CarbonImmutable, fim: CarbonImmutable}`.
  - `GatewayDeCobrancaFake` implementa o contrato: filas `enfileirarPagamento(ResultadoDePagamento ...)` (por `buscarPagamento`, ordenadas; sem fila devolve `Pendente`), `enfileirarBoleto(...)`, `falharEm(string $metodo, Throwable)`, registro público `$chamadas` (lista de `[metodo, argumentos]`).
  - `CenarioDeCobranca` (trait): `montarCenarioCobranca()` cria `Plano` (mensal 10000, anual 100000, ativo e visível), `Tenant` em `TESTE` com o plano, `Usuario` `Admin`, define `TenantContext`, e faz `app()->instance(GatewayDeCobranca::class, $this->gateway = new GatewayDeCobrancaFake)`.

- [ ] **Step 1: Teste do período (RED)**

```php
public function test_primeiro_pagamento_comeca_hoje(): void
{
    $p = PeriodoPago::proximo(null, CarbonImmutable::parse('2026-10-07'), CicloDeCobranca::Mensal);
    $this->assertSame(['2026-10-07', '2026-11-07'], [$p['inicio']->toDateString(), $p['fim']->toDateString()]);
}

public function test_pagamento_antecipado_continua_do_fim_do_periodo_ja_pago(): void
{
    $p = PeriodoPago::proximo(CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-07'), CicloDeCobranca::Mensal);
    $this->assertSame(['2026-10-20', '2026-11-20'], [$p['inicio']->toDateString(), $p['fim']->toDateString()]);
}

public function test_pagamento_tardio_comeca_hoje_e_nao_no_vencimento_antigo(): void
{
    $p = PeriodoPago::proximo(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-07'), CicloDeCobranca::Anual);
    $this->assertSame(['2026-10-07', '2027-10-07'], [$p['inicio']->toDateString(), $p['fim']->toDateString()]);
}

public function test_fim_de_mes_nao_transborda(): void
{
    $p = PeriodoPago::proximo(null, CarbonImmutable::parse('2026-01-31'), CicloDeCobranca::Mensal);
    $this->assertSame('2026-02-28', $p['fim']->toDateString());
}
```

- [ ] **Step 2:** `php artisan test --filter=PeriodoPagoTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** `PeriodoPago::proximo`: `$inicio = $pagaAte !== null && $pagaAte->greaterThan($hoje) ? $pagaAte : $hoje; return ['inicio' => $inicio, 'fim' => $inicio->addMonthsNoOverflow($ciclo->meses())];`. Os DTOs são `final readonly class` com construtor promovido. O falso e o trait seguem o molde de `EmissorDeNfseFake` e `CenarioDeNfse` (ler esses dois antes).
- [ ] **Step 4:** `php artisan test --filter=PeriodoPagoTest`. Expected: PASS (4).
- [ ] **Step 5:** pint, phpstan, commit `feat(billing): contrato do gateway, falso de teste e período pago`.

---

### Task 4: ConciliarPagamento

**Files:**
- Create: `backend/app/Modules/Billing/Application/Actions/ConciliarPagamento.php`, `backend/tests/Feature/Billing/ConciliarPagamentoTest.php`

**Interfaces:**
- Consumes: `GatewayDeCobranca::buscarPagamento`, `PeriodoPago`, `MudarSituacaoDaEmpresa::executar(Tenant, SituacaoAssinatura, string, ?Usuario)`.
- Produces: `ConciliarPagamento::executar(string $gatewayPagamentoId): ?Cobranca` (devolve a cobrança afetada ou `null` quando não há cobrança nossa para o pagamento). Roda **sem usuário nem `TenantContext`**: acha a cobrança com `Cobranca::withoutTenantScope()` e, ao escrever, define o `TenantContext` do tenant da cobrança e o restaura no `finally`.

- [ ] **Step 1: Testes (RED)** com o `CenarioDeCobranca`; helper `cobrancaPendente(array $campos = [])` cria `Assinatura` (`PENDENTE`, boleto, `gateway_assinatura_id = 'pre-1'`) e `Cobranca` (`PENDENTE`, valor 10000, vence hoje); `aprovado(Cobranca $c, string $id = 'pg-1', array $extra = [])` enfileira no gateway um `ResultadoDePagamento($id, Aprovado, $c->id, 10000)`:

```php
public function test_pagamento_aprovado_marca_paga_estende_o_periodo_e_ativa_o_tenant(): void
{
    $c = $this->cobrancaPendente();
    $this->aprovado($c);

    app(ConciliarPagamento::class)->executar('pg-1');

    $c->refresh();
    $this->assertSame(StatusDaCobranca::Paga, $c->status);
    $this->assertSame('pg-1', $c->gateway_pagamento_id);
    $this->assertSame(today(config('app.fuso_negocio'))->addMonth()->toDateString(), $c->assinatura->paga_ate->toDateString());
    $this->assertSame(StatusDaAssinatura::Ativa, $c->assinatura->situacao);
    $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    $this->assertSame($c->assinatura->plano_id, $this->tenant->plano_id);
}

public function test_conciliar_duas_vezes_nao_estende_o_periodo_de_novo(): void
{
    $c = $this->cobrancaPendente();
    $this->aprovado($c);
    $this->aprovado($c);
    app(ConciliarPagamento::class)->executar('pg-1');
    $primeira = $c->assinatura->refresh()->paga_ate->toDateString();

    app(ConciliarPagamento::class)->executar('pg-1');

    $this->assertSame($primeira, $c->assinatura->refresh()->paga_ate->toDateString());
}

public function test_pagamento_pendente_ou_recusado_nao_ativa(): void { /* enfileira Pendente e depois Recusado: cobrança continua PENDENTE, tenant continua em TESTE */ }

public function test_valor_divergente_nao_concilia(): void { /* gateway devolve 9999 para cobrança de 10000: cobrança PENDENTE, tenant em TESTE, e a Action lança BillingException BILLING_VALOR_DIVERGENTE */ }

public function test_pagamento_tardio_de_cobranca_vencida_reativa_tenant_suspenso(): void
{
    $c = $this->cobrancaPendente(['status' => StatusDaCobranca::Vencida, 'vence_em' => today()->subDays(20)->toDateString()]);
    $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
    $this->aprovado($c);

    app(ConciliarPagamento::class)->executar('pg-1');

    $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
}

public function test_inadimplente_volta_a_ativa(): void { /* mesmo, com tenant INADIMPLENTE */ }

public function test_acha_a_cobranca_pelo_assinatura_gateway_id_quando_nao_ha_referencia(): void
{
    $c = $this->cobrancaPendente();
    $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-9', StatusDoPagamento::Aprovado, null, 10000, assinaturaGatewayId: 'pre-1'));

    app(ConciliarPagamento::class)->executar('pg-9');

    $this->assertSame(StatusDaCobranca::Paga, $c->refresh()->status);
}

public function test_ciclo_seguinte_do_cartao_cria_nova_cobranca_paga(): void
{
    $c = $this->cobrancaPendente();           // primeira, paga
    $this->aprovado($c);
    app(ConciliarPagamento::class)->executar('pg-1');
    $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-2', StatusDoPagamento::Aprovado, null, 10000, assinaturaGatewayId: 'pre-1'));

    app(ConciliarPagamento::class)->executar('pg-2');

    $this->assertSame(2, Cobranca::query()->where('assinatura_id', $c->assinatura_id)->where('status', StatusDaCobranca::Paga)->count());
    $this->assertSame(today(config('app.fuso_negocio'))->addMonths(2)->toDateString(), $c->assinatura->refresh()->paga_ate->toDateString());
}

public function test_pagamento_de_assinatura_cancelada_nao_ativa_o_tenant(): void { /* assinatura CANCELADA: cobrança paga fica registrada, mas tenant segue como estava */ }

public function test_pagamento_sem_cobranca_nossa_e_ignorado(): void { /* referencia e assinaturaGatewayId desconhecidos: devolve null, nada muda */ }
```

- [ ] **Step 2:** `php artisan test --filter=ConciliarPagamentoTest`. Expected: FAIL.
- [ ] **Step 3: Implementar**

```php
public function executar(string $gatewayPagamentoId): ?Cobranca
{
    $pagamento = $this->gateway->buscarPagamento($gatewayPagamentoId);
    $cobranca = $this->localizar($pagamento);
    if ($cobranca === null) {
        return null;
    }
    if ($pagamento->status !== StatusDoPagamento::Aprovado) {
        return $cobranca;   // pendente/recusado/vencido: o `AplicarInadimplencia` cuida do vencimento
    }
    if ($pagamento->valorCentavos !== $cobranca->valor_centavos) {
        throw new BillingException('BILLING_VALOR_DIVERGENTE', 'O valor pago não confere com o da cobrança.', 409);
    }

    return $this->comTenant($cobranca->tenant_id, function () use ($cobranca, $pagamento): Cobranca {
        return DB::transaction(function () use ($cobranca, $pagamento): Cobranca {
            $cobranca = Cobranca::query()->whereKey($cobranca->id)->lockForUpdate()->firstOrFail();
            if ($cobranca->status === StatusDaCobranca::Paga) {
                return $cobranca;   // idempotente
            }
            $assinatura = Assinatura::query()->whereKey($cobranca->assinatura_id)->lockForUpdate()->firstOrFail();
            $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));
            $periodo = PeriodoPago::proximo($assinatura->paga_ate === null ? null : CarbonImmutable::parse($assinatura->paga_ate), $hoje, $assinatura->ciclo);

            $cobranca->forceFill([
                'status' => StatusDaCobranca::Paga, 'gateway_pagamento_id' => $pagamento->id, 'pago_em' => now(),
                'periodo_inicio' => $periodo['inicio']->toDateString(), 'periodo_fim' => $periodo['fim']->toDateString(),
            ])->save();
            if ($cobranca->tipo === TipoDeCobranca::Assinatura && $assinatura->situacao !== StatusDaAssinatura::Cancelada) {
                $assinatura->forceFill(['paga_ate' => $periodo['fim']->toDateString(), 'situacao' => StatusDaAssinatura::Ativa])->save();
                $this->ativarTenant($assinatura);
            }

            return $cobranca;
        });
    });
}
```

`localizar`: (1) `Cobranca::withoutTenantScope()->where('gateway_pagamento_id', $id)->first()`; (2) por `referenciaExterna` (`whereKey`); (3) por `assinaturaGatewayId`: `Assinatura::withoutTenantScope()->where('gateway_assinatura_id', ...)->first()`, depois a cobrança `PENDENTE`/`VENCIDA` mais antiga dela; se não houver, criar uma nova cobrança `PENDENTE` do próximo ciclo (valor = `ciclo->precoDo(plano)`, `vence_em` hoje, mesmo método) na mesma transação do tenant. `ativarTenant`: `forceFill(['plano_id' => $assinatura->plano_id])` no tenant e, conforme a situação atual, `MudarSituacaoDaEmpresa->executar($tenant, Ativa, 'Pagamento confirmado.', null)` quando for `Pendente`, `Teste`, `Inadimplente` ou `Suspensa` (nada quando já `Ativa`; nunca reativa `Cancelada`). `comTenant` guarda `app(TenantContext::class)->id()` (conferir o nome do getter no `TenantContext`), faz `set` do tenant da cobrança, executa e restaura no `finally` (como o `ReconciliarNotasCommand`).
- [ ] **Step 4:** `php artisan test --filter=ConciliarPagamentoTest`. Expected: PASS. phpstan.
- [ ] **Step 5:** pint e commit `feat(billing): conciliação idempotente de pagamento`.

---

### Task 5: IniciarAssinatura e CancelarAssinatura

**Files:**
- Create: `backend/app/Modules/Billing/Application/Actions/{IniciarAssinatura,CancelarAssinatura}.php`, `backend/tests/Feature/Billing/IniciarAssinaturaTest.php`

**Interfaces:**
- Consumes: `GatewayDeCobranca`, `CicloDeCobranca::precoDo`, `Plano` (ativo e visível).
- Produces:
  - `IniciarAssinatura::executar(Usuario $autor, string $planoId, CicloDeCobranca $ciclo, MetodoDePagamento $metodo, ?string $tokenDoCartao): ResultadoDoCheckout`; `ResultadoDoCheckout` (readonly): `Assinatura $assinatura`, `Cobranca $cobranca`.
  - `CancelarAssinatura::executar(Usuario $autor): Assinatura`.
  - Códigos: `BILLING_SEM_PERMISSAO` (403, papel), `BILLING_PLANO_INDISPONIVEL` (422), `BILLING_ASSINATURA_ATIVA` (409), `BILLING_CARTAO_SEM_TOKEN` (422), `BILLING_GATEWAY_FALHOU` (502), `BILLING_SEM_ASSINATURA` (404).

- [ ] **Step 1: Testes (RED)**:

```php
public function test_boleto_cria_assinatura_pendente_e_cobranca_com_boleto(): void
{
    $this->gateway->enfileirarBoleto(new ResultadoDePagamento('pg-1', StatusDoPagamento::Pendente, null, 10000, boletoUrl: 'https://mp.test/b/1', linhaDigitavel: '1234.5678'));

    $r = app(IniciarAssinatura::class)->executar($this->admin, $this->plano->id, CicloDeCobranca::Mensal, MetodoDePagamento::Boleto, null);

    $this->assertSame(10000, $r->cobranca->valor_centavos);
    $this->assertSame('https://mp.test/b/1', $r->cobranca->boleto_url);
    $this->assertSame('pg-1', $r->cobranca->gateway_pagamento_id);
    $this->assertSame(today(config('app.fuso_negocio'))->addDays(3)->toDateString(), $r->cobranca->vence_em->toDateString());
    $this->assertSame(StatusDaAssinatura::Pendente, $r->assinatura->situacao);
    $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao, 'só o pagamento ativa');
}

public function test_cartao_cria_o_preapproval_com_valor_do_servidor_e_idempotencia(): void
{
    app(IniciarAssinatura::class)->executar($this->admin, $this->plano->id, CicloDeCobranca::Anual, MetodoDePagamento::Cartao, 'tok_123');

    [$metodo, $args] = $this->gateway->chamadas[0];
    $this->assertSame('criarAssinaturaDeCartao', $metodo);
    $this->assertSame(100000, $args[0]->valorCentavos);
    $this->assertSame(12, $args[0]->meses);
    $this->assertNotSame('', $args[0]->idempotencia);
}

public function test_cartao_sem_token_e_recusado(): void { /* BILLING_CARTAO_SEM_TOKEN, nada criado */ }
public function test_plano_inativo_ou_invisivel_ou_sem_preco_do_ciclo_e_recusado(): void { /* BILLING_PLANO_INDISPONIVEL / BILLING_CICLO_INDISPONIVEL */ }
public function test_so_proprietario_e_admin_assinam(): void { /* Vendedor, Fiscal, Leitura: BILLING_SEM_PERMISSAO */ }
public function test_segunda_assinatura_ativa_ou_pendente_e_recusada(): void { /* BILLING_ASSINATURA_ATIVA */ }
public function test_falha_do_gateway_nao_deixa_assinatura_pendente_orfa(): void
{
    $this->gateway->falharEm('criarBoleto', new RuntimeException('mp fora'));

    try {
        app(IniciarAssinatura::class)->executar($this->admin, $this->plano->id, CicloDeCobranca::Mensal, MetodoDePagamento::Boleto, null);
        $this->fail('deveria falhar');
    } catch (BillingException $e) {
        $this->assertSame('BILLING_GATEWAY_FALHOU', $e->codigo());
    }
    $this->assertSame(0, Assinatura::query()->where('situacao', StatusDaAssinatura::Pendente)->count());
    $this->assertSame(0, Cobranca::query()->where('status', StatusDaCobranca::Pendente)->count());
}
public function test_assinatura_pendente_antiga_sem_pagamento_pode_ser_refeita(): void { /* assinatura PENDENTE sem cobrança PENDENTE válida não bloqueia */ }

// CancelarAssinatura
public function test_cancelar_cancela_o_preapproval_a_assinatura_e_as_cobrancas_pendentes_e_mantem_o_acesso(): void { /* gateway recebe cancelarAssinatura('pre-1'); situação CANCELADA; cobranças PENDENTE → CANCELADA; tenant continua ATIVA */ }
public function test_cancelar_sem_assinatura_da_404(): void { /* BILLING_SEM_ASSINATURA */ }
```

- [ ] **Step 2:** `php artisan test --filter=IniciarAssinaturaTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Ordem em `IniciarAssinatura`: papel (`Papel::Proprietario|Admin`, senão `BillingException('BILLING_SEM_PERMISSAO', ..., 403)`); plano `Plano::query()->where('ativo', true)->where('visivel', true)->find(...)` (null → `BILLING_PLANO_INDISPONIVEL`); preço `ciclo->precoDo`; cartão sem token → `BILLING_CARTAO_SEM_TOKEN`; assinatura não cancelada existente (`PENDENTE` com cobrança `PENDENTE` ainda dentro do vencimento, ou `ATIVA`) → `BILLING_ASSINATURA_ATIVA` (uma `PENDENTE` sem cobrança aberta é cancelada e refeita). Dentro de `DB::transaction`: cria `Assinatura` (`forceFill`) e `Cobranca` (`ASSINATURA`, valor, `vence_em` hoje no cartão e hoje + `dias_primeiro_boleto` no boleto); chama o gateway com `idempotencia = $cobranca->id` (descrição `"Assinatura {plano} ({ciclo})"`, pagador = e-mail do `$autor`, nome/CNPJ do tenant). Em exceção do gateway (qualquer `Throwable`): `report($e)`, desfazer a transação (lançar `BillingException('BILLING_GATEWAY_FALHOU', 'Não foi possível falar com o Mercado Pago. Tente novamente.', 502)` dentro da transação para o rollback apagar assinatura e cobrança). Sucesso: cartão grava `gateway_assinatura_id`; boleto grava `gateway_pagamento_id`, `boleto_url`, `boleto_linha_digitavel`. `CancelarAssinatura`: papel igual; acha a assinatura não cancelada do tenant (senão `BILLING_SEM_ASSINATURA` 404); se tiver `gateway_assinatura_id`, `cancelarAssinatura`; depois marca `CANCELADA`, `cancelada_em`, e as cobranças `PENDENTE` viram `CANCELADA`.
- [ ] **Step 4:** `php artisan test --filter=IniciarAssinaturaTest`. Expected: PASS.
- [ ] **Step 5:** pint, phpstan e commit `feat(billing): iniciar e cancelar assinatura`.

---

### Task 6: Webhook (HMAC, idempotência, rota pública)

**Files:**
- Create: `backend/app/Modules/Billing/Infrastructure/VerificadorDeAssinaturaDoWebhook.php`, `Application/Actions/ReceberWebhook.php`, `Http/Controllers/WebhookDoMercadoPagoController.php`, `Http/routes-webhooks.php`, `backend/tests/Feature/Billing/WebhookTest.php`, `backend/tests/Unit/Billing/VerificadorDeAssinaturaTest.php`
- Modify: `Providers/BillingServiceProvider.php` (rota `Route::middleware(['api', 'throttle:120,1'])->prefix('api/webhooks')->group(...)`)

**Interfaces:**
- Produces: `VerificadorDeAssinaturaDoWebhook::valido(?string $xSignature, ?string $xRequestId, ?string $dataId, ?string $segredo, CarbonImmutable $agora): bool` (manifesto `id:{dataId};request-id:{xRequestId};ts:{ts};`, HMAC-SHA256 hex com o segredo, comparação com `hash_equals`; `x-signature` no formato `ts=...,v1=...`; recusa se qualquer parte faltar, se o segredo for vazio/nulo ou se `|agora - ts| > tolerancia_webhook_segundos`; o `ts` do Mercado Pago pode vir em segundos ou milissegundos: normalizar dividindo por 1000 quando tiver 13 dígitos); `ReceberWebhook::executar(array $corpo, ?string $dataId): void`; rota `POST /api/webhooks/mercadopago`.

- [ ] **Step 1: Testes (RED)**. Unidade do verificador: válido; header ausente; `v1` errado; `ts` fora da tolerância; `data.id` diferente do assinado; segredo vazio e nulo (recusam mesmo com assinatura "correta" calculada com segredo vazio). Feature (`WebhookTest`, segredo `s3cr3t` em `config(['billing.mercadopago.webhook_secret' => 's3cr3t'])`, helper `assinado(string $dataId, string $requestId = 'req-1')` devolve os headers `x-signature` e `x-request-id` corretos):

```php
public function test_assinatura_invalida_da_401_e_nao_processa_nada(): void
{
    $this->postJson('/api/webhooks/mercadopago?data.id=pg-1', ['type' => 'payment', 'id' => 777, 'data' => ['id' => 'pg-1']], ['x-signature' => 'ts=1,v1=errado', 'x-request-id' => 'req-1'])->assertStatus(401);
    $this->assertSame(0, WebhookRecebido::query()->count());
    $this->assertSame([], $this->gateway->chamadas);
}

public function test_sem_segredo_configurado_recusa_tudo(): void { /* config segredo null: mesmo com headers "corretos" → 401 */ }

public function test_pagamento_aprovado_pelo_gateway_ativa_o_tenant(): void { /* cobrança pendente; gateway devolve Aprovado; POST assinado → 200; cobrança PAGA; tenant ATIVA */ }

public function test_corpo_dizendo_aprovado_com_gateway_pendente_nao_ativa(): void
{
    $c = $this->cobrancaPendente();
    $this->gateway->enfileirarPagamento(new ResultadoDePagamento('pg-1', StatusDoPagamento::Pendente, $c->id, 10000));

    $this->postJson('/api/webhooks/mercadopago?data.id=pg-1', ['type' => 'payment', 'id' => 778, 'action' => 'payment.updated', 'status' => 'approved', 'data' => ['id' => 'pg-1', 'status' => 'approved']], $this->assinado('pg-1'))->assertOk();

    $this->assertSame(StatusDaCobranca::Pendente, $c->refresh()->status);
    $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao);
}

public function test_evento_repetido_responde_200_e_nao_refaz(): void { /* mesmo `id` de evento duas vezes: uma só chamada ao gateway; segunda 200 */ }
public function test_tipo_que_nao_e_pagamento_e_ignorado_com_200(): void { /* type 'plan' etc.: 200, sem chamar o gateway */ }
public function test_falha_transitoria_do_gateway_da_503_e_permite_reenvio(): void { /* gateway lança; resposta 503; WebhookRecebido fica sem processado_em; novo POST com o gateway ok processa (200, processado_em preenchido) */ }
public function test_valor_divergente_responde_200_e_registra_sem_ativar(): void { /* BILLING_VALOR_DIVERGENTE não pode virar loop de reenvio: 200, cobrança PENDENTE, log de erro */ }
```

- [ ] **Step 2:** `php artisan test --filter="WebhookTest|VerificadorDeAssinatura"`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Controller: lê `x-signature`, `x-request-id`, `request->query('data.id')` (o Laravel transforma `data.id` em `data_id`? use `$request->query->all()['data']['id'] ?? $request->input('data.id')`; conferir no teste) e o segredo `config('billing.mercadopago.webhook_secret')`; inválido → `abort(401)`; válido → `ReceberWebhook`. `ReceberWebhook`: `type !== 'payment'` → retorna; `eventoId = (string) ($corpo['id'] ?? sha1(json_encode($corpo)))`; `WebhookRecebido::firstOrCreate(['gateway_evento_id' => $eventoId], ['tipo' => 'payment', 'recebido_em' => now()])`; se `processado_em !== null` retorna (duplicado); chama `ConciliarPagamento`; `BillingException` de valor divergente: `report`, marca processado e responde 200; qualquer outra exceção: não marca processado e relança (o controller devolve 503 com `report`). Sucesso: marca `processado_em`.
- [ ] **Step 4:** `php artisan test --filter="WebhookTest|VerificadorDeAssinatura"`. Expected: PASS.
- [ ] **Step 5:** pint, phpstan e commit `feat(billing): webhook do Mercado Pago com HMAC e idempotência`.

---

### Task 7: Comandos diários (boleto do ciclo e inadimplência)

**Files:**
- Create: `backend/app/Modules/Billing/Console/{GerarBoletosDoCicloCommand,AplicarInadimplenciaCommand}.php`, `backend/tests/Feature/Billing/{GerarBoletosDoCicloTest,AplicarInadimplenciaTest}.php`
- Modify: `Providers/BillingServiceProvider.php` (registrar comandos), `backend/routes/console.php` (Edit: `Schedule::command('billing:gerar-boletos')->dailyAt('00:20')->timezone('America/Sao_Paulo')->withoutOverlapping()->onOneServer();` e `Schedule::command('billing:aplicar-inadimplencia')->dailyAt('00:30')...` igual)

**Interfaces:**
- Produces: comandos `billing:gerar-boletos` e `billing:aplicar-inadimplencia`, ambos sem `TenantContext` (usam `withoutTenantScope()` e definem o contexto por assinatura/tenant, restaurando no `finally`).

- [ ] **Step 1: Testes (RED)**. `GerarBoletosDoCiclo`: assinatura `ATIVA` de boleto com `paga_ate` = hoje + 5 dias gera uma cobrança `PENDENTE` com `vence_em = paga_ate` e boleto do gateway; com `paga_ate` = hoje + 6 não gera; rodar duas vezes não duplica; assinatura de cartão e `CANCELADA` são ignoradas; falha do gateway numa assinatura não impede as outras (e o comando retorna sucesso, registrando o erro). `AplicarInadimplencia` (fixar `Carbon::setTestNow`/`CarbonImmutable::setTestNow` em `2026-10-07 12:00 America/Sao_Paulo`):

```php
public function test_cobranca_pendente_vencida_ontem_vira_vencida_e_tenant_fica_inadimplente(): void { /* vence_em = hoje-1; tenant ATIVA → INADIMPLENTE */ }
public function test_vence_hoje_ainda_nao_e_vencida(): void { /* vence_em = hoje: continua PENDENTE, tenant ATIVA */ }
public function test_limite_da_suspensao_exatamente_no_decimo_dia(): void
{
    // vence_em = hoje-9 → INADIMPLENTE; vence_em = hoje-10 → SUSPENSA
}
public function test_tenant_em_teste_nao_e_alterado_por_cobranca_vencida(): void { /* o teste expira pelo comando próprio */ }
public function test_assinatura_cancelada_e_ignorada(): void { /* cobranças CANCELADA/assinatura CANCELADA: tenant intacto */ }
public function test_usa_a_cobranca_vencida_mais_antiga_do_tenant(): void { /* duas vencidas (hoje-2 e hoje-12): SUSPENSA */ }
public function test_rodar_de_novo_e_idempotente(): void { /* segunda execução não muda nem lança TransicaoDeSituacaoInvalida (já INADIMPLENTE/SUSPENSA) */ }
public function test_usa_as_carencias_da_config(): void { /* config(['billing.carencia_inadimplente_dias' => 3]): vencida há 2 dias segue ATIVA */ }
```

- [ ] **Step 2:** `php artisan test --filter="GerarBoletosDoCiclo|AplicarInadimplencia"`. Expected: FAIL.
- [ ] **Step 3: Implementar.** `AplicarInadimplencia`: `$hoje = today(config('app.fuso_negocio'))`; `Cobranca::withoutTenantScope()->where('status', Pendente)->whereDate('vence_em', '<', $hoje)` → `Vencida` (por `lazyById`); depois, para cada tenant com cobrança `VENCIDA` de assinatura não cancelada (`Cobranca::withoutTenantScope()->where('status', Vencida)->whereHas assinatura situacao != Cancelada` agrupando por `tenant_id` com `min(vence_em)`): `dias = $hoje->diffInDays($maisAntiga)`; `dias >= suspensao` e situação em `Ativa|Inadimplente` → `Suspensa` ("Cobrança vencida há N dias."); senão `dias >= inadimplente` e situação `Ativa` → `Inadimplente`; capturar `TransicaoDeSituacaoInvalidaException` por tenant (registrar e seguir). `GerarBoletosDoCiclo`: assinaturas `ATIVA` com `metodo = BOLETO`, `paga_ate <= hoje + antecedencia` e sem cobrança `PENDENTE` do tipo `ASSINATURA`; cria a cobrança (`vence_em = paga_ate`, valor = `ciclo->precoDo(plano)` atual) e chama `criarBoleto` (idempotência = id da cobrança); em falha do gateway, remover a cobrança recém-criada, `report`, continuar.
- [ ] **Step 4:** `php artisan test --filter="GerarBoletosDoCiclo|AplicarInadimplencia"`; rodar também `php artisan schedule:list` e conferir os dois comandos. Expected: PASS.
- [ ] **Step 5:** pint, phpstan e commit `feat(billing): boleto do ciclo e inadimplência por comando diário`.

---

### Task 8: Adaptador real do Mercado Pago

**Files:**
- Create: `backend/app/Modules/Billing/Infrastructure/MercadoPagoGateway.php`, `backend/tests/Unit/Billing/MercadoPagoGatewayTest.php`
- Modify: `Providers/BillingServiceProvider.php` (binding `GatewayDeCobranca` → `MercadoPagoGateway` quando `config('billing.gateway') === 'mercadopago'`)

**Interfaces:**
- Produces: `MercadoPagoGateway implements GatewayDeCobranca` usando `Http::withToken(...)->baseUrl(...)`. **Nunca exercitado contra o Mercado Pago real nesta fase**: os testes só provam o formato do pedido com `Http::fake()` e a leitura da resposta; os campos da API vêm da documentação do Mercado Pago e estão no roteiro de sandbox.

- [ ] **Step 1: Testes (RED)** com `Http::fake([...])`:
  - `criarBoleto`: `POST /v1/payments` com `transaction_amount` = valor/100 (float com 2 casas), `payment_method_id` = `bolbradesco`, `external_reference` = id da cobrança, `date_of_expiration` ISO-8601 com fuso, `payer.email`, `payer.first_name`, `payer.identification` (`type` `CNPJ`, `number` só dígitos), `notification_url` de `route`/`config('app.url')`, e header `X-Idempotency-Key`; resposta lida: `id` (como string), `status` (`pending` → `Pendente`), `transaction_details.external_resource_url` → `boletoUrl`, `barcode.content` → `linhaDigitavel` (ver nota abaixo).
  - `buscarPagamento`: `GET /v1/payments/{id}`; mapa de status `approved→Aprovado`, `pending|in_process|authorized→Pendente`, `rejected→Recusado`, `cancelled|refunded|charged_back→Cancelado`, `expired→Vencido`; `transaction_amount` 100.5 → 10050 centavos (arredondar, nunca truncar); `external_reference` e `metadata.preapproval_id` ou `point_of_interaction`… (usar só `external_reference`; o `assinaturaGatewayId` vem de `metadata.preapproval_id` quando existir, senão `null`).
  - `criarAssinaturaDeCartao`: `POST /preapproval` com `reason`, `external_reference`, `payer_email`, `card_token_id`, `auto_recurring` (`frequency` = meses, `frequency_type` = `months`, `transaction_amount`, `currency_id` = `BRL`), `back_url` e `status` = `authorized`; devolve `id` e `autorizada = status === 'authorized'`.
  - `cancelarAssinatura`: `PUT /preapproval/{id}` com `status` = `cancelled`.
  - Resposta 4xx/5xx lança `BillingException('BILLING_GATEWAY_FALHOU', ...)` (sem vazar o corpo da resposta na mensagem; o corpo vai para o log sem dados de cartão).
  - Sem `access_token` configurado, qualquer chamada lança `BillingException('BILLING_GATEWAY_NAO_CONFIGURADO', ...)`.
- [ ] **Step 2:** `php artisan test --filter=MercadoPagoGatewayTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** conforme os testes. Nota de risco no PHPDoc da classe: "campos do boleto (`barcode.content`, `external_resource_url`) e `metadata.preapproval_id` a confirmar no sandbox".
- [ ] **Step 4:** `php artisan test --filter=MercadoPagoGatewayTest` e a suíte do backend `php artisan test --compact` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(billing): adaptador do Mercado Pago (sem sandbox ainda)`.

---

### Task 9: API do tenant (checkout, faturas, cancelar)

**Files:**
- Create: `backend/app/Modules/Billing/Http/Controllers/AssinaturaDeCobrancaController.php`, `Http/Requests/CheckoutRequest.php`, `Http/Resources/{CobrancaResource,AssinaturaDeCobrancaResource}.php`, `Http/routes-app.php`, `backend/tests/Feature/Billing/CobrancaApiTest.php`
- Modify: `Providers/BillingServiceProvider.php` (`Route::middleware('api')->prefix('api/app')->group(.../routes-app.php)`)

**Interfaces:**
- Produces (todos sob `empresa`): `POST /api/app/assinatura/checkout` (201, corpo `data: {assinatura: {...}, cobranca: {...}}`), `GET /api/app/assinatura/cobrancas` (lista do tenant, mais recente primeiro; cada item: `id`, `tipo`, `valor_centavos`, `vence_em`, `status`, `metodo`, `boleto_url`, `boleto_linha_digitavel`, `pago_em`), `POST /api/app/assinatura/cancelar` (200). `GET /api/app/assinatura` (existente) passa a incluir `assinatura` (ciclo, método, situação, `paga_ate`) ou `null`.

- [ ] **Step 1: Testes (RED)** (`spaComo($this->admin)`): checkout de boleto devolve 201 com `cobranca.boleto_url`; checkout de cartão sem `token_do_cartao` devolve 422 com `codigo` `BILLING_CARTAO_SEM_TOKEN`; validação (`plano_id` uuid, `ciclo` em `MENSAL,ANUAL`, `metodo` em `CARTAO,BOLETO`); vendedor e leitura recebem 403 em todos os verbos; faturas só do próprio tenant; **isolamento**: usuário do tenant B recebe a lista vazia nas faturas e `BILLING_SEM_ASSINATURA` (404) ao cancelar a assinatura do tenant A; `GET /assinatura` mostra a assinatura quando existe.
- [ ] **Step 2:** `php artisan test --filter=CobrancaApiTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** (controller só chama as Actions; `CheckoutRequest` valida formato; `BillingException` já vira JSON pelo handler de `ErroDeNegocio`). Em `AssinaturaController` (Platform) acrescentar `assinatura` ao JSON via consulta ao Model `Assinatura` (módulo `Billing` conhecido pelo `Platform` só por esta leitura; usar `Assinatura::query()->where('situacao', '!=', 'CANCELADA')->latest()->first()`).
- [ ] **Step 4:** `php artisan test --compact`, phpstan, pint. Expected: tudo verde (os testes de `AssinaturaController` existentes continuam passando com o campo novo).
- [ ] **Step 5:** commit `feat(billing): API de checkout, faturas e cancelamento`.

---

### Task 10: Frontend (planos, checkout, faturas, faixa)

**Files:**
- Create: `frontend/features/cobranca/{types.ts,api.ts,schemas.ts}`, `frontend/features/cobranca/components/{CartoesDePlanos,Checkout,ListaDeFaturas}.tsx` e seus testes (`Cobranca.test.tsx`), `frontend/app/(app)/assinatura/planos/page.tsx`, `frontend/app/(app)/assinatura/faturas/page.tsx`
- Modify: `frontend/features/assinatura/components/FaixaSituacao.tsx` (link "Escolher plano"/"Regularizar" para `/assinatura/planos` quando `TESTE`, `INADIMPLENTE` ou `SUSPENSA`), `frontend/features/assinatura/types.ts` (campo `assinatura`), `frontend/components/layout/nav-items.ts` (item "Assinatura" para `PROPRIETARIO` e `ADMIN`), e o teste existente da faixa.

**Interfaces:**
- Consumes: `GET /api/publico/planos` (já existe), os endpoints da Task 9.
- Produces: `cobrancaApi.{planos, checkout, faturas, cancelar}`; `checkoutSchema` (zod: `plano_id`, `ciclo`, `metodo`, `token_do_cartao` obrigatório quando `CARTAO`); `<CartoesDePlanos />` (planos com preço do ciclo escolhido; ciclo sem preço desabilitado), `<Checkout plano ciclo />` (boleto: botão "Gerar boleto" e depois link e linha digitavel; cartão: carrega o SDK do Mercado Pago (`https://sdk.mercadopago.com/js/v2`) e monta o Payment Brick com a chave pública vinda de `NEXT_PUBLIC_MERCADOPAGO_PUBLIC_KEY`; sem a chave a tela mostra "Pagamento por cartão indisponível nesta instalação" e o boleto continua), `<ListaDeFaturas />`.

- [ ] **Step 1: Testes (RED)** (Vitest, mocks de `api`/`next/navigation` no molde de `Notas.test.tsx`; o SDK do Mercado Pago é simulado com um `window.MercadoPago` falso que registra o `onSubmit` do brick): (a) planos listam preço mensal e anual e desabilitam o ciclo sem preço; (b) escolher plano + ciclo + boleto chama `checkout` com os três valores e mostra o link e a linha digitável da resposta; (c) cartão: o `onSubmit` do brick envia `token_do_cartao` e mostra "Assinatura enviada, aguardando confirmação do pagamento"; (d) sem chave pública o cartão fica indisponível e o boleto funciona; (e) erro 422 da API aparece em `role="alert"`; (f) faturas: lista valor, vencimento, status e link do boleto; (g) a faixa de situação mostra o link para `/assinatura/planos` em `INADIMPLENTE`, `SUSPENSA` e `TESTE` e nenhum em `ATIVA`.
- [ ] **Step 2:** `npx vitest run features/cobranca features/assinatura`. Expected: FAIL.
- [ ] **Step 3: Implementar** no molde de `features/vendas` e `features/notas` (TS strict, sem `any`; tipar `window.MercadoPago` por `declare global` mínimo). O botão de pagar usa `useEnvioUnico`. Depois de um checkout bem-sucedido invalidar `['assinatura']` e as faturas.
- [ ] **Step 4:** `npx vitest run`, `npx tsc --noEmit`, `npm run lint`, `npm run build`. Expected: verde (lint só com o warning antigo).
- [ ] **Step 5:** commit `feat(cobranca): telas de planos, checkout e faturas`.

---

### Task 11: Documentação e fechamento

**Files:**
- Modify: `TAREFAS.md` (F5a `[x]`, F5b pendente, "Pendências da F5a"), `PROGRESSO.md` ("Fechamento da F5a", roteiro de sandbox, próxima tarefa F5b com o contexto necessário), memória `project_estado_atual.md` e `MEMORY.md`, `docs/referencia-fiscal/` não se aplica (não é fiscal): criar `docs/cobranca.md` curto com o fluxo, as variáveis de ambiente (`MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_WEBHOOK_SECRET`, `MERCADOPAGO_PUBLIC_KEY`, `BILLING_GATEWAY`, carências) e o roteiro de sandbox.

- [ ] **Step 1:** Ler a F5b em `TAREFAS.md` e o spec mestre (seção 12) e reescrever o "Contexto necessário" do `PROGRESSO.md` só com o que a F5b usa (troca de plano: `TrocarPlanoDaEmpresa`, `EntitlementService::excessos`; excedente: `ContadorDeDocumentosMes`/`DOCUMENTOS_MES`, `politica_excedente`).
- [ ] **Step 2:** Roteiro de sandbox (usuário, com credenciais de teste): (1) configurar as três variáveis e registrar a URL do webhook no painel do Mercado Pago; (2) assinar com boleto e conferir o boleto, pagar no sandbox e conferir o webhook, a cobrança `PAGA` e o tenant `ATIVA`; (3) assinar com cartão de teste, conferir o `preapproval` e se o primeiro pagamento chega ao webhook com o `external_reference` esperado ou só com o `preapproval_id` (hipótese da ordem de busca da conciliação); (4) verificar se o `preapproval` aceita boleto (se aceitar, avaliar abandonar o agendamento próprio); (5) conferir o manifesto do HMAC e a tolerância do `ts`; (6) deixar uma cobrança vencer e rodar `billing:aplicar-inadimplencia`; (7) cancelar e conferir o cancelamento no painel.
- [ ] **Step 3:** Suíte completa (backend `php artisan test --compact`, phpstan, pint; frontend vitest, tsc, lint, build). Expected: tudo verde.
- [ ] **Step 4:** Revisão final da branch por subagente (modelo `fable`) com `review-package`; corrigir Critical/Important em uma passada com teste RED→GREEN; Minors vão para o ledger e para "Pendências da F5a".
- [ ] **Step 5:** Commit `docs: F5a concluída (cobrança)`; apagar o workspace `.superpowers/sdd/2026-10-07-f5a-cobranca`; recomendar `/compact` ou `/clear`. Sem push.
