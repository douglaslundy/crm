# Cobrança (Mercado Pago): como funciona e como validar

Spec: `docs/superpowers/specs/2026-10-07-f5a-cobranca-design.md`. Código: `backend/app/Modules/Billing` e `frontend/features/cobranca`.

## Fluxo

1. O proprietário ou administrador abre **Assinatura > Planos**, escolhe plano, ciclo (mensal ou anual) e forma de pagamento.
2. **Boleto:** o servidor cria a assinatura e a primeira cobrança e pede o boleto ao Mercado Pago (`POST /v1/payments`). O plano só ativa quando o pagamento for confirmado.
3. **Cartão:** o navegador monta o Card Payment Brick e devolve só um **token**; o servidor cria o `preapproval` (assinatura recorrente) com o token. Número de cartão nunca passa pelo nosso servidor.
4. O Mercado Pago avisa por **webhook** (`POST /api/webhooks/mercadopago`). O servidor valida o HMAC, **consulta o pagamento na API** e só então concilia: cobrança `PAGA`, `paga_ate` estendida, tenant `ATIVA`.
5. Todo dia: `billing:gerar-boletos` (00:20) cria o boleto do próximo ciclo das assinaturas de boleto 5 dias antes do fim do período; `billing:aplicar-inadimplencia` (00:30) marca cobranças vencidas e leva o tenant a `INADIMPLENTE` (1 dia depois do vencimento) e a `SUSPENSA` (10 dias depois).

## Atraso e regularização

- O atraso de um tenant é o maior entre os dias da cobrança vencida mais antiga e os dias desde o fim do período pago (`paga_ate`) de uma assinatura ativa. Assim a renovação do cartão que o Mercado Pago não conseguiu cobrar também leva à inadimplência.
- Quem está `INADIMPLENTE` ou `SUSPENSA` com assinatura ativa vê **Regularizar** em Planos e gera um novo boleto na mesma assinatura (vence em 3 dias; boleto vencido não se paga). Pagar esse boleto baixa as cobranças vencidas e reativa o tenant. O gerador de boletos não emite boleto novo para quem já tem cobrança vencida.
- Trocar de assinatura (novo checkout) cancela a pendente anterior, no Mercado Pago também; um boleto vencido há até 5 dias ainda pode estar sendo compensado e segura a troca.

## Variáveis de ambiente

Backend (`backend/.env`): `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_WEBHOOK_SECRET`, `MERCADOPAGO_PUBLIC_KEY`, opcionais `MERCADOPAGO_BASE_URL`, `BILLING_CARENCIA_INADIMPLENTE_DIAS` (1) e `BILLING_CARENCIA_SUSPENSAO_DIAS` (10). Frontend (`frontend/.env.local`): `NEXT_PUBLIC_MERCADOPAGO_PUBLIC_KEY`. Sem o token o checkout responde `BILLING_GATEWAY_NAO_CONFIGURADO`; sem o segredo do webhook todo webhook é recusado; sem a chave pública da tela o cartão fica indisponível e o boleto continua.

## Roteiro de validação no sandbox (usuário, com credenciais de teste)

1. Configurar as quatro variáveis e cadastrar a URL `https://<api>/api/webhooks/mercadopago` no painel do Mercado Pago (eventos de pagamentos).
2. **Boleto:** assinar com boleto e conferir o link e a linha digitável. Pagar no sandbox e conferir o webhook, a cobrança `PAGA` e o tenant `ATIVA`. Conferir os campos `transaction_details.external_resource_url` e `digitable_line` da resposta (o adaptador os lê desses nomes).
3. **Cartão:** assinar com cartão de teste e conferir o `preapproval`. Ver se o primeiro pagamento chega ao webhook com o `external_reference` da nossa cobrança ou só com o `preapproval_id` (a conciliação tenta nessa ordem: id do pagamento, referência externa, `metadata.preapproval_id`).
4. Conferir se o `preapproval` aceita boleto. Se aceitar, avaliar abandonar o agendamento próprio de boletos.
5. Conferir o manifesto do HMAC (`id:<data.id>;request-id:<x-request-id>;ts:<ts>;`), a unidade do `ts` (segundos ou milissegundos) e a tolerância de 10 minutos.
6. Deixar uma cobrança vencer e rodar `php artisan billing:aplicar-inadimplencia`; pagar depois e conferir que reativa.
7. Cancelar a assinatura e conferir o cancelamento do `preapproval` no painel.
8. Reenviar o mesmo webhook pelo painel e conferir que nada muda (idempotência).

## Fora da F5a

Troca de plano e excedente de documentos (F5b), estorno, NFS-e da própria plataforma, e-mails de aviso de cobrança.
