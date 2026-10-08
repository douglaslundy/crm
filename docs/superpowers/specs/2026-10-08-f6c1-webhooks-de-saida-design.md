# F6c1: webhooks de saída

Data: 2026-10-08. A F6c (spec mestre, seção 8) é cortada em **F6c1 (este documento: webhooks)** e **F6c2 (OpenAPI 3.1 e guia do integrador)**. Continua as F6a (chaves, `api-v1`, erros RFC 9457, cursor) e F6b (notas pela API). Decisões do usuário: o corte acima; corpo "completo" (recurso da nota) e ambiente por endpoint.

## Objetivo

O sistema avisa o ERP quando uma nota muda de situação (autorizada, rejeitada, cancelada, erro, contingência), com assinatura verificável, retentativas, log de entregas e reenvio manual, sem o ERP precisar consultar. Quem configura usa a API (`/api/v1/webhooks`) ou a tela do app; as duas chamam as mesmas Actions.

**Fora da F6c1:** rotação de segredo; webhooks de outros recursos (cliente, produto, cobrança); ordenação garantida; fila externa; OpenAPI e guia (F6c2).

## Eventos

`nota.autorizada`, `nota.rejeitada`, `nota.cancelada`, `nota.erro`, `nota.contingencia` (de `StatusNota` AUTORIZADA, REJEITADA, CANCELADA, ERRO, CONTINGENCIA) e `webhook.teste` (botão de teste). `PROCESSANDO`, `CANCELANDO` e `RASCUNHO` não geram evento.

Gatilho: observer de `created` em `NotaEvento` (único ponto onde o status muda). Cria as entregas dentro da transação da mudança de status e despacha o envio **depois do commit** (`DB::afterCommit`). Uma nota que só passa de status por `forceFill` sem `NotaEvento` não notifica (não existe hoje).

## Dados

Models de tenant (Global Scope, teste de isolamento).

- `webhook_endpoints`: `id` uuid, `tenant_id`, `usuario_id` (quem criou), `url` (até 500), `descricao` (até 80, nula), `eventos` (json, lista de nomes; vazia = todos os 5), `ambiente` (`HOMOLOGACAO`|`PRODUCAO`), `segredo` (cast `encrypted`; `whsec_` + 40 caracteres), `ativo` (bool), `desativado_em` e `motivo_desativacao` (nulos), `falhas_seguidas` (int), timestamps. Máximo de **5** endpoints por empresa (`WEBHOOK_LIMITE_ATINGIDO`).
- `webhook_entregas`: `id`, `tenant_id`, `webhook_endpoint_id`, `nota_evento_id` (nulo no teste), `nota_id` (nulo no teste), `evento` (nome), `evento_id` (uuid do evento, **o mesmo em todas as tentativas**), `payload` (json, congelado), `estado` (`PENDENTE`|`ENTREGUE`|`FALHOU`), `tentativas` (int), `proxima_tentativa_em` (nula), `entregue_em` (nula), timestamps; único `(webhook_endpoint_id, nota_evento_id)` (idempotência do gatilho); índice `(estado, proxima_tentativa_em)`.
- `webhook_tentativas`: `id`, `tenant_id`, `webhook_entrega_id`, `numero`, `status_http` (nulo), `duracao_ms`, `erro` (até 500, nulo), `resposta` (primeiros 1024 bytes, nula), `created_at`.

## Payload e assinatura

Corpo:

```json
{ "id": "<uuid do evento>", "tipo": "nota.autorizada", "criado_em": "ISO-8601", "ambiente": "HOMOLOGACAO",
  "data": { "nota": { /* NotaResource do app, com itens, pagamentos e eventos, sem XML */ } } }
```

`webhook.teste` leva `data: { "mensagem": "..." }`. Cabeçalhos: `Content-Type: application/json`, `User-Agent: PlataformaFiscal-Webhook/1`, `X-Webhook-Id` (= `id`), `X-Webhook-Event`, `X-Signature: t=<unix>,v1=<hex hmac_sha256(segredo, "<t>.<corpo>")>`. O integrador recusa `t` com mais de 5 minutos e compara a assinatura em tempo constante (documentado). O `t` e a assinatura são recalculados a cada tentativa; o `id` e o corpo não mudam.

## Entrega e retentativas

- Job `EnviarWebhookJob(entregaId)` (fila `database` já existente): `POST` com timeout de 10 s, **sem seguir redirecionamentos**; sucesso = qualquer `2xx`. Cada tentativa grava um `webhook_tentativas`.
- Falha (não 2xx, timeout, erro de conexão, URL bloqueada): `tentativas++` e reagenda em **1 min, 5 min, 30 min, 2 h, 6 h, 12 h** depois da falha anterior (a 7ª falha encerra: `FALHOU`, ≈ 20 h no total). Estado e agendamento ficam na tabela; o comando `webhooks:reenviar-pendentes` (a cada minuto, `withoutOverlapping`) despacha o job das entregas `PENDENTE` com `proxima_tentativa_em <= agora`, reservando-as com um `UPDATE` condicional (sem despachar duas vezes). O envio imediato do primeiro evento é despachado no commit.
- `falhas_seguidas` do endpoint sobe a cada entrega que chega a `FALHOU` e zera a cada `ENTREGUE`. Ao chegar a **10**, o endpoint é desativado (`ativo=false`, `desativado_em`, `motivo_desativacao`).
- Entrega at-least-once, sem ordem garantida (documentado; o integrador deduplica por `id`).
- Antes de enviar: o endpoint precisa estar `ativo`, o plano ter o módulo `API` e o `ambiente` do endpoint coincidir com `payload.ambiente`; caso contrário a entrega é descartada (`FALHOU` com `erro` explicativo, sem retentativa).

## Segurança

- **SSRF:** a URL precisa ser `https` (em `local`/`testing`, `http` também) e sem credenciais embutidas. O host não pode ser IP privado, loopback, link-local, `0.0.0.0` nem os endereços de metadados de nuvem (v4 e v6). A validação roda ao cadastrar/atualizar **e a cada envio**, resolvendo o DNS e checando **todos** os IPs, e o envio se conecta ao IP validado (sem segunda resolução). Resolvedor de DNS injetável para teste.
- O segredo só aparece na resposta da criação; nunca em listagem, log, `activity` ou erro. As respostas do destino guardadas no log são truncadas e tratadas como texto.
- Só PROPRIETARIO e ADMIN gerenciam; módulo `API` obrigatório; auditoria `activity('webhooks')` de criar, alterar, excluir e reenviar, sem o segredo.

## Gestão (Actions no módulo `Fiscal`, pasta `Webhooks`)

Actions `CriarWebhook(Usuario, dados): ResultadoDoWebhook (modelo + segredo)`, `AtualizarWebhook` (url, descrição, eventos, ambiente, ativo; reativar zera `falhas_seguidas`), `ExcluirWebhook`, `ListarWebhooks`, `ListarEntregasDeWebhook` (cursor), `ReenviarEntrega` (volta a `PENDENTE`, nova tentativa imediata, mesmo `evento_id`; recusa se o endpoint está inativo), `EnviarTesteDeWebhook`, `PoliticaDeWebhooks`.

- API: `GET/POST /api/v1/webhooks`, `GET/PUT/DELETE /api/v1/webhooks/{id}`, `GET /api/v1/webhooks/{id}/entregas`, `POST /api/v1/webhooks/{id}/testar`, `POST /api/v1/webhooks/entregas/{id}/reenviar` (idempotência opcional nos POST). Mesmas rotas em `/api/app/webhooks` (nomes `app.webhooks.*`).
- Recurso: `id`, `url`, `descricao`, `eventos`, `ambiente`, `ativo`, `desativado_em`, `motivo_desativacao`, `segredo_final` (últimos 4), `criado_em`; a criação traz `segredo` uma vez. Entrega: `id`, `evento`, `evento_id`, `estado`, `tentativas`, `proxima_tentativa_em`, `entregue_em`, `tentativas_log[]` (status, duração, erro, resposta truncada), sem o payload completo na lista (o `GET` da entrega o traz).
- Erros novos: `WEBHOOK_LIMITE_ATINGIDO` (422), `WEBHOOK_URL_INVALIDA` (422, com `errors[]`), `WEBHOOK_INATIVO` (409).

## Tela (`/configuracoes/webhooks`)

Lista de endpoints (URL, ambiente, eventos, estado, motivo se desativado); criar (URL, descrição, ambiente, eventos; o segredo aparece **uma vez** com aviso e botão copiar, fora de storage e cache); ativar/desativar, excluir com confirmação, **Enviar teste**; ao abrir um endpoint, as entregas (evento, estado, tentativas, próxima tentativa) com detalhe das tentativas e **Reenviar**. Papel sem permissão vê só o aviso. Item de menu para PROPRIETARIO/ADMIN.

## Operação

`webhooks:limpar` diário (00:55) apaga entregas e tentativas com mais de 30 dias. O `queue:work` e o `schedule:work` do deploy já existem; o `--tries=1` do worker é adequado porque a retentativa é da tabela.

## Testes

- Gatilho: cada status gera o evento certo; status sem evento não gera; só endpoints ativos, do ambiente da nota e que assinam o evento; evento duplicado (mesmo `nota_evento_id`) não duplica; rollback da transação não deixa entrega; entrega criada na mesma transação.
- Entrega: assinatura recalculável pelo teste (`t` e hmac), cabeçalhos, corpo sem XML, `2xx` entrega, não-2xx/timeout agenda a próxima nos intervalos do spec (`travelTo`), 7ª falha encerra, `falhas_seguidas` e desativação automática na 10ª, redirecionamento não é seguido, resposta truncada em 1 KB, `X-Webhook-Id` estável nas retentativas, endpoint inativo/ambiente diferente/módulo ausente descartam.
- SSRF: IPs privados/loopback/link-local/metadados (v4, v6, e hostname que resolve para eles) bloqueados no cadastro e no envio; `http` recusado fora de local/testing; rebinding (DNS muda entre cadastro e envio) bloqueado.
- Gestão: papéis, limite de 5, módulo, segredo só na criação, auditoria sem segredo, reenviar mantém `evento_id`, testar envia `webhook.teste`, **isolamento** (endpoint e entrega de outro tenant: 404 nas duas superfícies), comando de reenvio não despacha duas vezes, limpeza.
- Frontend: lista, criar mostra o segredo uma vez, ativar/desativar, excluir com confirmação, entregas e reenviar, erro em alerta, papel sem permissão.

## Pendências a registrar

Rotação de segredo; outros recursos; ordenação; fila externa; `ETag`; allowlist de IP de saída documentada para o integrador; F6c2 (OpenAPI e guia).
