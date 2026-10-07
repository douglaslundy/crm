# F5b1 Pagamento avulso e excedente Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução Native, escolhida pelo usuário). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Gateway com pagamento avulso (cartão e PIX), fechamento mensal do documento excedente em uma cobrança `EXCEDENTE`, pagamento pela tela de faturas e reativação de quem estava suspenso por excedente.

**Architecture:** Estende o módulo `Billing` da F5a. `PagarCobranca` fala com o `GatewayDeCobranca`; `FecharExcedenteDoMes` usa um contador mensal novo do módulo Fiscal pelo contrato `ContadorDeUsoMensal` do Platform; `ConciliarPagamento` passa a tratar cobranças que não estendem período.

**Tech Stack:** Laravel 12, PHPUnit, Pint, PHPStan; Next.js, React, TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-08-f5b1-excedente-pagamento-avulso-design.md` (e, de fundo, o da F5a: `2026-10-07-f5a-cobranca-design.md`).

## Global Constraints

- Valores em centavos inteiros; nunca chutar valor: `COBRAR` sem preço continua bloqueando; zero é válido onde faz sentido (`=== null`, nunca `empty()`/`?? 0`).
- Valor do pagamento é sempre o da cobrança, nunca do cliente. Todo envio ao Mercado Pago leva `X-Idempotency-Key`.
- Todo Model de tenant com Global Scope e teste de isolamento; todo endpoint novo com teste de isolamento. Schema só por migration. `declare(strict_types=1)`. TS strict, sem `any`.
- Frontend e API usam as mesmas Actions.
- Comandos: backend `cd backend && php artisan test --filter=<Nome>`; suíte `php artisan test --compact`; `vendor/bin/pint`; `vendor/bin/phpstan analyse --no-progress`. Frontend `cd frontend && npx vitest run <arquivo>`, `npx tsc --noEmit`, `npm run lint`, `npm run build`.
- **Armadilhas desta máquina (aprendidas na F5a):** (1) o shell come barras invertidas em heredoc e em `node -e`: para gravar arquivo use a ferramenta **Write**, para editar use **Edit**; nunca `sed` com `\` nem script `node` com `\` no texto; (2) arquivos do projeto têm CRLF, então `sed` com âncora de fim de linha falha em silêncio; (3) em testes, importe toda classe usada (um `use` faltando só aparece em execução); (4) `status()` é método final do PHPUnit: não use esse nome em testes; (5) `Tenant::plano_id` e `situacao` só mudam por `forceFill`.
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs`. Sem push.
- Erros de negócio: `BillingException(codigo, mensagem, status)`.

## Review Focus

- Pagamento do excedente não estende `paga_ate` nem baixa nada além dele mesmo; pagar a mensalidade não baixa excedente vencido (Task 5).
- Conta suspensa por excedente volta a `ATIVA` ao pagar, mas não se ainda houver outra cobrança vencida ou o período pago tiver acabado (Task 5).
- Fechamento do excedente: limites do mês (último e primeiro dia), idempotência, plano sem preço, tenant sem assinatura ativa (Task 4).
- PIX expirado gera outro com chave nova; PIX válido não duplica; cartão recusado não deixa estado (Task 6).
- Pagar cobrança de outro tenant dá 404 (Task 7).

## File Structure

- `backend/database/migrations/2026_10_10_000001_pagamento_avulso_e_excedente.php`
- `backend/app/Modules/Billing/Domain/{Enums/MetodoDePagamento,Enums/TipoDeCobranca,Models/Cobranca}.php`, `Domain/Gateway/{DadosDoPagamentoDeCartao,DadosDoPix,ResultadoDePagamento}.php`, `Domain/Contracts/GatewayDeCobranca.php`
- `backend/app/Modules/Billing/Infrastructure/MercadoPagoGateway.php`; `backend/tests/Fixtures/GatewayDeCobrancaFake.php`
- `backend/app/Modules/Platform/Domain/Contracts/ContadorDeUsoMensal.php`; `Platform/Application/EntitlementService.php`; `Platform/Domain/Models/Plano.php`
- `backend/app/Modules/Fiscal/Application/ContadorDeDocumentosMes.php`
- `backend/app/Modules/Billing/Application/Actions/{FecharExcedenteDoMes,PagarCobranca,ConciliarPagamento}.php`, `Console/FecharExcedenteCommand.php`, `Providers/BillingServiceProvider.php`, `backend/routes/console.php`, `config/billing.php`
- `backend/app/Modules/Billing/Http/{Controllers/AssinaturaDeCobrancaController,Requests/PagarCobrancaRequest,Resources/CobrancaResource}.php`, `routes-app.php`; `backend/app/Modules/Platform/Http/Middleware/VerificarSituacaoDoTenant.php`
- `frontend/features/cobranca/{types.ts,api.ts}`, `components/{PainelDePagamento,ListaDeFaturas}.tsx`

---

### Task 1: Migration, enums e Model

**Files:**
- Create: `backend/database/migrations/2026_10_10_000001_pagamento_avulso_e_excedente.php`
- Modify: `Billing/Domain/Enums/MetodoDePagamento.php`, `Billing/Domain/Enums/TipoDeCobranca.php`, `Billing/Domain/Models/Cobranca.php`, `Billing/Http/Resources/CobrancaResource.php`, `database/factories/CobrancaFactory.php` (sem mudança de comportamento)
- Test: `backend/tests/Feature/Billing/CobrancaExcedenteModeloTest.php`

**Interfaces:**
- Produces: `MetodoDePagamento::Pix = 'PIX'`; `TipoDeCobranca::Excedente = 'EXCEDENTE'` com `estendePeriodo()` falso; `Cobranca::metodo` é `?MetodoDePagamento`; colunas `pix_qr_code`, `pix_qr_code_base64`, `pix_expira_em`, `mes_referencia`, `documentos_excedentes`, `valor_unitario_centavos`; índice único parcial de excedente por tenant e mês.

- [ ] **Step 1: Testes (RED)**

```php
public function test_excedente_nasce_sem_metodo_e_com_os_campos_do_mes(): void
{
    $a = Assinatura::factory()->create();
    $c = new Cobranca;
    $c->forceFill([
        'assinatura_id' => $a->id, 'tipo' => TipoDeCobranca::Excedente, 'valor_centavos' => 5000, 'vence_em' => '2026-11-10',
        'status' => StatusDaCobranca::Pendente, 'metodo' => null, 'mes_referencia' => '2026-10-01',
        'documentos_excedentes' => 50, 'valor_unitario_centavos' => 100,
    ])->save();

    $c->refresh();
    $this->assertNull($c->metodo);
    $this->assertSame(50, $c->documentos_excedentes);
    $this->assertFalse(TipoDeCobranca::Excedente->estendePeriodo());
    $this->assertTrue(TipoDeCobranca::Assinatura->estendePeriodo());
}

public function test_o_banco_nao_aceita_dois_excedentes_do_mesmo_mes_no_tenant_mas_aceita_se_um_foi_cancelado(): void
{
    // cria o primeiro; o segundo igual lança QueryException; cancelar o primeiro e criar de novo funciona
}

public function test_resource_da_cobranca_aceita_metodo_nulo_e_traz_os_campos_novos(): void
{
    // (CobrancaResource)->resolve(request()) com a cobrança acima: 'metodo' => null, 'mes_referencia' => '2026-10-01', 'documentos_excedentes' => 50, pix_* => null
}
```

(use `RefreshDatabase`, o `CenarioDeCobranca` ou, no mínimo, `Tenant::factory()->create()` + `TenantContext::set` como em `BillingIsolamentoTest`.)

- [ ] **Step 2:** `php artisan test --filter=CobrancaExcedenteModeloTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Migration (`up`): `Schema::table('cobrancas', ...)` com `$table->string('metodo', 10)->nullable()->change();` e as colunas novas; depois `DB::statement("CREATE UNIQUE INDEX cobrancas_um_excedente_por_mes ON cobrancas (tenant_id, mes_referencia) WHERE tipo = 'EXCEDENTE' AND status <> 'CANCELADA'");` (`down` apaga o índice e as colunas). No `Model`: `@property ?MetodoDePagamento $metodo` (o cast já aceita nulo), `@property ?string $pix_qr_code` etc., casts `pix_expira_em` datetime, `mes_referencia` date. No `CobrancaResource`: `'metodo' => $this->metodo?->value` e os campos novos (`pix_qr_code`, `pix_qr_code_base64`, `pix_expira_em` ISO, `mes_referencia` Y-m-d, `documentos_excedentes`, `valor_unitario_centavos`). Em `TipoDeCobranca::estendePeriodo()` o `match` ganha `self::Excedente => false`. Rodar `phpstan`: ajustar os lugares que usavam `$cobranca->metodo->` sem nulo (procure com Grep `->metodo`).
- [ ] **Step 4:** `php artisan test --filter="CobrancaExcedenteModeloTest|Billing"` e `phpstan`. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(billing): campos do PIX e do excedente nas cobranças`.

---

### Task 2: Gateway: cartão avulso e PIX

**Files:**
- Create: `Billing/Domain/Gateway/{DadosDoPagamentoDeCartao,DadosDoPix}.php`
- Modify: `Billing/Domain/Gateway/ResultadoDePagamento.php`, `Billing/Domain/Contracts/GatewayDeCobranca.php`, `Billing/Infrastructure/MercadoPagoGateway.php`, `backend/tests/Fixtures/GatewayDeCobrancaFake.php`, `backend/config/billing.php`
- Test: `backend/tests/Unit/Billing/MercadoPagoGatewayTest.php` (acrescentar)

**Interfaces:**
- Produces:
  - `DadosDoPagamentoDeCartao(string $cobrancaId, int $valorCentavos, string $descricao, string $emailDoPagador, string $nomeDoPagador, string $documento, string $tokenDoCartao, string $idempotencia)`.
  - `DadosDoPix(string $cobrancaId, int $valorCentavos, string $descricao, string $emailDoPagador, string $nomeDoPagador, string $documento, CarbonImmutable $expiraEm, string $idempotencia)`.
  - `ResultadoDePagamento` ganha, ao final e opcionais: `?string $pixCopiaECola = null`, `?string $pixQrCodeBase64 = null`, `?CarbonImmutable $pixExpiraEm = null`.
  - `GatewayDeCobranca::criarPagamentoDeCartao(DadosDoPagamentoDeCartao): ResultadoDePagamento` e `criarPix(DadosDoPix): ResultadoDePagamento`.
  - Fake: `enfileirarCartao(ResultadoDePagamento ...)`, `enfileirarPix(ResultadoDePagamento ...)` (sem fila, devolve cartão `Aprovado` e PIX `Pendente` com `pixCopiaECola = '000201PIXFAKE'`, `pixQrCodeBase64 = 'QVJDRA=='`, `pixExpiraEm = $dados->expiraEm`); registra `chamadas` como os demais.
  - `config('billing.pix_expira_minutos')` = 30; `config('billing.dias_vencimento_excedente')` = 10.

- [ ] **Step 1: Testes do adaptador (RED)** no padrão dos existentes (`Http::fake`, mesmo `gateway()` e `boleto()` helpers):
  - `criarPagamentoDeCartao`: `POST /v1/payments` com `transaction_amount` (float), `token`, `installments = 1`, `description`, `external_reference` = id da cobrança, `notification_url`, `payer.email`, `payer.first_name`, `payer.identification` (CNPJ/CPF pelo tamanho) e header `X-Idempotency-Key`; resposta `status approved` vira `Aprovado`; `rejected` vira `Recusado`.
  - `criarPix`: mesmo caminho com `payment_method_id = pix`, `date_of_expiration` ISO-8601 do `expiraEm`; lê `point_of_interaction.transaction_data.qr_code` (copia e cola) e `qr_code_base64`, e `date_of_expiration` da resposta como `pixExpiraEm` (se faltar, usa a do pedido); resposta `pending` vira `Pendente`.
  - Os dois lançam `BILLING_GATEWAY_FALHOU` em 4xx/5xx sem vazar o corpo e `BILLING_GATEWAY_NAO_CONFIGURADO` sem token (os testes existentes já cobrem o caminho comum; um caso por método basta).
- [ ] **Step 2:** `php artisan test --filter=MercadoPagoGatewayTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** os DTOs, o contrato, o fake e o adaptador. No adaptador, extrair o `payer` e o `notification_url` do `criarBoleto` num método privado para os três pedidos compartilharem; `pagamento()` passa a preencher os campos do PIX a partir de `point_of_interaction.transaction_data.*`. Nota de risco no PHPDoc: "campos do PIX a conferir no sandbox".
- [ ] **Step 4:** `php artisan test --filter="MercadoPagoGatewayTest|Billing"` + phpstan. Expected: PASS (os implementadores do contrato, só o fake e o adaptador, estão atualizados).
- [ ] **Step 5:** pint e commit `feat(billing): cartão avulso e PIX no gateway`.

---

### Task 3: Contador mensal e política de excedente

**Files:**
- Create: `backend/app/Modules/Platform/Domain/Contracts/ContadorDeUsoMensal.php`
- Modify: `Fiscal/Application/ContadorDeDocumentosMes.php`, `Platform/Application/EntitlementService.php`, `Platform/Domain/Models/Plano.php`
- Test: `backend/tests/Feature/Fiscal/ContadorDeDocumentosMesTest.php` (acrescentar), `backend/tests/Feature/Platform/PoliticaDeExcedenteTest.php`

**Interfaces:**
- Produces: `ContadorDeUsoMensal extends ContadorDeUso { contarNoMes(Tenant $tenant, CarbonInterface $mes): int }`; `ContadorDeDocumentosMes::contarNoMes` (autorizadas, canceladas e cancelando, produção, por `emitido_em`, do primeiro ao último instante do mês de `$mes` no fuso da aplicação, o mesmo de `contar()`); `Plano::permiteExcederDocumentos(): bool` (`politica_excedente === Cobrar` e `preco_documento_excedente_centavos > 0`); `EntitlementService::contadorMensal(Recurso): ?ContadorDeUsoMensal` não é necessário: use `RegistroDeContadores::para()` e `instanceof`.

- [ ] **Step 1: Testes (RED)**:
  - `contarNoMes`: notas em `2026-09-30 23:59:59`, `2026-10-01 00:00:00` e `2026-10-31 23:59:59`, `2026-11-01 00:00:00` contam só no mês certo (use `emitido_em` com `forceFill`); homologação e rejeitada não contam; `contar()` continua igual (um teste existente já cobre).
  - `PoliticaDeExcedenteTest` (use o cenário de `ContadorDeDocumentosMesTest`): plano com limite 1 em `DOCUMENTOS_MES` e 1 nota autorizada em produção; com `BLOQUEAR` `garantirCapacidade` lança `LimiteDoPlanoAtingidoException`; com `COBRAR` e preço 100 não lança; com `COBRAR` e preço `null` ou 0 lança; `COBRAR` com preço **não** libera `USUARIOS` (crie o limite e exceda: ainda lança).
- [ ] **Step 2:** `php artisan test --filter="ContadorDeDocumentosMesTest|PoliticaDeExcedenteTest"`. Expected: FAIL.
- [ ] **Step 3: Implementar.** `ContadorDeDocumentosMes implements ContadorDeUsoMensal`; `contar()` chama `contarNoMes(now())`; `contarNoMes` usa `$mes->copy()->startOfMonth()` e `->endOfMonth()` (se `$mes` for imutável, `copy` funciona nos dois). `Plano::permiteExcederDocumentos()`. Em `EntitlementService::garantirCapacidade`, antes de lançar: `if ($recurso === Recurso::DocumentosMes && $tenant->plano?->permiteExcederDocumentos() === true) { return; }` (comentário: o excedente é cobrado no mês seguinte).
- [ ] **Step 4:** testes acima + `php artisan test --filter="Entitlement|Limite|ContadorDeDocumentos|Emissao"`. Expected: PASS.
- [ ] **Step 5:** pint, phpstan, commit `feat(billing): política de excedente libera documentos acima do limite`.

---

### Task 4: Fechamento mensal do excedente

**Files:**
- Create: `Billing/Application/Actions/FecharExcedenteDoMes.php`, `Billing/Console/FecharExcedenteCommand.php`
- Modify: `Billing/Providers/BillingServiceProvider.php` (registrar o comando), `backend/routes/console.php`
- Test: `backend/tests/Feature/Billing/FecharExcedenteTest.php`

**Interfaces:**
- Consumes: `RegistroDeContadores::para(Recurso::DocumentosMes)` (instância de `ContadorDeUsoMensal`), `CobrancaExcedente` colunas da Task 1.
- Produces: `FecharExcedenteDoMes::executar(CarbonImmutable $mes): int` (quantas cobranças criou); comando `billing:fechar-excedente {--mes=}` (formato `AAAA-MM`, padrão = mês anterior no fuso do negócio), agendado dia 1 às 00:40 America/Sao_Paulo.

- [ ] **Step 1: Testes (RED)** (`CenarioDeCobranca`; plano com `DOCUMENTOS_MES` limite 2, `COBRAR` e `preco_documento_excedente_centavos = 100`; `Carbon::setTestNow('2026-11-01 12:00 America/Sao_Paulo')`; assinatura `ATIVA` criada já ativa; 5 notas autorizadas em produção em outubro, `emitido_em` no mês):

```php
public function test_cria_uma_cobranca_de_excedente_com_o_valor_do_mes(): void
{
    // 5 docs, limite 2 → 3 × R$ 1,00 = 300
    $this->assertSame(1, app(FecharExcedenteDoMes::class)->executar(CarbonImmutable::parse('2026-10-01')));
    $c = Cobranca::query()->where('tipo', TipoDeCobranca::Excedente)->firstOrFail();
    $this->assertSame([300, 3, 100, '2026-10-01', '2026-11-11'], [$c->valor_centavos, $c->documentos_excedentes, $c->valor_unitario_centavos, $c->mes_referencia->toDateString(), $c->vence_em->toDateString()]);
    $this->assertSame(StatusDaCobranca::Pendente, $c->status);
    $this->assertNull($c->metodo);
}
public function test_rodar_de_novo_nao_duplica(): void { /* executar duas vezes: 1 cobrança; segunda devolve 0 */ }
public function test_so_conta_o_mes_pedido(): void { /* notas em 2026-09-30 23:59:59 e 2026-11-01 00:00:00 não entram em outubro */ }
public function test_dentro_do_limite_ou_ilimitado_nao_gera(): void { /* 2 docs com limite 2; limite -1 com 50 docs */ }
public function test_plano_bloquear_ou_sem_preco_nao_gera(): void { /* politica BLOQUEAR; COBRAR com preço null */ }
public function test_tenant_sem_assinatura_ativa_nao_gera_e_registra(): void { /* sem assinatura; assinatura CANCELADA/PENDENTE: 0 cobranças */ }
public function test_vale_para_conta_suspensa_ou_inadimplente_com_assinatura_ativa(): void { /* tenant SUSPENSA, assinatura ATIVA: gera */ }
public function test_comando_usa_o_mes_anterior_por_padrao_e_aceita_mes(): void
{
    $this->artisan('billing:fechar-excedente')->assertSuccessful();             // outubro (hoje = 2026-11-01)
    $this->assertSame(1, Cobranca::query()->where('tipo', TipoDeCobranca::Excedente)->count());
    $this->artisan('billing:fechar-excedente --mes=2026-09')->assertSuccessful(); // setembro: sem notas, nada novo
    $this->assertSame(1, Cobranca::query()->where('tipo', TipoDeCobranca::Excedente)->count());
}
public function test_o_contexto_do_tenant_e_restaurado(): void { /* TenantContext::id() antes == depois */ }
```

- [ ] **Step 2:** `php artisan test --filter=FecharExcedenteTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Percorra `Assinatura::withoutTenantScope()->where('situacao', Ativa)->lazyById(100)`; por assinatura: defina o `TenantContext` (restaure no `finally`), carregue `Tenant` e `plano`; pule se `! $plano->permiteExcederDocumentos()`; `limite = $plano->limiteDe(Recurso::DocumentosMes)`, pule se ilimitado (`Plano::ILIMITADO`); `uso = $contador->contarNoMes($tenant, $mes)`; `excesso = $uso - $limite`, pule se `<= 0`; crie a cobrança (`forceFill`) dentro de `try/catch (UniqueConstraintViolationException)` para tratar a corrida com o índice único como "já existe". `vence_em = hoje(fuso) + config('billing.dias_vencimento_excedente')`. Sem assinatura ativa: nada é criado e `Log::info` registra o tenant com excesso (a lista dos tenants sem assinatura ativa não é percorrida: o excedente só existe para quem tem assinatura; deixe o log para o caso de assinatura ativa sem plano `COBRAR` ignorado). Comando: `--mes` com validação `/^\d{4}-(0[1-9]|1[0-2])$/`, senão `FAILURE` com mensagem. Registre o comando no provider e o agendamento `Schedule::command('billing:fechar-excedente')->monthlyOn(1, '00:40')->timezone('America/Sao_Paulo')->withoutOverlapping()->onOneServer();` em `routes/console.php`.
- [ ] **Step 4:** `php artisan test --filter=FecharExcedenteTest`; `php artisan schedule:list | grep excedente`. Expected: PASS.
- [ ] **Step 5:** pint, phpstan, commit `feat(billing): fechamento mensal do documento excedente`.

---

### Task 5: Conciliação das cobranças que não estendem período

**Files:**
- Modify: `Billing/Application/Actions/ConciliarPagamento.php`
- Test: `backend/tests/Feature/Billing/ConciliarPagamentoTest.php` (acrescentar)

**Interfaces:**
- Consumes: `TipoDeCobranca::estendePeriodo()`.
- Produces: conciliação de `EXCEDENTE` (e, depois, `UPGRADE`): marca `PAGA` com `pago_em` e `gateway_pagamento_id`, **sem** `periodo_*`, sem mexer em `paga_ate`; baixa de vencidas só para cobranças de assinatura e só as do tipo `ASSINATURA`; reativação por "em dia".

- [ ] **Step 1: Testes (RED)** com helpers `excedente(array $campos = [])` (cobrança `EXCEDENTE` pendente, `metodo` nulo, valor 300, na assinatura `ATIVA` com `paga_ate` daqui a 10 dias):
  - pagar excedente marca `PAGA`, `periodo_inicio`/`periodo_fim` nulos e `paga_ate` igual ao de antes;
  - pagar excedente de assinatura com tenant `ATIVA` não muda a situação;
  - tenant `SUSPENSA` com **só** esse excedente vencido: pagar reativa para `ATIVA`;
  - tenant `SUSPENSA` com excedente e mais uma cobrança de assinatura vencida: pagar o excedente **não** reativa;
  - tenant `SUSPENSA` e `paga_ate` no passado: pagar o excedente **não** reativa (o período pago acabou);
  - pagar a mensalidade (tipo `ASSINATURA`) com um excedente vencido: o excedente continua `VENCIDA` (não é cancelado) e, nesse caso, o tenant **não** é reativado se havia excedente vencido... **ruling a registrar:** a mensalidade ativa o tenant (comportamento atual), mesmo com excedente vencido; o `AplicarInadimplencia` do dia seguinte o leva de novo a inadimplente. O teste fixa: após pagar a mensalidade, o excedente segue `VENCIDA` e o tenant fica `ATIVA` até a próxima rodada.
  - o `AplicarInadimplencia` suspende por excedente vencido há 10 dias (cobrança `VENCIDA`, assinatura `ATIVA`, `paga_ate` futuro): tenant vai a `SUSPENSA`.
- [ ] **Step 2:** `php artisan test --filter=ConciliarPagamentoTest`. Expected: FAIL nos novos.
- [ ] **Step 3: Implementar.** Em `aplicar`: só calcule e grave `periodo_*` quando `$cobranca->tipo->estendePeriodo()`; o bloco que estende a assinatura e ativa o tenant continua só para esse caso; a baixa das vencidas troca o filtro para `->where('tipo', TipoDeCobranca::Assinatura->value)`; no fim, para **qualquer** tipo, chame `reativarSeEmDia($assinatura)`: recarrega o tenant com lock; se a situação é `Inadimplente` ou `Suspensa`, a assinatura está `ATIVA`, `paga_ate !== null` e `>= hoje`, e `! Cobranca::query()->where('assinatura_id', ...)->where('status', Vencida)->exists()`, chama `MudarSituacaoDaEmpresa` para `Ativa` com o motivo "Pagamento confirmado." (o caminho da mensalidade já ativa antes e continua igual). Ajuste a ordem para não duplicar a transição.
- [ ] **Step 4:** `php artisan test --filter="ConciliarPagamentoTest|AplicarInadimplencia|Webhook"`. Expected: PASS.
- [ ] **Step 5:** pint, phpstan, commit `feat(billing): conciliação de cobrança que não estende o período`.

---

### Task 6: PagarCobranca

**Files:**
- Create: `Billing/Application/Actions/PagarCobranca.php`
- Test: `backend/tests/Feature/Billing/PagarCobrancaTest.php`

**Interfaces:**
- Consumes: gateway (Task 2), `ConciliarPagamento`.
- Produces: `PagarCobranca::executar(Usuario $autor, string $cobrancaId, MetodoDePagamento $metodo, ?string $tokenDoCartao): Cobranca`. Códigos: `BILLING_SEM_PERMISSAO` (403), `BILLING_COBRANCA_NAO_PAGAVEL` (422: tipo diferente de `EXCEDENTE` ou status fora de pendente/vencida), `BILLING_METODO_INVALIDO` (422: método `BOLETO`), `BILLING_CARTAO_SEM_TOKEN`, `BILLING_CARTAO_RECUSADO`, `BILLING_GATEWAY_FALHOU`. Cobrança inexistente ou de outro tenant: `ModelNotFoundException` (404).

- [ ] **Step 1: Testes (RED)** (`CenarioDeCobranca`, helper `excedente()` da Task 5 movido para o trait `CenarioDeCobranca` como `excedentePendente(array $campos = [])`):
  - PIX novo: grava `pix_qr_code`, `pix_qr_code_base64`, `pix_expira_em`, `gateway_pagamento_id`, `metodo = PIX`; o gateway recebe `valorCentavos` 300, `expiraEm` = agora + 30 min e `idempotencia` = id da cobrança;
  - PIX ainda válido: segunda chamada devolve a mesma cobrança e **não** chama o gateway de novo;
  - PIX expirado (`pix_expira_em` no passado): cria outro com idempotência diferente do id da cobrança e atualiza os campos;
  - cartão aprovado: chama o gateway com o token, grava `metodo = CARTAO` e `gateway_pagamento_id`, e a cobrança já sai `PAGA` (conciliada na hora: enfileire o mesmo resultado `Aprovado` com `valorCentavos` 300 em `enfileirarPagamento`);
  - cartão recusado: `BILLING_CARTAO_RECUSADO`, nada gravado (`gateway_pagamento_id` nulo, `metodo` nulo);
  - cartão sem token; método `BOLETO`; tipo `ASSINATURA`; status `PAGA`/`CANCELADA`; papel sem permissão; falha do gateway (`falharEm('criarPix', new RuntimeException)`) vira `BILLING_GATEWAY_FALHOU` sem gravar;
  - cobrança vencida também paga;
  - cobrança de outro tenant: `ModelNotFoundException`.
- [ ] **Step 2:** `php artisan test --filter=PagarCobrancaTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.** Política (`PoliticaDeCobranca`), `Cobranca::query()->findOrFail($id)` (com escopo), validações de tipo e status, depois `DB::transaction` com lock da cobrança. PIX: se `pix_expira_em` no futuro e `gateway_pagamento_id` não nulo e `metodo === Pix`, devolve sem chamar; senão cria (`idempotencia = $cobranca->gateway_pagamento_id === null ? $cobranca->id : (string) Str::uuid()`). Pagador: e-mail do `$autor`, nome e CNPJ do tenant. Cartão: `criarPagamentoDeCartao`; resultado `Recusado`/`Cancelado` lança `BILLING_CARTAO_RECUSADO` (a transação desfaz); aprovado grava e, **depois do commit**, chama `ConciliarPagamento::executar($resultado->id)` dentro de `try/catch` (falha de conciliação vira `report`, o webhook refaz). Qualquer `Throwable` do gateway que não seja `BillingException` vira `BILLING_GATEWAY_FALHOU` (502). Devolve `$cobranca->refresh()`.
- [ ] **Step 4:** `php artisan test --filter=PagarCobrancaTest` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(billing): pagar cobrança avulsa por PIX ou cartão`.

---

### Task 7: API do pagamento avulso

**Files:**
- Create: `Billing/Http/Requests/PagarCobrancaRequest.php`
- Modify: `Billing/Http/Controllers/AssinaturaDeCobrancaController.php`, `Billing/Http/routes-app.php`, `Platform/Http/Middleware/VerificarSituacaoDoTenant.php` (constante `LIBERADAS_PARA_PAGAR` ganha `app.assinatura.cobrancas.pagar`)
- Test: `backend/tests/Feature/Billing/CobrancaApiTest.php` (acrescentar)

**Interfaces:**
- Produces: `POST /api/app/assinatura/cobrancas/{cobranca}/pagar` (200, `data` = `CobrancaResource`); `{cobranca}` resolve pelo Global Scope (404 de outro tenant). Corpo: `metodo` em `CARTAO,PIX` (obrigatório), `token_do_cartao` opcional.

- [ ] **Step 1: Testes (RED)** em `CobrancaApiTest`: PIX devolve 200 com `data.pix_qr_code`; cartão sem token 422 `BILLING_CARTAO_SEM_TOKEN`; `metodo = BOLETO` 422 de validação; vendedor/leitura 403; **isolamento:** admin do tenant B recebe 404 ao pagar cobrança do tenant A; conta `SUSPENSA` consegue pagar (rota liberada).
- [ ] **Step 2:** `php artisan test --filter=CobrancaApiTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** (controller só chama a Action; a request valida formato com `Rule::enum` restrito a `['CARTAO', 'PIX']`).
- [ ] **Step 4:** `php artisan test --compact` (suíte inteira), phpstan, pint. Expected: verde.
- [ ] **Step 5:** commit `feat(billing): API de pagamento avulso`.

---

### Task 8: Frontend (pagar excedente por PIX ou cartão)

**Files:**
- Modify: `frontend/features/cobranca/{types.ts,api.ts}`, `components/ListaDeFaturas.tsx`, `components/Cobranca.test.tsx`
- Create: `frontend/features/cobranca/components/PainelDePagamento.tsx`

**Interfaces:**
- Produces: `MetodoDePagamento` ganha `'PIX'`; `Cobranca` ganha `tipo: 'ASSINATURA' | 'EXCEDENTE'`, `metodo: MetodoDePagamento | null`, `pix_qr_code`, `pix_qr_code_base64`, `pix_expira_em`, `mes_referencia`, `documentos_excedentes`, `valor_unitario_centavos` (todos `| null`); `cobrancaApi.pagar(id, { metodo: 'CARTAO' | 'PIX', token_do_cartao: string | null })`; `<PainelDePagamento cobranca />` (PIX/cartão, reutilizável pelo upgrade da F5b2).

- [ ] **Step 1: Testes (RED)** no molde de `Cobranca.test.tsx` (mocks de `../api`, `@/lib/mercadopago`):
  - fatura de excedente mostra "Documentos acima do limite em outubro de 2026: 3 × R$ 1,00" e o botão "Pagar"; fatura de assinatura paga não mostra Pagar;
  - Pagar abre o painel; "Gerar PIX" chama `pagar` com `{ metodo: 'PIX', token_do_cartao: null }` e mostra a imagem do QR (`data:image/png;base64,...`), o código copia e cola e o botão "Copiar código" (use `navigator.clipboard.writeText` simulado);
  - cartão: o Brick devolve o token e `pagar` é chamado com `CARTAO`; sem chave pública mostra a mensagem de cartão indisponível e o PIX continua;
  - com PIX gerado a lista de faturas é recarregada a cada poucos segundos até a cobrança sair de pendente (teste com `vi.useFakeTimers` ou verifique `refetchInterval` por uma segunda resposta de `faturas` com status `PAGA` e o texto "Paga");
  - erro da API aparece em `role="alert"`.
- [ ] **Step 2:** `npx vitest run features/cobranca`. Expected: FAIL.
- [ ] **Step 3: Implementar** no molde de `Checkout`/`PagamentoComCartao` (o painel reaproveita `PagamentoComCartao`); o mês em texto com `toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })`; polling com `refetchInterval` da query de faturas (3 s) enquanto houver cobrança pendente com PIX gerado e não expirado; invalidar `['assinatura']` ao detectar `PAGA`.
- [ ] **Step 4:** `npx vitest run`, `npx tsc --noEmit`, `npm run lint`, `npm run build`. Expected: verde (lint só com o warning antigo).
- [ ] **Step 5:** commit `feat(cobranca): pagar excedente por PIX ou cartão`.

---

### Task 9: Documentação e fechamento

**Files:**
- Modify: `TAREFAS.md` (F5b1 `[x]`, F5b2 pendente, "Pendências da F5b1"), `PROGRESSO.md` ("Fechamento da F5b1", próxima tarefa F5b2 e contexto), `docs/cobranca.md` (excedente, PIX, roteiro de sandbox), memória `project_estado_atual.md` e `MEMORY.md`.

- [ ] **Step 1:** Contexto da F5b2 no `PROGRESSO.md`: `TAREFAS.md` (F5b2), o spec mestre (seção 12), os specs da F5a e da F5b1, `docs/cobranca.md`, `Platform/Application/Actions/TrocarPlanoDaEmpresa.php`, `Platform/Application/EntitlementService.php` (`excessos`), `Billing/Application/Actions/{IniciarAssinatura,PagarCobranca,ConciliarPagamento}.php`, `Billing/Console/GerarBoletosDoCicloCommand.php`, `frontend/features/cobranca/components/{Assinar,PainelDePagamento}.tsx`.
- [ ] **Step 2:** Roteiro de sandbox acrescentado: PIX (QR, copia e cola, expiração e webhook de aprovação), cartão avulso aprovado e recusado, e o excedente de ponta a ponta (`php artisan billing:fechar-excedente --mes=AAAA-MM` num tenant de teste com plano `COBRAR`).
- [ ] **Step 3:** Suíte completa (backend `php artisan test --compact`, phpstan, pint; frontend vitest, tsc, lint, build). Expected: verde.
- [ ] **Step 4:** Revisão final da branch por subagente (modelo `fable`) com `review-package` (base = commit do plano); corrigir Critical/Important em uma passada com teste RED→GREEN; Minors vão para "Pendências da F5b1".
- [ ] **Step 5:** Commit `docs: F5b1 concluída (pagamento avulso e excedente)`; apagar o workspace `.superpowers/sdd/2026-10-08-f5b1-excedente-pagamento-avulso`; recomendar `/compact` ou `/clear`. Sem push.
