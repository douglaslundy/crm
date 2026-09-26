# NFePHP: NF-e modelo 55 (MotorNfe)

Fontes lidas em 2026-09-25, todas em `mecanicapro/backend/app/Services/Fiscal/NfePhp/`:
- `MotorNfe.php` (1519 linhas)
- `Concerns/ProcessaRespostaSefaz.php`
- `CertificadoStore.php`

Biblioteca: `nfephp-org/sped-nfe ^5.2`, verificada na versão v5.2.8. Schema `PL_009_V4`, versão `4.00`.

## Configuração do `Tools`

```php
$tools = new Tools(json_encode([
  'atualizacao' => now()->format('Y-m-d H:i:s'),
  'tpAmb'       => $ambiente === 'PRODUCAO' ? 1 : 2,
  'razaosocial' => $cfg->razao_social,
  'siglaUF'     => $cfg->uf,
  'cnpj'        => somenteDigitos($cfg->cnpj),
  'schemes'     => 'PL_009_V4',
  'versao'      => '4.00',
]), Certificate::readPfx($pfx, $senha));
$tools->model(55);              // 65 para NFC-e
```

- Os campos obrigatórios, conforme `storage/config.schema` da lib, são `tpAmb`, `razaosocial`, `cnpj`, `siglaUF`, `schemes` e `versao`.
- **Limitação do Mecânica Pro:** o `cUF` está fixo em 31 (MG), e o motor recusa emitir quando a UF não é MG. **No SaaS, o `cUF` precisa ser derivado da UF do emitente** por uma tabela IBGE. Também é preciso manter a guarda "UF da chave igual à UF do autor".

## Montagem com `Make` (sem I/O, testável)

**Guardas antes de montar:**
- **CNPJ do emitente vazio:** lançar exceção. Sem o CNPJ, a `Make` gera a chave com `00000000000000` **em silêncio**; só o PHPUnit transforma o warning em erro.
- **DIFAL:** se `idDest=2`, `indFinal=1`, `indIEDest=9` e `CRT≠1`, **bloquear** (o `ICMSUFDest` não está implementado e a nota seria rejeitada com cStat 694). O Simples Nacional é dispensado pela ADI 5464.

**Tags usadas, em ordem:**

| Tag | Campos |
|---|---|
| `taginfNFe` | `versao='4.00'`, `Id=null` (a Make calcula a chave), `pk_nItem=''` |
| `tagide` | `cUF`, `natOp`, `mod=55`, `serie`, `nNF`, `dhEmi=now()->format('c')`, `tpNF=1` (saída), `idDest` (1 interna, 2 interestadual, 3 exterior), `cMunFG=IBGE`, `tpImp=1`, `tpEmis=1` (4 no EPEC), `tpAmb`, `finNFe=1`, `indFinal=1`, `indPres=1`, `procEmi=0`, `verProc` |
| `tagemit` | `CNPJ`, `xNome`, `xFant`, `IE` (só dígitos), `CRT` |
| `tagenderEmit` | `xLgr`, `nro` ('S/N'), `xBairro`, `cMun`, `xMun`, `UF`, `CEP`, `cPais=1058`, `xPais=Brasil` |
| `tagdest` | `CNPJ` ou `CPF` (mais de 11 dígitos = CNPJ), `xNome`, `indIEDest`, `IE` |
| `tagenderDest` | endereço do destinatário, incluindo `cMun` IBGE |
| `tagprod` (por item) | `item`, `cProd` (SKU), `cEAN` e `cEANTrib` (GTIN ou **'SEM GTIN'**), `xProd`, `NCM`, `CEST`, `CFOP`, `uCom`/`uTrib`, `qCom`/`qTrib`, `vUnCom`/`vUnTrib`, `vProd=round(q*v,2)`, `indTot=1` |
| `tagICMSSN` (CRT=1) | `item`, `orig`, `CSOSN`. **É outro método**: `tagICMS` descarta `CSOSN` em silêncio |
| `tagICMS` (CRT=3) | `item`, `orig`, `CST`. Sem ST: `modBC=3`, `vBC`, `pICMS=0`, `vICMS=0` |
| `tagPIS` / `tagCOFINS` | **Obrigatórios por item**: `CST='49'`, `vBC=0`, `pPIS/pCOFINS=0`, `vPIS/vCOFINS=0`. O XSD exige o choice `vBC+pX`, e omiti-lo gera erro de schema |
| `tagICMSTot` | todos os totais zerados, exceto `vProd` e `vNF` (soma dos itens) |
| `tagtransp` | `modFrete=9` (sem frete) |
| `tagpag` + `tagdetPag` | `indPag=0`, `tPag`, `xPag` (**obrigatório quando tPag=99**, cStat 441), `vPag` |
| `taginfAdic` | `infCpl` (limite de 5000), só quando houver texto |

- `$make->getXML()` sempre devolve string e nunca `false`. Os erros ficam em `$make->getErrors()`, que **precisa ser checado** (e lançar exceção quando não estiver vazio).
- **Mapeamento de `tPag`** a partir do texto: Dinheiro=01, Crédito=03, Débito=04, PIX=17, e o resto 99 (acompanhado de `xPag`).
- **IBS/CBS (reforma tributária):** não implementado, porque exige schema acima de 9 (PL_010+). O Simples fica dispensado até 04/01/2027 (NT 2025.002-RTC). **No SaaS isso é pendência para clientes em Regime Normal.**
- **PIS/COFINS do Regime Normal:** usa CST 49 zerado de forma provisória. A alíquota real não foi implementada.

## Emitir

1. **Número:** usa `numeroReservado` (retentativa depois de rejeição, sem queimar número novo) ou então `proximoNumeroNfe()` (contador com `lockForUpdate`). A série vem de `serie_nfe`.
2. **Assinatura:** `signNFe($xml)`. O `sefazEnviaLote` **não assina** e valida contra o XSD; sem a assinatura, a validação lança exceção.
3. **Envio:** `sefazEnviaLote([$xmlAssinado], (string)$numero, 1)`, com `indSinc=1`, o que torna a resposta síncrona.
4. **Resposta** (`processarRespostaAutorizacao`):
   - Com `protNFe` e `cStat=100`: **AUTORIZADA**. `Complements::toAuthorize(xmlEnviado, resposta)` monta o **nfeProc**, o XML oficial com o protocolo, que é o que deve ser guardado. Também traz `chNFe` e `nProt`.
   - Com `protNFe` e outro cStat: **REJEITADA**, com a mensagem `"cStat=X: xMotivo"`, devolvendo o número para ser reaproveitado.
   - Sem `protNFe`: REJEITADA ("Lote rejeitado").
5. **cStat=539 (número já usado):** extrai a `chNFe: <44>` da mensagem e consulta essa chave. **Só avança para o próximo número se a nota que ocupa o número estiver CANCELADA**, com no máximo 5 tentativas. Se estiver autorizada, devolve para reconciliação manual, porque avançar criaria uma duplicata real.
6. **Erro com número já alocado:** a resposta de erro devolve o número alocado para que ele seja persistido e reaproveitado.

## Contingência EPEC

- Entra **só em `SoapException`**, que é falha de comunicação: timeout, conexão recusada, HTTP diferente de 200 ou corpo vazio. **Nunca** entra por erro de schema, configuração ou validação.
- **Bug do vendor v5.2.8:** o `Tools::sefazEPEC()` não pode ser alcançado (`checkContingencyForWebServices` recusa 'EPEC'). O Mecânica Pro reproduz o corpo dele com métodos públicos:
  - Monta um `Contingency` com `type='EPEC'`, `tpEmis=4`, `timestamp` e `motive`.
  - Chama `signNFe` com o **timezone temporariamente em UTC** (bug do `dhCont` 3h adiantado, que causa cStat 558). Restaura o fuso no `finally`.
  - Extrai `chNFe` de `substr(Id,3,44)` e confere se a UF da chave bate com `cUF` do Tools.
  - Monta o `tagAdic`: `cOrgaoAutor`, `tpAutor=1`, `verAplic`, `dhEmi`, `tpNF`, `IE`, e `dest{UF, CNPJ|CPF|idEstrangeiro, IE, vNF, vICMS, vST}`.
  - Zera `contingency->type` e chama `sefazEvento('AN', $chNFe, Tools::EVT_EPEC, 1, $tagAdic)`.
  - cStat 135 ou 136 resulta em **CONTINGENCIA** (a chave, o número e o XML são guardados). Qualquer outro vira ERRO.
- **Prazo:** transmitir o XML normal em até **7 dias**. Há alerta quando faltam 2 dias ou menos (`PrazoContingencia`) e reconciliação horária (`ReconciliarContingenciaNfe`).
- **`retransmitir`:** consulta a chave antes de tudo. Se estiver AUTORIZADA ou CANCELADA, só concilia. Senão, reenvia o **mesmo XML salvo** (com `tpEmis=4`) e nunca o remonta, porque isso mudaria a chave impressa no DANFE.

## Consultar, cancelar e inutilizar

- **`sefazConsultaChave($chave)`:** cStat 100 é AUTORIZADA (traz `nProt`), 101 e 151 são CANCELADA, e qualquer outro é ERRO.
- **`sefazCancela($chave, $xJust, $nProt)`:** **exige o protocolo** da autorização. Em respostas de evento (`retEnvEvento`), ler o `cStat` **dentro de `retEvento`**; o `cStat` de topo é o do lote. 135, 136 e 155 são cancelamento aceito.
  - Justificativa: de 15 a 255 caracteres.
  - Prazo de cancelamento (valores da referência da Focus): NF-e em até 24h, NFC-e em até 30 min.
- **`sefazInutiliza($serie, $nIni, $nFin, $xJust)`:** 102 é homologada. É uma ação administrativa para faixas perdidas (queda entre alocar o número e transmitir).

## Notas recebidas (entrada), via DF-e

- **Consulta por chave:** `sefazDistDFe(0, 0, $chave)`. O schema `procNFe*` é o XML completo; `resNFe` é só o resumo, sem itens. Quando vem o resumo, manifesta **ciência da operação** (`sefazManifesta($chave, Tools::EVT_CIENCIA)`) e o resultado fica AGUARDANDO_MANIFESTACAO. **Nunca** inventar itens a partir do resumo.
- **Listagem:** varredura por NSU com `sefazDistDFe(ultNSU)` e checkpoint de NSU persistido. Consulta agressiva gera **cStat 656** (bloqueio). O certo é uma sincronização agendada.
- **Ambiente:** consulta de nota de terceiro vai **sempre para produção**, porque homologação não tem dados reais.

## Certificado (`CertificadoStore`)

- O `.pfx` e a senha ficam criptografados no banco: `certificado_pfx_encrypted` e `certificado_senha_encrypted` (`Crypt::encryptString`).
- A decifragem acontece em memória. O `sped-nfe` aceita bytes (`Certificate::readPfx`).
- O `nfse-php` exige um **caminho de arquivo**. Por isso: `tempnam` + `chmod 0600` + callback + `unlink` dentro do `finally`.
- **Validação no upload** (`CertificadoValidator`): `openssl_pkcs12_read` (senha correta), `openssl_x509_parse` (validade e CN), e recusa o certificado se estiver expirado.
