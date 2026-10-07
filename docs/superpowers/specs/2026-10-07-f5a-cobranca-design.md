# F5a: cobrança (núcleo): assinatura, checkout, webhook e inadimplência

Data: 2026-10-07. Decisões do usuário: **F5a (núcleo) + F5b (extras)**; **sem credenciais do sandbox do Mercado Pago**, então tudo é construído atrás de uma interface com gateway falso nos testes; **cartão por `preapproval`, boleto por agendamento próprio**; carências de **1 dia** (inadimplente) e **10 dias** (suspensa).

## Objetivo

O assinante escolhe um plano, um ciclo (mensal ou anual) e um método (cartão ou boleto) e paga pelo Mercado Pago. O pagamento confirmado estende o período pago e ativa o tenant. Cobrança vencida e não paga leva o tenant a `INADIMPLENTE` e depois a `SUSPENSA`.

**Fora da F5a (F5b):** troca de plano (upgrade proporcional, downgrade bloqueado pelo uso), documento excedente. **Fora de ambas:** estorno, NFS-e da própria plataforma para o assinante, Pix avulso.

## Módulo e dados

Novo módulo `app/Modules/Billing` (Domain, Application, Infrastructure, Http, Providers), com o mesmo desenho dos demais. Todos os Models de tenant usam `BelongsToTenant` e têm teste de isolamento.

- `assinaturas`: `id`, `tenant_id`, `plano_id`, `ciclo` (`MENSAL`|`ANUAL`), `metodo` (`CARTAO`|`BOLETO`), `situacao` (`PENDENTE`|`ATIVA`|`CANCELADA`), `gateway_assinatura_id` (nulo no boleto), `paga_ate` (data; nula até o primeiro pagamento), `cancelada_em`, timestamps. Uma assinatura **não cancelada** por tenant (índice parcial/regra na Action).
- `cobrancas`: `id`, `tenant_id`, `assinatura_id`, `tipo` (`ASSINATURA`; `EXCEDENTE` fica para a F5b), `valor_centavos`, `vence_em`, `status` (`PENDENTE`|`PAGA`|`VENCIDA`|`CANCELADA`), `metodo`, `gateway_pagamento_id` (único quando não nulo), `boleto_url`, `boleto_linha_digitavel`, `pago_em`, `periodo_inicio`, `periodo_fim`, timestamps.
- `webhooks_recebidos`: `id`, `gateway_evento_id` (único), `tipo`, `recebido_em`, `processado_em`. Sem `tenant_id` (o evento chega antes de achar o tenant); não é Model de tenant e só o conciliador lê.
- Valores sempre em centavos inteiros. Preço do ciclo: `Plano::preco_mensal_centavos` ou `preco_anual_centavos`; plano sem o preço do ciclo escolhido não aceita o ciclo (`BILLING_CICLO_INDISPONIVEL`). Nunca há valor padrão.

## Gateway

`Billing\Domain\Contracts\GatewayDeCobranca`:

- `criarPagamentoDeCartao(DadosDoPagamento $dados): ResultadoDePagamento` (primeira cobrança do cartão, com o token do Payment Brick)
- `criarAssinaturaDeCartao(DadosDaAssinatura $dados): ResultadoDeAssinatura` (`/preapproval`)
- `criarBoleto(DadosDoBoleto $dados): ResultadoDePagamento`
- `buscarPagamento(string $id): ResultadoDePagamento` (única fonte de status confiável)
- `cancelarAssinatura(string $id): void`

`ResultadoDePagamento`: `id`, `status` (`APROVADO`|`PENDENTE`|`RECUSADO`|`CANCELADO`|`VENCIDO`), `referenciaExterna` (o id da `cobranca`), `valorCentavos`, `boletoUrl`, `linhaDigitavel`. `MercadoPagoGateway` (HTTP, `X-Idempotency-Key` com o id da cobrança, `external_reference` = id da cobrança) e `GatewayDeCobrancaFake` (respostas enfileiradas, registro das chamadas). O adaptador real **não é exercitado nesta fase**; o roteiro do sandbox vai para o `PROGRESSO.md` (inclui validar se o `preapproval` gera boleto, o formato real do webhook e o manifesto do HMAC).

Segredos (`MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_WEBHOOK_SECRET`, chave pública do Brick) só em variável de ambiente (`config/billing.php`), nunca no repositório.

## Regras e Actions

As telas e a API chamam as mesmas Actions.

- `IniciarAssinatura(Tenant, Usuario, plano, ciclo, metodo, dadosDoPagamento)`: só para papéis `PROPRIETARIO`/`ADMIN`; plano ativo e visível; valor definido pelo servidor. Cria a `assinatura` (`PENDENTE`) e a primeira `cobranca`, e chama o gateway: cartão cria o `preapproval` e a primeira cobrança; boleto cria o boleto da primeira cobrança. Devolve o que a tela precisa (link do boleto ou status do cartão). Falha do gateway não deixa assinatura `PENDENTE` órfã: a Action marca a cobrança `CANCELADA` e devolve erro acionável. Idempotente por `assinatura` + ciclo corrente.
- `ConciliarPagamento(gatewayPagamentoId)`: chama `buscarPagamento`, acha a `cobranca` por `referenciaExterna` e confere o valor. Só confia no status consultado. `APROVADO` + tipo `ASSINATURA`: marca `PAGA`, calcula o período (`periodo_inicio` = maior entre hoje e `paga_ate`; `periodo_fim` = início + 1 mês ou 1 ano), grava `paga_ate` e ativa o tenant (`MudarSituacaoDaEmpresa`). Valor divergente: não concilia e registra para auditoria (`BILLING_VALOR_DIVERGENTE`). Reaplicar o mesmo pagamento não muda nada.
- `ReceberWebhook`: valida `x-signature` e `x-request-id` por HMAC-SHA256 com o manifesto `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`, **falha fechada** (sem header, sem segredo ou assinatura errada: 401, nada é processado), grava `gateway_evento_id` (duplicado: responde 200 sem refazer) e chama `ConciliarPagamento`. Resposta rápida; erros de conciliação são registrados e o webhook responde 5xx só para falha transitória (para o gateway reenviar).
- `GerarBoletosDoCiclo` (comando diário): para cada assinatura `ATIVA` de boleto cuja `paga_ate` vence em até 5 dias e que ainda não tem cobrança pendente do próximo período, cria a `cobranca` e o boleto no gateway.
- `AplicarInadimplencia` (comando diário): cobrança `PENDENTE` com `vence_em` anterior a hoje vira `VENCIDA`; tenant `ATIVA` com cobrança vencida há 1 dia ou mais vai a `INADIMPLENTE`; vencida há 10 dias ou mais vai a `SUSPENSA`. As carências ficam em `config/billing.php` (`carencia_inadimplente_dias`, `carencia_suspensao_dias`). Pagamento depois disso reativa pela conciliação.
- `CancelarAssinatura`: cancela o `preapproval` (cartão), marca a assinatura `CANCELADA` e cobranças pendentes `CANCELADA`; o acesso continua até `paga_ate` e o `AplicarInadimplencia` não age sobre assinatura cancelada.

**Máquina de situação (`SituacaoAssinatura`):** hoje `ATIVA`/`INADIMPLENTE` só podem ir a `SUSPENSA`. A F5a acrescenta `ATIVA → INADIMPLENTE`, `INADIMPLENTE → ATIVA`, `TESTE → ATIVA` e `PENDENTE → ATIVA` (já existe) com teste da tabela inteira. `permiteEscrita()` não muda (inadimplente ainda escreve; suspenso só lê).

## API e telas

`/api/app` (middleware `empresa`):
- `GET /assinatura/cobrancas`: faturas do tenant (valor, vencimento, status, link do boleto).
- `POST /assinatura/checkout`: plano, ciclo, método e dados do pagamento.
- `POST /assinatura/cancelar`.

Público: `POST /api/webhooks/mercadopago` (sem sessão; `throttle`).

Frontend (`features/assinatura`): página `/assinatura/planos` (cartões dos planos visíveis com ciclo e preço), checkout (cartão com o Payment Brick, carregado do SDK do Mercado Pago e simulado nos testes; boleto com botão que mostra o link e a linha digitável), `/assinatura/faturas` e a faixa de situação existente passando a levar ao checkout quando `INADIMPLENTE`/`SUSPENSA`/`TESTE`. TS strict, zod, TanStack Query.

## Testes

- Isolamento por tenant: `Assinatura` e `Cobranca`; endpoints de faturas e cancelamento (404 de outro tenant).
- Domínio: preço do ciclo, período (inclusive `paga_ate` futura), tabela de transições.
- Webhook: HMAC válido, ausente, errado e segredo vazio (todos recusados, exceto o válido); evento duplicado; status só pelo gateway (corpo do webhook dizendo "aprovado" com o gateway dizendo "pendente" não ativa); valor divergente.
- Conciliação idempotente; só `ASSINATURA` estende período e reativa; reativação de `SUSPENSA` e de `INADIMPLENTE`.
- `IniciarAssinatura`: cartão e boleto, plano sem preço do ciclo, papel sem permissão, falha do gateway sem órfã, segunda assinatura ativa recusada.
- Comandos: `GerarBoletosDoCiclo` (antecedência, sem duplicar) e `AplicarInadimplencia` (limites exatos de 1 e 10 dias, fuso do negócio, assinatura cancelada ignorada).
- Frontend (Vitest): escolha de plano, checkout de boleto, faturas, faixa de situação com link.

## Pendências a registrar

Adaptador real do Mercado Pago nunca exercitado (roteiro de sandbox: cartão, boleto, `preapproval` com boleto, webhook com HMAC real, reenvio); F5b (troca de plano e excedente); estorno; NFS-e da plataforma; e-mail de aviso de cobrança e inadimplência (depende do módulo de e-mail, ainda não feito).
