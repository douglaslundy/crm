# F3c1: dashboard fiscal: plano de implementação

> **Para executores:** SUB-SKILL OBRIGATÓRIA: `superpowers:executing-plans` (modo Native). Passos com `- [ ]`.

**Objetivo:** painel fiscal do tenant (notas do mês, valor emitido, faturamento de 12 meses, rejeições, certificado, ambiente).

**Arquitetura:** Action `ResumoFiscal` (Fiscal/Application) devolve um DTO-array; `DashboardFiscalController` responde `GET /api/app/dashboard/fiscal`; o frontend ganha `features/dashboard-fiscal` e o bloco "Fiscal" no painel.

**Stack:** Laravel 12, PHPUnit; Next/React, TanStack Query, `recharts` (nova), vitest.

**Spec:** `docs/superpowers/specs/2026-10-05-f3c1-dashboard-fiscal-design.md`. **Skill fiscal:** `br-fiscal-note-emission` (dado ausente nunca vira zero por conveniência).

## Global Constraints

- `declare(strict_types=1)`; TypeScript strict sem `any`; Action reutilizável (sem regra no controller); Global Scope por tenant e teste de isolamento.
- Meses em `America/Sao_Paulo`; consultas portáveis (SQLite/PostgreSQL), sem SQL específico de banco.
- Valor emitido = soma de `total_centavos` de AUTORIZADA e CANCELANDO com `emitido_em` no período; só o **ambiente atual** do emitente.
- Comandos: backend `php artisan test`, `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`; frontend `npm run test`, `typecheck`, `lint`, `build`.
- Sem push. Commits locais, um por tarefa. Testes que criam várias notas usam números/chaves distintos (índice único).

## Review Focus

- Nota emitida às 23h30 de 31/mar em São Paulo (02h30 UTC de 01/abr) conta em **março**.
- Mês sem notas aparece com `valor_centavos` 0 e `quantidade` 0 (12 itens sempre).
- Notas do outro ambiente (homologação x produção) não entram.
- Certificado sem validade: `dias_restantes` nulo (nunca 0); vencido: negativo.
- Plano sem módulo fiscal: 403; tenant B não vê nada do A.

---

### Task 1: Action `ResumoFiscal`

**Arquivos:** criar `backend/app/Modules/Fiscal/Application/ResumoFiscal.php`, `backend/tests/Feature/Fiscal/ResumoFiscalTest.php`.

**Produz:** `ResumoFiscal::executar(?CarbonImmutable $agora = null): array` com chaves `ambiente`, `certificado`, `mes`, `faturamento_12_meses`, `rejeicoes` (formato da spec §3).

- [ ] **Passo 1: testes (RED).** `ResumoFiscalTest` (`CenarioDeNota`, `RefreshDatabase`; criar notas com `Nota::factory()` + `forceFill`, números e chaves distintos). Fixar `$agora = CarbonImmutable::parse('2026-10-15 12:00', 'America/Sao_Paulo')`. Casos: (1) 12 meses de nov/2025 a out/2026, em ordem, todos presentes, zeros reais; (2) notas AUTORIZADA em set e out somam nos meses certos, CANCELADA não soma, CANCELANDO soma; (3) só o ambiente atual (nota PRODUCAO ignorada com o emitente em HOMOLOGACAO); (4) `mes.notas` lista `{modelo,status,quantidade}` só com quantidade > 0 e separa NFE de NFCE; (5) rascunho/rejeitada/erro entram no mês pela `created_at`; (6) fuso: `emitido_em` = `2026-04-01T02:30:00Z` (23h30 de 31/mar em SP) conta em março; (7) rejeições: 7 REJEITADA/ERRO ⇒ `quantidade` 7 e `recentes` 5, mais nova primeiro, com `modelo`, `numero`, `cstat`, `motivo`, `mensagem_erro`, `status`; (8) certificado: validade futura ⇒ `dias_restantes` positivo, vencida ⇒ negativo, sem validade ⇒ `validade` e `dias_restantes` nulos; (9) isolamento: notas de outro tenant não aparecem. Esperado: FAIL (classe inexistente).
- [ ] **Passo 2: implementar.** Ler `Emitente::query()->firstOrCreate([], [])` (padrão das Actions); `$ambiente = $emitente->ambiente_fiscal->value`. Para o intervalo de cada mês, `inicio = $agora->startOfMonth()` em São Paulo e `fim = endOfMonth()`, convertidos a UTC para comparar com as colunas. `valor/quantidade` por mês: `Nota::query()->where('ambiente', $ambiente)->whereIn('status', [Autorizada, Cancelando])->whereBetween('emitido_em', [$ini, $fim])` com `->count()` e `->sum('total_centavos')` (12 iterações). `mes.notas`: duas consultas agrupadas (`groupBy('modelo','status')` com `selectRaw('modelo, status, count(*) as quantidade')`): uma para status emitidos (`emitido_em` no mês) e outra para os demais (`created_at` no mês). Rejeições: `where('ambiente', $ambiente)`? **Não filtrar por ambiente**: erro e rejeição de qualquer ambiente pedem ação **só se do ambiente atual**; usar o ambiente atual para ser coerente com o resto (registrar Ruling se mudar). Certificado: `dias_restantes = $agora->startOfDay()->diffInDays($validade->startOfDay(), false)` (inteiro com sinal).
- [ ] **Passo 3:** verde, Pint, PHPStan. **Passo 4: commit** `feat(fiscal): Action ResumoFiscal`.

### Task 2: endpoint

**Arquivos:** criar `backend/app/Modules/Fiscal/Http/Controllers/DashboardFiscalController.php`, `backend/tests/Feature/Fiscal/DashboardFiscalApiTest.php`; modificar `Http/routes-app.php`.

- [ ] **Passo 1: testes (RED).** `GET /api/app/dashboard/fiscal`: 200 com a estrutura (`data.ambiente`, `data.certificado`, `data.mes.periodo`, `data.faturamento_12_meses` com 12 itens, `data.rejeicoes.quantidade`); papel Leitura recebe 200; sem login 401; plano sem `FISCAL_NFE` nem `FISCAL_NFCE` ⇒ 403 `MODULO_NAO_CONTRATADO`; plano só com `FISCAL_NFCE` ⇒ 200; **isolamento**: usuário de outro tenant não vê as notas do primeiro (valores zerados). Esperado: FAIL (404).
- [ ] **Passo 2: implementar.** Controller invocável: verifica `temModulo(FiscalNfe) || temModulo(FiscalNfce)` com `EntitlementService` (se nenhum, `ModuloNaoContratadoException::para(Modulo::FiscalNfe)`), chama `ResumoFiscal` e responde `response()->json(['data' => $resumo])`. Rota `Route::get('dashboard/fiscal', DashboardFiscalController::class)->name('app.dashboard.fiscal');` dentro do grupo `empresa`.
- [ ] **Passo 3:** verde, suíte inteira, Pint, PHPStan. **Passo 4: commit** `feat(fiscal): GET /dashboard/fiscal`.

### Task 3: tipos, api, hook e helpers do frontend

**Arquivos:** criar `frontend/features/dashboard-fiscal/{types.ts,api.ts,hooks/useDashboardFiscal.ts,helpers.ts,helpers.test.ts}`.

**Produz:** `DashboardFiscal` (espelha a API), `dashboardFiscalApi.buscar()`, `useDashboardFiscal()`, `faixaDoCertificado(dias: number | null): 'ok' | 'alerta' | 'vencido' | 'sem-validade'`, `rotuloDoMes('2026-10') => 'out/2026'`, `mesDeMaiorValor(itens): string | null`.

- [ ] **Passo 1: testes (RED).** `faixaDoCertificado`: `null` ⇒ `sem-validade`; `-1` ⇒ `vencido`; `0` ⇒ `alerta`; `30` ⇒ `alerta`; `31` ⇒ `ok`. `rotuloDoMes`: `'2026-10'` ⇒ `out/2026`, `'2026-01'` ⇒ `jan/2026`. `mesDeMaiorValor`: devolve o mês do maior valor, `null` se todos são 0.
- [ ] **Passo 2: implementar** (puros, sem Date no fuso: usar o texto do mês). `api.ts`: `api<{ data: DashboardFiscal }>('/api/app/dashboard/fiscal')`; hook com `useQuery` e chave `['dashboard-fiscal']`.
- [ ] **Passo 3:** testes, typecheck. **Passo 4: commit** `feat(dashboard): tipos, api e helpers do painel fiscal`.

### Task 4: componentes e gráfico

**Arquivos:** `npm install recharts` em `frontend/`; criar `frontend/features/dashboard-fiscal/components/{PainelFiscal.tsx,GraficoDeFaturamento.tsx,PainelFiscal.test.tsx}`.

- [ ] **Passo 1: testes (RED).** `PainelFiscal` com o hook mockado e `recharts` mockado (`vi.mock('recharts', ...)` com `ResponsiveContainer`, `BarChart`, `Bar`, `XAxis`, `Tooltip`, `Cell` triviais): (a) mostra o valor emitido em reais e o total de notas do mês, e as `Pilula` por status com o modelo; (b) rejeições: contagem e, por item, link `/notas/<id>` com o motivo; (c) certificado: texto de dias restantes e `data-faixa` conforme a faixa (verde > 30, âmbar ≤ 30, vermelho vencido/sem validade); (d) ambiente: `PRODUCAO` mostra o aviso "Ambiente de PRODUÇÃO" (`role="alert"`), `HOMOLOGACAO` o aviso informativo (`role="status"`); (e) tabela acessível do gráfico: `table` com `aria-label` "Faturamento dos últimos 12 meses" e 12 linhas; (f) sem notas no mês: "Nenhuma nota neste mês ainda."; (g) carregando e erro (`Banner`). Esperado: FAIL.
- [ ] **Passo 2: implementar** com `Card`, `StatCard`, `Pilula`, `Banner`, `BarraDeProgresso` não necessário; gráfico `GraficoDeFaturamento({ itens })` com `BarChart` (maior mês `primary`, demais `primary` a 30% via `fill`/`fillOpacity` em `Cell`) e a tabela `sr-only`. Valores com `formatarCentavos`.
- [ ] **Passo 3:** testes, typecheck, lint. **Passo 4: commit** `feat(dashboard): painel fiscal e gráfico de faturamento`.

### Task 5: integração no painel

**Arquivos:** modificar `frontend/app/(app)/dashboard/page.tsx`; teste em `frontend/features/dashboard-fiscal/components/DashboardPage.test.tsx` (ou o teste existente da página, se houver).

- [ ] **Passo 1: teste (RED).** Com `useAssinatura` mockado: plano com `FISCAL_NFE` ⇒ o bloco "Fiscal" aparece; plano só com `CRM` ⇒ não aparece; a saudação e o consumo do plano continuam.
- [ ] **Passo 2: implementar.** `const temFiscal = assinatura?.plano?.modulos.some((m) => m === 'FISCAL_NFE' || m === 'FISCAL_NFCE')`; renderizar `<PainelFiscal />` quando verdadeiro, abaixo dos dois cartões atuais (grade de uma coluna larga).
- [ ] **Passo 3:** `npm run test && typecheck && lint && build`. **Passo 4: commit** `feat(dashboard): bloco fiscal no painel da empresa`.

### Task 6: fechamento

- [ ] Suítes completas (backend e frontend), Pint, PHPStan, build verdes.
- [ ] `TAREFAS.md` (F3c1 concluída; F3c2 e F3c3 pendentes), `PROGRESSO.md` (feito, decisões, roteiro: abrir o painel no staging), "Próxima tarefa" = F3c2 (brainstorming), memória do projeto atualizada.
- [ ] Auto-revisão declarada no ledger. Commit `docs: F3c1 concluída`. Nenhum push sem confirmação.
