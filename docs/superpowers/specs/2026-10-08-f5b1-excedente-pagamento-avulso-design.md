# F5b1: pagamento avulso (cartão e PIX) e documento excedente

Data: 2026-10-08. Decisões do usuário: a F5b é cortada em **F5b1 (este documento)** e **F5b2 (troca de plano)**; a diferença do upgrade (F5b2) é paga por **cartão ou PIX, nunca boleto**, e o plano só muda ao pagar; o excedente é uma **cobrança avulsa por PIX ou cartão**, e excedente vencido entra na **mesma inadimplência** da mensalidade (carências de 1 e 10 dias); excedente fechado **uma vez por mês**, em uma cobrança só.

## Objetivo

1. O gateway passa a fazer pagamento **avulso** no cartão e **PIX**, e o assinante paga uma cobrança avulsa pela tela de faturas. É a base do upgrade da F5b2.
2. Documento além do limite do plano deixa de bloquear quando o plano tem política `COBRAR` com preço; no começo do mês seguinte o excedente do mês vira uma cobrança `EXCEDENTE`.

**Fora da F5b1:** troca de plano (F5b2); estorno; e-mails de aviso; excedente de tenant sem assinatura ativa (fica registrado em log); trocar o cartão da assinatura; parcelamento (sempre 1x).

## Dados

Migration nova sobre as tabelas da F5a:

- `cobrancas.metodo` passa a **nullable** (a cobrança de excedente nasce sem forma de pagamento; ela é escolhida ao pagar). `Cobranca::metodo` vira `?MetodoDePagamento`.
- `cobrancas`: `pix_qr_code` (text, copia e cola), `pix_qr_code_base64` (text), `pix_expira_em` (timestamp), `mes_referencia` (date, dia 1 do mês cobrado), `documentos_excedentes` (unsigned int), `valor_unitario_centavos` (unsigned big int). Todos nulos fora do que usam.
- Índice único parcial: uma cobrança `EXCEDENTE` não cancelada por tenant e mês (`tenant_id, mes_referencia WHERE tipo = 'EXCEDENTE' AND status <> 'CANCELADA'`). É o que torna o fechamento idempotente.
- `MetodoDePagamento` ganha `PIX`. `TipoDeCobranca` ganha `EXCEDENTE`; `estendePeriodo()` é falso para ele.

## Gateway (`GatewayDeCobranca`)

- `criarPagamentoDeCartao(DadosDoPagamentoDeCartao): ResultadoDePagamento`: `POST /v1/payments` com o token do Card Payment Brick, 1 parcela, `external_reference` = id da cobrança.
- `criarPix(DadosDoPix): ResultadoDePagamento`: `POST /v1/payments` com `payment_method_id = pix` e `date_of_expiration` (config `pix_expira_minutos`, padrão 30).
- `ResultadoDePagamento` ganha `pixCopiaECola`, `pixQrCodeBase64`, `pixExpiraEm` (todos opcionais).
- Falso de teste e adaptador real seguem o padrão da F5a (adaptador só com `Http::fake()`; formato real dos campos do PIX no roteiro de sandbox).

## Regras

- **Política de excedente:** `EntitlementService::garantirCapacidade` deixa de bloquear `DOCUMENTOS_MES` quando o plano tem `politica_excedente = COBRAR` **e** `preco_documento_excedente_centavos` maior que zero. Sem preço, ou com `BLOQUEAR`, bloqueia como hoje. Outros recursos nunca excedem.
- **Contagem por mês:** novo contrato `ContadorDeUsoMensal` (`contarNoMes(Tenant, CarbonInterface $mes): int`) implementado por `ContadorDeDocumentosMes` com as mesmas regras do `contar()` (autorizadas, canceladas e cancelando em produção, por `emitido_em`); `contar()` passa a usar `contarNoMes(now())`.
- **`FecharExcedenteDoMes(CarbonImmutable $mes)`:** para cada tenant com assinatura `ATIVA` (qualquer situação da conta) e plano `COBRAR` com preço, `excesso = max(0, contarNoMes(mes) − limite)`; limite ilimitado não gera. Com excesso, cria uma cobrança `EXCEDENTE` (`valor = excesso × preço`, `valor_unitario_centavos`, `documentos_excedentes`, `mes_referencia`, `vence_em` = hoje + `dias_vencimento_excedente` (10), `status` pendente, sem método). Rodar de novo não duplica. Usa o plano do tenant no dia do fechamento. Comando `billing:fechar-excedente [--mes=AAAA-MM]` agendado no dia 1 às 00:40 (America/Sao_Paulo), padrão = mês anterior.
- **`PagarCobranca(Usuario, cobrancaId, metodo, token?)`:** só `PROPRIETARIO`/`ADMIN`; a cobrança é do tenant (outro tenant dá 404), do tipo `EXCEDENTE` (a F5b2 acrescenta `UPGRADE`) e está `PENDENTE` ou `VENCIDA`. O valor é sempre o da cobrança.
  - **PIX:** cria o PIX (idempotência = id da cobrança na primeira vez, uuid novo quando o anterior expirou) e grava `pix_*`, `gateway_pagamento_id`, `metodo = PIX`. PIX ainda válido é devolvido sem criar outro.
  - **Cartão:** exige o token (`BILLING_CARTAO_SEM_TOKEN`), cria o pagamento avulso; recusado lança `BILLING_CARTAO_RECUSADO` e nada é gravado; aprovado grava `gateway_pagamento_id` e `metodo = CARTAO` e chama `ConciliarPagamento` na hora (idempotente; o webhook confirma de novo sem efeito).
  - Falha do gateway: `BILLING_GATEWAY_FALHOU`, sem estado a meio.
- **Conciliação (`ConciliarPagamento`):** o pagamento aprovado de uma cobrança que não estende período só marca `PAGA` (`pago_em`; sem `periodo_*` nem `paga_ate`). A baixa das cobranças vencidas que o pagamento da mensalidade faz passa a valer **só para cobranças de assinatura**: pagar a mensalidade não perdoa o excedente. Depois de qualquer pagamento, se a conta está `INADIMPLENTE` ou `SUSPENSA`, a assinatura está `ATIVA` com `paga_ate` de hoje em diante e não resta cobrança vencida, a conta volta a `ATIVA` (assim quem foi suspenso por excedente consegue se regularizar).
- **Inadimplência:** não muda de código: já considera qualquer cobrança vencida de assinatura não cancelada, inclusive `EXCEDENTE`.

## API (`/api/app`, middleware `empresa`)

- `POST /assinatura/cobrancas/{cobranca}/pagar` (`metodo` em `CARTAO,PIX`, `token_do_cartao` opcional): devolve a cobrança com os campos do PIX. Entra na lista de rotas liberadas para pagar em qualquer situação da conta.
- `GET /assinatura/cobrancas` passa a trazer `pix_qr_code`, `pix_qr_code_base64`, `pix_expira_em`, `mes_referencia`, `documentos_excedentes`, `valor_unitario_centavos` e `metodo` nulo.

## Frontend

- Faturas: cobrança `EXCEDENTE` mostra "Documentos acima do limite em <mês>: N × R$ x" e o botão **Pagar** (também em vencidas). Abre o painel de pagamento com PIX (QR em imagem, código copia e cola com botão copiar, expiração) ou cartão (Card Payment Brick com o valor da cobrança). Com PIX gerado, a lista atualiza sozinha a cada poucos segundos até a cobrança sair de pendente.
- O painel de pagamento avulso é um componente reutilizável (a F5b2 o usa no upgrade).

## Testes

- Isolamento: pagar cobrança de outro tenant dá 404; `EXCEDENTE` de um tenant não aparece em outro.
- Política: `COBRAR` com preço deixa passar do limite; sem preço, `BLOQUEAR` e outros recursos bloqueiam; ilimitado não gera excedente.
- Fechamento: conta só o mês pedido (limites do mês: último dia e primeiro dia do seguinte), valor = excesso × preço, idempotente, ignora tenant sem assinatura `ATIVA` e plano sem preço, assinatura cancelada, e usa o fuso do negócio.
- `PagarCobranca`: PIX novo, PIX reaproveitado, PIX expirado gera outro, cartão aprovado concilia na hora, cartão recusado não grava, sem token, papel sem permissão, cobrança de tipo ou status inválido, falha do gateway.
- Conciliação: excedente pago não estende `paga_ate`; pagar a mensalidade não baixa o excedente vencido; pagar o excedente reativa conta suspensa que não deve mais nada, e não reativa se ainda há outra vencida ou o período pago acabou.
- Inadimplência: excedente vencido leva a inadimplente e a suspensa nos limites de 1 e 10 dias.
- Adaptador: formato do pedido de PIX e de cartão avulso com `Http::fake()`.
- Frontend: botão Pagar, PIX com QR e copiar, cartão com token, atualização da lista, erro em alerta.

## Pendências a registrar

Sandbox do Mercado Pago para PIX e cartão avulso (campos `point_of_interaction.transaction_data`, expiração, webhook do PIX); F5b2; excedente de tenant sem assinatura; e-mail de aviso do excedente fechado.
