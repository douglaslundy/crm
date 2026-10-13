# F7a: CRM, funis e negócios

Primeira de quatro partes da F7 (CRM). Projeto mestre: `2026-09-25-plataforma-fiscal-crm-design.md` (§9 e §10).

## 1. Objetivo e escopo

O vendedor de uma PME gerencia negócios num funil kanban, com itens do catálogo, ganho ou perda com motivo, e o gestor configura funis, etapas e motivos. É a base sobre a qual as partes seguintes (atividades e timeline, propostas e aceite para nota, relatórios e metas) se apoiam.

**Decomposição da F7 (cada parte com spec, plano e execução próprios):**

| Parte | Conteúdo | Depende de |
|---|---|---|
| **F7a (este documento)** | funis e etapas configuráveis, negócios, itens, ganho e perda com motivo, kanban, visibilidade por vendedor | Customers, Catalog |
| F7b | atividades e agenda, timeline do cliente, alerta de negócio parado, WhatsApp e e-mail em 1 clique | F7a |
| F7c | propostas em PDF, link público com aceite, aceite → ganho → emissão da nota | F7a, F7b, Fiscal |
| F7d | relatórios, metas por vendedor, dashboard CRM | F7a a F7c |

**Fora de escopo da F7a:** atividades, agenda, timeline, propostas, relatórios, metas, reabrir negócio fechado, campos personalizados, importação e exportação CSV de negócios, API pública `/api/v1` do CRM, notificações.

## 2. Decisões

| # | Decisão | Motivo |
|---|---|---|
| D1 | Todo negócio pertence a um `Cliente`. O formulário permite criar um lead na hora (`estagio = LEAD`) | cadastro único, timeline por cliente e conversão para nota sem caso especial |
| D2 | Um único interruptor por empresa: "vendedor vê só os seus negócios", padrão ligado | simples de explicar e testar |
| D3 | Itens do negócio são opcionais e só do catálogo (`Produto` ou `Servico`), com snapshot de descrição, quantidade e valor unitário | a emissão da F7c usa dados fiscais do catálogo, sem item avulso sem dado fiscal |
| D4 | Motivo de perda é uma lista por empresa, obrigatório ao perder, com observação opcional | relatório de motivos da F7d confiável |
| D5 | `Negocio` tem `etapa_id` (onde está) e `status` `ABERTO`, `GANHO` ou `PERDIDO` (como terminou). Ganho e perda não são colunas do kanban | etapas finais complicariam a configuração do funil |
| D6 | `Crm` não acessa tabelas nem Models de outros módulos; usa contratos declarados em `Crm/Domain/Contracts` e implementados em Customers e Catalog | regra de fronteiras da spec mestre (§3); inclui o diretório de usuários (responsável do negócio), para o `Crm` não importar o Model `Usuario` |
| D7 | Papel Fiscal sem acesso ao CRM | a spec descreve vendedor, gestor e leitura |
| D8 | `@dnd-kit/core` e `@dnd-kit/sortable` para o arrastar no frontend | HTML5 nativo não funciona bem em toque; a biblioteca cobre teclado |

## 3. Modelo de dados

Todas as tabelas levam `tenant_id` (UUID, `BelongsToTenant` com Global Scope), chaves UUID e valores em centavos inteiros, como o catálogo (`preco_centavos`). Mudanças só por migration.

| Tabela | Campos principais |
|---|---|
| `crm_configuracoes` | `tenant_id` (único), `vendedor_ve_so_os_seus` (bool, padrão `true`) |
| `crm_funis` | `nome`, `padrao` (bool; um por empresa), `ativo`, `posicao` |
| `crm_etapas` | `funil_id`, `nome`, `posicao`, `probabilidade` (0 a 100), `ativa` |
| `crm_motivos_de_perda` | `nome`, `ativo` |
| `crm_negocios` | `titulo`, `cliente_id`, `funil_id`, `etapa_id`, `responsavel_id`, `status`, `valor_centavos`, `previsao_fechamento` (data, opcional), `posicao_na_etapa`, `motivo_de_perda_id`, `observacao_da_perda`, `fechado_em` |
| `crm_itens_do_negocio` | `negocio_id`, `tipo` (`PRODUTO` ou `SERVICO`), `item_id`, `descricao`, `quantidade`, `valor_unitario_centavos` |

**Integridade:**
- `cliente_id` e `item_id` são ids sem chave estrangeira para tabelas de outros módulos; a existência no tenant é validada pelos contratos na Action.
- `valor_centavos` é derivado: soma dos itens quando há itens, valor digitado quando não há. Com itens, o valor do request é ignorado. Zero é valor válido.
- Etapa com negócios abertos não pode ser excluída nem desativada. Funil padrão não pode ser desativado nem excluído.
- Motivo de perda usado nunca é apagado, só desativado.
- Funil padrão "Vendas" semeado na primeira ativação do CRM da empresa, com as etapas Qualificação (10%), Proposta (40%) e Negociação (70%). Motivos padrão: Preço, Concorrente, Sem resposta, Outro.
- `posicao_na_etapa` ordena o cartão no kanban; é reindexada ao mover.
- Negócio fechado não aceita mudança de etapa nem de itens.

## 4. Contratos, Actions e eventos

**Contratos** (declarados em `Crm/Domain/Contracts`, implementados e registrados no `ServiceProvider` do módulo provedor, no padrão de `Platform\ContadorDeUso`):

| Contrato | Provedor | Operações |
|---|---|---|
| `ConsultaDeClientes` | Customers | `existe(id)`, `resumo(id)`, `criarLead(nome, telefone?, email?)`, `converterEmCliente(id)` |
| `CatalogoDeItens` | Catalog | `descrever(tipo, id)` retorna descrição e valor unitário atual em centavos; falha se o item não existe no tenant |
| `DiretorioDeUsuarios` | Identity | `ehDaEmpresa(id)` (usuário ativo da empresa atual), `nomes(ids)` (mapa id => nome) |

**Actions do `Crm`** (o controller só valida e chama a Action; a API pública futura reaproveita as mesmas):

| Action | Regra |
|---|---|
| `CriarFunil`, `AtualizarFunil` | um funil padrão por empresa; não desativa o padrão |
| `CriarEtapa`, `AtualizarEtapa`, `ReordenarEtapas` | probabilidade de 0 a 100; não desativa etapa com negócio aberto |
| `CriarMotivoDePerda`, `AtualizarMotivoDePerda` | só desativa |
| `AtualizarConfiguracao` | altera o interruptor de visibilidade |
| `CriarNegocio` | valida funil e etapa ativos e do mesmo funil; cliente por id ou lead novo na mesma transação; responsável padrão é o autor |
| `AtualizarNegocio` | título, previsão, responsável e valor (só sem itens); recusa se fechado |
| `DefinirItensDoNegocio` | substitui os itens com snapshot e recalcula o valor |
| `MoverNegocio` | troca etapa (do mesmo funil) e posição; reindexa origem e destino na mesma transação |
| `GanharNegocio` | `status = GANHO` e `fechado_em`; converte o cliente `LEAD` em `CLIENTE` pelo contrato |
| `PerderNegocio` | exige motivo ativo do tenant; observação opcional; não converte o lead |

**Eventos de domínio**, disparados após o commit: `NegocioCriado`, `NegocioMovido` (etapa de, para), `NegocioGanho`, `NegocioPerdido`. Cada um carrega `tenant_id`, `negocio_id`, `cliente_id` e o usuário autor. A F7a não tem listeners; timeline (F7b) e relatórios (F7d) apenas escutam.

## 5. API e permissões

Rotas em `Crm/Http/routes-app.php`, prefixo `api/app/crm`, grupo `empresa`.

| Recurso | Endpoints |
|---|---|
| Funis e etapas | `GET funis` (com etapas), `POST funis`, `PUT funis/{id}`, `POST funis/{id}/etapas`, `PUT etapas/{id}`, `PUT funis/{id}/etapas/ordem` |
| Motivos de perda | `GET motivos-de-perda`, `POST motivos-de-perda`, `PUT motivos-de-perda/{id}` |
| Negócios | `GET negocios?funil_id&status&responsavel_id&cliente_id`, `POST negocios`, `GET negocios/{id}`, `PUT negocios/{id}`, `PUT negocios/{id}/itens`, `POST negocios/{id}/mover`, `POST negocios/{id}/ganhar`, `POST negocios/{id}/perder` |
| Configuração | `GET configuracao`, `PUT configuracao` |

**Política** (`PoliticaDoCrm` em `Crm/Application`, chamada pelas Actions e consultas):

| Papel | Negócios | Funis, etapas, motivos, configuração |
|---|---|---|
| Proprietário, Admin | ler e escrever todos; reatribuir responsável | escrever |
| Vendedor | com o interruptor ligado, só os seus (ler, mover, ganhar, perder, editar); desligado, todos; não reatribui | só ler |
| Leitura | ler todos | só ler |
| Fiscal | sem acesso | sem acesso |

- **Gate de plano:** toda Action e consulta chama `EntitlementService::garantirModulo($tenant, Modulo::Crm)`.
- **Visibilidade:** o escopo `visiveisPara(Usuario)` em `Negocio` aplica o interruptor em listagem, `show` e toda mutação. Vendedor que pede negócio alheio recebe 404, não 403.

## 6. Frontend

**Estrutura:** `frontend/features/crm/` (`api.ts`, `types.ts`, `schemas.ts`, `hooks/`, `components/`). Páginas: `app/(app)/crm/negocios/page.tsx` (kanban), `app/(app)/crm/negocios/[id]/page.tsx` (detalhe), `app/(app)/configuracoes/crm/page.tsx`. O item "CRM" do menu só aparece com o módulo `CRM` no plano e papel diferente de Fiscal.

**Regras do projeto:** TypeScript strict sem `any`, `formatarCentavos`, `useEnvioUnico`, `useWatch` no lugar de `form.watch`, componentes até 350 linhas (gate de lint). Antes de codar páginas, ler `node_modules/next/dist/docs/` (aviso do `frontend/AGENTS.md`).

**Kanban:**
- Seletor de funil no topo; abas Abertos, Ganhos e Perdidos. Ganhos e perdidos aparecem em tabela.
- Uma coluna por etapa com nome, contagem e soma de valor; rolagem horizontal com snap no celular.
- Cartão: título, cliente, valor, previsão (destaque se vencida) e responsável.
- Mover por arrastar (entre colunas e dentro da coluna) e pelo menu "Mover para...", que é o caminho acessível por teclado e toque. Atualização otimista com rollback e aviso se a API recusar.

**Detalhe do negócio:** dados, editor de itens (busca em produtos e serviços pelas APIs existentes; com itens, o valor trava e vira soma), botões Ganhar e Perder (a perda abre diálogo com motivo obrigatório e observação opcional).

**Novo negócio:** cliente existente ou criar lead na hora (nome mais telefone ou e-mail).

**Configurações do CRM:** funis e etapas (reordenar com botões subir e descer, probabilidade por etapa), motivos de perda e o interruptor de visibilidade. Só Proprietário e Admin editam.

## 7. Testes

**Backend:**
- Uma classe de teste por Action, com o caminho feliz e cada recusa (etapa de outro funil; etapa com negócio aberto; funil padrão intocável; negócio fechado imutável; perda sem motivo; item inexistente no tenant).
- Valor derivado: com itens vale a soma e o valor do request é ignorado; sem itens vale o digitado; zero é válido.
- Reindexação de posições na origem e no destino do `MoverNegocio`.
- Ganhar converte `LEAD`; perder não converte.
- Cada Action dispara o evento certo, só após o commit (falha na transação não dispara).
- HTTP: teste de isolamento entre duas empresas em todo endpoint; matriz de papéis; bloqueio sem módulo `CRM`; Vendedor com interruptor ligado recebe 404 em negócio alheio; Fiscal barrado.
- Contratos testados nos módulos provedores.

**Frontend (Vitest e Testing Library, como `Clientes.test.tsx`):** mover pelo menu atualiza a coluna; rollback em erro 422; perda exige motivo; vendedor não vê controles de configuração; módulo ausente esconde o menu.

## 8. Ordem de entrega

1. Migrations, Models, scope de tenant, módulo `Crm` e ServiceProvider.
2. Contratos em Customers e Catalog.
3. Actions de configuração (funis, etapas, motivos, interruptor) e API.
4. Actions de negócio, eventos e API.
5. Frontend: feature `crm`, configurações, kanban, detalhe, novo negócio (inclui a dependência `@dnd-kit`).
6. Seeding do funil padrão e motivos, menu e `PROGRESSO.md`.

Cada passo termina verde (testes, lint, typecheck) antes do seguinte.

## 9. Riscos

| Risco | Mitigação |
|---|---|
| Vazamento entre tenants ou entre vendedores | scope de tenant, escopo `visiveisPara`, teste de isolamento por endpoint, 404 em vez de 403 |
| Posições do kanban inconsistentes sob movimentos concorrentes | reindexação na mesma transação, com lock da etapa de destino |
| Acoplamento com Customers e Catalog | só contratos; Crm nunca importa Models de outros módulos |
| Crescimento do escopo do CRM | a lista "fora de escopo" deste documento; inclusões passam por revisão |
