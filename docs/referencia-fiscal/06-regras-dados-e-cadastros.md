# Regras fiscais, modelo de dados e cadastros

Fontes lidas em 2026-09-25, em `mecanicapro/backend/app/Services/Fiscal/*`:
- `*Resolver.php`
- `CriarNotaFiscalService.php`
- `ProdutoFiscalService.php`
- `ValidadorCamposFiscais.php`
- as migrations fiscais

## Resolvers (funções puras, exaustivas, que lançam exceção fora das saídas conhecidas)

| Resolver | Entrada | Saída |
|---|---|---|
| `CrtResolver` | regime (texto) | vazio lança exceção. Contém "simples" ou "mei" dá **1**; qualquer outro dá **3**. **MEI é Simples**. É a fonte única da classificação Simples |
| `CfopSaidaResolver` (NF-e B2B) | UF origem, UF destino, ST? | 5102 / 5405 (ST) / 6102 / 6404 (ST). UF inválida lança exceção |
| `CfopConsumidorResolver` (NFC-e) | UF origem, UF destino | 5102 / 6108 |
| `TributacaoIcmsSaidaResolver` | regime + `NORMAL`/`ST` | Simples: CSOSN 102 / 500. Normal: CST 00 / 60 |
| `ClassificacaoIcms` (entrada, XML do fornecedor) | CST/CSOSN | ST: CST 10, 30, 60, 70; CSOSN 201, 202, 203, 500. NORMAL: CST 00, 20, 40, 41, 50, 51, 90; CSOSN 101, 102, 103, 300, 400, 900. Desconhecido é **null** (vira pendência, nunca NORMAL). Os CSTs novos de 2023/2024 são instáveis |
| `IndicadorIeDestinatarioResolver` (só NF-e) | documento, IE, flag de isento | CPF: 9. CNPJ com IE: 1 (+ IE). CNPJ marcado isento: 2. CNPJ sem IE e sem flag: **bloqueia** |
| `CodigoTributacaoNacionalResolver` (NFS-e nacional) | LC 116 "14.01" | `140101`. Código sem mapeamento lança exceção. **No SaaS vira um cadastro por serviço** |
| `PoliticaConflitoFiscal` | valor atual vs valor do XML | PREENCHER (atual vazio), NADA (iguais ou XML vazio) ou DIVERGENCIA (**nunca sobrescreve**; gera um registro para decisão humana) |
| `ValidadorCamposFiscais` | NCM, CEST, CFOP, origem | NCM com 8 dígitos, CEST com 7, CFOP com 4, origem de 0 a 8. Valor malformado vira **null** (nunca o valor cru, porque lixo parece preenchido) |

**Vazio não é zero.** Usar `=== null` ou `=== ''`, **nunca `empty()` ou `?? 0`**. Origem `0` (nacional) é um valor válido.

## Criação da nota (`CriarNotaFiscalService`)

**Naturezas de operação:**
- "Prestação de Serviços" gera NFS-e.
- "Venda de Mercadoria" gera NF-e ou NFC-e.

**Bloqueios da venda** (`EmissaoBloqueadaException`, com mensagem que diz como resolver):
- Empresa sem UF ou sem regime.
- Cliente sem UF.
- Produto com `tributacao_icms` nulo.
- Produto com `origem` nula.
- Produto com ST mas sem CEST.

**NFC-e ou NF-e automático:** vira NFC-e quando o cliente é **pessoa física**, **está na mesma UF**, a configuração tem `modelo_venda_padrao='NFC-e'` e não há `forcar_nfe`. Em qualquer outro caso vira NF-e.

**Totais:**
- `valor_total = subtotal - desconto`.
- Serviço: `valor_iss = (subtotal - desconto) * aliquota / 100`. É o **ISS por dentro**, e **não é somado** ao total.
- Venda: ISS = 0.
- Arredondamento: `round(,2)` em cada etapa.
- Para rateio de desconto entre itens, usar `ratearDesconto()` da skill (`scripts/reference-calculations.php`), que joga o resíduo no último item.

**Itens:** grava um **snapshot fiscal** no item da nota: `sku`, `descricao`, `unidade`, `ncm`, `cfop`, `origem`, `tributacao_icms`, `cst_csosn`, `quantidade` e `valor_unitario`. Assim a nota não muda quando o produto é editado depois.

## Modelo de dados (colunas relevantes)

**`notas_fiscais`**
- Identificação: `id` uuid, `numero` (int, **largo**: o nNFSe tem até 13 dígitos), `serie`, `modelo` (`NFS-e|NF-e|NFC-e`).
- Vínculos: `cliente_id`, `os_id`.
- Dados: `natureza_operacao`, `forma_pagamento`.
- Valores: `subtotal`, `desconto`, `aliquota_iss`, `valor_iss`, `valor_total`.
- Estado: `status`.
- Retorno da autoridade: `chave_acesso` (**largo**: 44 na NF-e, 50 na NFS-e), `protocolo`, `xml_retorno` (text; o **nfeProc** autorizado), `pdf_url`, `qrcode_url`.
- Texto: `observacoes`, `informacoes_complementares` (snapshot do que foi enviado).
- Controle: `mensagem_erro`, `provedor`, `ambiente`, `referencia_externa` (indexada), `contingencia_desde`, `emitido_em`.

**`notas_fiscais_itens`**
- `nota_fiscal_id`, `produto_id`, `oficina_id`.
- Snapshot: `sku`, `descricao`, `unidade`, `ncm`, `cfop`, `origem`, `tributacao_icms`, `cst_csosn`.
- Valores: `quantidade` (8,2), `valor_unitario`, `valor_total` (coluna gerada: q × v).

**`configuracoes`** (dados fiscais por tenant; no SaaS vira o **emitente**)
- Identificação: `razao_social`, `nome_fantasia`, `cnpj`, `inscricao_estadual`, `inscricao_municipal`, `regime_tributario`, `cnae`.
- Endereço: `logradouro`, `numero`, `bairro`, `cidade`, `uf`, `cep`, `codigo_ibge`.
- Emissão: `ambiente_fiscal`, `aliquota_iss`.
- Numeração:
  - NF-e: `serie_nf` / `proximo_numero_nf` (vendors) e `serie_nfe` / `proximo_numero_nfe` (NFePHP).
  - NFC-e: `serie_nfce` / `proximo_numero_nfce` (vendors) e `proximo_numero_nfce_nfephp`.
  - DPS: `serie_dps` / `proximo_numero_dps`.
- NFC-e: `csc_id_{homologacao,producao}` + `csc_token_*_encrypted`, `modelo_venda_padrao`.
- Certificado: `certificado_pfx_encrypted`, `certificado_senha_encrypted`, `certificado_validade`, `certificado_nome`, `certificado_status`.
- Cálculo: `calculo_tributario_modo`.
- Notas de terceiros: `dist_dfe_ultimo_nsu`, `notas_terceiro_ultima_verificacao`.

**Contadores por motor.** Existem contadores distintos por motor porque cada vendor gera a numeração dele.

**`produtos`** (campos fiscais)
- `ncm` (8), `cest` (7), `origem` (smallint, nullable), `tributacao_icms` (`NORMAL|ST`, nullable), `codigo_barras` (GTIN).
- `fiscal_fonte` (`XML|PADRAO|MANUAL`) e `fiscal_revisado_em`.

**`categoria_padrao_fiscal`**
- Colunas: `categoria`, `ncm`, `origem`, `tributacao_icms` por tenant.
- Preenche **só os campos vazios** e marca a fonte como `PADRAO`, que é um palpite assistido e aparece em "Pendências".

**`produto_fiscal_divergencias`**
- Colunas: `produto_id`, `nota_entrada_id`, `campo`, `valor_atual`, `valor_xml`, `resolvido_em`, `resolucao`.

**`clientes`** (campos fiscais)
- `cpf_cnpj`, `inscricao_estadual`, `ie_isento`, `codigo_ibge` (vem do ViaCEP), mais endereço e `uf`.

**`emissores_fiscais`**
- `oficina_id`, `provedor`, `ambiente`, `emissor_externo_id`, `token_encrypted`, `status` (`PENDENTE`…), `registrado_em`, `ultimo_erro`.

**`saas_config`**
- Fiscal: `provedor_fiscal_padrao`, `emissao_fiscal_modo_padrao`, e as chaves master Spedy/Focus por ambiente (criptografadas).
- Mercado Pago: `mp_access_token`, `mp_webhook_secret`.

**`planos`**
- `nome`, `preco_mensal`, `ativo`.
- Limites (`-1` = ilimitado): `limite_usuarios`, `limite_os_mes`, `limite_produtos`, `limite_clientes`, `limite_notas_mes`.
- Excedente: `preco_nota_excedente`.

## Cadastro fiscal de produtos (fluxo útil para o SaaS)

- **Pendências fiscais:** uma tela lista produtos sem NCM, origem ou tributação, ou com fonte `PADRAO` ainda não revisada (`GET produtos/pendencias-fiscais`, `POST produtos/{id}/marcar-revisado`).
- **Importação de XML de compra:** preenche os campos vazios. Quando há divergência, abre um registro para resolução humana (`POST produtos/divergencias/{id}/resolver`).
- **Exportação fiscal:** `GET produtos/exportar-fiscal` gera planilha para o contador revisar.

## Endpoints fiscais do Mecânica Pro (referência para o API do SaaS)

**Leitura:**
- `GET notas-fiscais`, `GET notas-fiscais/{id}`.
- `GET notas-fiscais/{id}/pdf`, `GET notas-fiscais/{id}/xml`.
- `GET notas-fiscais/{id}/status`: polling, que também consulta o provedor.
- `POST notas-fiscais/download-zip`: de 1 a 50 ids, com PDF + XML.

**Ações:**
- `POST notas-fiscais` cria o RASCUNHO.
- `POST notas-fiscais/{id}/emitir` responde **202**, porque a emissão vai para a fila.
- `POST notas-fiscais/{id}/cancelar`: `motivo` de 15 a 255 caracteres.
- `POST notas-fiscais/{id}/retransmitir`: para notas em contingência.
- `POST notas-fiscais/inutilizar-numeracao`: `serie`, `numero_inicial`, `numero_final` (≥ inicial) e `justificativa` (mínimo 15).
- `DELETE notas-fiscais/{id}`: **só homologação ou RASCUNHO**. Nota de produção nunca é excluída.
- `POST os/{id}/emitir-notas` chama o orquestrador.

**Configuração:**
- `POST configuracoes/certificado` faz o upload do `.pfx` + senha, com validação.
- `PUT config/fiscal`, `.../spedy`, `.../focus` configuram o SaaS.
- `PUT oficinas/{id}/fiscal` define o provider por tenant.

**Papéis:** ADMIN e FINANCEIRO emitem, cancelam e inutilizam; ATENDENTE só lê.

**Agendamentos:**
- `nfe:reconciliar-processando` a cada 15 min, `withoutOverlapping`.
- `nfe:reconciliar-contingencia` de hora em hora.
- `nfe:verificar-notas-recebidas` de hora em hora.

## PDF (`Pdf/NotaFiscalDocumentoService`)

- **NFS-e:** DANFSe v2.0 clonado (`DanfseRenderer` + `danfse.blade.php`), página de 595×842 pt, com logo e QR (`endroid/qr-code` 6).
- **NF-e:** DANFE A4 montado **a partir do XML autorizado** (`DanfeRenderer`), com código de barras da chave (`picqer/php-barcode-generator`).
- **NFC-e:** cupom de 80 mm, com altura calculada pelo número de itens e QR a partir do XML.
- **Downloads:** o nome vem do servidor, por modelo: `NFSe-<n>`, `NFe-<n>`, `NFCe-<n>` (`.pdf` e `.xml`).
- **E-mail** de nota autorizada leva o PDF e o XML anexos.
- **Base:** o PDF imprime **o que foi autorizado**, lido do XML, e não de uma coluna paralela (pitfall #23).
- **Alternativa oficial:** `nfephp-org/sped-da` (`NFePHP\DA\NFSe\Danfse`, DANFSe da NT 008, que também gera DANFE e DANFCE).

## Cobrança do SaaS (Mercado Pago, já implementado no Mecânica Pro)

`app/Services/MercadoPagoService.php` usa a base `https://api.mercadopago.com` com `Bearer` = access token.

**Clientes e assinatura:**
- `POST /v1/customers` e `GET /v1/customers/{id}`.
- Assinatura recorrente: `POST /preapproval` com `payer_id` e `auto_recurring{frequency:1, frequency_type:'months', transaction_amount, currency_id:'BRL', start_date}`, mais `reason`, `back_url` e `status:'authorized'`.
- `PUT /preapproval/{id}` para cancelar (`status:cancelled`) ou mudar o valor. `GET /preapproval/{id}` para consultar.

**Checkout transparente (Payment Brick):**
- `POST /v1/payments`, aceitando **só** os campos do Brick: `token`, `issuer_id`, `payment_method_id`, `installments`, `payer`.
- O servidor define `transaction_amount`, `description` e `external_reference`. **Nunca confiar no valor enviado pelo cliente.**
- Header `X-Idempotency-Key = external_reference`.

**Cobrança avulsa:** Checkout Pro com `POST /checkout/preferences` (`items`, `external_reference`, `expires`, `expiration_date_to`), que devolve `init_point`.

**Outras chamadas:**
- `GET /v1/payments/{id}`: conciliação ativa.
- `POST /v1/payments/{id}/refunds`: estorno.

**Webhook** (`POST saas/webhooks/mercadopago`, throttle 60/min):
- **Falha fechado:** sem segredo configurado ou sem o header `x-signature`, responde 401.
- Assinatura: o manifest é `"id:{data_id};request-id:{x-request-id};ts:{ts};"` e o esperado é `hash_hmac('sha256', manifest, secret)`, comparado com `v1=` via `hash_equals`.
- Só processa `type=payment`. **Busca o pagamento na API** em vez de confiar no corpo do webhook. `approved` localiza a `Cobranca` pelo `external_reference` e chama `confirmarPagamento`.
- Tempo de assinatura só avança (e o tenant suspenso só é reativado) quando a cobrança é do tipo `ASSINATURA`.

**Nota excedente** (`PlanLimitService::registrarNotaSeExcedente`):
- Conta as notas AUTORIZADAS no mês pelo `emitido_em`. Acima de `limite_notas_mes`, cria uma `Cobranca NOTA_EXCEDENTE`.
- Idempotente: checa se já existe **e** tem **índice único parcial** `(nota_fiscal_id, tipo)`.
- Só roda em produção.
