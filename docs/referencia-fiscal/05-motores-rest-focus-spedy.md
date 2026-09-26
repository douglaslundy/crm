# Motores REST: Focus NFe e Spedy

Fontes lidas em 2026-09-25:
- `mecanicapro/.../Providers/FocusNfeProvider.php` (724 linhas)
- `SpedyProvider.php` (1042 linhas)

O caches dos schemas está na skill: `~/.claude/skills/br-fiscal-note-emission/assets/{focus-nfe,spedy}-schema-reference.md`.

## Matriz: motor × documento

| | NF-e (55) | NFC-e (65) | NFS-e | Nota de entrada (DF-e) | Contingência | Numeração | Status |
|---|---|---|---|---|---|---|---|
| **NFEPHP** | `sped-nfe` | `sped-nfe` + CSC | `nfse-nacional/nfse-php` (só no padrão nacional) | `sefazDistDFe` + manifestação | EPEC (NF-e) e offline `tpEmis=9` (NFC-e) | local (DPS, NF-e e NFC-e) | síncrono |
| **FOCUS** | `/v2/nfe` | `/v2/nfce` | `/v2/nfse` (municipal, qualquer município atendido pela Focus) | sim (`FocusNfeRecebidaMapper`) | não exposta | local, enviada | assíncrono, com polling |
| **SPEDY** | `/v1/product-invoices` | `/v1/consumer-invoices` | `/v1/service-invoices` | não | não exposta | local, **tem que ser enviada** (`series`/`number`) na NF-e e NFC-e | assíncrono |

## Focus NFe

**Autenticação.** HTTP Basic, com o token como usuário e senha vazia.
- **Token master do SaaS:** usado em `/v2/empresas`, por ambiente.
- **Token do emissor:** usado nos documentos. Se não houver, cai para o master.

**Endereços.** URL base por ambiente: `services.focusnfe.producao_url|homologacao_url`.

**Endpoints.**
- **Onboarding:**
  - `POST /v2/empresas` cria o emissor (payload de empresa).
  - `PUT /v2/empresas/{cnpj}` envia o certificado.
- **Documentos:**
  - `POST /v2/{nfe|nfce|nfse}?ref={referencia}`: o `ref` é a chave de idempotência.
  - `GET /v2/{recurso}/{ref}` consulta.
  - `DELETE /v2/{recurso}/{ref}` com justificativa cancela. O recurso é escolhido **pelo modelo**; um bug antigo cancelava sempre em `/nfse`.

**Mapeamento de status.**
- `autorizado` → AUTORIZADA.
- `cancelado` → CANCELADA.
- `erro_autorizacao` e `denegado` → REJEITADA. O `denegado` recebe o prefixo `[Denegado]` e **só existe na NFC-e**.
- `processando_autorizacao` → PROCESSANDO.
- Status desconhecido vira PROCESSANDO com log de aviso.
- Falha HTTP na consulta **mantém PROCESSANDO** e nunca inventa um status.

**NFS-e municipal (`montarPayloadNfse`):**
- `natureza_operacao` é um **código** (`"1"` a `"6"`, padrão `"1"`), **não texto**. É o pitfall #26.
- `optante_simples_nacional` é boolean e obrigatório. MEI conta como optante. Para MEI, `regime_especial_tributacao='5'`.
- `prestador`: CNPJ + `inscricao_municipal` + `codigo_municipio`.
- `tomador`: documento, razão social e endereço, com `codigo_municipio` IBGE do tomador.
- `servico`:
  - `item_lista_servico` = LC 116 ("14.01").
  - `codigo_tributario_municipio`.
  - `codigo_municipio` = IBGE do **prestador** (onde o ISS é devido).
  - `aliquota` e `iss_retido`.
  - `discriminacao`: é onde entram as informações complementares, porque **não há campo próprio**.

**NF-e e NFC-e.**
- As informações complementares vão em `informacoes_adicionais_contribuinte`, que vira `infCpl`.
- Prazos de cancelamento: 24h para NF-e e 30 min para NFC-e.

## Spedy

**Autenticação.** API key.
- **Master do SaaS:** `spedy_master_key_{producao,sandbox}`.
- **Chave do emissor:** vem do registro do emissor.

**Endereços.** URL base: `services.spedy.producao_url|sandbox_url`.

**Endpoints.**
- **Onboarding:** cria a empresa e configura `PUT /v1/companies/{id}/settings`. O bloco `consumerInvoice` guarda o **CSC da NFC-e**.
- **Documentos:** `POST /{product-invoices|consumer-invoices|service-invoices}`.
- **Consulta:** **não existe "GET pela minha referência"**. O caminho é `GET /{recurso}?integrationId=X` e ler o resultado da lista.
  - O `integrationId` tem limite de tamanho. Usar **UUID puro**, nunca `nf-<uuid>` (pitfall #5).
  - O mesmo formato tem que ser usado na criação e no filtro.
- **Modo `AUTOMATICO_PROVEDOR`:** `POST /v1/orders` **sem nenhum campo fiscal**, e a Spedy calcula CFOP/CST/ICMS/ISS.
  - É opt-in por tenant (`calculo_tributario_modo`) e abre mão da validação "nunca chutar".
  - A Focus recusa esse modo.

**Mapeamento de status.**
- `authorized` → AUTORIZADA.
- `canceled` → CANCELADA.
- `rejected`, `denied`, `removed` e `disabled` → REJEITADA, com prefixo que distingue `denied`.
- `created`, `enqueued`, `received` e `inContingent` → PROCESSANDO.

**NF-e.**
- `cfop` é **integer**.
- `cst` e `csosn` são **campos separados**, decididos pelo CRT via `CrtResolver`.
- **Exige `series` e `number`**: sem eles a Spedy usa nNF=0 e a nota é rejeitada.
- `isFinalCustomer` corresponde a `indFinal`. **Não é `indIEDest`**, que não existe no schema da Spedy.
- A IE do destinatário vai em `receiver.stateTaxNumber` e só é enviada quando `indicador_ie=1`. Um destinatário isento é representado omitindo o campo.

**NFS-e.**
- `federalServiceCode`, `cityServiceCode`, `taxationType`.
- `issRate` **em fração** (`aliquota/100`).
- As informações complementares vão em `additionalInformation`.

## Lições de paridade entre motores (pitfall #26 e Check 10 da skill)

- **Mesma entrada, todos os motores:** rodar a mesma `NotaFiscalData` por todos os providers e comparar os payloads.
- **Enum, não texto:** campos de enum levam o **código**. Ex.: `natureza_operacao` na NFS-e da Focus.
- **Fonte única do regime:** Simples/MEI vem sempre de `CrtResolver`, nunca de uma checagem de string local.
- **Espelhar cada correção:** um bug corrigido em um motor tem que ser procurado em todos os outros e em todos os modelos. O GTIN, o CEST e o `xPag` apareceram um de cada vez.
