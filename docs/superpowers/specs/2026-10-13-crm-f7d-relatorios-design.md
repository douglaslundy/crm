# F7d: relatórios, metas e dashboard comercial do CRM

Data: 2026-10-13. Fase: F7d (última parte da F7). Projeto mestre: `2026-09-25-plataforma-fiscal-crm-design.md` §9 e §10.

## 1. Objetivo

Dar ao gestor e ao vendedor a visão numérica do funil: quanto foi ganho e perdido, por quê, quanto se espera fechar, como cada vendedor está contra a meta do mês e quanto virou faturamento.

## 2. Decisões do usuário (brainstorming)

- **Escopo:** núcleo (relatórios, metas e dashboard). CSV do CRM e relatório de faturamento geral ficam fora.
- **Metas:** por vendedor e por mês, em valor (centavos) ou quantidade, contadas por negócio **ganho** na data de fechamento. Só proprietário e admin definem; o vendedor lê a própria.
- **Conversão por etapa sem histórico:** a conversão é do período (ganhos ÷ ganhos+perdidos). O funil mostra a situação atual por etapa. Não há tabela de passagem por etapa.
- **Telas:** uma página `/crm/relatorios` com filtro de período e abas. O bloco do CRM no painel ganha funil, previsão e meta do mês.
- **Faturamento:** soma das notas autorizadas ligadas às propostas aceitas, lida por contrato Crm→Fiscal.

## 3. Fora de escopo

Histórico de etapas e tempo médio por etapa; CSV do CRM; meta de atividades; exportação dos relatórios; cache; relatório de faturamento geral do tenant (já existe no dashboard fiscal).

## 4. Modelo de dados

Migration nova `crm_metas`:

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | uuid | PK |
| `tenant_id` | uuid | FK `tenants`, Global Scope |
| `usuario_id` | uuid | usuário do mesmo tenant |
| `mes` | date | sempre o dia 1 do mês, no fuso do negócio |
| `unidade` | string(10) | `VALOR` ou `QUANTIDADE` |
| `alvo` | unsigned bigint | centavos (`VALOR`) ou número de negócios (`QUANTIDADE`); maior que zero |
| `timestamps` | | |

Índice único `(tenant_id, usuario_id, mes)`: uma meta por vendedor e mês. Índice novo em `crm_negocios (tenant_id, status, fechado_em)` para os relatórios.

Model `MetaDoVendedor` com `BelongsToTenant`. Enum `UnidadeDaMeta`.

## 5. Backend

### 5.1 Actions (`Crm/Application/Actions`)

- `DefinirMeta` (upsert por usuário e mês): exige `PoliticaDoCrm::garantirPodeConfigurar`; usuário ativo do mesmo tenant com papel que opera negócios (proprietário, admin ou vendedor); `alvo > 0`; `mes` normalizado para o dia 1.
- `RemoverMeta`: mesma permissão.
- `ListarMetas` (mês): proprietário e admin veem todas; vendedor vê só a própria.
- `RelatorioComercial`: leitura. Entrada: `de`, `ate` (datas no fuso do negócio, período máximo de 366 dias), `vendedor_id` opcional (só proprietário e admin). Saída (valores em centavos):
  - `resumo`: abertos, ganhos e perdidos (quantidade e valor); `taxa_de_conversao` (`null` quando não há negócio fechado no período; zero só quando há fechados e nenhum ganho).
  - `funil`: por etapa ativa do funil padrão ou do `funil_id` pedido: quantidade, valor e probabilidade dos negócios abertos.
  - `previsao`: soma de `valor × probabilidade / 100` dos abertos com `previsao_fechamento` dentro do período, mais `sem_previsao` (quantidade e valor dos abertos sem data).
  - `motivos_de_perda`: por motivo, quantidade e valor; "Sem motivo" quando nulo.
  - `vendedores`: ganhos e perdidos (quantidade e valor), abertos, e `meta` (`unidade`, `alvo`, `realizado`, `percentual`) quando o período cobre exatamente um mês e há meta. Lista omitida para vendedor restrito.
  - `propostas`: enviadas, aceitas, recusadas e valor aceito no período (por `enviada_em` e `respondida_em`); `taxa_de_aceite` (`null` sem respondidas).
  - `faturado`: soma dos totais das notas autorizadas das propostas aceitas no período (`null` quando o plano não inclui Fiscal).
- `ResumoDoCrm` ganha `funil`, `previsao_do_mes` e `meta_do_mes` do usuário. Os campos atuais (`hoje`, `atrasadas`, `parados`) ficam.

### 5.2 Contrato Crm→Fiscal

`Crm/Domain/Contracts/ConsultorDeValorFaturado`: recebe `list<string>` de ids de nota e devolve centavos das notas com status autorizado. Implementado em `Fiscal/Application` e ligado em `FiscalServiceProvider`, no mesmo padrão de `GeradorDeRascunhoDeNota`. O Crm não importa Model do Fiscal.

### 5.3 Regras

- Agregação em SQL (`GROUP BY`), sem carregar negócios na memória nem N+1.
- Ganho e perda entram pelo `fechado_em`, convertido do fuso do negócio para UTC nas bordas do período. Negócio fechado não reabre, então `fechado_em` é estável.
- Valor zero é válido. Taxa sem denominador é `null`, nunca 0% inventado.
- Visibilidade: tudo passa por `Negocio::visiveisPara`. Vendedor restrito vê só os seus números, sem ranking e sem filtro de vendedor.
- Módulo CRM do plano exigido (`PoliticaDoCrm`). `faturado` exige também Fiscal no plano; sem ele vem `null`.
- Período inválido (`ate` antes de `de`, mais de 366 dias, data malformada) responde 422 com mensagem acionável.

### 5.4 API (`api/app/crm`)

| Método | Rota | Quem |
|---|---|---|
| GET | `relatorios?de&ate&vendedor_id&funil_id` | leitura do CRM |
| GET | `metas?mes` | leitura do CRM (vendedor restrito: a própria) |
| PUT | `metas` (`usuario_id`, `mes`, `unidade`, `alvo`) | proprietário e admin |
| DELETE | `metas/{id}` | proprietário e admin |

Controller fino chamando as Actions, com Resource/Apresentador no padrão do módulo. Entram na documentação interna da API autenticada, não na API pública `/api/v1`.

## 6. Frontend

- `/crm/relatorios` (rota em `app/(app)/crm/relatorios`): filtro de período (mês atual, mês anterior, 90 dias, intervalo), filtro de vendedor para admin, abas **Visão geral** (cards de resumo, funil em barras, previsão, propostas e faturado), **Vendedores** (tabela que vira cards no celular, com barra de meta) e **Perdas** (motivos).
- Diálogo **Metas do mês** para admin e proprietário (definir e remover por vendedor).
- Bloco do CRM no painel: funil resumido, previsão do mês e meta do mês do usuário.
- Estados de carregando, vazio e erro; zero exibido como `R$ 0,00` ou `0`, `null` como "—". Textos em pt-BR; tokens do design system; Recharts já está no projeto.

## 7. Testes

- Backend: isolamento por tenant em cada endpoint novo; vendedor restrito não vê alheios nem ranking; período (bordas de mês, fuso, 366 dias, inválido); zero e `null` da taxa; funil só com etapas ativas; previsão com e sem data; meta única por mês e upsert; permissão de definir meta; `faturado` nulo sem Fiscal e somando só autorizadas; contrato Crm→Fiscal.
- Frontend: página com dados, vazio e erro; vendedor sem aba Vendedores; diálogo de metas; bloco do painel.

## 8. Riscos e pendências conhecidas

- Sem histórico de etapas, o funil é uma foto de agora; o período só afeta ganhos, perdas, previsão e propostas. A tela diz isso.
- Metas de meses futuros e passados são editáveis; sem trava, por simplicidade.
- Faturado depende de a nota estar autorizada; nota em processamento não conta até autorizar.
