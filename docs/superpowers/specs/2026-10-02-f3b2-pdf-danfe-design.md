# F3b2: PDF (DANFE) da NF-e (design)

Data: 2026-10-02. Fase do roteiro: F3 (NF-e e NFC-e), quarta fatia. Base: F3a e F3b1 concluídas, spec mestre §7 ("PDF sempre a partir do XML autorizado").

## 1. Contexto e decomposição

A F3b foi dividida em três specs: F3b1 (cancelamento e inutilização, concluída), **F3b2 (este: PDF da NF-e)** e F3b3 (NFC-e completa, incluindo o cupom). A F3c (contingência, retransmissão, dashboard fiscal) vem depois.

**Fora da F3b2:** DANFE da NFC-e (cupom, F3b3), e-mail ao destinatário, ZIP em lote, upload e impressão de logo do emitente, DANFE simplificado e etiqueta, armazenamento ou cache de PDF, PDF de NFS-e.

## 2. Entendimento e decisões

Objetivo: o usuário do tenant abre o DANFE em PDF de qualquer NF-e emitida, normal ou cancelada, com o que a lei exige impresso (chave, código de barras, itens, totais) e com a marca de ambiente de homologação quando for o caso.

Decisões do usuário (2026-10-02):
1. **Motor: `nfephp-org/sped-da`** (v1.1.6, LGPL/GPL/MIT), da mesma família do `sped-nfe` (compatível com `sped-common ^5`). Alternativa descartada: renderer da skill com DomPDF (layout não verificado pixel a pixel, sem código de barras na v1, ~500 linhas de template a manter).
2. O design em seções abaixo foi aprovado no chat.

Spike (fora do repositório, 2026-10-02): `sped-da` 1.1.6 instala no PHP 8.2 sem tocar no projeto e renderiza um `nfeProc` montado pelo nosso `MontadorDeXml`, normal e com o `retEvento` de cancelamento anexado. O texto extraído do PDF contém "DANFE", o emitente, o produto e "EMITIDA EM HOMOLOGAÇÃO" (tpAmb 2); o da nota cancelada contém também "CANCELADA". A biblioteca decide as marcas sozinha: `tpAmb=2` imprime homologação; `cStat` 101/151/135/155 ou um `retEvento` 110111 aceito imprime cancelada; 110/205/301/302/303 imprime denegada; `setCancelFlag(true)` força cancelada (necessário para o cStat 136, que ela não lista).

## 3. Arquitetura

### 3.1 Contrato e motor

- Dependência nova: `nfephp-org/sped-da ^1.1` (traz `tecnickcom/tc-lib-barcode` para o Code-128C da chave).
- Interface `Domain/Contracts/GeradorDeDanfe`: `gerar(string $xml, bool $cancelada): string` devolve os bytes do PDF. Existe porque a F3b3 acrescentará o cupom e as Actions não devem depender da biblioteca.
- `Infrastructure/Pdf/SpedDaGeradorDeDanfe` implementa a interface: `new \NFePHP\DA\NFe\Danfe($xml)`, `setCancelFlag($cancelada)`, `render()`. Qualquer exceção da biblioteca vira `PdfIndisponivelException` (422, `PDF_INDISPONIVEL`) com a causa em `report()`. Binding no `FiscalServiceProvider`.

### 3.2 Action `GerarDanfe`

`GerarDanfe::executar(Nota $nota): ArquivoPdf`, onde `ArquivoPdf` é `final readonly` com `nome` (`DANFE-<numero>.pdf`) e `conteudo`.

- **Fonte do PDF é sempre o XML**, nunca colunas soltas: `AUTORIZADA` e `CANCELANDO` usam `xml_autorizado`; `CANCELADA` usa `xml_cancelamento` (o `nfeProc` com `retEvento` que a F3b1 grava) com `cancelada=true`. `xml_cancelamento` que não for o `nfeProc` (resposta crua do evento, fallback da F3b1) não serve de base: nesse caso usa `xml_autorizado` com `cancelada=true`.
- Qualquer outro status, ou o XML necessário ausente, lança `NotaSemPdfException` (409, `NOTA_SEM_PDF`), com mensagem que diz o motivo.
- Não grava nada: o PDF é gerado sob demanda a cada pedido.
- Sem restrição de papel além de enxergar a nota (o Global Scope já isola o tenant); LEITURA pode abrir.

### 3.3 API (grupo `empresa`)

`GET /api/app/notas/{nota}/pdf` responde `application/pdf` com `Content-Disposition: inline; filename="DANFE-<numero>.pdf"` (abre numa aba do navegador). Nota de outro tenant dá 404. Erros de negócio seguem o formato `{message, codigo}`.

### 3.4 Frontend

No `DetalheDaNota`, para `AUTORIZADA`, `CANCELANDO` e `CANCELADA`, o link "Abrir DANFE (PDF)" (`<a target="_blank" rel="noopener">`) aponta para `urlDoPdf(id)`. Em `api.ts`, `urlDoPdf(id)` como `urlDoXml`. O link é navegação com cookie de sessão, não fetch JSON.

## 4. Testes

O gerador real roda nos testes (não usa rede). Um helper de teste descomprime os streams do PDF para texto, para asserções de conteúdo. Casos:
- PDF começa com `%PDF` e contém "DANFE", o emitente e o produto.
- `tpAmb=2` imprime "EMITIDA EM HOMOLOGAÇÃO"; `tpAmb=1` não imprime.
- Nota cancelada imprime "CANCELADA" (por `retEvento` e por `setCancelFlag`, inclusive com cStat 136).
- XML malformado vira 422 `PDF_INDISPONIVEL`.
- Status sem PDF (rascunho, processando, rejeitada, erro) e XML ausente dão 409 `NOTA_SEM_PDF`.
- API: 200 com `Content-Type` e `Content-Disposition` corretos, 404 de outro tenant, LEITURA pode abrir, 401 sem login.
- Frontend: o link aparece nos três status e não nos demais, abre em nova aba, com o `href` certo.

## 5. Critério de pronto

Em testes: a partir de uma nota autorizada e de uma cancelada, o endpoint devolve um PDF válido com as marcas certas. Em homologação real (depende do certificado A1, roteiro para o usuário): abrir o DANFE de uma NF-e de teste autorizada e conferir os dados contra o XML.

## 6. Pendências conhecidas (registrar em `TAREFAS.md`)

Logo do emitente no DANFE, DANFE da NFC-e (F3b3), e-mail com PDF e XML, ZIP em lote, cache de PDF se o volume pedir, DANFE simplificado, conferência visual do PDF contra o DANFE oficial (o spike só confirmou o texto, não o desenho).
