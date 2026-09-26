# Arquitetura do módulo fiscal do Mecânica Pro

Fonte: `mecanicapro/backend/app/Services/Fiscal/*`, `Jobs/EmitirNotaFiscalJob.php` e `Services/NfeService.php`, lidos em 2026-09-25.

## Camadas

```
Controller (NotaFiscalController)
  └─ CriarNotaFiscalService          valida, escolhe o modelo, resolve CFOP/CST, cria RASCUNHO
  └─ IniciarEmissaoNotaService       trava a linha, aloca o número, PROCESSANDO, despacha o job
       └─ EmitirNotaFiscalJob (fila, timeout 180s, tries 1)
            └─ NfeService::emitir → montarNotaData (NotaFiscalData) → FiscalProviderManager::forTenant()
                 └─ FiscalProvider: SpedyProvider | FocusNfeProvider | NfePhpProvider
                                                                    ├─ MotorNfe  (sped-nfe, mod 55)
                                                                    ├─ MotorNfce (sped-nfe, mod 65)
                                                                    └─ MotorNfse (nfse-php, DPS nacional)
            └─ AplicarResultadoNotaService   trava a linha, persiste o resultado, cobra o excedente do plano e alerta
EmissaoOrquestradorService           OS mista gera NF-e (peças) + NFS-e (serviços), de forma independente
Comandos agendados: ReconciliarNotasProcessando, ReconciliarContingenciaNfe, VerificarNotasTerceirosRecebidas
```

## Contrato `FiscalProvider`

```php
registrarEmissor(EmissorData): RegistroResultado          // REGISTRADO | ERRO (+ emissorExternoId, token)
enviarCertificado(EmissorData, string $pfx, string $senha): void
emitir(NotaFiscalData): EmissaoResultado
consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado   // 'NFSE'|'NFE'|'NFCE'
cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado
```

Existe uma interface opcional à parte, `ConsultaNotaTerceiroProvider`, para notas de entrada:
- `consultarNotaRecebida(chave44)` devolve `COMPLETA|AGUARDANDO_MANIFESTACAO|NAO_ENCONTRADA|ERRO`.
- `listarNotasRecebidas(cnpj, desde)` **lança exceção quando falha**. Nunca devolve `[]` para um erro, porque o chamador precisa distinguir "sem notas" de "falhou".

**Particularidade do NFePHP:**
- `registrarEmissor` só **valida localmente** CNPJ, IE, IM, CNAE, IBGE e regime, e devolve o token `'local'`.
- `enviarCertificado` só **valida** o `.pfx`.
- `cancelar` de NF-e/NFC-e precisa do **protocolo**, que a interface não carrega. O controller busca a nota e chama o motor direto. **No SaaS, melhorar isso:** passar a nota ou um DTO de cancelamento com `chave` + `protocolo`.

## DTOs

**`EmissaoResultado`**
- `status`: `AUTORIZADA | PROCESSANDO | REJEITADA | CANCELADA | CONTINGENCIA | ERRO`.
- Campos: `chave`, `protocolo`, `numero`, `xml`, `pdfUrl`, `mensagemErro`, `referenciaExterna`, `qrCodeUrl`.
- Fábricas: `autorizada()`, `processando()`, `rejeitada(msg, ref, numero)`, `erro(msg, ref, numero)`, `cancelada()`, `contingencia(chave, numero, xml, ref)`.
- **REJEITADA** é decisão da autoridade fiscal. **ERRO** é falha técnica ou incerteza. Nunca misturar os dois (pitfall #6).
- `rejeitada` e `erro` carregam o **número já alocado**, para que ele seja reaproveitado na retentativa.

**`NotaFiscalData`** (entrada do provider, com tudo já resolvido)
- **Identificação:** `tipo`, `modelo` (`NFSE|NFE|NFCE`), `referenciaExterna` (idempotência).
- **Destinatário:** `tomador[]`: `cpf_cnpj`, `nome`, `uf`, `logradouro`, `numero`, `bairro`, `cidade`, `cep`, `codigo_ibge`, `indicador_ie`, `inscricao_estadual`.
- **NFS-e:** `descricao`, `valorServicos`, `aliquotaIss`, `issRetido`, `codigoServicoFederal` (LC 116 "14.01"), `codigoServicoMunicipal`, `naturezaOperacao`.
- **NF-e/NFC-e:** `itens[]`: `produto_id`, `sku`, `descricao`, `unidade`, `ncm`, `cest`, `codigo_barras`, `cfop`, `origem`, `tributacao_icms`, `cst_csosn`, `quantidade`, `valor_unitario`. Também `formaPagamento`.
- **Numeração:** `numeroReservado` (reuso em retry, só NFePHP), `numeroAlocado` + `serieNf` (para vendors que exigem série e número; a Spedy colocava nNF=0 sem isso).
- **Regime:** `regimeTributario`, `calculoTributarioModo` (`MANUAL` ou `AUTOMATICO_PROVEDOR`, este só na Spedy).
- **Emitente:** `cnpjEmitente`, `inscricaoMunicipalEmitente`, `codigoIbgeEmitente` (a Focus exige; o ISS é devido no município do **prestador**).
- **Texto livre:** `informacoesComplementares`, já montado e sanitizado.

**`EmissorData`**
- `cnpj`, `razaoSocial`, `nomeFantasia`, `inscricaoEstadual`, `inscricaoMunicipal`, `regimeTributario`, `email`, `telefone`.
- Endereço completo com `codigoIbge` e `cnae`.

## Seleção de provider e ambiente

- **Provedores válidos:** `SPEDY | FOCUS | NFEPHP`. O provider do tenant (`oficinas.provedor_fiscal`) tem prioridade sobre o padrão do SaaS (`saas_config.provedor_fiscal_padrao`).
- **Credenciais master dos vendors**, por ambiente e criptografadas, em `saas_config`: `focus_master_token_{producao,homologacao}`, `spedy_master_key_{producao,sandbox}`.
- **Token do emissor:** `emissores_fiscais` (`oficina_id`, `provedor`, `ambiente`, `emissor_externo_id`, `token_encrypted`).
- **Ambiente** vem da configuração do tenant (`ambiente_fiscal`: `HOMOLOGACAO|PRODUCAO`) e é **gravado na própria nota** no momento do `iniciar`.
- **Consulta de nota de terceiro** usa sempre PRODUCAO.

## Fluxo de estados da nota

`RASCUNHO → PROCESSANDO → AUTORIZADA | REJEITADA | ERRO | CONTINGENCIA → (CANCELADA)`

- **`iniciar`:** ignora a nota se ela já está AUTORIZADA ou PROCESSANDO. Faz `lockForUpdate`, aloca o número, grava `provedor`, `ambiente` e `referencia_externa` e marca PROCESSANDO dentro da transação. O **dispatch do job acontece fora da transação**, porque `queue.after_commit=false`.
  - Numeração NFePHP: o motor aloca o número. O `iniciar` só repassa o número anterior quando a nota já era do NFePHP (retry).
  - Numeração dos outros providers: `proximoNumeroNf` ou `proximoNumeroNfce`.
- **Job:** só age se a nota está PROCESSANDO. Uma exceção inesperada vira REJEITADA com mensagem técnica.
  - **No SaaS usar ERRO, não REJEITADA**, pela distinção semântica acima.
- **`aplicar`:** grava `status`, `chave`, `protocolo`, `xml_retorno`, `qrcode_url`, `mensagem_erro`, `numero`, `contingencia_desde` e `emitido_em`, com lock.
  - **Efeitos colaterais** (cobrança do excedente do plano via `PlanLimitService::registrarNotaSeExcedente` e alerta `NF_AUTORIZADA` com PDF e XML anexos) só disparam na transição **para** AUTORIZADA **e** em PRODUCAO.
  - O ambiente é relido na hora, não vem do valor capturado no dispatch.
- **Frontend:** faz polling em `GET /notas-fiscais/{id}/status`.
- **Reconciliação:** um comando agendado varre as notas presas em PROCESSANDO, incluindo o caso "nunca chegou a ser enviada".

## Orquestrador (venda mista)

- Uma OS com peças gera NF-e ou NFC-e, e uma OS com serviços gera NFS-e.
- As duas notas são **independentes**: se uma é bloqueada, a outra sai, e o retorno traz `avisos`.
- **Desconto rateado** proporcionalmente entre peças e serviços. O resíduo vai para serviços (`descontoServicos = total - descontoPecas`).
- **Idempotente por categoria:** AUTORIZADA, CONTINGENCIA e PROCESSANDO contam como já feito. REJEITADA, ERRO, RASCUNHO e CANCELADA permitem gerar de novo.
- Peça sem produto vinculado (sem NCM) fica de fora e gera aviso.
