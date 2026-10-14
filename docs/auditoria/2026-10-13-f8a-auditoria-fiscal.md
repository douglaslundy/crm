# F8a: auditoria fiscal pré-go-live

Data: 2026-10-13. Spec: `docs/superpowers/specs/2026-10-13-f8a-auditoria-fiscal-design.md`. Plano: `docs/superpowers/plans/2026-10-13-f8a-auditoria-fiscal.md`. Roteiro do que depende da SEFAZ/ADN: `docs/auditoria/roteiro-homologacao-real.md`.

## Grade (motor × documento)

| Motor | NF-e | NFC-e | NFS-e |
|---|---|---|---|
| NFePHP (`Modules/Fiscal/Infrastructure/NfePhp`) | sim | sim | |
| NFS-e Nacional (`Modules/Fiscal/Infrastructure/Nfse`, lib `nfse-php`) | | | sim |

## Achados

| ID | Checagem | arquivo:linha | Gravidade | Efeito para o usuário | Decisão |
|---|---|---|---|---|---|
| A-01 | 4 | `Infrastructure/NfePhp/InterpretadorDeResposta.php:42-54` e `:68-73` | Importante | cStat 150 (autorizado fora de prazo) vira REJEITADA na emissão e ERRO na consulta, embora a SEFAZ tenha autorizado: a nota some como "rejeitada", o usuário reemite e esbarra em 539. | corrigir |
| A-02 | 4 / 3 | `InterpretadorDeResposta.php:68-73`, `Application/Actions/IniciarEmissao.php:105-112` | Importante | Uso denegado (110, 301, 302, 303) na consulta cai em ERRO e a conciliação nunca resolve (terminal tratado como pendente, pitfall #16). Na emissão vira REJEITADA e a reemissão reaproveita o número, que a denegação consumiu. | corrigir |
| A-03 | 3 / 9 | `Application/Actions/AtualizarSerie.php:14-20`, `Http/Controllers/ConfiguracaoFiscalController.php:31` | Importante | `PUT /api/app/emitente/series/{modelo}` aceita `proximo_numero` menor que o já emitido, sem trava nem lock; o comentário da classe ("ainda não existe nota emitida") está vencido. O contador pode retroceder e gerar número duplicado (539 e numeração queimada). | corrigir |
| A-04 | 5 / 6 | `Infrastructure/NfePhp/MontadorDeXml.php:48,114,117` | Importante | NF-e envia sempre `indPres=1` (presencial), `finNFe=1`, `modFrete=9` e `indPag=0` sem o usuário informar: venda por telefone ou e-commerce sai declarada como presencial e parcelado sai "à vista". Suposição do autor, sem citação legal. | confirmar com o usuário |
| A-05 | 4 | `Infrastructure/NfePhp/InterpretadorDeResposta.php:29-37` | Importante | cStat 108/109 (serviço paralisado) vira REJEITADA em vez de contingência/EPEC, que só dispara em `FalhaDeComunicacaoException`. NFC-e não vende durante a paralisação quando a SEFAZ responde 108/109 em vez de cair. | confirmar com o usuário |
| A-06 | 3 | `Application/Actions/InutilizarNumeracao.php:60-66` | Minor | O conflito ignora notas REJEITADA, ERRO e rascunho que ainda guardam o número: inutilizar esse número e reemitir a nota é rejeitado para sempre, sem mensagem acionável. | Minor (TAREFAS) |
| A-07 | 8 | `tests/Unit/Fiscal/Nfse/MontadorDeDpsTest.php` | Minor | A DPS não tem teste de XSD (NF-e e NFC-e têm). Spike descartável: a DPS do montador valida contra `DPS_v1.01.xsd` (cópia do schema sem `^` e `$`, que o libxml lê como literais). | Minor (TAREFAS) |
| A-08 | 3 | `Billing/Console/GerarBoletosDoCicloCommand.php` | Minor | O comando não trava nem declara `withoutOverlapping`; a chave de idempotência no gateway (id da cobrança) impede boleto duplicado, mas duas execuções simultâneas gravam o mesmo registro. | Minor (TAREFAS) |

## Checagem 1, 2 e 10: espelhamento e paridade

- Campos fiscais conferidos em `MontadorDeXml` (NF-e e NFC-e) e `MontadorDeDps` (NFS-e): emitente, destinatário (`indIEDest`, IE, documento), item (NCM, CFOP, CSOSN 102/500, origem, GTIN `SEM GTIN`), totais, pagamento, `indPres`, `finNFe`, `tpAmb`.
- NF-e e NFC-e compartilham montador, resolvers (`CfopSaidaResolver`, `CfopConsumidorResolver`, `IndicadorIeDestinatarioResolver`, `TributacaoIcmsSaidaResolver`, `CrtResolver`) e os testes de XSD; a NFS-e traduz o regime por tabela própria (`opSimpNac`), nunca pelo CRT.
- Regime Normal bloqueia com `FISCAL_REGIME_NAO_SUPORTADO`; nenhum default de CST ou alíquota.
- `indPres`, `finNFe`, `modFrete` e `indPag` fixos: ver A-04.
- Sem segundo motor: a paridade entre motores não se aplica. Com o XML autorizado de fixture (`tests/Fixtures/XmlAutorizadoDeTeste.php`, `nfse_nacional_autorizada.xml`) a leitura da resposta concorda com os campos conferidos.

## Checagem 3: travas e idempotência

- Com `lockForUpdate` e checagem de transição: `AplicarResultadoDaEmissao`, `AplicarResultadoDaNfse`, `AplicarResultadoDoCancelamento`, `IniciarEmissao`, `IniciarCancelamento`, `RetransmitirNota`, `EntrarEmContingencia`, `EntrarEmEpec`, e no Billing `IniciarAssinatura`, `PagarCobranca`, `ConciliarPagamento`, `TrocarDePlano`, `AplicarDowngrade`, `CancelarAssinatura`.
- Idempotência de efeito externo: `EmitirNotaJob` consulta a chave antes de reenviar; `EmitirNfseJob` consulta a DPS na ADN e incerteza nunca reenvia; webhooks são entregas com identificador próprio.
- Sem trava: `AtualizarSerie` (A-03), `InutilizarNumeracao` (a resposta 563 trata a repetição; ver A-06) e `GerarBoletosDoCicloCommand` (A-08).

## Checagem 4: enums e status

- NF-e: 100 autorizada, 103/105 processando, 539 número ocupado (recuperação limitada por `tentativas_numero`), demais viram REJEITADA na emissão; a consulta trata 100, 101/151 e 217 e joga o resto em ERRO. Faltam 150 e as denegadas (A-01, A-02); 108/109 é decisão pendente (A-05).
- NFS-e: só 100, 102, 103 e 107 autorizam; qualquer outro status vira ERRO explícito, nunca "pendente". Fonte: enum `Nfse\Enums\CodigoStatus` da `nfse-php` instalada (versão no `composer.lock`). Substituição (101) segue na pendência da F4a.
- Cancelamento NF-e: 135, 136 e 155 aceitos; qualquer outro vira recusa com o cStat do evento.

## Checagem 5: comentários de regra de negócio

- Com citação ou schema: NFC-e sempre operação interna (Ajuste SINIEF 19/16), `SEM GTIN`, IM e endereço do prestador nunca enviados (E0120/E0128), `opSimpNac` por tabela.
- Suposição do autor: NF-e presencial, finalidade normal e frete 9 (A-04); contingência só por falha de comunicação (A-05).

## Checagem 6: dado fabricado

- Nenhum `?? 0`, `empty()` ou `?:` em contexto fiscal em `Modules/Fiscal`. Os únicos defaults são `SEM GTIN` (valor do schema) e os fixos de A-04.

## Checagem 7: documento impresso vem do XML autorizado

- `GerarDanfe` lê `xml_autorizado` (ou `xml_enviado` em contingência, ou o XML da cancelada) e nunca colunas paralelas; o DANFSe lê o XML autorizado. Só o nome do arquivo usa a coluna `numero`. Sem achado.

## Checagem 8: XSD

- NF-e, NFC-e, contingência e EPEC: validadas em teste contra `PL_009_V4/nfe_v4.00.xsd` (`MontadorDeXmlTest`, `XmlNfceTest`, `XmlContingenciaTest`, `XmlEpecTest`).
- DPS: sem teste (A-07). XML autorizado real da ADN/SEFAZ: roteiro.

## Checagem 9: deriva de numeração

- O contador só avança em `IniciarEmissao` (com lock) e na recuperação do 539; `AtualizarSerie` é o único ponto que o move para trás (A-03). A conferência com a autoridade e a restauração de backup (que pode pôr o contador atrás do que a SEFAZ conhece; o 539 recupera até `tentativas_numero`) ficam no roteiro e na F8c. O contador único entre homologação e produção segue para a F8b.

## Checagem 11: nada privado em repositório público

- Repositório `douglaslundy/crm` é **PUBLIC** (`gh repo view --json visibility`).
- CNPJ: só números de teste conhecidos ou sintéticos (`11.222.333/0001-81`, `11.444.777/0001-61`, `11.222.333/0001-82`, `00.000.000/0000-00`, `12.345.678/0001-95` e `52.998.224/0001-72`). As chaves de 44 dígitos embutem `11444777000161` (sintético).
- CPF: `111.444.777-35` e `529.982.247-25` (números de teste de validação), `111.111.111-11` e `000.000.000-00` (casos inválidos).
- E-mails: só domínios fictícios (`x.com`, `exemplo.com.br`, `empresa.local`, `plataforma.local`) e `taylor@laravel.com` (padrão do framework).
- Nenhum `.pfx`, `.p12`, `.pem`, `.key` ou `.cer` versionado; nenhum `.env` versionado.
- `docs/design-system/01-analise-mecanicapro.md` descreve o estilo do Mecânica Pro sem dados de cliente nem credenciais.
- **Sem achado.**
