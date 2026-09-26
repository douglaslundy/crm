# Plataforma Fiscal + CRM: instruções do projeto

SaaS por assinatura. Cada CNPJ emite NF-e, NFC-e e NFS-e (frontend e API pública) e usa um CRM integrado.

**Projeto mestre:** `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`.

**Base fiscal:** `docs/referencia-fiscal/00-INDICE.md`. Abrir só o arquivo do assunto.

**Skill fiscal:** `br-fiscal-note-emission` (`~/.claude/skills/`). Ler antes de qualquer código fiscal.

## Regras invioláveis

- **Nunca chutar um valor fiscal.** Dado ausente bloqueia com mensagem acionável. Não há default. Zero é valor válido: usar `=== null`, nunca `empty()` ou `?? 0`.
- **Regra tributária fica fora dos providers.** O motor só traduz formato.
- **Frontend e API pública chamam as mesmas Actions.** Nenhuma regra fica duplicada no controller.
- **Todo Model de tenant tem Global Scope por `tenant_id`.** Todo endpoint novo ganha um teste de isolamento.
- **Mudanças de schema só por migration.** Segredos sempre criptografados. Nenhum dado real em fixtures.
- **`declare(strict_types=1)` em todo PHP. TypeScript em modo strict, sem `any`.**
- **Mecânica Pro é só leitura:** `../mecanicapro` é consultado, nunca editado.

## Gestão de contexto (executar em toda sessão)

1. **Ao iniciar:** ler `PROGRESSO.md` e retomar pela "Próxima tarefa". Ler **só** os arquivos da "Contexto necessário".
2. **Durante:** a cada passo concluído, atualizar `PROGRESSO.md` com o que foi feito (1 a 3 linhas), os arquivos e as decisões que não são óbvias.
3. **Ao concluir:**
   - Marcar a tarefa como concluída.
   - Ler a próxima em `TAREFAS.md`.
   - Reescrever a "Contexto necessário" só com o que a próxima tarefa usa.
   - Recomendar `/compact` ou `/clear`.
4. **Economia:**
   - Leitura parcial ou grep em vez de abrir arquivos inteiros.
   - Não repetir no chat um conteúdo já lido; citar o caminho.
   - Resumir em `PROGRESSO.md` o que crescer demais.
5. **Retomada** ("continue de onde parou"): `PROGRESSO.md` → tarefa em andamento → só o contexto indicado → seguir sem pedir reexplicação.
