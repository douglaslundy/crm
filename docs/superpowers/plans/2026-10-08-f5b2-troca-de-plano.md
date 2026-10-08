# F5b2 Troca de plano Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução Native, escolhida pelo usuário). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Upgrade pago por cartão ou PIX (o plano só muda ao pagar), downgrade agendado para o fim do período e bloqueado por excesso de uso, no mesmo ciclo.

**Architecture:** Estende o módulo `Billing`. `TrocarDePlano` cria a cobrança `UPGRADE` ou agenda o downgrade; `PagarCobranca` e `ConciliarPagamento` (F5b1) tratam o upgrade; `billing:aplicar-trocas` aplica os downgrades; o gateway ganha `atualizarAssinatura` para o valor da recorrência do cartão.

**Tech Stack:** Laravel 12, PHPUnit, Pint, PHPStan; Next.js, React, TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-08-f5b2-troca-de-plano-design.md` (de fundo: F5a `2026-10-07-f5a-cobranca-design.md` e F5b1 `2026-10-08-f5b1-excedente-pagamento-avulso-design.md`).

## Global Constraints

- Mesmas da F5b1: centavos inteiros; valor do pagamento sempre o da cobrança; `X-Idempotency-Key` em todo envio ao Mercado Pago; todo Model de tenant com Global Scope e todo endpoint novo com teste de isolamento; schema só por migration; `declare(strict_types=1)`; TS strict sem `any`; frontend e API usam as mesmas Actions; erros de negócio por `BillingException(codigo, mensagem, status)`.
- Comandos: backend `cd backend && php artisan test --filter=<Nome>`, suíte `php artisan test --compact`, `vendor/bin/pint`, `vendor/bin/phpstan analyse --no-progress`. Frontend `cd frontend && npx vitest run <arquivo>`, `npx tsc --noEmit`, `npm run lint`, `npm run build`.
- **Armadilhas desta máquina:** (1) o shell come barras invertidas em heredoc e em `node -e`; para gravar use **Write**, para editar use **Edit**; (2) **nunca** edite arquivo com acento por script Python sem `encoding='utf-8'` (o padrão aqui é cp1252 e corrompe os acentos novos); prefira Edit; se precisar de script, `io.open(p, encoding='utf-8', newline='')`; (3) arquivos do projeto podem ter CRLF; (4) em testes, importe toda classe usada; (5) `status()` é método final do PHPUnit; (6) `Tenant::plano_id` e `situacao` só mudam por `forceFill`; (7) um tenant só pode ter uma assinatura não cancelada (índice único): nos testes reaproveite a assinatura (`excedentePendente([], $assinatura)`); (8) `app.timezone` é UTC e `fuso_negocio` é America/Sao_Paulo: datas de negócio com `CarbonImmutable::today(config('app.fuso_negocio'))`.
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01RPfvFFDCUmyPSVb9ArsEVs`. Sem push.

## Review Focus

- Upgrade pago estende o período por engano ou baixa outras cobranças; upgrade vencido entra na inadimplência (Task 4).
- Valor da renovação do cartão: cobrança paga de excedente ou upgrade não pode virar o "último valor pago" (Task 1; bug latente da F5b1 em `ConciliarPagamento::valorDaRenovacao` e `IniciarAssinatura::regularizar`).
- Proporcional: ciclo de 28/30/31 dias, último dia do período, arredondamento (Task 3).
- Downgrade: checado ao pedir e ao aplicar; boleto do próximo ciclo no preço novo; cartão atualiza o valor e reverte ao cancelar (Tasks 3 e 5).
- Isolamento: trocar plano e pagar upgrade de outro tenant dão 404; conta que não está `ATIVA` não troca (Task 6).

## File Structure

- `backend/database/migrations/2026_10_11_000001_troca_de_plano.php`
- `Billing/Domain/Enums/TipoDeCobranca.php`, `Domain/Models/{Assinatura,Cobranca}.php`, `Domain/Contracts/GatewayDeCobranca.php`, `Infrastructure/MercadoPagoGateway.php`, `tests/Fixtures/GatewayDeCobrancaFake.php`
- `Billing/Domain/ValorDoUpgrade.php` (cálculo puro)
- `Billing/Application/Actions/{TrocarDePlano,CancelarDowngrade,PagarCobranca,ConciliarPagamento,IniciarAssinatura}.php`
- `Billing/Console/{AplicarTrocasCommand,AplicarInadimplenciaCommand,GerarBoletosDoCicloCommand}.php`, `Providers/BillingServiceProvider.php`, `routes/console.php`
- `Billing/Http/{Controllers/AssinaturaDeCobrancaController,Requests/TrocarPlanoRequest,Resources/{AssinaturaDeCobrancaResource,CobrancaResource},routes-app.php}`
- `frontend/features/cobranca/{types.ts,api.ts}`, `components/{Assinar,TrocaDePlano,ListaDeFaturas}.tsx`

---

### Task 1: Migration, enum, models e valor do ciclo

**Files:**
- Create: `backend/database/migrations/2026_10_11_000001_troca_de_plano.php`
- Modify: `TipoDeCobranca`, `Assinatura`, `Cobranca`, `AssinaturaDeCobrancaResource`, `CobrancaResource`, `IniciarAssinatura`, `ConciliarPagamento::valorDaRenovacao`
- Test: `backend/tests/Feature/Billing/TrocaDePlanoModeloTest.php`, `ConciliarPagamentoTest.php` e `IniciarAssinaturaTest.php` (acrescentar)

**Interfaces:**
- Produces: `TipoDeCobranca::Upgrade = 'UPGRADE'` (`estendePeriodo()` falso); `assinaturas.valor_ciclo_centavos` (nulo), `plano_proximo_id` (uuid nulo, FK `planos`), `plano_proximo_em` (date nula); `cobrancas.plano_destino_id` (uuid nulo, FK `planos`); índice único parcial `cobrancas_um_upgrade_pendente (assinatura_id) WHERE tipo = 'UPGRADE' AND status = 'PENDENTE'`; propriedades nos Models (`?int`, `?string`, `?CarbonInterface`); `Assinatura::valorDoCiclo(): int` (`valor_ciclo_centavos` ou, se nulo, `ciclo->precoDo(plano)`); resources trazem `valor_ciclo_centavos`? **não** (só `plano_proximo_id`, `plano_proximo_em` na assinatura e `plano_destino_id` na cobrança).

- [ ] **Step 1: Testes (RED)**
  - `TrocaDePlanoModeloTest`: upgrade nasce com `plano_destino_id`; o banco recusa dois upgrades pendentes da mesma assinatura mas aceita um novo depois de cancelar o primeiro; resources trazem os campos novos; `valorDoCiclo()` usa o gravado ou o preço do plano.
  - `IniciarAssinaturaTest`: o checkout (cartão e boleto) grava `valor_ciclo_centavos` com o preço do ciclo.
  - `ConciliarPagamentoTest` (**bug latente**): renovação do cartão com um excedente **pago** mais recente que a última mensalidade usa o valor do ciclo, não o do excedente (cobrança paga de excedente R$ 3,00 e renovação de R$ 100,00 concilia sem `BILLING_VALOR_DIVERGENTE`); idem para assinatura com `valor_ciclo_centavos` nulo: usa a última cobrança paga **do tipo ASSINATURA**.
  - `IniciarAssinaturaTest`: a regularização por boleto usa o mesmo valor (ciclo, nunca excedente).
- [ ] **Step 2:** `php artisan test --filter="TrocaDePlanoModeloTest|IniciarAssinaturaTest|ConciliarPagamentoTest"`. Expected: FAIL nos novos.
- [ ] **Step 3: Implementar.** Migration (`up`/`down`) com as colunas, FKs e o índice (`DB::statement`). Em `IniciarAssinatura`, ao criar a assinatura, gravar `valor_ciclo_centavos = $valor`. `ConciliarPagamento::valorDaRenovacao` passa a `return $assinatura->valorDoCiclo()` quando o gravado existe; sem ele, a última cobrança `ASSINATURA` paga (filtrar `tipo`) e, sem histórico, o preço do plano. Mesmo filtro em `IniciarAssinatura::regularizar`.
- [ ] **Step 4:** `php artisan test --filter="Billing"` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(billing): campos da troca de plano e valor do ciclo`.

---

### Task 2: Gateway: atualizar o valor da assinatura de cartão

**Files:**
- Modify: `GatewayDeCobranca`, `MercadoPagoGateway`, `tests/Fixtures/GatewayDeCobrancaFake.php`
- Test: `backend/tests/Unit/Billing/MercadoPagoGatewayTest.php` (acrescentar)

**Interfaces:**
- Produces: `GatewayDeCobranca::atualizarAssinatura(string $id, int $valorCentavos): void`; fake registra `chamadas` como os demais e aceita `falharEm('atualizarAssinatura', ...)`.

- [ ] **Step 1: Testes (RED)** no padrão do adaptador: `PUT https://mp.test/preapproval/<id>` com `auto_recurring.transaction_amount` em reais (float, `round(c/100, 2)`) e `currency_id = BRL`; erro 4xx/5xx vira `BILLING_GATEWAY_FALHOU`; sem token `BILLING_GATEWAY_NAO_CONFIGURADO`.
- [ ] **Step 2:** `php artisan test --filter=MercadoPagoGatewayTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** (reuse `enviar('PUT', ...)`, sem chave de idempotência: o PUT é idempotente). Acrescente a nota de risco no PHPDoc do adaptador ("`PUT /preapproval`: a conferir no sandbox se o valor novo vale na próxima cobrança").
- [ ] **Step 4:** `php artisan test --filter="MercadoPagoGatewayTest|Billing"` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(billing): atualizar o valor da assinatura de cartão no gateway`.

---

### Task 3: TrocarDePlano (upgrade, downgrade) e CancelarDowngrade

**Files:**
- Create: `Billing/Domain/ValorDoUpgrade.php`, `Billing/Application/Actions/TrocarDePlano.php`, `Billing/Application/Actions/CancelarDowngrade.php`, `Billing/Domain/ResultadoDaTroca.php`
- Test: `backend/tests/Unit/Billing/ValorDoUpgradeTest.php`, `backend/tests/Feature/Billing/TrocarDePlanoTest.php`

**Interfaces:**
- Consumes: `Assinatura::valorDoCiclo()`, `CicloDeCobranca::precoDo/meses`, `EntitlementService::excessos`, `GatewayDeCobranca::atualizarAssinatura`.
- Produces:
  - `ValorDoUpgrade::calcular(int $precoAtual, int $precoNovo, CarbonImmutable $hoje, CarbonImmutable $pagaAte, CicloDeCobranca $ciclo): int` (centavos; `round((novo − atual) × restantes ÷ diasDoCiclo)` meio para cima; `diasDoCiclo = pagaAte − (pagaAte − meses)`; restantes = `pagaAte − hoje`; lança `BillingException('BILLING_TROCA_SEM_DIAS')` se restantes ≤ 0 ou se o resultado for ≤ 0).
  - `TrocarDePlano::executar(Usuario $autor, string $planoId): ResultadoDaTroca` com `tipo` (`UPGRADE`|`DOWNGRADE`), `cobranca` (`?Cobranca`), `assinatura`.
  - `CancelarDowngrade::executar(Usuario $autor): Assinatura`.
  - Códigos: `BILLING_SEM_PERMISSAO`, `BILLING_TROCA_INDISPONIVEL` (422: assinatura não `ATIVA`, `paga_ate` vencido, conta não `ATIVA`), `BILLING_PLANO_INDISPONIVEL`, `BILLING_TROCA_MESMO_PLANO`, `BILLING_TROCA_MESMO_PRECO`, `BILLING_CICLO_INDISPONIVEL` (já existe em `precoDoPlano`), `BILLING_TROCA_SEM_DIAS`, `BILLING_SEM_DOWNGRADE` (404 ao cancelar sem agendamento) e `PlanoInsuficienteException` (422 `PLANO_EXCEDIDO`).

- [ ] **Step 1: Testes (RED)**
  - `ValorDoUpgradeTest`: mensal de 30 dias com 15 restantes (R$ 100 → R$ 160) = 3000; mês de 28 e de 31 dias; anual (365 dias); arredondamento meio para cima (`.5` sobe); último dia (restantes 0) lança; resultado ≤ 0 lança.
  - `TrocarDePlanoTest` (use `CenarioDeCobranca` + assinatura `ATIVA` paga até daqui a 15 dias, tenant `ATIVA`; dois planos extras com preço maior e menor):
    - upgrade cria cobrança `UPGRADE` pendente com `plano_destino_id`, valor proporcional, `metodo` nulo, vence amanhã; **plano do tenant e da assinatura não mudam**;
    - segundo pedido para o mesmo destino devolve a mesma cobrança; para outro destino cancela a anterior e cria nova (um pendente só);
    - downgrade: grava `plano_proximo_id` e `plano_proximo_em = paga_ate`, plano atual intacto; assinatura de cartão chama `atualizarAssinatura(gateway_assinatura_id, preço novo)`; boleto não chama o gateway;
    - downgrade bloqueado (uso passa do limite do plano novo) lança `PlanoInsuficienteException` com a lista e não grava nada nem chama o gateway;
    - falha do gateway no downgrade (`falharEm('atualizarAssinatura', ...)`) vira `BILLING_GATEWAY_FALHOU` e nada é gravado;
    - `CancelarDowngrade` limpa o agendamento e devolve o valor antigo no cartão; sem agendamento dá `BILLING_SEM_DOWNGRADE`;
    - recusas: papel sem permissão; assinatura `PENDENTE`/`CANCELADA`; conta `INADIMPLENTE`; `paga_ate` no passado; plano igual; plano inativo ou invisível; mesmo preço; plano sem preço no ciclo.
- [ ] **Step 2:** `php artisan test --filter="ValorDoUpgradeTest|TrocarDePlanoTest"`. Expected: FAIL.
- [ ] **Step 3: Implementar.** `PoliticaDeCobranca::garantirPodeGerenciar`; `DB::transaction` com lock da assinatura ativa (`Assinatura::query()->where('situacao', Ativa)->lockForUpdate()->first()`) e do tenant (`Tenant::query()->lockForUpdate()`); compare `precoNovo` (`ciclo->precoDo($novo)`, que já lança `BILLING_CICLO_INDISPONIVEL`) com `valorDoCiclo()`: maior = upgrade, menor = downgrade, igual = `BILLING_TROCA_MESMO_PRECO`. Upgrade: cancele o pendente de outro destino e reaproveite o de mesmo destino; cobrança por `forceFill` como `FecharExcedenteDoMes` (tipo `Upgrade`, `plano_destino_id`, `vence_em` = hoje + 1, `metodo` nulo). Downgrade: `excessos`; cartão (`gateway_assinatura_id !== null` e `metodo === Cartao`): `atualizarAssinatura` **dentro** da transação (falha de qualquer `Throwable` não `BillingException` vira `BILLING_GATEWAY_FALHOU`, e a transação desfaz). `activity('assinatura')` com `de`/`para`.
- [ ] **Step 4:** testes acima + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(billing): trocar de plano (upgrade pago e downgrade agendado)`.

---

### Task 4: Pagar e conciliar o upgrade; inadimplência ignora o upgrade vencido

**Files:**
- Modify: `PagarCobranca` (aceita `Upgrade`, descrição), `ConciliarPagamento` (efeito do upgrade), `AplicarInadimplenciaCommand`
- Test: `PagarCobrancaTest`, `ConciliarPagamentoTest`, `AplicarInadimplenciaTest` (acrescentar)

**Interfaces:**
- Consumes: Task 1 (colunas), Task 2 (`atualizarAssinatura`), `Plano`.
- Produces: pagar `UPGRADE` por PIX/cartão (mesmas regras do excedente); conciliação do upgrade; `AplicarInadimplencia` cancela (não vence) upgrade pendente vencido.

- [ ] **Step 1: Testes (RED)**
  - `PagarCobrancaTest`: PIX e cartão aprovado de uma cobrança `UPGRADE` funcionam (descrição "Troca para <plano>" no pedido); tipo `ASSINATURA` continua recusado.
  - `ConciliarPagamentoTest` (helper `upgradePendente(Plano $destino)` no trait `CenarioDeCobranca`): upgrade pago marca `PAGA` sem `periodo_*`, troca `tenant.plano_id` e `assinatura.plano_id` para o destino, grava `valor_ciclo_centavos` com o preço novo, limpa `plano_proximo_*`, **não** muda `paga_ate`; assinatura de cartão chama `atualizarAssinatura` com o preço novo e falha do gateway nessa chamada **não** desfaz o pagamento (cobrança `PAGA`, plano trocado, erro vai para o log via `report`); destino inativado antes do pagamento: cobrança fica `PAGA` e o plano **não** muda (log de aviso para estorno manual); upgrade não baixa mensalidade vencida nem excedente; conciliar duas vezes não troca de novo.
  - `AplicarInadimplenciaTest`: upgrade pendente vencido vira `CANCELADA` (não `VENCIDA`) e o tenant **não** vai a inadimplente por causa dele; mensalidade e excedente pendentes vencidos continuam virando `VENCIDA`.
- [ ] **Step 2:** `php artisan test --filter="PagarCobrancaTest|ConciliarPagamentoTest|AplicarInadimplenciaTest"`. Expected: FAIL nos novos.
- [ ] **Step 3: Implementar.** `PagarCobranca`: aceitar `Excedente` e `Upgrade`; `descricao()` para o upgrade busca o plano destino. `ConciliarPagamento::aplicar`, no ramo que não estende período: depois de gravar a baixa, `if ($cobranca->tipo === TipoDeCobranca::Upgrade) { $this->aplicarUpgrade($cobranca, $assinatura); }` (tenant com lock; `forceFill` de `plano_id` no tenant e na assinatura; `valor_ciclo_centavos`; limpa `plano_proximo_*`; `activity`); a chamada ao gateway fica **depois do commit** (retorne um sinal ao `executar`, como o cartão do `PagarCobranca`) com `try/catch` e `report`. `AplicarInadimplenciaCommand`: antes do `update` geral, cancele os `UPGRADE` pendentes vencidos e restrinja o `update` para `tipo != UPGRADE`.
- [ ] **Step 4:** `php artisan test --filter="Billing"` + phpstan. Expected: PASS.
- [ ] **Step 5:** pint e commit `feat(billing): pagar e conciliar o upgrade de plano`.

---

### Task 5: Aplicar os downgrades e o boleto no preço novo

**Files:**
- Create: `Billing/Console/AplicarTrocasCommand.php`
- Modify: `Billing/Providers/BillingServiceProvider.php`, `routes/console.php`, `GerarBoletosDoCicloCommand`
- Test: `backend/tests/Feature/Billing/AplicarTrocasTest.php`, `GerarBoletosDoCicloTest.php` (acrescentar)

**Interfaces:**
- Produces: `billing:aplicar-trocas` (00:25 America/Sao_Paulo, `withoutOverlapping`, `onOneServer`): para cada assinatura `ATIVA` com `plano_proximo_em <= hoje`: reconfere `excessos`; sem excesso troca `tenant.plano_id` e `assinatura.plano_id`, grava `valor_ciclo_centavos`, limpa o agendamento, `activity`; com excesso cancela o agendamento, devolve o valor antigo no cartão (`atualizarAssinatura`) e `Log::warning`. Idempotente.

- [ ] **Step 1: Testes (RED)**
  - `AplicarTrocasTest`: aplica no dia (`plano_proximo_em` = hoje) e não antes; troca os dois planos e o valor do ciclo; idempotente (segunda rodada nada muda); com excesso cancela o agendamento, mantém o plano, devolve o valor no cartão e registra o aviso; restaura o `TenantContext`; assinatura cancelada ou conta não ativa é ignorada; isolamento (não troca plano de outro tenant).
  - `GerarBoletosDoCicloTest`: assinatura com `plano_proximo_id` gera o boleto do próximo ciclo no **preço do plano próximo**; sem agendamento, no do plano atual (comportamento atual).
- [ ] **Step 2:** `php artisan test --filter="AplicarTrocasTest|GerarBoletosDoCicloTest"`. Expected: FAIL nos novos.
- [ ] **Step 3: Implementar** (comando no molde de `AplicarInadimplenciaCommand`/`FecharExcedenteCommand`; a lógica de aplicar numa classe `Billing/Application/Actions/AplicarDowngrade` se passar de uns 40 linhas). Em `GerarBoletosDoCicloCommand::gerar`, o plano do preço é `Plano::find($assinatura->plano_proximo_id) ?? $assinatura->plano` e a descrição usa esse plano. Registre o comando e o agendamento (`Schedule::command('billing:aplicar-trocas')->dailyAt('00:25')->timezone('America/Sao_Paulo')->withoutOverlapping()->onOneServer();`).
- [ ] **Step 4:** testes acima + `php artisan schedule:list | grep trocas`. Expected: PASS.
- [ ] **Step 5:** pint, phpstan e commit `feat(billing): aplicar downgrades agendados`.

---

### Task 6: API da troca de plano

**Files:**
- Create: `Billing/Http/Requests/TrocarPlanoRequest.php`
- Modify: `AssinaturaDeCobrancaController`, `Billing/Http/routes-app.php`
- Test: `backend/tests/Feature/Billing/CobrancaApiTest.php` (acrescentar)

**Interfaces:**
- Produces: `POST /api/app/assinatura/trocar-plano` (`plano_id` uuid obrigatório) → 200 `data: { tipo: 'UPGRADE', cobranca: CobrancaResource, assinatura }` ou `{ tipo: 'DOWNGRADE', cobranca: null, assinatura }`; `DELETE /api/app/assinatura/trocar-plano` → 200 `data: assinatura`. Rotas `app.assinatura.trocar-plano` (POST) e `app.assinatura.cancelar-troca` (DELETE), **fora** de `LIBERADAS_PARA_PAGAR`.

- [ ] **Step 1: Testes (RED)** em `CobrancaApiTest`: upgrade devolve 201? **não: 200** com `data.tipo = UPGRADE` e a cobrança (`plano_destino_id`); downgrade devolve `plano_proximo_id` e `plano_proximo_em`; downgrade bloqueado 422 com `codigo = PLANO_EXCEDIDO` e `excessos`; `plano_id` inválido 422 de validação; vendedor/fiscal/leitura 403 `BILLING_SEM_PERMISSAO`; conta `INADIMPLENTE` ou `SUSPENSA` recebe 403/422 pelo middleware ou `BILLING_TROCA_INDISPONIVEL` (fixe o que o middleware já faz: escrita bloqueada vira o erro padrão da situação); `DELETE` cancela o agendamento; **isolamento:** o admin de outro tenant, sem assinatura, recebe 404/`BILLING_SEM_ASSINATURA` e nada muda; pagar o upgrade de outro tenant (`POST /assinatura/cobrancas/{id}/pagar`) dá 404; `GET /assinatura/cobrancas` traz `plano_destino_id`.
- [ ] **Step 2:** `php artisan test --filter=CobrancaApiTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** (controller só chama as Actions; a request valida formato). `AssinaturaDeCobrancaResource` ganha `plano_proximo_id` e `plano_proximo_em`; `CobrancaResource` ganha `plano_destino_id`.
- [ ] **Step 4:** `php artisan test --compact` (suíte inteira), phpstan, pint. Expected: verde.
- [ ] **Step 5:** commit `feat(billing): API de troca de plano`.

---

### Task 7: Frontend (trocar de plano, pagar o upgrade)

**Files:**
- Create: `frontend/features/cobranca/components/TrocaDePlano.tsx`
- Modify: `types.ts`, `api.ts`, `components/Assinar.tsx`, `components/ListaDeFaturas.tsx`, `Cobranca.test.tsx`/novo teste
- Test: `frontend/features/cobranca/components/TrocaDePlano.test.tsx`

**Interfaces:**
- Produces: `Cobranca.tipo` ganha `'UPGRADE'` e `plano_destino_id: string | null`; `AssinaturaDeCobranca` ganha `plano_proximo_id` e `plano_proximo_em` (`string | null`); `cobrancaApi.trocarPlano(plano_id)` → `{ data: { tipo: 'UPGRADE' | 'DOWNGRADE'; cobranca: Cobranca | null; assinatura: AssinaturaDeCobranca } }` e `cobrancaApi.cancelarTroca()`; `<TrocaDePlano plano atual />`.

- [ ] **Step 1: Testes (RED)** (mocks de `../api`, `usePlanosPublicos`, `useAssinatura`, `useUsuario`, `@/lib/mercadopago`):
  - assinante ativo e em dia vê "Trocar para este plano" nos outros planos (não no atual) e o aviso "Sua assinatura está ativa até…" continua;
  - clicar chama `trocarPlano`; upgrade abre o `PainelDePagamento` com o valor da cobrança retornada; downgrade mostra "A troca para <plano> vale em <data>" e o botão "Cancelar troca" (chama `cancelarTroca`);
  - plano excedido mostra os recursos acima do limite em alerta (a mensagem e `excessos` do erro);
  - assinatura com downgrade agendado mostra o aviso e esconde os outros botões de troca;
  - fatura `UPGRADE` mostra "Troca de plano: diferença de R$ x" e o botão **Pagar** (também em vencida não existe: upgrade vencido é cancelado);
  - papéis sem permissão não veem os botões; conta que não está ativa não vê "Trocar".
- [ ] **Step 2:** `npx vitest run features/cobranca`. Expected: FAIL.
- [ ] **Step 3: Implementar** no molde de `Checkout`/`PainelDePagamento` (reaproveite o painel; invalide `['assinatura']` e `['faturas']`). A diferença exibida vem da cobrança devolvida pelo servidor (o cliente nunca calcula valor).
- [ ] **Step 4:** `npx vitest run`, `npx tsc --noEmit`, `npm run lint`, `npm run build`. Expected: verde (lint só com o warning antigo).
- [ ] **Step 5:** commit `feat(cobranca): trocar de plano e pagar o upgrade`.

---

### Task 8: Documentação e fechamento

**Files:**
- Modify: `TAREFAS.md` (F5b2 `[x]`, "Pendências da F5b2"), `PROGRESSO.md` ("Fechamento da F5b2", próxima tarefa F6), `docs/cobranca.md` (troca de plano e roteiro de sandbox), memória `project_estado_atual.md` e `MEMORY.md`.

- [ ] **Step 1:** Contexto da F6 no `PROGRESSO.md`: ler a seção da API pública do spec mestre e `TAREFAS.md` (F6); fazer brainstorming (arquitetural).
- [ ] **Step 2:** `docs/cobranca.md`: fluxo de upgrade e downgrade, comando `billing:aplicar-trocas` e roteiro de sandbox (PIX/cartão do upgrade, `PUT /preapproval` com o valor novo valendo na próxima cobrança, boleto do ciclo seguinte no preço do downgrade).
- [ ] **Step 3:** Suíte completa (backend `php artisan test --compact`, phpstan, pint; frontend vitest, tsc, lint, build). Expected: verde.
- [ ] **Step 4:** Revisão final da branch por subagente (modelo `fable`) com `review-package` (base = commit do plano); corrigir Critical/Important em uma passada com teste RED→GREEN; Minors vão para "Pendências da F5b2". **Antes de enviar ao revisor, verifique que nenhum arquivo tocado tem UTF-8 inválido** (laço com `python -c` de `decode('utf-8')`).
- [ ] **Step 5:** Commit `docs: F5b2 concluída (troca de plano)`; apagar o workspace `.superpowers/sdd/2026-10-08-f5b2-troca-de-plano`; recomendar `/compact` ou `/clear`. Sem push.
