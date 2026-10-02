# F3b1: cancelamento e inutilização da NF-e (design)

Data: 2026-10-02. Fase do roteiro: F3 (NF-e e NFC-e), terceira fatia. Base: F3a concluída (`docs/superpowers/specs/2026-10-01-f3a-nfe-emissao-design.md`) e spec mestre §7.

## 1. Contexto e decomposição

A F3b foi dividida em três specs, cada uma com plano próprio, nesta ordem:

| Fase | Conteúdo |
|---|---|
| **F3b1 (este)** | Cancelamento e inutilização da NF-e (modelo 55). |
| F3b2 | PDF da NF-e (DANFE A4) a partir do XML autorizado. |
| F3b3 | NFC-e completa: emissão (CSC e QR Code), cupom, cancelamento (30 min). |

A F3c (contingência, retransmissão, dashboard fiscal) segue depois.

**Fora da F3b1:** cancelamento por substituição, cancelamento extemporâneo, carta de correção, NFC-e, PDF, contingência, e-mail ao destinatário, API pública (F6; as Actions já nascem reutilizáveis), Regime Normal.

## 2. Entendimento e decisões

Objetivo: o usuário fiscal do tenant cancela uma NF-e autorizada e inutiliza faixas de numeração perdidas, sem nunca ficar em dúvida sobre o estado real na SEFAZ.

Decisões do usuário (2026-10-02):
1. **Cancelamento por fila com estado `CANCELANDO`**, no mesmo padrão da emissão (job, resultado aplicado numa única porta, reconciliação).
2. **O prazo de cancelamento é decisão da SEFAZ.** O sistema não bloqueia por prazo nem guarda número fixo; recusa vira mensagem com `cStat` e motivo.
3. **Nota cancelada continua contando na cota mensal** `DOCUMENTOS_MES` (a SEFAZ autorizou o documento; cancelar não devolve cota).

Suposições minhas, a confirmar na revisão:
- Quem cancela e inutiliza: PROPRIETARIO, ADMIN e FISCAL (os mesmos que emitem).
- A inutilização é **síncrona** (ação administrativa rara, uma chamada só).
- Cancelar em homologação é permitido e segue o mesmo fluxo.

## 3. Arquitetura

### 3.1 Estados

`StatusNota` ganha `Cancelando`. Transições novas:

```
AUTORIZADA --(cancelar)--> CANCELANDO --(cStat 135/136/155)--> CANCELADA
                              |--(recusa da SEFAZ)--> AUTORIZADA (com cancelamento_cstat/motivo)
                              |--(falha técnica)--> CANCELANDO (a reconciliação resolve)
```

- `CANCELANDO` não é editável (`editavel()` continua falso) e não permite emitir de novo.
- A reconciliação da F3a continua levando `PROCESSANDO` ou `ERRO` a `CANCELADA` quando a SEFAZ diz que a chave foi cancelada fora do sistema.
- `ContadorDeDocumentosMes` passa a contar `AUTORIZADA`, `CANCELANDO` e `CANCELADA` emitidas em produção no mês (por `emitido_em`).

### 3.2 Modelo de dados (migration nova)

Colunas em `notas`: `cancelamento_solicitado_em` (timestamp, nulo), `cancelamento_justificativa` (text), `cancelamento_protocolo` (string 20), `cancelado_em` (timestamp), `cancelamento_cstat` (string 4), `cancelamento_motivo` (text), `xml_cancelamento` (longText, oculto no JSON, como os outros XMLs). O `cstat`/`motivo` existentes continuam sendo os da autorização.

Tabela `inutilizacoes` (Model de tenant com `BelongsToTenant` e teste de isolamento): `id` uuid, `tenant_id`, `emitente_id`, `serie` (string 3), `numero_inicial`, `numero_final`, `justificativa`, `ambiente`, `status` (`INUTILIZADA | RECUSADA | ERRO`), `cstat`, `motivo`, `protocolo`, `xml_resposta` (oculto), `usuario_id`, timestamps. Só modelo 55 nesta fase.

### 3.3 Valor `Justificativa`

Value object único para cancelamento e inutilização: 15 a 255 caracteres após sanear (controle, espaços repetidos, emojis, como o texto livre do XML). Inválida lança erro de negócio 422 `JUSTIFICATIVA_INVALIDA` com a regra na mensagem. O limite é espelhado no FormRequest e na tela.

### 3.4 Contrato do emissor

`EmissorDeNfe` ganha:
- `cancelar(Emitente $e, string $chave, string $protocolo, string $justificativa, string $ambiente): ResultadoDeCancelamento` (aceito, recusado com `cStat`/motivo, ou lança em falha de comunicação).
- `inutilizar(Emitente $e, int $serie, int $ini, int $fin, string $justificativa, string $ambiente): ResultadoDeInutilizacao`.
- `consultarPorChave` passa a receber o `ambiente` **da nota**. Hoje usa o ambiente atual do emitente, o que consultaria (ou cancelaria) no ambiente errado uma nota de homologação depois de "Ir para produção". A F3b1 corrige isso nos chamadores (reconciliação, job de emissão, tratamento do 539).

`InterpretadorDeResposta` ganha `cancelamento(string $respostaXml, string $xmlAutorizado)` e `inutilizacao(string $respostaXml)`: puros, sem rede. No evento, o `cStat` vem de dentro de `retEvento` (o de topo é do lote). Aceitos: 135, 136, 155; o XML do cancelamento é o `nfeProc` autorizado com o `retEvento` anexado, montado por `Complements::cancelRegister` (conferido no vendor 5.2.8); se não puder ser montado, guarda-se a resposta crua do evento. O `cancelar` do emissor recebe também o `xmlAutorizado` para isso. Inutilização homologada: cStat 102; 563 (duplicidade) vira "já inutilizada".

### 3.5 Cancelamento (Actions e job)

- **`IniciarCancelamento::executar(string $notaId, string $justificativa, Usuario $autor): Nota`:** trava a nota com `lockForUpdate`; exige papel, módulo `FISCAL_NFE`, assinatura com escrita; `Justificativa` válida. Já `CANCELANDO` ou `CANCELADA`: devolve sem mudar e **sem** despachar (duplo clique). Só `AUTORIZADA` com `chave`, `protocolo` e `xml_autorizado`; outro status lança `CancelamentoNaoPermitidoException` (409, `CANCELAMENTO_NAO_PERMITIDO`). Grava `cancelamento_solicitado_em` e a justificativa, marca `CANCELANDO` com evento e, **depois** da transação, despacha `CancelarNotaJob($notaId, $tenantId)`.
- **`CancelarNotaJob`:** restaura o `TenantContext` anterior (como o `EmitirNotaJob`), ignora nota fora de `CANCELANDO`, chama `EmissorDeNfe::cancelar` com o ambiente **da nota** e entrega o resultado. Exceção vira "falha técnica" e a nota **permanece** `CANCELANDO`.
- **`AplicarResultadoDoCancelamento`:** única porta, com lock, só age em `CANCELANDO`. Aceito: `CANCELADA`, `cancelado_em`, protocolo, `xml_cancelamento`, evento. Recusa: volta a `AUTORIZADA` e grava `cancelamento_cstat`/`cancelamento_motivo`, evento com o motivo. Também recebe a consulta da reconciliação.
- **Reconciliação:** `ConciliarNota` passa a tratar `CANCELANDO`: consulta a chave; cancelada → `CANCELADA`; autorizada e passado o prazo `config('fiscal.minutos_para_nunca_enviada')` desde `cancelamento_solicitado_em` → volta a `AUTORIZADA` com `cancelamento_motivo` "Cancelamento não confirmado pela SEFAZ; tente de novo"; não encontrada ou erro de consulta → não muda. O comando `fiscal:reconciliar-processando` passa a varrer também `CANCELANDO` (nome mantido).

### 3.6 Inutilização

- **`InutilizarNumeracao::executar(array $dados, Usuario $autor): Inutilizacao`**, síncrona. Papel, módulo, assinatura com escrita, `Justificativa`. `numero_inicial ≤ numero_final`. A série existe no emitente e a faixa está **toda abaixo** de `proximo_numero` (já alocada; inutilizar número futuro é recusado com mensagem acionável). Nenhuma nota nossa na faixa (mesma série e ambiente) com status `AUTORIZADA`, `CANCELANDO`, `CANCELADA` ou `PROCESSANDO`; o erro cita o número. Ambiente: o atual do emitente.
- Resultado gravado sempre: `INUTILIZADA` (102), `RECUSADA` (outro cStat, com cStat/motivo; 563 com texto próprio) ou `ERRO` (falha técnica, pode tentar de novo). Não altera `proximo_numero` nem notas.
- Falha de comunicação não fica em dúvida: SEFAZ responde 563 se o pedido anterior chegou, e o resultado é mostrado como "já inutilizada".

## 4. API (grupo `empresa`, mesmas Actions que a API pública usará)

- `POST /api/app/notas/{nota}/cancelar` `{ justificativa }` → 202 `NotaStatusResource`.
- `GET /api/app/notas/{nota}/xml-cancelamento` → download `NFe-<numero>-cancelamento.xml`; só `CANCELADA` com XML do evento, senão 409 `NOTA_SEM_XML_CANCELAMENTO`.
- `GET /api/app/inutilizacoes` (mais recentes primeiro, 25 por página) e `POST /api/app/inutilizacoes` `{ serie, numero_inicial, numero_final, justificativa }` → 201.
- `NotaResource` ganha `cancelamento_justificativa`, `cancelado_em`, `cancelamento_protocolo`, `cancelamento_cstat`, `cancelamento_motivo`. `NotaStatusResource` ganha `cancelamento_cstat` e `cancelamento_motivo`. Nunca expõe XML.
- Route model binding com Global Scope: nota de outro tenant dá 404 antes do 403.

## 5. Frontend

- `DetalheDaNota`: para `AUTORIZADA` e quem pode emitir, botão "Cancelar NF-e" abre um bloco com a justificativa (contador de 15 a 255), aviso de que o prazo é definido pela SEFAZ e confirmação em segundo clique (sem `window.confirm`). Em `CANCELANDO`, mensagem "Aguardando a SEFAZ" e polling do status a cada 2 s (como em `PROCESSANDO`). Recusa aparece em `role="alert"` com `cStat` e motivo, a nota segue autorizada. `CANCELADA` mostra protocolo, data, justificativa e "Baixar XML do cancelamento".
- `StatusDaNota` e `ROTULO_STATUS` ganham `CANCELANDO`.
- Tela `/notas/inutilizar` (link na página de notas, só para quem emite): formulário (série, faixa, justificativa) e histórico com status por linha.

## 6. Testes (padrão da F3a)

- `EmissorFake` ganha `cancelar`/`inutilizar` programáveis; interpretador testado com respostas XML escritas à mão (135, 136, 155, recusa 501, 102, 563, resposta ilegível).
- Cancelamento: sucesso, recusa volta a `AUTORIZADA`, duplo clique não despacha de novo, status inválido, justificativa curta/longa/só emoji, falha técnica mantém `CANCELANDO`, job ignora nota fora de `CANCELANDO`, usa o ambiente da nota (nota de homologação com emitente já em produção).
- Reconciliação de `CANCELANDO` (cancelada, autorizada após o prazo, dentro do prazo, erro de consulta) e contador de cota somando canceladas.
- Inutilização: faixa válida, faixa futura, faixa com nota autorizada, `ini > fin`, recusa, duplicidade 563, erro técnico.
- Papéis (VENDEDOR e LEITURA recusados), isolamento entre tenants em todo endpoint e Model novo, frontend (cancelar com confirmação, recusa, polling, histórico de inutilizações).
- Guardas de sempre: sem `empty()` nem `?? 0` em código fiscal, `declare(strict_types=1)`, TypeScript sem `any`.

## 7. Critério de pronto

Em testes: uma NF-e autorizada vira `CANCELADA` pelo fluxo completo (HTTP, fila, resultado) e a reconciliação resolve um `CANCELANDO` preso. Em homologação real (roteiro para o usuário, depende do certificado A1): cancelar uma NF-e de teste recém-autorizada e inutilizar uma faixa de teste.

## 8. Pendências conhecidas (registrar em `TAREFAS.md`)

Cancelamento por substituição e extemporâneo, carta de correção, inutilização de NFC-e (F3b3), PDF do cancelamento, número fixo de prazo por UF (decisão: SEFAZ decide), lista de inutilizações sem filtros.
