# F3c1: dashboard fiscal (design)

Data: 2026-10-05. Fase do roteiro: F3 (NF-e e NFC-e), sexta fatia. Base: F3a a F3b3b concluídas; spec mestre §10 (dashboards) e §7.

## 1. Contexto e decomposição

A F3c (contingência, retransmissão, dashboard fiscal) foi dividida em três specs, nesta ordem (decisão do usuário, 2026-10-05): **F3c1 dashboard fiscal (este documento)**, F3c2 contingência offline da NFC-e com retransmissão e prazo, F3c3 EPEC da NF-e. Contingência entra **só automaticamente** em falha real de comunicação com a SEFAZ (timeout, conexão recusada, HTTP diferente de 200), nunca por rejeição, schema ou configuração.

**Fora da F3c1:** contingências com prazo no painel (F3c2, quando existir o status), dashboard do CRM, dashboard da plataforma (já existe em `/admin`), exportação, filtros por período livre, notas de terceiros.

## 2. Decisões do usuário (2026-10-05)

1. Dashboard primeiro (testável sem SEFAZ).
2. Gráficos com **`recharts`** (nova dependência do frontend; prevista na spec mestre).
3. Escopo: **mês corrente e 12 meses**, visível a **todos os papéis** da empresa que enxergam notas (Leitura inclusive).
4. Design aprovado no chat (seções 3 e 4).

## 3. API

`GET /api/app/dashboard/fiscal` (grupo `empresa`). Lógica na Action `ResumoFiscal` (Fiscal/Application), reutilizável pela API pública; o controller só a chama. O Global Scope isola o tenant; todo acesso por outro tenant simplesmente não enxerga as notas do primeiro.

- **Permissão:** qualquer papel da empresa. O plano precisa ter `FISCAL_NFE` **ou** `FISCAL_NFCE`; senão `403` com `MODULO_NAO_CONTRATADO`. Sem login: `401`.
- **Ambiente:** os números valem só para o **ambiente atual do emitente** (`HOMOLOGACAO` ou `PRODUCAO`); o payload devolve qual.
- **Resposta (`data`):**
  - `ambiente`: `HOMOLOGACAO | PRODUCAO`.
  - `certificado`: `{ status, validade (YYYY-MM-DD|null), dias_restantes (int|null) }`; `dias_restantes` negativo quando vencido, `null` sem validade (nunca 0 por default).
  - `mes`: `{ periodo: "YYYY-MM", notas: [{ modelo, status, quantidade }], valor_emitido_centavos }`. `notas` traz só combinações com quantidade > 0. O mês é o da data de criação da nota para os status sem emissão (rascunho, rejeitada, erro, processando) e o de `emitido_em` para autorizada, cancelando e cancelada.
  - `valor_emitido_centavos`: soma de `total_centavos` das notas **AUTORIZADA e CANCELANDO** com `emitido_em` no mês (cancelada sai da conta).
  - `faturamento_12_meses`: 12 itens, do mais antigo ao mês corrente, **sempre preenchidos** (zero real quando não há notas): `{ mes: "YYYY-MM", valor_centavos, quantidade }`, mesmo critério do valor emitido.
  - `rejeicoes`: `{ quantidade, recentes: [{ id, modelo, numero, cstat, motivo, mensagem_erro, status, created_at }] }` das notas `REJEITADA` e `ERRO`, mais novas primeiro, no máximo 5; `quantidade` é o total (sem limite).
- **Fuso:** meses calculados em `America/Sao_Paulo`.
- **Agregação:** uma consulta por mês (12) e uma por grupo, portáveis entre SQLite (testes) e PostgreSQL, sem SQL específico de banco.
- **Dado ausente nunca vira zero por conveniência:** só o zero real (nenhuma nota) é 0.

## 4. Frontend

`app/(app)/dashboard` mantém saudação e consumo do plano e ganha o bloco "Fiscal", **visível só se o plano tem módulo fiscal** (via `useAssinatura`). Componentes em `features/dashboard-fiscal/` (api, hooks, components, types):

- **Cartões (`StatCard`):** notas do mês (total; por status em `Pilula`, com o modelo), valor emitido no mês (reais), rejeições que pedem ação (com link para cada nota), certificado (validade e dias restantes: verde > 30, âmbar 1 a 30, vermelho vencido ou sem validade).
- **Ambiente:** `Banner` informativo em homologação; `Banner` de perigo "Ambiente de PRODUÇÃO" em produção.
- **Gráfico:** barras do faturamento dos 12 meses (`recharts`, maior mês em `primary`, demais a 30%), com **tabela equivalente só para leitor de tela** (`sr-only`): a informação não depende de cor.
- **Estados:** carregando (esqueleto), vazio ("Nenhuma nota neste mês ainda."), erro (`Banner`).
- Valores monetários formatados com `formatarCentavos`; sem lógica fiscal no frontend.

## 5. Testes

- **Backend:** zero-fill dos 12 meses e ordem; mês corrente separado dos passados; só o ambiente atual; cancelada fora do valor e cancelando dentro; rejeições (máximo 5, ordem, total sem limite); certificado com validade futura, vencido e sem validade; 403 sem módulo fiscal; 401; Leitura pode; **isolamento entre tenants**; fuso (nota às 23h do último dia do mês em São Paulo cai no mês certo).
- **Frontend:** cartões e valores; estados vazio, carregando e erro; cor do certificado por faixa de dias; bloco oculto sem módulo fiscal; tabela acessível do gráfico; `recharts` mockado no jsdom (`ResponsiveContainer` não mede em jsdom).

## 6. Critério de pronto

Backend e frontend verdes (Pint, PHPStan, `tsc`, lint, build). Em ambiente real (usuário): abrir o painel no staging com as notas de demonstração e conferir os números e o gráfico nos dois temas e em 360 px.

## 7. Pendências conhecidas (registrar em `TAREFAS.md`)

Contingências com prazo no painel (F3c2); índice em `notas(tenant_id, emitido_em)` se o volume pedir; filtros de período; cache do resumo; faturamento por cliente/produto; dashboard do CRM (F7).
