# F3c2: contingência offline da NFC-e, retransmissão e prazo (design)

Data: 2026-10-05. Fase do roteiro: F3 (NF-e e NFC-e), sétima fatia. Base: F3a a F3c1 concluídas; spec mestre §7; `docs/referencia-fiscal/04-nfephp-nfce.md`; skill `br-fiscal-note-emission` (`pitfalls.md`, `reliability-patterns.md`).

## 1. Contexto e decomposição

A F3c foi dividida em F3c1 (dashboard fiscal, concluída), **F3c2 (este documento)** e F3c3 (EPEC e SVC da NF-e). Contingência entra **só automaticamente**, em falha real de comunicação com a SEFAZ (timeout, conexão recusada, HTTP diferente de 200, corpo vazio ou não XML), nunca por rejeição, schema ou configuração: ela registra um ato legal real.

**Fora da F3c2:** EPEC/SVC da NF-e (F3c3), prazo por empresa, fuso por UF no `dhEmi`/`dhCont` (hoje fixo em `America/Sao_Paulo`, limitação já existente), notificação por e-mail ou webhook (F6), cancelamento de nota em contingência.

## 2. Decisões do usuário (2026-10-05)

1. Prazo de transmissão **configurável, padrão 24 horas** (o valor legal da NFC-e offline varia por UF e não foi confirmado; 7 dias é o prazo do EPEC da NF-e). Alerta configurável, padrão 6 horas.
2. Retransmissão por **agendador de hora em hora + botão** "Retransmitir agora".
3. Design aprovado no chat.

## 3. Fato técnico que molda o design

A biblioteca `sped-nfe` 5.2.8 **recusa o modelo 65 em contingência**: `ContingencyNFe::adjust` lança "incorreto para contingência SVCAN ou SVCRS" quando `mod=65`. A NFC-e offline (`tpEmis=9`) é, portanto, **remontada pelo `MontadorDeXml`** com `tpEmis=9`, `dhCont` e `xJust` (a chave muda, pois o `tpEmis` faz parte dela) e assinada normalmente; a biblioteca gera o QR Code offline sozinha ao assinar.

## 4. Dados (uma migration)

- Novo status `CONTINGENCIA` (`StatusNota::Contingencia`, 12 caracteres, cabe na coluna `status`). Fluxo: `PROCESSANDO → CONTINGENCIA → AUTORIZADA | REJEITADA`. Não é editável, não é cancelável e **não conta** em `DOCUMENTOS_MES` (conta só ao autorizar).
- `notas`: `contingencia_desde` (timestamp), `contingencia_motivo` (text), `contingencia_limite_em` (timestamp), `ultima_retransmissao_em` (timestamp), todas nuláveis. O limite é gravado na entrada (mudar o prazo depois não altera notas que já estão em contingência).
- `chave` e `xml_enviado` passam a guardar a chave e o XML **offline** assinado; o evento da nota registra a chave original.
- `config/fiscal.php`: `contingencia_prazo_horas` (env `FISCAL_CONTINGENCIA_PRAZO_HORAS`, 24) e `contingencia_alerta_horas` (env `FISCAL_CONTINGENCIA_ALERTA_HORAS`, 6).

## 5. Entrada em contingência

- Nova `FalhaDeComunicacaoException` (Domain). O `NfePhpEmissor` a lança quando a biblioteca lança `NFePHP\Common\Exception\SoapException` em `transmitir` e `consultarPorChave`. Qualquer outra exceção continua virando ERRO como hoje.
- Action `EntrarEmContingencia`, chamada pelo `EmitirNotaJob` quando **a nota é NFC-e** e ocorre `FalhaDeComunicacaoException`:
  1. **Consulta a chave original** (se existir). Autorizada ⇒ aplica a consulta e termina (resposta perdida no caminho: nunca duplicar a nota). Cancelada ⇒ idem. Consulta que também falha por comunicação, "não encontrada" ou erro ⇒ segue.
  2. `EmissorDeNfe::prepararContingencia(Emitente, NotaParaEmissao, justificativa, instante)` remonta com `tpEmis=9`, `dhCont` no fuso da emissão (evita o erro 558) e `xJust` (15 a 255 caracteres, texto fixo: "Falha de comunicação com a SEFAZ na emissão; emitida em contingência offline."), assina e devolve chave e XML.
  3. Grava, com lock: `status=CONTINGENCIA`, nova `chave`, `xml_enviado`, `contingencia_desde=agora`, `contingencia_limite_em=agora+prazo`, `contingencia_motivo`, e o evento "Entrou em contingência (chave original …)".
- NF-e com a mesma falha continua em ERRO (EPEC é a F3c3). Contingência nunca é acionada por rejeição nem por exceção que não seja de comunicação.

## 6. Retransmissão

- Action `RetransmitirNota`: trava a nota; só opera em `CONTINGENCIA`. **Consulta a chave primeiro**: autorizada ou cancelada ⇒ concilia (`AUTORIZADA` com o `nfeProc`, ou `CANCELADA`). Senão reenvia o **mesmo `xml_enviado` assinado**, sem remontar (remontar mudaria a chave impressa no cupom).
- Resultado: autorizada ⇒ `AUTORIZADA` (`xml_autorizado`, `emitido_em`); rejeitada ⇒ `REJEITADA` com cStat e motivo e evento "Rejeitada após contingência: o consumidor já recebeu o cupom"; falha de comunicação ⇒ permanece `CONTINGENCIA`, atualiza `ultima_retransmissao_em` e registra evento.
- Gatilhos: comando `fiscal:retransmitir-contingencia` (agendado de hora em hora, `withoutOverlapping`, `onOneServer`) e `POST /api/app/notas/{nota}/retransmitir` (202; mesmos papéis que emitem; 409 `NOTA_NAO_EM_CONTINGENCIA` para outros status). Nota de outro tenant: 404.

## 7. Prazo, alerta e painel

`ResumoFiscal` ganha `contingencias`: `{ quantidade, em_alerta, vencidas, limite_mais_proximo (ISO|null) }`, do ambiente atual. "Em alerta" = faltam menos de `contingencia_alerta_horas` para o limite; "vencida" = limite no passado. O painel fiscal mostra um cartão e um `Banner` (âmbar em alerta, vermelho se há vencidas).

## 8. Cupom e cancelamento

`GerarDanfe` aceita `CONTINGENCIA`: usa `xml_enviado` (offline, sem protocolo). Para NFC-e em contingência o `SpedDaGeradorDeDanfe` liga `setOffLineDoublePrint(true)` (via do consumidor e via do estabelecimento). `IniciarCancelamento` já exige AUTORIZADA e bloqueia a contingência com mensagem clara.

## 9. Frontend

`StatusNota` ganha `CONTINGENCIA` ("Em contingência", pílula âmbar). O detalhe da nota em contingência mostra `Banner` com motivo, o limite e o tempo restante, o botão "Retransmitir agora" (`POST …/retransmitir`, invalida a nota) e o link do cupom; sem polling automático. Lista e filtro por status incluem o novo status; o painel fiscal mostra o cartão de contingências.

## 10. Testes

Com `EmissorFake` e XML real: entrada (NFC-e com falha de comunicação ⇒ CONTINGENCIA com chave nova e limite; NF-e ⇒ ERRO; exceção que não é de comunicação em NFC-e ⇒ ERRO; consulta prévia autorizada não entra em contingência; consulta que também falha entra); retransmissão (autorizada, rejeitada, falha mantém, consulta que concilia, idempotência, status errado 409, isolamento por tenant, papéis); comando agendado e registrado; XML offline (`tpEmis=9`, `dhCont`, `xJust`, chave com `tpEmis` 9, `infNFeSupl/qrCode`, XSD); cupom com duas vias; `ResumoFiscal` com alerta e vencidas; frontend (status, banner, botão, painel).

## 11. Critério de pronto

Backend e frontend verdes (Pint, PHPStan, `tsc`, lint, build). Em homologação real (usuário, com A1 e CSC): simular indisponibilidade (rede bloqueada para a SEFAZ), emitir NFC-e, conferir a entrada em contingência e o cupom de duas vias; restaurar a rede e conferir a retransmissão; confirmar o prazo legal da UF e ajustar `FISCAL_CONTINGENCIA_PRAZO_HORAS`.

## 12. Pendências conhecidas (registrar em `TAREFAS.md`)

EPEC/SVC da NF-e (F3c3); prazo por empresa; fuso por UF; notificações; rejeição após contingência pode exigir inutilização do número (decisão do usuário na homologação); mapeamento fino de outras falhas de transporte além de `SoapException`.
