# NFePHP: NFS-e Nacional (MotorNfse)

Fonte: `mecanicapro/backend/app/Services/Fiscal/NfePhp/MotorNfse.php` (633 linhas), lido em 2026-09-25.

## Biblioteca

- No Mecânica Pro, o provider `NFEPHP` emite a NFS-e com o pacote **`nfse-nacional/nfse-php` `^1.21@beta`**. É o Sistema Nacional NFS-e (SEFIN Nacional / ADN) e fica ao lado do `nfephp-org/sped-nfe ^5.2`.
- Para o sistema, "NFePHP" é o **nome do provider**, e ele agrupa as duas bibliotecas: `sped-nfe` para NF-e/NFC-e e `nfse-php` para NFS-e.
- `new Nfse(new NfseContext(ambiente, certificatePath, certificatePassword, codigoMunicipio))`, seguido de `->contribuinte()->emitir|consultar|consultarEvento|cancelar`.
- A lib assina, envia e faz o parse da resposta. Quando há erro, **lança `NfseApiException`** em vez de devolver um objeto de erro, por isso cada chamada fica dentro de `catch(\Throwable)` e o erro vira `EmissaoResultado::erro`.
- O certificado é o A1 do emissor, decifrado para um arquivo temporário (`CertificadoStore::comoArquivoTemporario`) que existe só durante a chamada.

## Emitir: montagem da DPS (versão `1.01`)

| Campo | Valor e regra |
|---|---|
| `infDPS@Id` | `IdGenerator::generateDpsId(cnpj, codigo_ibge, serie, numero)` |
| `tpAmb` | `1` para PRODUCAO e `2` para homologação. Vem do parâmetro `$ambiente`, nunca de uma releitura da config |
| `dhEmi` | `now()->format('c')`, em ISO8601 **com fuso**, que o schema exige |
| `verAplic` | `config('app.version')` |
| `serie` | `serie_dps` da config, com `'1'` como padrão |
| `nDPS` | contador próprio `proximoNumeroDps()` com `lockForUpdate`. **Não pode ser fixo**, porque DPS duplicada é rejeitada |
| `dCompet` | data de hoje, `Y-m-d` |
| `tpEmit` | `1`, o prestador é sempre o emitente |
| `cLocEmi` / `locPrest.cLocPrestacao` | código IBGE do município do emitente |
| `prest.CNPJ` | CNPJ só com dígitos |
| `prest.IM` | **NUNCA enviar** (E0120). A IM municipal é diferente do cadastro no CNC NFS-e |
| `prest.end` | **NUNCA enviar** quando `tpEmit=1` (E0128) |
| `prest.regTrib` | `opSimpNac` (1=Não optante, 2=MEI, 3=ME/EPP), `regApTribSN=1` (só no Simples) e `regEspTrib=0` |
| `toma` | chave `CNPJ` quando o documento tem mais de 11 dígitos, senão `CPF`; mais `xNome` |
| `serv.cServ.cTribNac` | **6 dígitos** (item + subitem + desdobro): LC 116 "14.01" vira `140101`. Mandar "14.01" dá E1235 |
| `serv.cServ.cTribMun` | só se tiver exatamente 3 dígitos; senão é omitido (é opcional, e o código municipal nunca deve ser chutado) |
| `serv.cServ.xDescServ` | descrição do serviço |
| `serv.infoCompl.xInfComp` | informações complementares, só quando houver texto. Limite: **255** pelo DTO da lib |
| `valores.vServPrest.vServ` | valor dos serviços |
| `trib.tribMun.tribISSQN` | `1`, operação tributável |
| `trib.tribMun.tpRetISSQN` | `1` = **Não retido**, `2` = Retido pelo tomador. Atenção: é fácil inverter |
| `trib.tribMun.pAliq` | **omitir** quando `regApTribSN=1` e o ISS não é retido (E0625: o Simples é calculado pela própria ADN) |
| `trib.totTrib` | obrigatório. ME/EPP e MEI usam `{pTotTribSN: 0.001}`, os demais `{indTotTrib: 0}` (E0712) |

**Bug da lib:** `DpsXmlBuilder` testa `if ($valor)`. Com `0.0` o elemento some e a nota é rejeitada por "trib incompleto". Daí o `0.001`, que a lib formata como `0.00`.

**Escalas diferentes:** o CRT da NF-e usa 1=Simples e 3=Normal, enquanto o `opSimpNac` da NFS-e usa 1=Não optante, 2=MEI e 3=ME/EPP. Não reutilizar o valor bruto de um no outro; traduzir de forma explícita.

## Resultado da emissão

- **Chave de acesso:** 50 dígitos. O `infNFSe@Id` vem como `"NFS" + 50 dígitos`. **Remover o prefixo** antes de gravar e antes de consultar, porque a API responde "não encontrada" quando recebe o prefixo.
- **Formato da chave:** `cMun(7) + tpInsc(1) + inscrição(14) + nNFSe(13) + AAMM(4) + cNum(9) + DV(1)`.
- **Protocolo:** não existe; é `null`.
- **Número:** vem de `infNfse->numeroNfse`, e o XML de `nfseXml`.
- **PDF:** gerado localmente a partir do XML (DANFSe v2.0). A API oficial de DANFSe seria desativada em 01/07/2026.

## Consultar

- `contribuinte()->consultar(chave50)` devolve `null` tanto para "não encontrada" quanto para falha de API, e a lib não diferencia os dois casos. O resultado é tratado como **ERRO**, nunca como "não existe".
- **Cancelamento** é um evento separado (101101), e não um cStat. Consultar `consultarEvento(chave, 101101, 1)`, que corresponde a `GET /nfse/{chave}/eventos/101101/1`.
  - 2xx: nota cancelada.
  - 404 **com corpo JSON** da API: não há evento.
  - Qualquer outra resposta (404 em HTML, sem corpo): incerteza, que vira ERRO. A rota antiga sem a sequência devolvia 404 HTML.
- **cStat que valem como autorizada:** NfseGerada, SubstituicaoGerada, DecisaoJudicial, Avulsa e Mei (códigos 100 a 107). Qualquer outro vira ERRO, nunca AUTORIZADA por palpite.

## Cancelar

- Monta um `PedRegEventoData` v1.01 com `tpAmb`, `verAplic`, `dhEvento` (ISO8601), `chNFSe` (50 dígitos), `CNPJAutor` e `tipoEvento=101101`.
- O grupo `e101101` leva `xDesc='Cancelamento de NFS-e'` (valor fixo), `cMotivo` e `xMotivo`.
- `cMotivo` segue a tabela TSCodJustCanc: 1=Erro na emissão, 2=Serviço não prestado, 9=Outros. É classificado por palavra-chave depois de `Str::ascii` (primeiro o ascii, depois o lowercase). O padrão seguro é 9, e o texto original vai sempre inteiro em `xMotivo`.
- Se a resposta vier sem `eventoXmlGZipB64`, o cancelamento **não está confirmado** e o resultado é ERRO.
- Justificativa: **mínimo de 15 caracteres, máximo de 255**, validados na UI e na API.

## Pendências registradas no projeto

- A exigência de `cNBS` para serviço nacional não foi verificada. O XSD da lib (v1.01) marca o campo como obrigatório, mas a ADN aceitou notas sem ele em 09/2026.
- Só o caminho "sem evento" foi observado ao vivo. O formato da resposta 200 de nota cancelada não foi confirmado.
- O `cTribNac` só tem mapeamento para `14.01`. **No SaaS isso precisa ser um cadastro por serviço**, validado contra a tabela oficial (gov.br/nfse, "códigos de tributação nacional NBS").
