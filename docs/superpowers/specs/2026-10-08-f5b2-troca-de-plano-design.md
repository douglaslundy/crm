# F5b2: troca de plano (upgrade pago e downgrade agendado)

Data: 2026-10-08. Decisões do usuário: o **ciclo não muda** na troca; o **upgrade é pago por cartão ou PIX (nunca boleto) e o plano só muda ao pagar**; o downgrade é no próximo ciclo e **bloqueado se o uso atual passar dos limites do plano novo** (spec mestre, seção 12). Base: F5a (assinatura, conciliação, inadimplência) e F5b1 (`PagarCobranca`, `PainelDePagamento`, cobrança que não estende período).

## Objetivo

O assinante troca de plano sem passar por um novo checkout: subir de plano cobra só a diferença proporcional ao tempo que falta e vale quando o pagamento é confirmado; descer de plano vale no fim do período já pago.

**Fora da F5b2:** troca de ciclo (mensal/anual); crédito ou estorno pela diferença; troca por quem está em teste, pendente, inadimplente ou suspenso (usam o checkout ou regularizam antes); trocar o cartão; e-mails de aviso.

## Dados

Migration nova:

- `assinaturas`: `valor_ciclo_centavos` (unsigned big int, nulo: preço contratado do ciclo atual, gravado no checkout e a cada troca; nulo em assinatura antiga cai no preço do plano no ciclo), `plano_proximo_id` (uuid nulo, FK de `planos`) e `plano_proximo_em` (date nula, igual ao `paga_ate` no pedido).
- `cobrancas`: `plano_destino_id` (uuid nulo, FK de `planos`).
- `TipoDeCobranca::Upgrade` (`estendePeriodo()` falso).
- Índice único parcial: um upgrade pendente por assinatura (`assinatura_id WHERE tipo = 'UPGRADE' AND status = 'PENDENTE'`).

## Gateway

`GatewayDeCobranca::atualizarAssinatura(string $id, int $valorCentavos): void` (`PUT /preapproval/{id}` com `auto_recurring.transaction_amount`), para assinatura de cartão. O adaptador só com `Http::fake()`; o formato real vai para o roteiro de sandbox. O falso registra a chamada.

## Regras

- **Quem pode:** `PROPRIETARIO` ou `ADMIN`, assinatura `ATIVA` com `paga_ate` de hoje em diante e conta `ATIVA`. O plano novo é ativo, visível, diferente do atual e tem preço no ciclo da assinatura. Preço igual ao atual é recusado (`BILLING_TROCA_MESMO_PRECO`).
- **Upgrade (preço do ciclo maior que o atual):** `TrocarDePlano` cria uma cobrança `UPGRADE` pendente com `plano_destino_id`, vencimento em 1 dia, sem método, valor `round((preço novo − valor_ciclo_centavos) × dias restantes ÷ dias do ciclo)` em centavos inteiros (meio para cima), sendo dias restantes = `paga_ate − hoje` e dias do ciclo = `paga_ate − (paga_ate − meses do ciclo)`. Valor zero (renovação hoje) é recusado (`BILLING_TROCA_SEM_DIAS`). Se já existe upgrade pendente, devolve o mesmo (se o destino for igual) ou cancela-o e cria o novo. Pagar usa `PagarCobranca` (que passa a aceitar `UPGRADE`).
- **Conciliação do upgrade:** ao pagar, em uma transação: confere se o plano destino ainda está ativo; `tenant.plano_id` e `assinatura.plano_id` passam ao destino (via `forceFill`), `valor_ciclo_centavos` ao preço novo do ciclo, e `plano_proximo_*` são limpos; `paga_ate` não muda; cartão: `atualizarAssinatura` com o preço novo (falha do gateway não desfaz o pagamento: fica no log para reprocessar). Upgrade não pago e vencido vira `CANCELADA` sem efeito algum e **não** entra na inadimplência (a rodada de inadimplência só considera cobrança de assinatura e excedente).
- **Downgrade (preço do ciclo menor):** `AgendarDowngrade` confere `EntitlementService::excessos(tenant, plano novo)`; se não for vazio recusa (`PlanoInsuficienteException`, com a lista). Senão grava `plano_proximo_id` e `plano_proximo_em = paga_ate`. Cartão: `atualizarAssinatura` com o preço novo na hora do pedido (o gateway cobra no vencimento). Boleto: o gerador de boletos usa o preço de `plano_proximo` quando o próximo período é o do plano novo. `CancelarDowngrade` limpa o agendamento e, no cartão, devolve o valor antigo.
- **Aplicar o downgrade:** comando `billing:aplicar-trocas` (00:25, depois do gerador de boletos) para assinaturas com `plano_proximo_em` até hoje: confere os excessos de novo; sem excesso, troca `tenant.plano_id` e `assinatura.plano_id`, grava `valor_ciclo_centavos` e limpa o agendamento. Com excesso, cancela o downgrade, devolve o valor antigo no cartão e registra em log (limitação aceita: o ciclo já cobrado a menor não é cobrado de novo).
- **Renovação:** o valor esperado na renovação do cartão vem de `valor_ciclo_centavos` quando não nulo (hoje vem da última cobrança paga); `IniciarAssinatura` grava `valor_ciclo_centavos` no checkout e na regularização.
- **Atividade:** toda troca registra `activity('assinatura')` com de/para (como `TrocarPlanoDaEmpresa`).
- **Papéis, isolamento e permissões:** como `PagarCobranca` (`PoliticaDeCobranca`), tudo com escopo de tenant.

## API (`/api/app`, middleware `empresa`)

- `POST /assinatura/trocar-plano` (`plano_id`): devolve `{ tipo: 'UPGRADE', cobranca }` ou `{ tipo: 'DOWNGRADE', plano_proximo_id, plano_proximo_em }`. Fica nas rotas liberadas só para conta `ATIVA` (não entra em `LIBERADAS_PARA_PAGAR`).
- `DELETE /assinatura/trocar-plano`: cancela o downgrade agendado.
- `GET /assinatura/cobrancas` traz `plano_destino_id`; `GET /assinatura` traz `plano_proximo_id` e `plano_proximo_em`.

## Frontend

- Planos: assinante ativo vê em cada outro plano "Trocar para este plano", com a diferença a pagar hoje (upgrade, estimada pelo servidor na resposta) ou a data do downgrade, e o erro de excessos listado quando bloqueado. Aviso do downgrade agendado, com "Cancelar troca".
- Faturas: o upgrade pendente aparece com "Pagar" e abre o `PainelDePagamento`; o rótulo da fatura diz "Troca para <plano>: diferença de R$ x".

## Testes

- Proporcional: ciclo de 28, 30 e 31 dias; último dia (recusa); arredondamento; anual.
- Upgrade: criação e reaproveitamento; pago por cartão e por PIX troca o plano e não mexe em `paga_ate`; não pago vence sem efeito e sem inadimplência; plano destino inativado antes do pagamento; atualização do `preapproval`.
- Downgrade: bloqueado por excesso (lista); agendado (cartão atualiza o valor); cancelado; aplicado pelo comando; bloqueado na aplicação cancela o agendamento; boleto do próximo ciclo sai no preço novo.
- Quem não pode: papel, assinatura não ativa, conta inadimplente, plano igual/inativo/sem preço no ciclo.
- Isolamento: trocar plano ou pagar upgrade de outro tenant dá 404; o agendamento de um tenant não aparece em outro.
- Frontend: botões, valor da diferença, bloqueio, aviso e cancelamento do downgrade, fatura do upgrade com Pagar.

## Pendências a registrar

Sandbox do Mercado Pago para `PUT /preapproval` (valor novo vale na próxima cobrança?); ciclo mensal↔anual; crédito/estorno pela diferença; e-mail de aviso; desconto de ciclo já cobrado a menor quando o downgrade é cancelado na aplicação.
