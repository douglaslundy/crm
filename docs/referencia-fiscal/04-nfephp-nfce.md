# NFePHP: NFC-e modelo 65 (MotorNfce)

Fonte: `mecanicapro/backend/app/Services/Fiscal/NfePhp/MotorNfce.php` (544 linhas), lido em 2026-09-25.

Reaproveita a `Make`, o `Tools` e o trait `ProcessaRespostaSefaz` da NF-e (ver `03-nfephp-nfe.md`). Abaixo, **só as diferenças**.

## Diferenças da NF-e

| Aspecto | NFC-e |
|---|---|
| `mod` | `65`, com `$tools->model(65)` |
| `tpImp` | `4` (DANFCE, o cupom) |
| `idDest` | **sempre 1**. A NFC-e nunca é interestadual |
| `indFinal` | sempre 1 (consumidor final) |
| CFOP | `CfopConsumidorResolver`: 5102 na mesma UF, 6108 fora dela. Sem distinção de ST |
| Destinatário | **opcional**. Com documento, vai `CPF` ou `CNPJ` + `xNome`, **sem endereço**. Sem documento (venda de balcão), o grupo `dest` é omitido |
| `indIEDest` | a lib força `9` em qualquer NFC-e. Não é decisão de negócio |
| Numeração | contador **separado** (`proximoNumeroNfceNfephp`) e série `serie_nfce` |
| QR Code | `signNFe()` detecta `mod=65` e chama `addQRCode()` sozinho. Basta ter **`CSC` e `CSCid` no config JSON do `Tools`**; sem eles, lança `RuntimeException` |
| Contingência | **OFFLINE (`tpEmis=9`)**, não EPEC. A lib recusa EPEC/SVC para o modelo 65 |
| Cancelamento | a referência da Focus indica **30 minutos** |

## CSC (Código de Segurança do Contribuinte)

- É um segredo **diferente por ambiente**: `csc_id_homologacao` + `csc_token_homologacao_encrypted`, e `csc_id_producao` + `csc_token_producao_encrypted`.
- **Sem CSC para o ambiente**, o resultado é ERRO explícito ("cadastre em Configurações › Fiscal"), antes de alocar número.
- O config JSON é igual ao da NF-e, com `'CSC' => token` e `'CSCid' => id`.

## Emitir

1. Guardas: UF (no Mecânica Pro, só MG), CSC do ambiente e certificado.
2. Número: `numeroReservado` ou o próximo contador de NFC-e.
3. `signNFe` (o QR Code entra junto), depois `sefazEnviaLote([...], num, 1)` e `processarRespostaAutorizacao`.
4. A URL do QR vem de `//nfe:qrCode` no **XML enviado**, em `infNFeSupl`, e vai para `EmissaoResultado::qrCodeUrl`.
5. **Em `SoapException`:** remonta com `tpEmis=9` (**a chave muda**, então é preciso remontar, não basta trocar um campo), assina e extrai `chNFe` (44 dígitos). O resultado é `CONTINGENCIA`, e o DANFCE é entregue na hora.
6. `retransmitir` reenvia o mesmo XML offline assinado quando a conexão volta, consultando a chave antes.

## Status específico

O status **`denegado`** só existe na NFC-e. É o 5º status da Focus. O mapeamento de status compartilhado precisa cobri-lo; ver pitfall #19 da skill.
