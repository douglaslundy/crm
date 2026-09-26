# Base de referência fiscal (extraída do Mecânica Pro)

**O que é:** o conhecimento do módulo fiscal do projeto `C:\Users\dougl\workspace\mecanicapro`, onde a skill `br-fiscal-note-emission` nasceu. Serve de base de consulta para implementar o SaaS fiscal e o CRM.

**Como foi extraído:** em 2026-09-25, **só lendo** o código-fonte (nada foi alterado lá). Cada arquivo diz de onde veio.

**Como usar:** leia este índice e abra **só o arquivo do assunto da tarefa**, para economizar contexto.

## Resposta direta: quem emite o quê

O provider **NFEPHP** do Mecânica Pro emite **os três documentos**, com **duas bibliotecas PHP** e o certificado A1 do próprio CNPJ, sem vendor intermediário:

| Documento | Biblioteca | Versão no composer | Autoridade |
|---|---|---|---|
| NF-e (55) | `nfephp-org/sped-nfe` | `^5.2` (v5.2.8) | SEFAZ da UF |
| NFC-e (65) | `nfephp-org/sped-nfe` | `^5.2` | SEFAZ da UF |
| NFS-e | `nfse-nacional/nfse-php` | `^1.21@beta` | Sistema Nacional NFS-e (SEFIN/ADN) |

- **Limite da NFS-e:** só municípios **aderidos ao padrão nacional**. Hoje são mais de 4.000, com cerca de 70% do volume.
- **Situação real:** a primeira NFS-e real via NFePHP foi **autorizada em 14/09/2026** (TAREFAS.md do Mecânica Pro).
- **Motores REST:** FOCUS e SPEDY emitem os mesmos três documentos por API. A Focus também cobre NFS-e municipal legada.

## Arquivos

| Arquivo | Conteúdo |
|---|---|
| `01-arquitetura.md` | camadas, contrato `FiscalProvider`, DTOs, seleção de motor e ambiente, estados, fila, orquestrador |
| `02-nfephp-nfse.md` | NFS-e Nacional: DPS campo a campo, chave de 50 dígitos, consulta de eventos, cancelamento 101101 |
| `03-nfephp-nfe.md` | NF-e: `Tools`/`Make` tag a tag, emissão, 539, EPEC, consulta, cancelamento, inutilização, DF-e, certificado |
| `04-nfephp-nfce.md` | NFC-e: diferenças da NF-e, CSC, QR Code, contingência offline |
| `05-motores-rest-focus-spedy.md` | endpoints, status e campos de Focus e Spedy; matriz motor × documento |
| `06-regras-dados-e-cadastros.md` | resolvers (CFOP/CST/CSOSN/CRT/indIEDest/cTribNac), modelo de dados, cadastros, endpoints, PDF, **Mercado Pago** e limites de plano |
| `07-rejeicoes-reais-e-checklist.md` | rejeições reais já resolvidas e checklist do SaaS com as pendências conhecidas |

## Fontes complementares

- **Skill:** `~/.claude/skills/br-fiscal-note-emission/`. Contém `pitfalls.md` (27 bugs reais), `domain-concepts.md`, `reliability-patterns.md`, `audit-checklist.md`, a tabela de cStat, o cache de schemas Focus/Spedy/NFePHP, os layouts de PDF e `reference-calculations.php` (ISS por dentro, rateio de desconto, numeração).
- **Código de referência:** `mecanicapro/backend/app/Services/Fiscal/` e os testes em `backend/tests/{Unit,Feature}/Fiscal/`.
- **Documentação de vendor e modelos de nota:** `mecanicapro/doc_documentos_fiscais/` (doc Spedy, doc Focus, PDFs de modelo).
- **PDF oficial alternativo:** `nfephp-org/sped-da` (DANFSe v2.0 da NT 008, DANFE e DANFCE).

## Adaptações obrigatórias ao levar para o SaaS

1. **Multi-tenant de verdade.** O Mecânica Pro lê `Configuracao::first()` dentro dos motores. No SaaS, o emitente (CNPJ) é **passado explicitamente** (`EmitenteData`/tenant), nunca lido de uma configuração global.
2. **UF dinâmica.** Derivar `cUF` da UF do emitente por tabela IBGE. O Mecânica Pro fixa MG (31).
3. **Serviço configurável.** `cTribNac`, código LC 116, código municipal e alíquota vêm do cadastro do serviço, não de constante fixa ("14.01").
4. **Cancelamento pela interface.** O DTO de cancelamento carrega `chave` + `protocolo` + `modelo`, sem caminho especial no controller.
5. **Falha técnica é ERRO.** Uma exceção inesperada no job vira `ERRO`, não `REJEITADA`.
6. **API pública para desenvolvedores.** Os mesmos casos de uso ficam expostos por API key + `Idempotency-Key`, com webhook de mudança de status para o integrador.
