# Plataforma Fiscal + CRM (SaaS): projeto mestre

- **Status:** APROVADO em 2026-09-25. S1 a S5 e a ordem das fases foram aceitas; o nome continua provisório.
- **Data:** 2026-09-25.
- **Nome do produto:** provisório.
- **Base técnica fiscal:** `docs/referencia-fiscal/00-INDICE.md`, extraída do Mecânica Pro, e a skill `br-fiscal-note-emission`.

Este documento é o **projeto mestre**: visão, arquitetura, decisões e roteiro de fases. Cada fase terá sua própria spec detalhada e seu próprio plano de implementação antes de ter código.

---

## 1. Entendimento

### O que o usuário pediu

1. Um SaaS de **emissão de documentos fiscais** vendido **por assinatura para cada CNPJ**.
2. Tudo que a emissão exige: cadastro de **produtos e serviços**, área administrativa para cada CNPJ cadastrar **seus dados**, e **dashboard**.
3. Um **administrador do SaaS** que cria **planos** com preço conforme o **limite de cadastros e de documentos fiscais**.
4. A emissão disponível pelo **frontend** e por uma **API pública** para desenvolvedores integrarem aos seus sistemas.
5. Um **CRM** a partir de pesquisa de mercado, com **só o que agrega valor**. Pode ser o CRM com o fiscal como módulo, ou o contrário.
6. As melhores práticas e padrões de projeto, e um projeto **muito bem documentado**.
7. Layout **responsivo** (inclusive mobile), **leve**, **moderno**, com temas **dark e light**.
8. Trabalho com **persistência em arquivos** e **economia de contexto** (salvar o necessário e descartar o resto).

### Decisões já tomadas pelo usuário

| Tema | Decisão |
|---|---|
| Stack | Laravel (API) + Next.js (frontend) |
| Motor fiscal | NFePHP para os três documentos: `sped-nfe` para NF-e e NFC-e, e `nfse-nacional/nfse-php` para NFS-e, como o Mecânica Pro já faz em produção |
| Documentos | NF-e (55), NFC-e (65), NFS-e (padrão nacional) |
| Cobrança | Mercado Pago com **checkout transparente**, cartão e boleto. O admin cria os planos e o cliente escolhe o plano ao assinar |

### Suposições (o usuário deve confirmar ou corrigir na revisão)

- **S1. Tenant = 1 CNPJ.** Cada assinatura corresponde a uma empresa emitente. Filiais e múltiplos CNPJs na mesma conta ficam fora do escopo inicial. O modelo de dados não impede isso depois.
- **S2. Banco único com `tenant_id` em cada linha**, sem um banco por tenant. É o mesmo padrão do Mecânica Pro e é suficiente para milhares de tenants.
- **S3. O produto é uma plataforma só, com módulos: Fiscal e CRM.** Não é o fiscal dentro do CRM, nem o contrário. Os dois compartilham um **núcleo** (empresa, usuários, clientes, produtos e serviços), e o plano define quais módulos o tenant tem. Com isso o SaaS vende "só emissor", "só CRM" ou "os dois" sem refazer nada. O ponto forte é o ciclo **proposta aprovada no CRM → venda → nota fiscal emitida → histórico do cliente**.
- **S4. Hospedagem:** VPS com Docker, igual ao Mecânica Pro (`deploy-vps.sh`). Pode migrar para nuvem gerenciada sem mudar a arquitetura.
- **S5. NFS-e só em municípios aderidos ao padrão nacional** (mais de 4.000, cerca de 70% do volume). Município legado entra depois, com um provider REST atrás da mesma interface.

---

## 2. Arquitetura

### Visão geral

```
┌─────────────── Next.js (frontend, responsivo, dark/light) ───────────────┐
│  (auth)  login/cadastro/assinatura   (app) área do CNPJ   (admin) SaaS  │
└──────────────┬─────────────────────────────────────────────────────────────┘
               │ /api/app/*   (Sanctum, cookie httpOnly)
┌──────────────▼─────────────────────────────────────────────────────────────┐
│  Laravel: monólito modular                                                │
│   Http (Controllers/Requests/Resources)  ← /api/app/*  e  /api/v1/* (API key)│
│   Application (Actions + DTOs)   ← mesma lógica para frontend e API pública│
│   Domain (Models, Enums, regras puras/Resolvers)                          │
│   Infrastructure (FiscalProvider/NFePHP, MercadoPago, Storage, Mail)       │
│  Fila (Redis + Horizon)  ·  Scheduler  ·  Webhooks de saída               │
└──────┬───────────────────────┬──────────────────────────┬─────────────────┘
   PostgreSQL              Redis                 Storage S3-compatível
                                              (XML/PDF, retenção ≥ 5 anos)
Externos: SEFAZ (UFs) · SEFIN/ADN NFS-e Nacional · Mercado Pago · ViaCEP
```

### Por que um monólito modular

Uma equipe pequena, deploy único e transações consistentes entre os módulos (por exemplo, "emitir nota a partir de um negócio ganho"). Os módulos têm fronteiras explícitas, e daria para extrair um serviço depois se fosse necessário. Microsserviços agora seriam custo sem retorno.

### Módulos do backend

Cada módulo vive em `backend/app/Modules/<Nome>/{Domain,Application,Infrastructure,Http,Database,Tests}`.

| Módulo | Responsabilidade | Depende de |
|---|---|---|
| `Platform` | admin do SaaS, planos, limites, módulos por plano, métricas da plataforma | nenhum |
| `Tenancy` | empresa (tenant), escopo global por `tenant_id`, status da assinatura, onboarding | Platform |
| `Identity` | usuários, autenticação, papéis e permissões, chaves de API | Tenancy |
| `Billing` | assinatura, cobranças, Mercado Pago, webhooks, inadimplência | Platform, Tenancy |
| `Catalog` | produtos, serviços, dados fiscais dos itens, categorias fiscais padrão | Tenancy |
| `Customers` | clientes/pessoas (PF e PJ), contatos, endereços, dados fiscais do destinatário | Tenancy |
| `Fiscal` | emitente, certificado, séries, notas, motores, PDF, contingência, reconciliação | Catalog, Customers |
| `Crm` | funis, negócios, atividades, propostas, timeline, relatórios comerciais | Customers, Catalog, Fiscal (via Actions) |
| `PublicApi` | `/api/v1`, idempotência, limite de requisições, webhooks de saída, OpenAPI | Application de todos |
| `Shared` | Money, CPF/CNPJ, UF/IBGE, erros de domínio, auditoria | nenhum |

**Regra das fronteiras:** um módulo não acessa as tabelas nem os Models de outro. A comunicação passa por **Actions ou contratos públicos** e por **eventos de domínio** (por exemplo, `NotaFiscalAutorizada`, que o CRM escuta para a timeline e o Billing escuta para o consumo).

### Padrões adotados (com o motivo)

| Padrão | Onde | Por quê |
|---|---|---|
| **Actions** (caso de uso = 1 classe) | Application | O frontend e a API pública chamam **o mesmo código**, sem regra divergente |
| **DTO** imutável (`readonly`) | entrada e saída das Actions e dos providers | contrato explícito e testável |
| **Strategy + Adapter** (`FiscalProvider`) | Fiscal | trocar ou adicionar motor sem mexer em regra tributária (skill, `architecture.md`) |
| **Resolvers puros e exaustivos** | regras fiscais | CFOP, CST e CRT testáveis. Lançam exceção em vez de chutar valor |
| **Domain Events + Listeners** | integração entre módulos | desacopla CRM, Billing e Fiscal |
| **Outbox + fila** | webhooks de saída e e-mails | nenhum efeito colateral se perde se a requisição cair |
| **Idempotency-Key** | API pública e emissão | um retry nunca gera nota duplicada |
| **Policies (Gate)** | autorização | permissão por papel e por módulo contratado |
| **Global Scope por tenant** | Models do tenant | isolamento por padrão. Consulta cross-tenant só explícita, no admin |

### Estrutura do repositório

```
crm/
├── CLAUDE.md  PROGRESSO.md  TAREFAS.md   ← gestão de contexto (ver §11)
├── docker-compose.yml  .github/workflows/
├── backend/   (Laravel 12, PHP 8.3+)
├── frontend/  (Next.js App Router + TypeScript strict)
└── docs/
    ├── referencia-fiscal/         (base extraída do Mecânica Pro)
    ├── superpowers/specs|plans/   (spec e plano por fase)
    ├── adr/                       (decisões de arquitetura numeradas)
    └── api/                       (OpenAPI gerado + guia do integrador)
```

---

## 3. Multi-tenancy, segurança e conformidade

- **Isolamento:** todo Model de tenant tem `tenant_id`, um Global Scope e um índice composto começando por `tenant_id`. Há um teste automatizado que tenta ler dados de outro tenant e tem que falhar.
- **Autenticação no frontend:** Sanctum SPA com cookie httpOnly, CSRF, limite de tentativas de login e recuperação de senha por token com hash e validade.
- **Autenticação na API pública:** chave `live_…` ou `test_…` guardada só como hash e mostrada uma única vez. A **chave `test_` sempre emite em homologação e a `live_` em produção**. O ambiente fica visível na própria chave, o que evita emitir nota real sem querer (pitfall #25).
- **Papéis do tenant:** `PROPRIETARIO`, `ADMIN`, `FISCAL`, `VENDEDOR`, `LEITURA`.
- **Papéis da plataforma:** `SUPERADMIN` e `SUPORTE`. O suporte entra no tenant por **impersonação auditada**.
- **Segredos:** o certificado A1 e sua senha, o CSC e os tokens ficam criptografados (AES-256, `Crypt`). O `.pfx` só é decifrado em memória, ou em arquivo temporário com permissão `0600` apagado no `finally`.
- **Auditoria:** `spatie/laravel-activitylog` registra mudanças de ambiente fiscal, certificado, emissão, cancelamento, plano e usuários.
- **LGPD:** exportação dos dados do tenant e exclusão de conta. Os documentos fiscais **ficam retidos pelo prazo legal** mesmo depois do cancelamento da assinatura.
- **Retenção fiscal:** o XML autorizado é guardado por **no mínimo 5 anos** (CTN art. 173) em storage com backup. Um **tenant suspenso ou cancelado continua podendo baixar seus XMLs.**

---

## 4. Plataforma SaaS (admin, planos e limites)

### Plano

| Campo | Exemplo |
|---|---|
| nome, descrição, preço mensal, preço anual (desconto) | "Profissional", R$ 99, R$ 990 |
| **módulos** | `FISCAL_NFE`, `FISCAL_NFCE`, `FISCAL_NFSE`, `CRM`, `API` |
| **limites** (`-1` = ilimitado) | usuários, clientes, produtos, serviços, **documentos fiscais por mês**, requisições de API por minuto |
| política de excedente de documentos | `BLOQUEAR` ou `COBRAR` (preço por documento excedente) |
| ativo e visível na página de planos | sim ou não |
| período de teste (dias) | 14 |

- Os limites ficam numa tabela `plano_limites(plano_id, recurso, limite)`, o que permite criar recursos novos sem migration.
- Um `EntitlementService` único responde duas perguntas: "o tenant pode usar o módulo X?" e "ainda cabe mais um Y?". É chamado pelas Actions, então vale igual para o frontend e para a API.
- **O documento fiscal é contado quando é AUTORIZADO**, e não na tentativa. O excedente é idempotente (índice único por nota), no padrão do Mecânica Pro.
- **Consumo em tempo real** fica visível no dashboard do tenant, com aviso em 80% e em 100%.

### Ciclo da assinatura

`TRIAL → ATIVA → INADIMPLENTE (carência configurável) → SUSPENSA → CANCELADA`

- **SUSPENSA:** acesso só de leitura. Não emite nem cadastra, mas baixa XML e PDF.
- **Admin da plataforma:** CRUD de planos, lista de tenants (status, plano, consumo, motor), mudança manual de plano, carência, cobranças e impersonação.
- **Métricas da plataforma:** MRR, novos, cancelados, inadimplentes, notas emitidas por tipo, taxa de rejeição por motor e certificados vencendo.

---

## 5. Área do CNPJ (tenant)

- **Onboarding em etapas:**
  1. Cadastro (CNPJ, responsável, e-mail).
  2. Escolha do plano.
  3. Pagamento.
  4. Dados da empresa (CEP via ViaCEP, IBGE e UF automáticos).
  5. Dados fiscais: regime, IE, IM, CNAE.
  6. Certificado A1.
  7. CSC (se o plano tiver NFC-e).
  8. Séries.
  9. Emissão de teste em homologação.
- **O ambiente começa sempre em HOMOLOGAÇÃO.** Ir para produção exige confirmação explícita e fica auditado.
- **Configurações:** dados da empresa, fiscal (ambiente, séries e próximos números por modelo, alíquota de ISS padrão, modelo de venda padrão NFC-e ou NF-e), certificado (validade, alertas em 90, 60, 30, 14 e 7 dias), CSC por ambiente, usuários e papéis, chaves de API, webhooks e assinatura.

---

## 6. Cadastros compartilhados (núcleo)

- **Clientes** (um único cadastro, usado pelo CRM e pelo fiscal):
  - Tipo PF ou PJ, nome, CPF/CNPJ, IE ou flag de isento, e-mail, telefone, endereço com IBGE, tags e origem.
  - **Estágio de ciclo de vida:** `LEAD → CLIENTE`. Um lead pode não ter os dados fiscais. **A exigência dos dados fiscais acontece na emissão, com bloqueio e mensagem acionável**, e não no cadastro.
  - **Contatos:** as pessoas de um cliente PJ.
- **Produtos:** SKU, nome, unidade, preço, GTIN, NCM, CEST, origem (nullable: zero é valor válido), tributação ICMS (`NORMAL|ST`), mais a fonte do dado fiscal e a data de revisão.
- **Serviços:** nome, preço, **código LC 116**, **cTribNac (6 dígitos)**, código municipal (opcional, 3 dígitos), alíquota de ISS, NBS (opcional).
- **Categorias fiscais padrão:** preenchem **só** os campos vazios de um produto e marcam o dado como "padrão, não revisado".
- **Tela de pendências fiscais:** lista produtos e serviços sem os dados obrigatórios.
- **Importação por CSV** de clientes, produtos e serviços, com validação linha a linha e relatório de erros. A **importação de XML de compra** fica para depois (ver §12).

---

## 7. Módulo Fiscal

Implementação conforme `docs/referencia-fiscal/01..07`, reaproveitando o que já é **comprovado em produção** no Mecânica Pro, com as adaptações obrigatórias listadas no índice:
- Emitente passado explicitamente, nunca uma configuração global.
- `cUF` derivado da UF do emitente.
- cTribNac vindo do cadastro do serviço.
- DTO de cancelamento com o protocolo.
- Falha técnica classificada como `ERRO`.

**Funcionalidades:**
- **Documentos:** NF-e, NFC-e e NFS-e Nacional.
- **Emissão:** a partir de uma tela de venda ou serviço, de uma proposta ou negócio do CRM, ou da API.
- **Escolha automática** entre NFC-e e NF-e: pessoa física na mesma UF com modelo padrão NFC-e.
- **Venda mista:** produtos e serviços viram NF-e e NFS-e **independentes**, com rateio de desconto.
- **Operações:** emitir (fila + polling), consultar, cancelar (15 a 255 caracteres), inutilizar faixa, retransmitir contingência, baixar PDF e XML, e ZIP em lote (até 50).
- **Contingência:** EPEC na NF-e e offline (`tpEmis=9`) na NFC-e. Prazo de 7 dias com alerta.
- **Reconciliação agendada:** notas em PROCESSANDO a cada 15 minutos (inclusive "nunca enviada") e notas em contingência de hora em hora.
- **PDF:** DANFE A4, cupom de 80 mm e DANFSe v2.0, sempre **a partir do XML autorizado**. Avaliar `nfephp-org/sped-da` contra os renderers da skill no plano da fase.
- **E-mail ao destinatário** com PDF e XML anexos (configurável).
- **Guardas desde o início:** toda a checklist de `referencia-fiscal/07` (GTIN, CEST, xPag, indIEDest, cStat 539, sanitização de texto, números reaproveitados em retry, chave NFS-e com 50 dígitos etc.).
- **Fora do escopo inicial**, e documentado como pendência:
  - IBS/CBS, DIFAL, PIS/COFINS/ICMS reais do Regime Normal.
  - ISS retido.
  - NFS-e municipal legada.
  - NF-e de devolução, complementar e ajuste.
  - Carta de correção.
  - O que vale para o público inicial: **o emissor atende no começo principalmente o Simples Nacional**. O Regime Normal fica **bloqueado com mensagem clara** nos casos que exigem cálculo não implementado, como já faz o Mecânica Pro.

---

## 8. API pública (para desenvolvedores)

- **REST versionado** `/api/v1`, JSON, com `Authorization: Bearer <api_key>`.
- **Mesmas Actions do frontend:** a API não tem lógica própria.
- **Recursos:**
  - `clientes`, `produtos`, `servicos`: CRUD.
  - `notas` (`POST` cria e emite; `GET`; `GET /{id}/pdf|xml`; `POST /{id}/cancelar`).
  - `notas/inutilizacoes`, `webhooks`, `empresa` (leitura), `consumo`.
- **Emissão:** `POST /notas` com o header **`Idempotency-Key`**, que é obrigatório. A resposta é **`202 Accepted`** com `id` e `status: PROCESSANDO`. O mesmo key com o mesmo corpo devolve a resposta original; o mesmo key com corpo diferente devolve `409`.
- **Emissão direta:** a API aceita **itens inline** (dados fiscais no corpo) **ou** referência a produtos já cadastrados. Assim um ERP integra sem sincronizar catálogo, e as mesmas validações e bloqueios valem.
- **Erros:** formato **RFC 9457** (`application/problem+json`), com `type`, `title`, `detail` e `errors[]`. Um bloqueio fiscal vira **422** com código estável, por exemplo `FISCAL_PRODUTO_SEM_ORIGEM`. A rejeição da autoridade fica no recurso (`status=REJEITADA`, `cstat`, `motivo`).
- **Webhooks de saída:**
  - Eventos: `nota.autorizada`, `nota.rejeitada`, `nota.cancelada`, `nota.erro`, `nota.contingencia`.
  - Assinatura `X-Signature: t=<ts>,v1=<hmac_sha256>`.
  - Retentativas com backoff (até 24h), log de entregas e reenvio manual.
- **Limite de requisições** por plano, com headers `X-RateLimit-*`.
- **Paginação** por cursor e filtros por data e status.
- **Documentação:** OpenAPI 3.1 gerado do código (Scramble ou Scribe) e publicado com um guia do integrador (autenticação, ambiente de teste, fluxo de emissão, webhooks, códigos de erro, exemplos em cURL, PHP e JS).
- **Sandbox:** a chave `test_` emite em homologação da SEFAZ e da ADN, com o certificado do próprio tenant.

---

## 9. CRM

### Pesquisa de mercado (síntese)

- **Referências:** Pipedrive, RD Station CRM, Agendor, HubSpot.
- **Recursos mais usados em PMEs:** gestão de contatos, funil visual, tarefas e follow-up automáticos, integração com e-mail, acesso mobile, relatórios e, mais recentemente, assistência por IA.
- **Uso real:** só cerca de 22% dos recursos de um CRM costumam ser usados. **Por isso o escopo é o núcleo que gera resultado.**

### Entra no CRM (e por quê)

| Recurso | Valor |
|---|---|
| **Clientes, leads e contatos** (cadastro único do núcleo) + tags + origem | base de tudo, reaproveitada pelo fiscal |
| **Funis múltiplos com etapas configuráveis** (kanban com arrastar e soltar) | o recurso central de todo CRM de vendas |
| **Negócios**: valor, previsão de fechamento, responsável, itens (produtos e serviços), probabilidade por etapa, ganho ou perdido + **motivo de perda** | previsão de receita e aprendizado com as perdas |
| **Atividades e tarefas** (ligação, reunião, e-mail, WhatsApp, tarefa) com data, responsável e lembrete, mais uma **agenda** do dia e da semana | a disciplina de follow-up é o que mais aumenta as vendas |
| **Alerta de negócio parado** (sem atividade há X dias, configurável por etapa) | automação simples com alto retorno |
| **Timeline do cliente**: notas, atividades, mudanças de etapa, propostas e **notas fiscais emitidas** | visão 360°, com a integração fiscal como diferencial |
| **Propostas e orçamentos** em PDF, com link público para aceite. **Aceite → negócio ganho → emissão da nota em 1 clique** | fecha o ciclo comercial e fiscal (diferencial) |
| **WhatsApp e e-mail em um clique** (`wa.me` e `mailto`), com registro automático da atividade | canais reais das PMEs brasileiras, sem custo de integração |
| **Relatórios**: conversão por etapa, ganhos e perdidos, motivos de perda, previsão, desempenho por vendedor, faturamento (a partir das notas) | gestão |
| **Metas** mensais por vendedor (valor ou quantidade) | acompanhamento simples de desempenho |
| **Importação e exportação CSV** | migração vinda de outro CRM ou de planilha |
| **Permissões**: o vendedor vê só os próprios negócios (configurável) | necessário para equipes |

### Fica de fora (com o motivo)

- **Automação de marketing, e-mail em massa, landing pages:** é outro produto (RD Marketing). Fora do foco.
- **Sincronização de caixa de e-mail (IMAP/Gmail) e chat omnichannel:** custo alto. Reavaliar com demanda real.
- **Telefonia VoIP e gamificação:** baixo uso em PMEs.
- **IA preditiva:** reavaliar depois que houver dados. Uma possibilidade futura é um resumo da timeline pela API da Claude.
- **Campos personalizados:** entram na fase 2 do CRM, se houver demanda. Tags + observações cobrem o começo.

---

## 10. Dashboards

- **Tenant, fiscal:**
  - Notas do mês por tipo e status, e valor emitido.
  - Consumo do plano (x de y), rejeições que pedem ação, contingências com prazo.
  - Validade do certificado e ambiente atual em destaque.
  - Gráfico de faturamento dos últimos 12 meses.
- **Tenant, CRM:**
  - Funil (valor por etapa), negócios abertos e previsão do mês, taxa de conversão.
  - Tarefas de hoje e atrasadas, negócios parados, ranking de vendedores (se houver meta).
- **Plataforma:** as métricas do §4.
- **Visibilidade:** cada widget só aparece se o módulo estiver no plano e o papel permitir.

---

## 11. Frontend e UX

- **Stack:** Next.js (App Router), TypeScript strict, Tailwind CSS, shadcn/ui, `next-themes` (dark, light e sistema), TanStack Query, React Hook Form + Zod, Recharts.
- **Leveza:** Server Components onde couber e code splitting por rota. Nada de biblioteca pesada de UI.
- **Meta de desempenho:** Lighthouse mobile ≥ 90.
- **Responsivo, mobile-first:**
  - Sidebar recolhível. No celular vira **barra inferior** com as quatro áreas principais.
  - Tabelas viram **cards** no celular.
  - O kanban do funil usa rolagem horizontal com snap.
- **Temas:** tokens CSS (`--background`, `--foreground`, `--primary`…) definidos para os dois temas, com cores de status acessíveis (contraste AA). Verde para autorizada, âmbar para processando/contingência, vermelho para rejeitada/cancelada, azul para informação.
- **Estrutura:** `app/(auth)`, `app/(app)/{dashboard,fiscal,cadastros,crm,configuracoes}`, `app/(admin)`. Cada funcionalidade em `features/<modulo>/{api,components,schemas,hooks}`.
- **Padrões de UX:**
  - Estados de skeleton e vazio.
  - Toast em toda mutação e botão com loading que impede duplo envio.
  - Máscaras de CPF, CNPJ, CEP e moeda. Datas `DD/MM/AAAA`.
  - **Faixa fixa "HOMOLOGAÇÃO"** enquanto o ambiente não for produção.
  - Mensagens de bloqueio fiscal com **link para corrigir**, por exemplo "Produto X sem origem → Abrir produto".
- **Acessibilidade:** navegação por teclado, foco visível, `aria` nos componentes.

---

## 12. Cobrança (Mercado Pago)

- **Checkout transparente com o Payment Brick** no frontend, e `POST /v1/payments` feito pelo servidor. O servidor define valor e descrição, e o header `X-Idempotency-Key` é enviado. Reaproveita o padrão de `MercadoPagoService` do Mecânica Pro.
- **Cartão:** assinatura recorrente (`/preapproval`).
- **Boleto:**
  - O Mercado Pago informa que a assinatura aceita boleto e Pix.
  - **Validar no sandbox**, na spec da fase, se o preapproval gera o boleto de cada ciclo com checkout transparente.
  - Se não gerar, o `Billing` cria o boleto de cada ciclo por `POST /v1/payments` (`payment_method_id` de boleto), agendado.
- **Webhook:**
  - Validação HMAC com falha fechada. Busca o pagamento na API e só confia no status consultado.
  - A conciliação é idempotente. Só uma cobrança do tipo ASSINATURA estende o período e reativa o tenant.
- **Troca de plano:** upgrade com efeito imediato e cobrança proporcional (a decidir na spec da fase). Downgrade no próximo ciclo, **bloqueado se o uso atual passar os limites do novo plano**.
- **Documento excedente:** acumulado no mês e cobrado no ciclo seguinte.
- **Faturas e recibos:** ficam visíveis ao tenant. Emitir NFS-e **da própria plataforma para o assinante** usando o próprio módulo fiscal (dogfooding) é uma opção para uma fase posterior.

---

## 13. Qualidade, testes e operação

- **Backend:**
  - PHPUnit/Pest, com TDD nas regras (resolvers, limites, idempotência, isolamento de tenant).
  - Testes dos motores **sem rede**, com os métodos puros `montarNfe`, `montarDps` e parsers de resposta, no padrão do Mecânica Pro.
  - Validação do XML contra o XSD oficial em teste.
- **Frontend:** Vitest + Testing Library. Playwright nos fluxos críticos: login, emitir nota em homologação, assinar plano.
- **CI (GitHub Actions):** lint (Pint, ESLint), análise estática (PHPStan nível 6+, `tsc --strict`), testes e build.
- **Fixtures:** anonimizadas, **nunca** com dados reais de cliente (pitfall #27).
- **Observabilidade:**
  - Horizon, logs estruturados com `tenant_id` e `nota_id`, health check.
  - Alertas: fila parada, taxa de rejeição alta, certificados vencendo.
- **Operação:**
  - Docker Compose (app, worker, scheduler, postgres, redis, nginx).
  - Backup diário do banco e do storage, com um runbook de restauração testado.
- **Segurança de dependências:** `composer audit` e `npm audit` no CI.

---

## 14. Documentação e gestão de contexto

- **Arquivos de controle na raiz:**
  - `CLAUDE.md`: regras do projeto, incluindo o protocolo de contexto.
  - `PROGRESSO.md`: estado atual, contexto necessário e próxima tarefa.
  - `TAREFAS.md`: backlog por fase.
- **Protocolo de sessão:**
  - Ao iniciar, ler `PROGRESSO.md` e **só** os arquivos da "Contexto necessário".
  - A cada passo concluído, atualizar o progresso.
  - Ao terminar uma tarefa, reescrever o contexto da próxima e sugerir `/compact` ou `/clear`.
- **ADRs** em `docs/adr/NNNN-titulo.md` para cada decisão de arquitetura. As primeiras vêm das decisões e suposições deste documento.
- **Por fase:** uma spec em `docs/superpowers/specs/` e um plano em `docs/superpowers/plans/`.
- **Documentação do produto:** README por app (como rodar), guia do integrador da API (§8) e manual de primeiros passos do tenant, com o onboarding fiscal explicado.

---

## 15. Roteiro de fases

Cada fase tem entrega utilizável, spec e plano próprios, e só começa quando a anterior está verificada.

| Fase | Entrega | Critério de pronto |
|---|---|---|
| **F0: Fundação** | repositório, Docker, CI, Laravel + Next.js, arquitetura modular, autenticação (login, recuperação de senha), tenancy + isolamento testado, layout responsivo com temas, arquivos de contexto | um usuário faz login, vê o layout nos dois temas no celular e no desktop, e o CI passa |
| **F1: Plataforma e planos** | admin do SaaS, CRUD de planos (módulos e limites), cadastro de empresa (onboarding sem pagamento), usuários e papéis, `EntitlementService`, ativação manual | o admin cria um plano, um tenant é ativado manualmente e um limite é respeitado |
| **F2: Cadastros e emitente** | clientes (PF/PJ, contatos), produtos, serviços, categorias fiscais, pendências, CSV, dados fiscais do emitente, certificado, CSC, séries | o tenant cadastra tudo o que a emissão exige, e as pendências aparecem |
| **F3: NF-e e NFC-e** | motores NFePHP 55/65, fila, polling, reconciliação, PDF, cancelamento, inutilização, contingência, dashboard fiscal | nota autorizada em **homologação** para NF-e e NFC-e, e XML validado contra o XSD |
| **F4: NFS-e Nacional** | motor `nfse-php`, DANFSe, cancelamento por evento, venda mista (orquestrador) | NFS-e autorizada em homologação da ADN |
| **F5: Cobrança** | página de planos, checkout transparente MP (cartão e boleto), assinatura, webhooks, inadimplência e suspensão, excedente | assinatura paga no sandbox MP ativa o tenant; um boleto vencido suspende o tenant |
| **F6: API pública** | `/api/v1`, chaves live/test, idempotência, webhooks de saída, limite de requisições, OpenAPI + guia | um integrador externo emite uma nota em homologação só pela documentação |
| **F7: CRM** | funis, negócios, atividades e agenda, timeline, propostas com aceite para nota, relatórios, metas, dashboard CRM | proposta aceita vira nota fiscal emitida e aparece na timeline |
| **F8: Go-live** | auditoria fiscal (`audit-checklist.md` da skill), testes de carga, backup e restauração, homologação real com um CNPJ piloto em produção | a primeira nota real em produção de um tenant pagante |

**Ordem:** o núcleo fiscal (F2–F4) vem antes da cobrança (F5) porque é o valor do produto e o maior risco técnico. A cobrança vem antes da API e do CRM porque é o requisito para vender. **F6 e F7 podem correr em paralelo** se houver capacidade.

---

## 16. Riscos

| Risco | Mitigação |
|---|---|
| `nfse-nacional/nfse-php` é beta e tem um único autor | isolada atrás do `FiscalProvider`, com versão fixada e testes de contrato. Um provider REST fica como plano B |
| Mudanças de NT e da reforma tributária (IBS/CBS a partir de 2027) | datas de vigência como configuração, schema versionado, acompanhamento das NTs e o tópico `reliability-patterns` da skill |
| Tenant em Regime Normal | bloqueio explícito dos casos não implementados. Venda inicial voltada ao Simples |
| Custódia de certificados de terceiros | criptografia, acesso mínimo, auditoria e um plano de migrar para HSM ou KMS se a escala justificar |
| Boleto recorrente no Mercado Pago | validar no sandbox na F5. O fallback é o agendamento próprio |
| Crescimento do escopo do CRM | a lista "fica de fora" deste documento. Qualquer inclusão passa por revisão |

## 17. Pontos para o usuário decidir na revisão

1. Confirmar as suposições **S1 a S5** (§1).
2. **Nome do produto e domínio.**
3. **Planos iniciais:** quantos, com quais módulos e limites. O admin pode criá-los depois; é só para a página comercial.
4. **Ordem das fases:** manter F5 (cobrança) antes da F6/F7 ou antecipar o CRM.
