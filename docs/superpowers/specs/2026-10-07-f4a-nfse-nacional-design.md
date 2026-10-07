# F4a: NFS-e Nacional (design)

Data: 2026-10-07. Fase do roteiro: F4 (NFS-e Nacional), primeira fatia. Base: F3 concluída; spec mestre §F4; `docs/referencia-fiscal/02-nfephp-nfse.md`; skill `br-fiscal-note-emission` (`pitfalls.md` #21, `domain-concepts.md` sobre ISS "por dentro", `assets/pdf-layouts/danfse`).

## 1. Contexto e decomposição

A F4 foi cortada em **F4a (este documento)** e **F4b (venda mista)**, decisão do usuário em 2026-10-07. A F4b (Action que recebe produtos e serviços e gera NF-e e NFS-e independentes, com rateio de desconto) terá spec e plano próprios depois da F4a.

A NFS-e é mais um modelo da tabela `notas`, como a NFC-e foi na F3b3. Reaproveita rascunho, fila, `NotaEvento`, cancelamento, políticas, listagem, resumo fiscal, endpoints e telas. O que muda fica isolado: contrato do emissor, montagem da DPS, validações e PDF.

**Dentro da F4a:** emitir pela fila (DPS), reconciliar e consultar, cancelar por evento 101101, DANFSe v2.0 e XML, telas e módulo `FISCAL_NFSE`.

**Fora da F4a** (registrar em `TAREFAS.md`): venda mista (F4b); ISS retido; Regime Normal (bloqueia com mensagem clara); município legado (provider REST); substituição de NFS-e; `cNBS` obrigatório; e-mail ao tomador (F6/F7).

## 2. Decisões do usuário (2026-10-07)

1. Corte F4a (NFS-e) + F4b (venda mista).
2. Um rascunho de NFS-e aceita **vários itens, desde que todos tenham o mesmo `c_trib_nac` e a mesma alíquota**. A DPS sai com o valor somado e a descrição composta. Códigos ou alíquotas diferentes bloqueiam com mensagem pedindo notas separadas.
3. Design aprovado no chat.

## 3. Dados (migrations)

- `notas.modelo = DPS`, reaproveitando o caso `ModeloDocumento::Dps` (já usado nas séries); rótulo "NFS-e" na tela e nos textos. A coluna `modelo` (4 caracteres) comporta o valor.
- `notas.chave` passa de 44 para **50 dígitos**, guardada **sem o prefixo `NFS`** do `infNFSe@Id` (pitfall #21: a API responde "não encontrada" quando recebe o prefixo).
- `notas.numero_nfse` (string nulável): o número oficial devolvido na autorização (`infNfse->numeroNfse`). `notas.numero` continua sendo o `nDPS`, alocado do contador da série `DPS` com lock, reaproveitado em retry como na NF-e. A DANFSe imprime o `numero_nfse`.
- `nota_itens`: `servico_id` nulável (e `produto_id` passa a nulável), com snapshot de `c_trib_nac`, `codigo_municipal`, `aliquota_iss` e `nbs` do serviço no momento do rascunho. Um item tem produto **ou** serviço; nota `DPS` só aceita serviço, nota `NFE`/`NFCE` só aceita produto.
- Sem protocolo, sem contingência, sem inutilização e sem pagamentos para a NFS-e (a `DPS` não leva `pag`). A cota `DOCUMENTOS_MES` conta a NFS-e autorizada como as demais.

## 4. Bloqueios antes de enviar (mensagem acionável, sem default)

`VerificadorDoEmitente` e `PreparadorDaNota` passam a conhecer a NFS-e. Bloqueiam:

- emitente sem inscrição municipal, código IBGE, regime tributário ou certificado A1; regime fora do Simples/MEI (o Regime Normal fica bloqueado nesta fatia);
- serviço sem `c_trib_nac` de 6 dígitos (a mensagem diz como cadastrar) ou sem alíquota (`aliquota_iss === null`; zero é valor válido);
- itens com `c_trib_nac` ou alíquota diferentes entre si;
- tomador sem documento (CPF/CNPJ válido) e nome;
- descrição composta maior que o limite do schema (conferir no XSD da lib): bloqueia, nunca trunca em silêncio;
- desconto: **a primeira tarefa do plano confirma no XSD da lib o campo do desconto incondicional**. Se a lib o expuser, envia; se não, a NFS-e bloqueia desconto com mensagem e fica como pendência.

## 5. Motor

- Nova interface `EmissorDeNfse` (emitir, consultar, cancelar) no Domain. A `EmissorDeNfe` é específica da NF-e (chave de 44 dígitos, SEFAZ) e não serve. Um resolvedor escolhe o emissor pelo modelo da nota.
- `NfseNacionalEmissor` (Infrastructure) com `nfse-nacional/nfse-php` `^1.21@beta`. A lib lança `NfseApiException`: cada chamada é capturada e vira resultado de erro, nunca AUTORIZADA por palpite.
- Montagem da DPS v1.01 conforme `02-nfephp-nfse.md`: `infDPS@Id` por `IdGenerator::generateDpsId`; `tpAmb` do parâmetro da nota; `dhEmi` ISO8601 com fuso; `prest.IM` e `prest.end` **nunca** enviados; `prest.regTrib` com tradução explícita do regime (o `opSimpNac` da NFS-e e o CRT da NF-e têm escalas diferentes; regime desconhecido bloqueia); `toma` por CNPJ ou CPF mais `xNome`; `cTribNac` com 6 dígitos; `cTribMun` só se tiver 3 dígitos; `tribISSQN=1`, `tpRetISSQN=1`; `pAliq` omitido no Simples não retido; `totTrib` obrigatório; workaround do `0.0` da lib (`pTotTribSN 0.001`).
- O `EmitirNotaJob`, o `AplicarResultadoDaEmissao` e a reconciliação de 15 minutos escolhem o emissor pelo modelo. Resposta com `cStat` fora de 100 a 107 vira ERRO.
- Falha de comunicação com a ADN: ERRO com o número preservado (a NFS-e não tem contingência).

## 6. Consulta e cancelamento

- `consultar(chave50)` devolve `null` tanto para "não encontrada" quanto para falha de API; a F4a trata como **ERRO**, nunca como "não existe". Cancelamento é evento separado: `consultarEvento(chave, 101101, 1)`; 2xx = cancelada; 404 com corpo JSON da API = sem evento; qualquer outra resposta = ERRO.
- Cancelar: evento 101101 (`PedRegEventoData` v1.01), `xDesc` fixo "Cancelamento de NFS-e", `cMotivo` por tabela (1 erro na emissão, 2 serviço não prestado, 9 outros; padrão 9 com o texto inteiro em `xMotivo`), justificativa de 15 a 255 caracteres validada na UI e na API. Resposta sem `eventoXmlGZipB64` = cancelamento **não confirmado** (ERRO). Reaproveita `CANCELANDO`, `IniciarCancelamento` e `CancelarNotaJob`.

## 7. DANFSe

Clone verificado da skill (`assets/pdf-layouts/danfse`: `DanfseRenderer`, Blade, logo, teste), gerado do XML autorizado (`NFSe/infNFSe` + `DPS/infDPS`) e nunca gravado, pelo mesmo `GET /api/app/notas/{nota}/pdf`, com o nome `DANFSE-<numero_nfse>.pdf`. Dependências novas: `barryvdh/laravel-dompdf` e `endroid/qr-code` ^6. O `GeradorDeDanfe` ganha o ramo da NFS-e. Marca de homologação e de CANCELADA seguem o padrão da NF-e.

## 8. API e telas

Mesmos endpoints e Actions, com `modelo=DPS`. O módulo `FISCAL_NFSE` controla o acesso. Seletor de modelo com "NFS-e"; itens vêm do cadastro de serviços; link "Abrir DANFSe (PDF)"; sem a tela de inutilização para NFS-e; lista e filtro por modelo incluem a NFS-e.

## 9. Testes

Com `EmissorFake` de NFS-e: rascunho e itens (mesmo código; códigos diferentes bloqueiam), bloqueios do §4 (um teste por bloqueio), fluxo de emissão (autorizada, rejeitada, erro técnico, número reaproveitado), reconciliação, consulta (nula vira ERRO), cancelamento (aceito, sem `eventoXmlGZipB64`, justificativa curta), isolamento por tenant e papéis, módulo `FISCAL_NFSE` desligado. Offline: XML da DPS validado contra o XSD da lib; DANFSe real conferido por texto (emitente, tomador, chave de 50 dígitos, QR, marca de homologação e de cancelada). `NfseNacionalEmissor` contra a ADN real **não** é testado: fica no roteiro de homologação.

## 10. Critério de pronto

Backend e frontend verdes (Pint, PHPStan, `tsc`, lint, build). Em homologação real (usuário, com A1 e município aderido ao padrão nacional): emitir uma NFS-e, conferir autorização, chave de 50 dígitos e DANFSe; consultar; cancelar com justificativa e conferir o evento; tentar emitir com serviço sem `c_trib_nac` (deve bloquear); município não aderido (a ADN recusa: mensagem clara).

## 11. Pendências conhecidas (registrar em `TAREFAS.md`)

Venda mista (F4b); ISS retido; Regime Normal; município legado; substituição de NFS-e; `cNBS`; desconto da NFS-e (se a lib não expuser o campo); a conversão de falha real de rede em ERRO e a emissão contra a ADN nunca exercitadas; formato da resposta 200 de nota cancelada não confirmado ao vivo (pendência herdada do Mecânica Pro).
