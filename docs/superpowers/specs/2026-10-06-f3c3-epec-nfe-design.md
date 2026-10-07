# F3c3: EPEC da NF-e (design)

Data: 2026-10-06. Fase do roteiro: F3 (NF-e e NFC-e), oitava fatia. Base: F3a a F3c2 concluídas; `docs/referencia-fiscal/03-nfephp-nfe.md` (seção "Contingência EPEC"); skill `br-fiscal-note-emission`.

## 1. Contexto e escopo

A NF-e com falha real de comunicação com a SEFAZ da UF hoje vai para ERRO. A F3c3 faz a NF-e entrar em **EPEC** (Evento Prévio de Emissão em Contingência, `tpEmis=4`), reaproveitando o status `CONTINGENCIA`, a retransmissão, o prazo e o painel da F3c2. Entrada **só automática**, **só em falha de comunicação**, nunca por rejeição, schema ou configuração: o EPEC registra um ato legal real.

**Decisão do usuário (2026-10-06):** só EPEC. **Fora da F3c3:** SVC-AN/SVC-RS (backlog), cancelamento de nota em contingência, entrada manual em EPEC, prazo por empresa, notificações (F6).

## 2. Fatos técnicos que moldam o design

- `sped-nfe` 5.2.8: `Tools::sefazEPEC()` é inalcançável (`checkContingencyForWebServices` recusa 'EPEC'). Reproduz-se o corpo com métodos públicos: `Contingency` com `type='EPEC'`, `tpEmis=4`, `timestamp` e `motive`; `signNFe` com timezone temporariamente em UTC (senão `dhCont` adiantado e cStat 558; restaurar no `finally`); `chNFe` = `substr(Id,3,44)`, conferindo que a UF da chave bate com a do `Tools`; `tagAdic` com `cOrgaoAutor`, `tpAutor=1`, `verAplic`, `dhEmi`, `tpNF`, `IE` e `dest{UF, CNPJ|CPF|idEstrangeiro, IE, vNF, vICMS, vST}`; zera `contingency->type` e chama `sefazEvento('AN', $chNFe, Tools::EVT_EPEC, 1, $tagAdic)`. cStat 135 ou 136 = EPEC registrado.
- A chave muda ao remontar (o `tpEmis` faz parte dela). A NF-e transmitida depois precisa repetir os dados do evento, senão é rejeitada.
- `sped-da` `Danfe::epec($protocolo, $data)` imprime "NÚMERO DE REGISTRO EPEC" e a mensagem "EPEC regularmente recebido pela Receita" quando `tpEmis=4`.

## 3. Dados (uma migration)

- `notas.contingencia_tipo` (string 12, nulável: `OFFLINE_NFCE` | `EPEC`). A migration preenche `OFFLINE_NFCE` nas notas já em `CONTINGENCIA`. Enum `TipoDeContingencia`.
- `notas.epec_protocolo` (string, nulável) e `notas.epec_registrado_em` (timestamp, nulável); `notas.xml_epec` (text, nulável, oculto como `xml_enviado`): o `retEvento` do EPEC, guardado como prova.
- `config/fiscal.php`: `epec_prazo_horas` (env `FISCAL_EPEC_PRAZO_HORAS`, padrão 168) e `epec_alerta_horas` (env `FISCAL_EPEC_ALERTA_HORAS`, padrão 48). `contingencia_limite_em` continua sendo gravado na entrada, com o prazo do tipo. O alerta do `ResumoFiscal` passa a usar o alerta do tipo de cada nota.
- Sem status novo: fluxo `PROCESSANDO → CONTINGENCIA(EPEC) → AUTORIZADA | REJEITADA`. Não conta em `DOCUMENTOS_MES` até autorizar, não é editável nem cancelável.

## 4. Motor (`EmissorDeNfe`)

- `prepararEpec(Emitente, NotaParaEmissao, justificativa, instante): XmlAssinado` — sem rede; remonta com `tpEmis=4`, `dhCont` e `xJust`, assina com o fuso em UTC e devolve chave e XML.
- `registrarEpec(Emitente, NotaParaEmissao, XmlAssinado, ambiente): ResultadoDeEpec` (DTO: `registrado`, `protocolo`, `cstat`, `motivo`, `xmlEvento`, `mensagemErro`). Falha de comunicação lança `FalhaDeComunicacaoException`. Os dados do `tagAdic` saem do XML assinado, nunca de defaults: dado ausente bloqueia com mensagem acionável (`=== null`, nunca `?? 0`).
- Implementação no `NfePhpEmissor`; `EmissorFake` ganha o equivalente. O `MontadorDeXml` já remonta com `tpEmis` 9; ganha o parâmetro para 4 (sem `xJust` fora do tamanho 15 a 255).

## 5. Entrada em EPEC (`EntrarEmEpec`)

Chamada pelo `EmitirNotaJob` quando a **nota é NF-e** (modelo 55) e a transmissão lança `FalhaDeComunicacaoException`:

1. Só opera em `PROCESSANDO` e modelo 55.
2. **Consulta a chave original.** Autorizada ou cancelada ⇒ aplica a consulta e termina (nunca duplicar a nota). Consulta que também falha, não encontrada ou erro ⇒ segue.
3. `prepararEpec` (sem rede). A consulta e o envio ficam **fora** da transação.
4. `registrarEpec`. Registrado (135/136) ⇒ grava com lock: `status=CONTINGENCIA`, `contingencia_tipo=EPEC`, nova `chave`, `xml_enviado`, `epec_protocolo`, `epec_registrado_em`, `xml_epec`, `contingencia_desde`, `contingencia_limite_em=agora+epec_prazo_horas`, motivo, e o evento "Entrou em EPEC (chave original …)".
5. EPEC recusado (outro cStat) ⇒ `ERRO` com cStat e motivo. Falha de comunicação **também no Ambiente Nacional** ⇒ `ERRO` com mensagem "SEFAZ e Ambiente Nacional indisponíveis; tente de novo" (sem XML EPEC gravado, a chave original segue reaproveitável pelo retry de ERRO existente).
6. Exceção que não é de comunicação continua virando ERRO como hoje.

## 6. Retransmissão

`RetransmitirNota` não muda de lógica: consulta a chave, reenvia o **mesmo `xml_enviado`** (`tpEmis=4`), sem remontar (mudaria a chave impressa no DANFE). Autorizada ⇒ `AUTORIZADA`; rejeitada ⇒ `REJEITADA` com evento "Rejeitada após EPEC (cStat …)"; falha de comunicação ⇒ permanece em contingência. O comando horário e o botão valem para os dois tipos. O texto do evento de rejeição passa a depender do tipo.

## 7. DANFE

`GerarDanfe` aceita NF-e `CONTINGENCIA` do tipo EPEC (usa `xml_enviado`); o `SpedDaGeradorDeDanfe` chama `Danfe::epec(epec_protocolo, epec_registrado_em)` nesse caso. Nota em `CONTINGENCIA` do tipo EPEC sem protocolo ⇒ 409 `NOTA_SEM_PDF`. Autorizada ⇒ DANFE normal.

## 8. Painel, API e frontend

- `NotaResource` expõe `contingencia_tipo`, `epec_protocolo` e `epec_registrado_em`. `ResumoFiscal.contingencias` continua agregando os dois tipos, cada nota com o limite e o alerta do seu tipo.
- Frontend: o bloco de contingência do detalhe mostra "EPEC" ou "Contingência offline", o protocolo do EPEC, o prazo restante, "Retransmitir agora" e o link do DANFE (para NF-e) ou do cupom (NFC-e). O texto do painel passa a citar EPEC quando houver.

## 9. Testes

`EmissorFake` e XML real: XML `tpEmis=4` (chave com `tpEmis` 4, `dhCont`, `xJust`, valida no XSD); montagem do `tagAdic` e bloqueio por dado ausente; entrada (falha de comunicação ⇒ CONTINGENCIA EPEC com limite de 168 h; consulta prévia autorizada não entra; EPEC recusado ⇒ ERRO; Ambiente Nacional também fora ⇒ ERRO; exceção que não é de comunicação ⇒ ERRO; NFC-e segue no fluxo offline); retransmissão (autorizada, rejeitada, falha mantém, texto do evento); DANFE em EPEC (texto "REGISTRO EPEC" e protocolo; 409 sem protocolo); `ResumoFiscal` com alerta de 48 h por tipo; isolamento por tenant nos endpoints alterados; frontend (rótulo, protocolo, prazo, link do DANFE). `NfePhpEmissor` contra a SEFAZ real **não** é testado: fica no roteiro de homologação.

## 10. Critério de pronto

Backend e frontend verdes (Pint, PHPStan, `tsc`, lint, build). Em homologação real (usuário, com A1): bloquear a rede para a SEFAZ da UF mantendo o Ambiente Nacional acessível, emitir uma NF-e e conferir a entrada em EPEC, o DANFE com o registro EPEC e o protocolo; restaurar a rede e conferir a autorização (os dados repetidos devem bater com o evento).

## 11. Pendências conhecidas (registrar em `TAREFAS.md`)

SVC-AN/SVC-RS; cancelamento e inutilização em contingência; fuso por UF no `dhEmi`/`dhCont`; rejeição após EPEC (regra 475 e correlatas: conferir códigos no `cstat-table.md` na homologação); a conversão de falha real de rede em `FalhaDeComunicacaoException` e o EPEC contra a SEFAZ nunca foram exercitados.
