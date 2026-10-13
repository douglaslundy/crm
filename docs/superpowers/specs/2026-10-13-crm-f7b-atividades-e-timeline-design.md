# F7b: CRM, atividades, agenda, timeline e negócio parado (design)

Data: 2026-10-13. Depende da F7a (funis e negócios). Spec mestre: `2026-09-25-plataforma-fiscal-crm-design.md` §9 e §10.

## 1. Objetivo

O vendedor registra e agenda contatos (ligação, reunião, e-mail, WhatsApp, tarefa), vê a agenda do dia e da semana, vê a história do cliente numa timeline e é avisado de negócios sem movimento. WhatsApp e e-mail saem em 1 clique e já ficam registrados.

**Fora de escopo:** propostas e nota fiscal na timeline (F7c), relatórios e metas (F7d), notificação externa (e-mail, push), atividade recorrente, anexos, API pública `/api/v1` do CRM.

## 2. Decisões

| # | Decisão | Motivo |
|---|---|---|
| D1 | Atividade tem `cliente_id` obrigatório e `negocio_id` opcional | follow-up de lead sem negócio aberto aparece na agenda e na timeline |
| D2 | Lembrete só dentro do app: "hoje" e "atrasadas" na agenda, no painel e no contador do menu | disciplina de follow-up sem job nem e-mail |
| D3 | Parado é calculado na leitura, sem job nem coluna de estado | sempre consistente, sem agendador |
| D4 | Timeline em tabela própria, gravada por listeners e pelas Actions | a F7a não guarda histórico de etapa; a F7c só adiciona listeners |
| D5 | Contato em 1 clique registra a atividade já concluída ao clicar | é o "registro automático" do spec mestre; custo aceito: quem clica e desiste fica registrado |
| D6 | Telefone ou e-mail inválido bloqueia o clique com mensagem acionável | regra do projeto: nunca chutar valor |
| D7 | "Hoje" e "atrasada" usam o fuso `America/Sao_Paulo` | corrige o problema de UTC das previsões da F7a |

## 3. Dados (módulo `Crm`; todo Model com Global Scope por `tenant_id`)

**`atividades`**
- `id` (uuid), `tenant_id`, `cliente_id` (obrigatório), `negocio_id` (nulável)
- `tipo`: LIGACAO, REUNIAO, EMAIL, WHATSAPP, TAREFA
- `titulo` (obrigatório), `descricao` (nulável)
- `agendada_para` (UTC, obrigatório), `concluida_em` (nulo = aberta)
- `responsavel_id`, `criado_por`, timestamps
- Índices: `(tenant_id, responsavel_id, concluida_em, agendada_para)` e `(tenant_id, cliente_id)`

**`timeline_registros`**
- `id` (uuid), `tenant_id`, `cliente_id`, `negocio_id` (nulável)
- `tipo`: NOTA, ATIVIDADE_CONCLUIDA, NEGOCIO_CRIADO, NEGOCIO_MOVIDO, NEGOCIO_GANHO, NEGOCIO_PERDIDO
- `texto`, `dados` (json: nomes das etapas de/para, tipo e título da atividade, motivo da perda; snapshot, sobrevive à edição da origem)
- `atividade_id` (nulável; liga o registro `ATIVIDADE_CONCLUIDA` à atividade, para a reabertura)
- `autor_id`, `ocorreu_em`
- Índice `(tenant_id, cliente_id, ocorreu_em desc, id)`

**`etapas.dias_para_parado`**: inteiro nulável, mínimo 1 (nulo = sem alerta).

Mudança de schema só por migration.

## 4. Escrita

- **Listeners síncronos** em `NegocioCriado`, `NegocioMovido`, `NegocioGanho` e `NegocioPerdido` gravam o registro da timeline. Os eventos saem após o commit: falha do listener é logada e não derruba a ação. Risco assumido: o registro dessa ação se perde.
- **`ConcluirAtividade` e `CriarNotaDoCliente`** gravam o registro na mesma transação da Action.
- **Actions** (frontend e API chamam as mesmas): `CriarAtividade`, `AtualizarAtividade`, `ConcluirAtividade`, `ReabrirAtividade`, `ExcluirAtividade`, `RegistrarContato`, `CriarNotaDoCliente`.
- **Reabrir** remove o registro `ATIVIDADE_CONCLUIDA` correspondente (a timeline não conta uma conclusão desfeita). **Excluir** atividade não apaga registros já gravados.
- **`RegistrarContato`:** cria a atividade WHATSAPP ou EMAIL com `agendada_para = concluida_em = agora`, para o cliente (e o negócio, se vier da tela dele), e devolve a URL (`https://wa.me/55<DDD+número>?text=` ou `mailto:`). Telefone sem DDD e 10 ou 11 dígitos, ou e-mail ausente, responde 422 com mensagem dizendo qual cadastro corrigir. Sem default.
- **Responsável:** por padrão o autor; só quem tem acesso ao negócio ou ao cliente pode ser escolhido (contrato `DiretorioDeUsuarios` da F7a).

## 5. Parado (D3)

Negócio aberto está parado quando `max(criado_em, última NEGOCIO_MOVIDO, última atividade concluída do negócio)` tem `dias_parado >= dias_para_parado` da etapa atual, com `dias_parado` contado em dias de calendário no fuso SP. Etapa com `dias_para_parado` nulo nunca para. Ganho e perdido nunca estão parados.

O `ApresentadorDeNegocios` devolve `parado` (bool) e `dias_parado` (int ou null). `GET /negocios` ganha `?parado=1`. A consulta usa subquery agregada, sem N+1; o kanban continua carregando os abertos do funil.

## 6. API (`/api/app/crm`)

| Rota | Função |
|---|---|
| `GET/POST /atividades`, `PUT/DELETE /atividades/{id}` | CRUD; filtros `cliente_id`, `negocio_id`, `responsavel_id`, `concluida` |
| `POST /atividades/{id}/concluir`, `POST /atividades/{id}/reabrir` | estado |
| `GET /agenda?de&ate&responsavel_id` | atividades abertas no intervalo, mais as atrasadas |
| `POST /contatos` | contato em 1 clique (`cliente_id`, `negocio_id?`, `canal`) |
| `GET /clientes/{id}/timeline` | paginação por cursor, mais recentes primeiro |
| `POST /clientes/{id}/notas` | nota livre (até 2000 caracteres) |
| `GET /resumo` | `hoje`, `atrasadas`, `parados` do usuário (menu e painel) |

IDs em rota com `whereUuid`; filtros validados como uuid (Postgres daria 500).

**Permissões** (`PoliticaDoCrm`): papel Fiscal sem acesso; vendedor com "vê só os seus" vê atividades em que é responsável ou de negócios que enxerga; na timeline, registros de negócios que enxerga mais as próprias notas e atividades. Fora da visibilidade: 404, nunca 403.

## 7. Frontend (`features/crm`)

- `/crm/agenda`: atrasadas em destaque, hoje, semana, filtro por responsável, concluir com um clique.
- Detalhe do negócio: aba Atividades, criar atividade, botões WhatsApp e E-mail.
- Cliente: aba Timeline, nota rápida, botões de contato.
- Kanban: selo "parado há N dias" e filtro "parados".
- Menu: contador de atrasadas (item de menu do módulo `CRM`).
- Painel: bloco CRM (hoje, atrasadas, parados), só com o módulo no plano.
- Configurações do CRM: campo "Dias para parado" por etapa.
- Datas `DD/MM/AAAA`, botões com loading que impede duplo envio (`useEnvioUnico`), design system existente.

## 8. Testes

- Isolamento de tenant em todo endpoint novo.
- Matriz de papéis e visibilidade do vendedor (404).
- Parado nos limites: exatamente X dias, etapa sem alerta, virada de dia no fuso SP, ganho e perdido.
- Listeners: um registro por evento; falha do listener não derruba a Action.
- `RegistrarContato`: telefone e e-mail inválidos, normalização, atividade criada concluída.
- Reabrir remove o registro da timeline; excluir não.
- `lockForUpdate` é no-op no SQLite dos testes: concorrência só vale em PostgreSQL (declarar na verificação, como na F7a).
- Frontend: agenda, timeline, contato em 1 clique, selo de parado; eslint com os gates, tsc e build limpos.

## 9. Ordem de construção sugerida

1. Migrations e Models (atividades, timeline, `dias_para_parado`).
2. Actions de atividade e nota, mais `RegistrarContato`.
3. Listeners da timeline e `GET timeline`.
4. Cálculo de parado no apresentador e filtro.
5. `agenda`, `resumo`, política.
6. Frontend: agenda, aba do negócio, timeline do cliente, selo, menu, painel, configuração.
7. Revisão final e fechamento em `PROGRESSO.md`.
