# F8a: auditoria fiscal pré-go-live (design)

Data: 2026-10-13. Primeira das quatro sub-fases da F8 (parte código): F8a auditoria fiscal, F8b pendências de go-live, F8c backup e restauração, F8d carga. Passos que dependem de certificado A1, CNPJ piloto, domínio ou SEFAZ real ficam como roteiro para o usuário.

## Objetivo

Aplicar as 11 checagens do `audit-checklist.md` (skill `br-fiscal-note-emission`) ao código fiscal existente, corrigir os achados Críticos e Importantes com teste RED→GREEN e registrar os demais. A auditoria não adiciona funcionalidade.

## Decisões do usuário

- Escopo: só o que é código; piloto real vira roteiro.
- Achados: auditar **e corrigir** (Crítico e Importante entram em uma passada de correção; Minor vai para `TAREFAS.md`).
- Execução: inline na master, sem subagentes; a revisão final é auto-revisão declarada.

## A grade (motor × documento)

| Motor | NF-e | NFC-e | NFS-e |
|---|---|---|---|
| NFePHP (`Modules/Fiscal/NfePhp`) | sim | sim | |
| NFS-e Nacional (`Modules/Fiscal/Nfse`, lib `nfse-php`) | | | sim |

Não há segundo motor por documento (sem Focus nem Spedy), então as checagens 2 e 10 se reduzem a comparar o que o motor monta com o XML autorizado de fixture.

## Checagens e o que cada uma produz

| # | Checagem | Como roda offline |
|---|---|---|
| 1 | Espelhamento entre tipos de documento | Para cada campo fiscal, conferir NF-e, NFC-e e NFS-e (montadores e validadores prévios). |
| 2 | Espelhamento entre motores | Aplicável só como "mesma forma de bug" entre `MontadorDeXml` e `MontadorDeDps`. |
| 3 | Consistência de travas | Procurar todo método que lê estado, decide por transição e dispara efeito externo (SEFAZ/ADN, cobrança, e-mail, numeração); conferir lock e idempotência em cada um. |
| 4 | Completude de enum/status contra o contrato vivo | `InterpretadorDeResposta` e `InterpretadorDeNfse`: cada cStat/status com braço explícito; terminais nunca caem em "pendente". Fonte: schema da lib em `vendor/`, com URL e data no registro. |
| 5 | Comentários de "regra de negócio" como hipótese | Grep por "sempre", "nunca", "este negócio não" perto de lógica fiscal; classificar em citação legal, schema do fornecedor ou suposição do autor. |
| 6 | Nunca fabricar dado regulatório | Procurar default silencioso, `?? 0`, `empty()` e alíquota ou CST assumidos. |
| 7 | Documento impresso vem do XML autorizado | DANFE, DANFCE, DANFSe e e-mail devem ler o XML ou snapshot; teste renderizando de XML de fixture. |
| 8 | XSD contra XML autorizado | Offline com XML anonimizado de fixture, usando cópia do schema (sem lookahead) e `procNFe_v4.00.xsd`. Divergência é achado a reportar, nunca valor inventado. |
| 9 | Deriva de numeração contra a autoridade | **Roteiro** (exige SEFAZ). Offline: só conferir que o contador nunca retrocede e que restaurar backup não o põe atrás (insumo para a F8c). |
| 10 | Paridade de payload | Um `NotaFiscalData` único; comparar o que o motor monta com o XML autorizado de fixture. |
| 11 | Nada privado em repositório | `gh repo view --json visibility`, grep por CNPJ/CPF/chave reais nas fixtures e docs, `git check-ignore` nos modelos. |

## Entregáveis

1. **Relatório da auditoria** em `docs/auditoria/2026-10-13-f8a-auditoria-fiscal.md`: uma linha por achado (checagem, arquivo:linha, gravidade, efeito para o usuário, decisão), com a grade preenchida e as fontes do contrato vivo.
2. **Correções** dos Críticos e Importantes, cada uma com teste que falhou antes e passa depois.
3. **Roteiro de homologação real** (checagens 9 e parte da 8 e do 4 que precisam de SEFAZ/ADN), em `docs/auditoria/roteiro-homologacao-real.md`, para o usuário executar com certificado e CNPJ.
4. **Pendências Minor** em `TAREFAS.md`, seção "Pendências da F8a".
5. `PROGRESSO.md` atualizado.

## Gravidade

Grau pelo efeito para quem usa o produto, não pelo silêncio do spec:

- **Crítico:** nota errada autorizada, nota duplicada, número queimado sem registro, dado de outro tenant, segredo exposto.
- **Importante:** rejeição evitável, status terminal tratado como pendente, documento impresso divergente do autorizado, trava ausente em efeito externo.
- **Minor:** mensagem, polimento, cobertura de teste sem risco fiscal direto.

## Regras

- Dado ausente bloqueia com mensagem acionável; nunca se inventa valor para fechar um achado (Checagem 6).
- Antes de remover um ramo "morto", confirmar contra todos os documentos que compartilham o código (Checagem 1, sentido inverso).
- Itens já listados em `TAREFAS.md` como pendência de F3a/F4a que a auditoria confirmar entram no relatório com a mesma gravidade, sem duplicar o registro.
- Regra de negócio que for suposição do autor vai ao usuário para confirmar; a auditoria não decide.

## Fora de escopo

TLS da `nfse-php` e numeração única homologação/produção (F8b); backup e restauração (F8c); carga (F8d); Regime Normal, DIFAL, IBS/CBS (já são funcionalidade futura); emissão real em homologação (roteiro).

## Verificação

Suíte backend completa verde, Pint e PHPStan limpos. O relatório declara o que não pôde ser verificado (SEFAZ, ADN, PostgreSQL).
