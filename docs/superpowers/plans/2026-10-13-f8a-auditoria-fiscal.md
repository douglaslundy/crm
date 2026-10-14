# F8a Auditoria fiscal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aplicar as 11 checagens do `audit-checklist.md` ao código fiscal, corrigir Críticos e Importantes com teste RED→GREEN e deixar relatório, roteiro de homologação real e pendências registrados.

**Architecture:** Auditoria por **padrão** (não por arquivo): cada tarefa roda um grupo de checagens sobre toda a grade (NFePHP × NF-e/NFC-e, NFS-e Nacional × NFS-e) e anexa linhas ao relatório. Uma tarefa final corrige os achados Críticos e Importantes em uma única passada, cada um com teste que falhou antes.

**Tech Stack:** Laravel 12, PHPUnit, Pint, PHPStan; `nfephp-org/sped-nfe`, `nfse-php`; skill `br-fiscal-note-emission` (`~/.claude/skills/br-fiscal-note-emission/references/audit-checklist.md` e `pitfalls.md`).

**Spec:** `docs/superpowers/specs/2026-10-13-f8a-auditoria-fiscal-design.md`

## Global Constraints

- Nunca chutar valor fiscal; dado ausente bloqueia com mensagem acionável. Zero é válido: `=== null`, nunca `empty()` nem `?? 0`.
- Regra tributária fica fora dos providers; o motor só traduz formato.
- `declare(strict_types=1)`; Model de tenant com Global Scope; teste de isolamento em endpoint novo.
- Auditoria não adiciona funcionalidade; correção é a menor mudança que fecha o achado.
- Regra de negócio que for suposição do autor vai ao usuário; não se decide na auditoria.
- Commits terminam com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` e `Claude-Session: https://claude.ai/code/session_01Bp3wNqb9ZsDGD3kqvKPy2u`.
- Stage por caminho explícito; não tocar `frontend/eslint.config.mjs`, `frontend/features/cadastro/components/CadastroForm.tsx`, `frontend/eslint-rules/`.
- Gravidade (spec): Crítico = nota errada autorizada, duplicada, número queimado sem registro, dado de outro tenant, segredo exposto; Importante = rejeição evitável, terminal tratado como pendente, impresso divergente do autorizado, trava ausente em efeito externo; Minor = o resto.

## Review Focus

- Status terminal da SEFAZ/ADN (denegada, cStat 150, 101 da NFS-e) caindo em "processando" para sempre.
- Reenvio ou clique duplo emitindo nota duplicada (idempotência por nota, não por requisição).
- Número consumido sem nota registrada (queda de rede entre reservar e gravar).
- DANFE/DANFSe/e-mail montado de coluna paralela em vez do XML autorizado.
- Valor fiscal assumido (CST, alíquota, `indPres`, `tpAmb`) em vez de bloqueado.

## Formato do relatório

Arquivo `docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md`. Cada achado é uma linha de tabela: `| ID | Checagem | arquivo:linha | Gravidade | Efeito para o usuário | Decisão |`. IDs `A-01`, `A-02`... na ordem de descoberta. A decisão é `corrigir`, `Minor (TAREFAS)`, `confirmar com o usuário` ou `roteiro`. Cada tarefa abaixo acrescenta uma seção própria com a lista de arquivos examinados, mesmo quando não acha nada ("examinado, sem achado").

### Task 1: Esqueleto, grade e Checagem 11 (nada privado no repositório)

**Files:**
- Create: `docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md`
- Read: `backend/tests/Fixtures/**`, `docs/**`, `.gitignore`

**Interfaces:**
- Produces: arquivo do relatório com cabeçalho, grade preenchida e seção "Checagem 11"; formato de linha de achado usado pelas tarefas seguintes.

- [ ] **Step 1: Criar o relatório com cabeçalho e a grade**

Criar o arquivo com: título, data, escopo (link da spec), a tabela da grade (NFePHP × NF-e/NFC-e; NFS-e Nacional × NFS-e), a tabela de achados vazia no formato acima e uma seção por checagem (1 a 11) com "pendente".

- [ ] **Step 2: Visibilidade do repositório**

Run: `gh repo view douglaslundy/crm --json visibility`
Expected: JSON com `visibility`. Se `PUBLIC`, qualquer achado desta checagem sobe de gravidade; anotar o valor no relatório.

- [ ] **Step 3: Procurar dado real em fixtures, docs e testes**

Run (a partir da raiz do repositório):
`git grep -n -E "[0-9]{2}\.?[0-9]{3}\.?[0-9]{3}/?[0-9]{4}-?[0-9]{2}" -- backend/tests docs frontend | head -80` e
`git grep -n -E "\b[0-9]{44}\b" -- backend/tests docs | head -40` (chaves de acesso, que embutem o CNPJ do emitente).
Para cada ocorrência, decidir: CNPJ/CPF sintético (dígito verificador válido mas número óbvio, como os de teste da lib) ou real. Em caso de dúvida, tratar como real e marcar `confirmar com o usuário`.

- [ ] **Step 4: Conferir modelos e certificados ignorados**

Run: `git ls-files | grep -i -E "\.(pfx|p12|pem|key)$"` e `git check-ignore -v ../mecanicapro 2>&1 | head -3`
Expected: nenhum certificado versionado. Qualquer `.pfx`/`.pem` rastreado é achado Crítico.

- [ ] **Step 5: Gravar achados e commitar**

Preencher a seção da Checagem 11 (arquivos examinados e achados).

```bash
git add docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md
git commit -m "docs(f8a): esqueleto do relatório e checagem 11"
```

### Task 2: Checagens 4 e 6 (enums de status completos; nenhum dado fabricado)

**Files:**
- Read: `backend/app/Modules/Fiscal/NfePhp/InterpretadorDeResposta.php`, `backend/app/Modules/Fiscal/Nfse/InterpretadorDeNfse.php`, `backend/app/Modules/Fiscal/Domain/Enums/*`, `backend/app/Modules/Fiscal/Domain/Resolvers/*`, `backend/app/Modules/Fiscal/NfePhp/MontadorDeXml.php`, `backend/app/Modules/Fiscal/Nfse/MontadorDeDps.php`
- Modify: relatório

**Interfaces:**
- Consumes: formato de achado da Task 1.

- [ ] **Step 1: Enumerar cStat tratados pela NF-e/NFC-e**

Run: `grep -n -E "case |=> |'1[0-9]{2}'|[0-9]{3}" backend/app/Modules/Fiscal/NfePhp/InterpretadorDeResposta.php | head -80`
Listar cada cStat com braço explícito e o status resultante. Confrontar com a tabela de cStat do manual da NF-e e `pitfalls.md` (#16, denegada 301/302/303; 100, 101, 110, 135, 136, 150, 204, 539 duplicidade).

- [ ] **Step 2: Classificar valores não tratados**

Para cada cStat/status sem braço explícito, decidir se é terminal. Terminal caindo em `default` com status pendente é achado Importante. Anotar o que o `default` faz hoje.

- [ ] **Step 3: Repetir para a NFS-e**

Run: `grep -n -E "case |=> |CodigoStatus|[0-9]{3}" backend/app/Modules/Fiscal/Nfse/InterpretadorDeNfse.php | head -60` e listar o enum da lib: `grep -n "case " backend/vendor/**/CodigoStatus.php`.
Registrar fonte (arquivo da lib e versão do `composer.lock`) na seção da checagem.

- [ ] **Step 4: Procurar valor fabricado (Checagem 6)**

Run: `grep -rn -E "\?\? 0|\?\? '0|empty\(|\?: 0|=> '(1|0)'," backend/app/Modules/Fiscal | head -80`
Para cada ocorrência em contexto fiscal (alíquota, CST, base, `indPres`, `tpAmb`, `cMun`, `cNBS`), decidir: valor legítimo por regra citada, ou default silencioso. Default silencioso é Importante (ou Crítico se resulta em nota autorizada com valor errado).

- [ ] **Step 5: Gravar achados e commitar**

```bash
git add docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md
git commit -m "docs(f8a): checagens 4 e 6"
```

### Task 3: Checagem 3 (travas e idempotência em todo efeito externo)

**Files:**
- Read: `backend/app/Modules/Fiscal/Application/Actions/*`, `backend/app/Modules/Fiscal/Application/Jobs/*`, `backend/app/Modules/Fiscal/Application/Webhooks/*`, `backend/app/Modules/Fiscal/Application/Emissao/*`, `backend/app/Modules/Billing/**` (cobrança), `backend/app/Modules/Fiscal/Domain/Models/*` (contador de numeração)
- Modify: relatório

- [ ] **Step 1: Inventariar efeitos externos**

Run: `grep -rn -E "Http::|->post\(|->get\(|Mail::|dispatch\(|Notification::|sefaz|consultaChave|sefazEnvia|enviar\(" backend/app/Modules --include=*.php -l | head -60`
Listar cada método que dispara efeito externo ou irreversível (SEFAZ, ADN, gateway de cobrança, e-mail, numeração).

- [ ] **Step 2: Para cada método, registrar a proteção**

Tabela: método | lê estado | decide por transição | lock (`lockForUpdate`/transação) | idempotência (chave, status guardado) | proteção igual à dos irmãos? Qualquer linha com "não" é achado Importante (Crítico se o efeito é emissão ou cobrança).

- [ ] **Step 3: Numeração**

Ler onde o número é reservado e confirmado. Registrar o que acontece se o processo cai entre reservar e gravar a nota, e se há reaproveitamento seguro (Checagem 9 offline: o contador nunca retrocede).

- [ ] **Step 4: Webhooks e jobs repetidos**

Para cada Job e handler de webhook, responder: rodar duas vezes produz o mesmo estado? (`--tries`, reentrega do provedor.) Falta de idempotência é achado.

- [ ] **Step 5: Gravar achados e commitar**

```bash
git add docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md
git commit -m "docs(f8a): checagem 3"
```

### Task 4: Checagens 1, 2 e 10 (espelhamento e paridade de payload)

**Files:**
- Read: `backend/app/Modules/Fiscal/NfePhp/MontadorDeXml.php`, `backend/app/Modules/Fiscal/Nfse/MontadorDeDps.php`, `backend/app/Modules/Fiscal/Application/Emissao/*`, `backend/app/Modules/Fiscal/Domain/Emissao/*`
- Modify: relatório

- [ ] **Step 1: Lista de campos fiscais**

Do `MontadorDeXml` e do `MontadorDeDps`, listar cada campo fiscal enviado: emitente, destinatário (IE, indIEDest, CPF/CNPJ), item (NCM, CFOP, CST/CSOSN, origem), totais, pagamento, `indPres`, `finNFe`, `natOp`, informações adicionais.

- [ ] **Step 2: Espelhar entre documentos**

Para cada campo, conferir NF-e, NFC-e e NFS-e: onde é derivado, se o resolvedor é o mesmo e se o bloqueio de dado ausente existe nos três. Registrar a matriz campo × documento.

- [ ] **Step 3: Paridade com XML de fixture**

Localizar fixtures de XML autorizado: `git ls-files backend/tests | grep -i -E "xml|autoriz"`. Para cada tipo de documento, comparar o que o montador gera para o mesmo `NotaFiscalData` com o XML autorizado de fixture (campo a campo nos itens da Step 1). Divergência de tipo, enum ou formato é achado.

- [ ] **Step 4: Remoção de ramo morto**

Procurar ramos `// não usado`, `@deprecated`, constantes sem referência no código fiscal. Antes de listar como removível, confirmar contra todos os documentos que compartilham o código.

- [ ] **Step 5: Gravar achados e commitar**

```bash
git add docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md
git commit -m "docs(f8a): checagens 1, 2 e 10"
```

### Task 5: Checagens 5, 7 e 8 (comentários, documento impresso, XSD)

**Files:**
- Read: `backend/app/Modules/Fiscal/**` (comentários), `backend/app/Modules/Fiscal/Pdf/*`, `backend/app/Modules/Fiscal/Pdf/Danfse/*`, e-mail da nota (`grep -rn "Mailable" backend/app/Modules/Fiscal`)
- Modify: relatório

- [ ] **Step 1: Comentários de regra de negócio (5)**

Run: `grep -rn -i -E "sempre|nunca|este neg[óo]cio|n[ãa]o (faz|usa|emite)|assum" backend/app/Modules/Fiscal --include=*.php | head -60`
Para cada um: citação legal, schema do fornecedor ou suposição do autor. Suposição vai como `confirmar com o usuário`.

- [ ] **Step 2: Fonte dos campos impressos (7)**

Ler `SpedDaGeradorDeDanfe`, `DompdfGeradorDeDanfse` e o corpo do e-mail. Para cada campo impresso, anotar se vem do XML autorizado, de snapshot ou de coluna paralela. Coluna paralela que pode divergir é achado Importante.

- [ ] **Step 3: Teste de renderização**

Run: `grep -rn -l -i -E "danfe|danfse" backend/tests`
Conferir se existe teste que renderiza o PDF a partir de XML autorizado. Se faltar, é achado Minor com a regra do pitfall #23.

- [ ] **Step 4: XSD (8) com XML de fixture**

Para cada tipo de documento com XML de fixture, validar contra o schema da lib com `DOMDocument::schemaValidate` em um script descartável fora do repositório (diretório de scratchpad), usando **cópia** do schema com o `pattern` com lookahead reescrito (NFS-e) e `procNFe_v4.00.xsd` para NF-e. Registrar resultado por documento. Divergência entre XSD empacotado e o que a autoridade aceita é achado a reportar, nunca valor inventado.

- [ ] **Step 5: `tpAmb` (pitfall #25)**

Conferir que o ambiente impresso e usado em decisões vem de `tpAmb` do XML, não de configuração atual do tenant.

- [ ] **Step 6: Gravar achados e commitar**

```bash
git add docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md
git commit -m "docs(f8a): checagens 5, 7 e 8"
```

### Task 6: Checagem 9 e roteiro de homologação real

**Files:**
- Create: `docs/auditoria/roteiro-homologacao-real.md`
- Modify: relatório

- [ ] **Step 1: Checagem 9 offline**

Ler o contador de numeração e a recuperação automática de número ocupado. Confirmar: o contador nunca retrocede por código; a recuperação confirma que o documento ocupante está cancelado antes de pular (pitfall #22); restauração de backup pode pôr o contador atrás do que a SEFAZ conhece (registrar como insumo da F8c).

- [ ] **Step 2: Escrever o roteiro**

Em `docs/auditoria/roteiro-homologacao-real.md`, passos numerados que o usuário executa com certificado A1 e CNPJ de homologação: emitir NF-e, NFC-e e NFS-e; rejeição proposital; cancelamento; inutilização; consulta de chave; conferir `tpAmb`, DANFE/DANFSe e XML; comparar numeração local com a autoridade (Checagem 9); validar XSD com XML real (Checagem 8). Cada passo diz o resultado esperado e onde ver.

- [ ] **Step 3: Gravar e commitar**

```bash
git add docs/auditoria/roteiro-homologacao-real.md docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md
git commit -m "docs(f8a): checagem 9 e roteiro de homologação real"
```

### Task 7: Passada de correção (Críticos e Importantes)

**Files:**
- Modify: arquivos nomeados pelos achados `corrigir` do relatório
- Test: `backend/tests/**` correspondente a cada achado

**Interfaces:**
- Consumes: tabela de achados do relatório com Gravidade e Decisão.

- [ ] **Step 1: Congelar a lista**

Ler a tabela de achados e listar os `Crítico` e `Importante` com decisão `corrigir`. Se houver achado `confirmar com o usuário` Crítico ou Importante, perguntar ao usuário (múltipla escolha) antes de corrigir.

- [ ] **Step 2: Para cada achado, RED**

Escrever o teste que reproduz o achado. Run: `cd backend && php artisan test --filter=<NomeDoTeste>`
Expected: FAIL pelo motivo do achado (não por typo).

- [ ] **Step 3: GREEN**

Aplicar a menor mudança que fecha o achado, sem default fiscal inventado. Run o mesmo filtro.
Expected: PASS.

- [ ] **Step 4: Mesma forma de bug nos irmãos**

Para cada correção, repetir a Checagem 1 e 3 sobre os documentos e métodos irmãos; aplicar a mesma correção a cada um (cada um com teste).

- [ ] **Step 5: Suíte completa**

Run: `cd backend && php artisan test 2>&1 | tail -15` e `vendor/bin/pint --test` e `vendor/bin/phpstan analyse --memory-limit=1G | tail -5`
Expected: suíte verde (1516+ testes), Pint e PHPStan limpos.

- [ ] **Step 6: Commitar por achado**

Um commit por achado ou grupo coeso, mensagem `fix(fiscal): <o que>`; atualizar a linha do relatório com `corrigido` e o nome do teste.

### Task 8: Fechamento e documentação

**Files:**
- Modify: `TAREFAS.md`, `PROGRESSO.md`, `docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md`

- [ ] **Step 1: Minors e pendências**

Acrescentar em `TAREFAS.md` a seção "Pendências da F8a" com cada achado `Minor (TAREFAS)` e `confirmar com o usuário`; marcar F8a e deixar F8b, F8c e F8d como próximas.

- [ ] **Step 2: PROGRESSO.md**

Seção "Fechamento da F8a": o que foi auditado, achados por gravidade, o que foi corrigido (com teste), o que **não** foi verificado (SEFAZ, ADN, PostgreSQL), e "Próxima tarefa: F8b". Declarar revisão final como auto-revisão.

- [ ] **Step 3: Auto-revisão e commit**

Reler o relatório contra a spec (11 checagens com seção preenchida, grade, roteiro, Minors registrados).

```bash
git add TAREFAS.md PROGRESSO.md docs/auditoria
git commit -m "docs: F8a concluída (auditoria fiscal)"
```
