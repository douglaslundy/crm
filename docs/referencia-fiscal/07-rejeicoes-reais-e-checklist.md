# Rejeições reais do Mecânica Pro e checklist de implementação

Todas as rejeições abaixo **aconteceram de verdade** (homologação ou produção) e foram corrigidas no Mecânica Pro. O SaaS deve nascer com as correções. Detalhe no código-fonte e em `~/.claude/skills/br-fiscal-note-emission/references/pitfalls.md`.

## NFS-e Nacional (ADN)

| Código | Causa | Correção |
|---|---|---|
| E1235 (cTribNac) | "14.01" enviado como `cTribNac` | usar 6 dígitos (`140101`) |
| E1235 (texto) | travessão "—" no modelo do veículo | `sanitizar()`: só U+0020–U+007E e U+00A1–U+00FF, sem quebras de linha, com trim |
| E0120 | `IM` do prestador enviado sem cadastro no CNC | nunca enviar `IM` |
| E0128 | endereço do prestador com `tpEmit=1` | nunca enviar `prest.end` |
| E0625 | `pAliq` informado para Simples sem retenção | omitir `pAliq` nesse caso |
| E0712 | `indTotTrib` informado para ME/EPP | usar `pTotTribSN` |
| "trib incompleto" | `totTrib` ausente, e depois `pTotTribSN=0.0` descartado pela lib | sempre enviar `totTrib`; usar `0.001` |
| DPS duplicada | `nDPS` fixo em '1' | contador transacional |
| "não encontrada" | chave com prefixo `NFS` | gravar e consultar com 50 dígitos |

## NF-e e NFC-e (SEFAZ)

| cStat | Causa | Correção |
|---|---|---|
| 232 | PJ contribuinte enviada como `indIEDest=9` | `IndicadorIeDestinatarioResolver`; cadastrar IE e flag de isento no cliente |
| 883 | `cEAN` vazio | usar `'SEM GTIN'` |
| 806 | ST sem CEST | enviar o CEST e bloquear produto com ST sem CEST |
| 441 | `tPag=99` sem `xPag` | mapear `tPag` e enviar `xPag` quando for 99 |
| 539 | número já usado (nota cancelada que o sistema não conhecia) | consultar a chave; avançar só se estiver cancelada, com no máximo 5 tentativas |
| 558 | `dhCont` 3h adiantado | fuso UTC durante `signNFe` em contingência |
| 694 | DIFAL ausente | bloquear localmente (CRT=3, interestadual, consumidor não contribuinte) |
| 656 | consulta DistDFe excessiva | checkpoint de NSU e agendamento |
| 217 em homologação | nota de terceiro consultada em homologação | DF-e sempre em produção |
| schema PIS/COFINS | choice `vBC+pX` omitido | enviar zerado com CST 49 |
| nNF=0 (Spedy) | `series`/`number` não enviados | enviar sempre |

## Checklist do SaaS (derivado do Mecânica Pro + skill)

**Por tenant (CNPJ), antes de emitir:**
- [ ] Dados do emitente: CNPJ, IE, IM, CNAE, regime, endereço completo com IBGE, e a **UF gerando o cUF** (o Mecânica Pro fixou MG; o SaaS **não pode** fazer isso).
- [ ] Certificado A1: upload, validação (senha e validade), criptografia e alertas em 90, 60, 30, 14 e 7 dias.
- [ ] CSC da NFC-e por ambiente.
- [ ] Séries e contadores por modelo e por ambiente.
- [ ] Ambiente visível na tela de emissão, com confirmação para produção e auditoria da troca.
- [ ] `registrarEmissor` = validação local dos campos obrigatórios.

**Cadastros:**
- [ ] Produto: NCM, CEST, origem (nullable), tributação `NORMAL/ST`, GTIN, unidade; mais fonte e revisão.
- [ ] Serviço: código LC 116, **cTribNac de 6 dígitos**, código municipal (3 dígitos, opcional), alíquota de ISS e, se for o caso, NBS.
- [ ] Cliente: CPF/CNPJ, IE ou flag de isento, endereço com IBGE.

**Emissão:**
- [ ] Resolvers puros que lançam exceção; bloqueio com mensagem acionável antes de alocar número.
- [ ] Número alocado com lock; reaproveitado em retry depois de rejeição.
- [ ] Fila, polling de status e reconciliação agendada (incluindo "PROCESSANDO nunca enviada").
- [ ] Idempotência: `referencia_externa` em UUID. Na **API pública**, aceitar o header `Idempotency-Key` do integrador.
- [ ] XSD validado localmente (o `sped-nfe` já valida no `sefazEnviaLote`).
- [ ] Guardar o nfeProc/XML autorizado; PDF gerado a partir dele.
- [ ] Cancelamento com justificativa de 15 a 255 caracteres, consultando a chave e o protocolo.
- [ ] Inutilização de faixa.
- [ ] Contingência: EPEC para NF-e, offline para NFC-e, com prazo de 7 dias e alerta.
- [ ] Limite do plano: contar AUTORIZADAS no mês; bloquear ou cobrar excedente de forma idempotente.

**Pendências conhecidas (não resolvidas nem no Mecânica Pro):**
- [ ] IBS/CBS (reforma tributária) no Regime Normal: schema PL_010+.
- [ ] Cálculo de DIFAL (`ICMSUFDest`).
- [ ] PIS/COFINS reais no Regime Normal.
- [ ] ICMS próprio real no Regime Normal (hoje vai com alíquota zero).
- [ ] ISS retido (o Mecânica Pro envia sempre "não retido").
- [ ] NFS-e de município **não aderido** ao padrão nacional (exige vendor).
- [ ] `cNBS` na NFS-e nacional: confirmar a exigência atual.
- [ ] NF-e de devolução, complementar e ajuste (`finNFe` 2, 3 e 4) e carta de correção (CC-e): não implementadas.
