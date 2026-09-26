# F1: Plataforma e planos (spec da fase)

- **Status:** RASCUNHO para revisão.
- **Data:** 2026-09-26.
- **Spec mestre:** `2026-09-25-plataforma-fiscal-crm-design.md`, §3, §4, §5 e §15 (linha F1).
- **Critério de pronto (da spec mestre):** o admin cria um plano, um tenant é ativado manualmente e um limite é respeitado.

## 1. Decisões do usuário (2026-09-26)

| Tema | Decisão |
|---|---|
| Cadastro | **Autoatendimento.** Plano com dias de teste → conta em `TESTE`. Plano sem teste → `PENDENTE`, até o admin ativar. Na F5, `PENDENTE` passa a significar "aguardando pagamento". |
| Impersonação pelo suporte | **Fora da F1.** Entra antes do go-live. |
| Busca de dados pelo CNPJ | **BrasilAPI**, sem travar o cadastro: se falhar, o usuário preenche à mão. |

## 2. Escopo

**Entra:**
- Admin da plataforma: planos (CRUD, módulos, limites e preços), lista e detalhe de empresas, ativação, suspensão, reativação, troca de plano e painel com métricas básicas.
- Cadastro público: página de planos e formulário de cadastro com consulta do CNPJ.
- Situação da assinatura: estados, regras de acesso por estado e expiração automática do período de teste.
- `EntitlementService`: módulo contratado e limite de recurso. Na F1 só o limite de **usuários** é exercitado; os demais recursos são criados no catálogo e ativados na fase de cada módulo.
- Usuários da empresa: listar, convidar (o convidado define a senha pelo link), editar papel, desativar e reativar.
- Consumo do plano no dashboard da empresa.
- Pendências herdadas da revisão da F0 (`TAREFAS.md`).

**Não entra:**
- Cobrança e pagamento (F5).
- Impersonação.
- Dados fiscais do emitente (IE, IM, regime, endereço, certificado), que são da F2.

## 3. Modelo de dados

**Valores monetários** ficam em **centavos inteiros** (`*_centavos`, `integer`). Isso evita erro de arredondamento com float. Um value object `Dinheiro` em `Shared` formata para BRL.

**`planos`**
- `id` (uuid), `nome` (60, único), `descricao` (texto, opcional).
- `preco_mensal_centavos`, `preco_anual_centavos` (opcional).
- `dias_teste` (0 = sem teste).
- `politica_excedente` (`BLOQUEAR|COBRAR`) e `preco_documento_excedente_centavos` (obrigatório se `COBRAR`).
- `ativo`, `visivel` (aparece na página pública), `ordem`, timestamps.

**`plano_modulos`**
- `plano_id` + `modulo`, com índice único.
- `modulo`: `FISCAL_NFE | FISCAL_NFCE | FISCAL_NFSE | CRM | API`.

**`plano_limites`**
- `plano_id` + `recurso` (índice único) + `limite` (`-1` = ilimitado).
- `recurso`: `USUARIOS | CLIENTES | PRODUTOS | SERVICOS | DOCUMENTOS_MES | API_REQUISICOES_MIN`.
- Recurso sem linha na tabela é tratado como **0**, ou seja, bloqueado. Nunca como ilimitado (fail-closed).

**`tenants`** (alteração)
- Renomear `nome` para `razao_social` e acrescentar `nome_fantasia` (opcional).
- Novos campos: `plano_id` (FK), `situacao`, `teste_termina_em` (data, opcional), `situacao_alterada_em`.
- O `status` da F0 vira `situacao`, com a enumeração abaixo.

**Auditoria:** `spatie/laravel-activitylog` registra criação e edição de plano, mudança de situação, troca de plano e mudanças de usuário (papel e ativo), sempre com quem fez.

## 4. Situação da assinatura

`PENDENTE → TESTE → ATIVA → INADIMPLENTE → SUSPENSA → CANCELADA`

Transições permitidas na F1, todas feitas pelo admin exceto a expiração:
- `PENDENTE → ATIVA`
- `TESTE → ATIVA`
- `TESTE → SUSPENSA`, automática quando o teste termina.
- `ATIVA | INADIMPLENTE → SUSPENSA`
- `SUSPENSA → ATIVA`
- `qualquer estado → CANCELADA`

Transição fora dessa lista lança exceção. Não há estado implícito.

**Regras de acesso** (middleware `VerificarSituacaoDoTenant` nas rotas de empresa):

| Situação | Login | Leitura (GET) | Escrita | O que o frontend mostra |
|---|---|---|---|---|
| PENDENTE | sim | só `/me` e consumo | **bloqueada** | tela "Aguardando ativação" |
| TESTE | sim | sim | sim | faixa "Teste grátis até DD/MM/AAAA" |
| ATIVA | sim | sim | sim | nada |
| INADIMPLENTE | sim | sim | sim | faixa de alerta (a carência vem na F5) |
| SUSPENSA | sim | sim | **bloqueada** | faixa "Conta suspensa"; downloads continuam liberados |
| CANCELADA | sim | sim | **bloqueada** | igual a SUSPENSA |

- **Escrita bloqueada** devolve `403` com `{"message": "...", "codigo": "ASSINATURA_SEM_ESCRITA"}`.
- **Leitura continua liberada** porque o tenant precisa exportar e baixar seus XMLs mesmo suspenso (spec mestre, §3).
- **Expiração do teste:** comando agendado `assinaturas:expirar-testes`, diário às 00:10 (America/Sao_Paulo). Ele passa para `SUSPENSA` todo `TESTE` com `teste_termina_em < hoje` e registra auditoria. É idempotente.

## 5. EntitlementService (módulo `Platform`)

```php
final class EntitlementService {
    public function temModulo(Tenant $t, Modulo $m): bool;
    public function limite(Tenant $t, Recurso $r): int;                 // -1 = ilimitado; ausente = 0
    public function uso(Tenant $t, Recurso $r): int;                    // via contadores registrados por módulo
    public function garantirCapacidade(Tenant $t, Recurso $r, int $adicionar = 1): void; // lança LimiteDoPlanoAtingidoException
}
```

- **Contadores de uso:** cada módulo registra o seu (`ContadorDeUso` por recurso). Na F1 só existe o de `USUARIOS`, que conta os usuários **ativos** do tenant. Um recurso sem contador lança exceção ao pedir o uso, em vez de devolver 0 em silêncio.
- **Onde se verifica:** a checagem de limite fica **nas Actions** (por exemplo, `ConvidarUsuario`), nunca só no controller, para valer também na futura API pública.
- **Módulo não contratado:** `garantirModulo()` devolve `403` com `codigo: MODULO_NAO_CONTRATADO`. Na F1 só há a infraestrutura e o teste; nenhuma rota de módulo existe ainda.
- **Limite atingido:** `422` com `codigo: LIMITE_DO_PLANO` e a mensagem "Seu plano permite até N usuários ativos."

## 6. CNPJ (value object `Cnpj` em `Shared`)

- Aceita os formatos **numérico** e **alfanumérico** (IN RFB 2.229/2024, novas inscrições a partir de jul/2026):
  - As 12 primeiras posições são `[0-9A-Z]` e as 2 últimas (DV) são numéricas.
  - O DV é calculado pelo módulo 11 com os pesos de sempre, tomando o valor de cada caractere como `código ASCII − 48`.
- A entrada é normalizada: remove `.`, `/` e `-`, e converte para maiúsculas. O valor é guardado com 14 caracteres, sem máscara.
- CNPJ inválido, com todos os dígitos iguais ou de tamanho errado lança exceção.
- A regra de validação `CnpjValido` é usada nos requests.
- **Testes:** um caso numérico real válido, um caso alfanumérico com DV conhecido tirado do exemplo oficial da Receita, e os inválidos.

## 7. Cadastro público

**Endpoints:**
- `GET /api/publico/planos`: planos `ativo && visivel`, ordenados por `ordem`, com preço, módulos e limites.
- `GET /api/publico/cnpj/{cnpj}`:
  - Valida o CNPJ e consulta a BrasilAPI (`/api/cnpj/v1/{cnpj}`) com timeout de 5 s.
  - Resposta: `{razao_social, nome_fantasia}`, ou `404` quando não encontrado.
  - Se a BrasilAPI falhar, responde `503` e o frontend libera a digitação manual.
  - Cache de 24 h por CNPJ. Limite de 10 requisições por minuto por IP.
- `POST /api/publico/cadastro`:
  - Campos: `cnpj`, `razao_social`, `nome_fantasia?`, `plano_id`, `responsavel_nome`, `email`, `password`, `password_confirmation`, `aceite_termos` (deve ser true).
  - Numa transação: cria o tenant (situação `TESTE` com `teste_termina_em = hoje + dias_teste`, ou `PENDENTE`), cria o usuário `PROPRIETARIO`, faz login (sessão) e responde `201` com o usuário.
  - CNPJ já cadastrado → `422` "Este CNPJ já possui conta. Entre ou recupere a senha." E-mail já cadastrado → `422`.
  - Plano inativo ou invisível → `422`.
  - Limite de 5 cadastros por hora por IP.
- **O que não se confia:** o nome e a razão social enviados valem porque o usuário pode editá-los. O CNPJ é validado pelo DV. A busca na BrasilAPI é só uma conveniência de preenchimento, nunca uma validação obrigatória.

**Frontend:**
- `/planos`: cards responsivos com preço, módulos, limites e o botão "Começar".
- `/cadastro?plano=<id>` em duas etapas:
  1. **Empresa:** CNPJ com máscara, busca automática ao completar, e razão social e nome fantasia editáveis.
  2. **Responsável:** nome, e-mail e senha com medidor de força, mais o aceite dos termos.
- Ao concluir vai para `/dashboard`. Se a conta for `PENDENTE`, mostra a tela "Aguardando ativação".

## 8. Admin da plataforma

**Acesso:**
- Rotas `/api/admin/*` com o middleware `somentePlataforma`: `SUPERADMIN` faz tudo e `SUPORTE` só lê.
- Usuário de tenant recebe `403`, e o admin recebe `403` nas rotas de tenant.

**Endpoints:**
- `GET|POST /admin/planos`, `GET|PUT /admin/planos/{id}`, `POST /admin/planos/{id}/desativar`.
- **Plano com empresas não é excluído**, só desativado. Editar um plano altera todas as empresas que o usam (o admin vê o aviso "N empresas usam este plano").
- `GET /admin/empresas`: filtros de situação, plano e busca por CNPJ ou razão social. Paginação por cursor.
- `GET /admin/empresas/{id}`: dados, plano, situação e consumo (uso × limite de cada recurso).
- `POST /admin/empresas/{id}/situacao` (`{situacao, motivo}`): segue a máquina de estados do §4, e o motivo é obrigatório.
- `POST /admin/empresas/{id}/plano` (`{plano_id}`):
  - Se o uso atual passa algum limite do plano novo, responde `422` listando os excessos. **Não troca.**
- `GET /admin/metricas`:
  - Empresas por situação, novas nos últimos 30 dias.
  - MRR estimado: soma do preço mensal das empresas `ATIVA` e `INADIMPLENTE`.

**Frontend** (`app/(admin)`, com layout próprio que reaproveita o `AppShell`):
- Painel com as métricas.
- Planos: lista e formulário com checkboxes de módulos e limites. "Ilimitado" é um toggle que grava `-1`.
- Empresas: tabela, que vira cards no celular, com o detalhe e as ações (ativar, suspender com motivo, trocar plano).

## 9. Usuários da empresa

**Endpoints:**
- `GET /app/usuarios`, `POST /app/usuarios` (convite), `PUT /app/usuarios/{id}` (nome e papel), `POST /app/usuarios/{id}/desativar|reativar`.

**Permissões:**
- `PROPRIETARIO` e `ADMIN` gerenciam os usuários.
- Ninguém altera ou desativa o `PROPRIETARIO`, e ninguém desativa a si mesmo.
- Um `ADMIN` não promove ninguém a `PROPRIETARIO`.

**Convite:**
- Cria o usuário com senha aleatória não revelada e envia o e-mail "Você foi convidado para <empresa>. Defina sua senha" com link do broker de senha, com validade de **72 h** para convites.
- Respeita `garantirCapacidade(USUARIOS)`, e reativar um usuário também respeita.

**Frontend:** `Configurações › Usuários` (lista, convite e edição).

**Isolamento:** as listagens filtram por `tenant_id` explicitamente (o `Usuario` não usa o trait; ver ADR 0002), e o teste cobre o acesso a usuário de outra empresa: `404`.

## 10. Pendências herdadas da F0 (entram nesta fase)

1. `Usuario::$fillable` sem `tenant_id` e `papel`. As Actions os atribuem de forma explícita.
2. `DefinirTenantDoUsuario` na prioridade de middleware antes de `SubstituteBindings`, com teste de route model binding de Model de tenant.
3. **Usuário desativado perde o acesso** na próxima requisição: o middleware verifica `ativo` e encerra a sessão com `401`.
4. **Frontend:**
   - `MutationCache.onError` para `401`.
   - Revalidação do `/me` ao voltar o foco da janela.
5. `aria-invalid` + `aria-describedby` em todos os campos de formulário, incluindo os novos. O erro de e-mail ou token do link de redefinição passa a aparecer.
6. **Testes de redefinição de senha:** token expirado (`travel(61)->minutes()`) e token reutilizado.
7. **Infraestrutura:**
   - Job de CI com PostgreSQL 16 rodando a suíte do backend.
   - `.env.example` com `QUEUE_CONNECTION=sync`.
   - README com a seção "Produção": `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE` e `TRUSTED_PROXIES`.
8. **Limite de login por IP:** 20 tentativas por minuto, somadas entre todos os e-mails, além do limite de 5 por e-mail + IP.

## 11. Testes (mínimo por área)

- **Máquina de estados:** todas as transições válidas e uma inválida. A expiração do teste é idempotente.
- **Acesso por situação:** escrita bloqueada em `PENDENTE`, `SUSPENSA` e `CANCELADA` com o código certo. Leitura liberada em `SUSPENSA`.
- **Entitlement:**
  - Recurso ausente vale 0.
  - `-1` é ilimitado.
  - O limite de usuários barra o convite e a reativação.
  - A troca de plano com excesso é recusada.
- **CNPJ:** numérico válido, alfanumérico válido e inválidos.
- **Cadastro:**
  - Com teste, a conta nasce `TESTE` com a data certa; sem teste, nasce `PENDENTE`.
  - CNPJ ou e-mail duplicado.
  - Plano invisível.
  - BrasilAPI simulada com `Http::fake()` para os casos ok, 404 e timeout.
- **Admin:** `SUPORTE` não escreve; tenant não acessa o admin.
- **Frontend:**
  - Cadastro em duas etapas, com o caso da BrasilAPI indisponível.
  - Formulário de plano com o "ilimitado".
  - Faixas de situação.
  - Formulário de convite.

## 12. Riscos

- **BrasilAPI é um serviço comunitário**, sem SLA. Por isso nunca é obrigatória e tem cache.
- **Editar um plano afeta quem já o usa.** Aceito para a F1, com o aviso explícito para o admin. Versionar planos, se for necessário, fica para a F5.
- **CNPJ alfanumérico em documentos fiscais:** as regras de XML (NF-e e NFS-e) serão verificadas na F2 e na F3.
