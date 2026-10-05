# 05. Plano de aplicação no CRM

**Estado: proposta. Nenhum arquivo do frontend do CRM foi alterado.** Aplicar o layout é uma mudança de interface em todo o frontend; ela segue o fluxo do projeto (spec → plano → execução) e só começa com a sua aprovação das decisões da §3.

## 1. Onde o CRM está e para onde vai

| Aspecto | CRM hoje | Alvo (Mecânica Pro, com correções) |
|---|---|---|
| Paleta | shadcn neutra em cinza puro (`oklch`), sem cor de marca | âmbar `#f5a623` + cinza-azulado (`02-tokens.md`) |
| Tema | `.dark` por classe; `defaultTheme="system"` | mesma mecânica (classe); padrão a decidir (§3, D1) |
| Fonte | Geist / Geist Mono | Barlow, Barlow Condensed, JetBrains Mono |
| Raio | `0.625rem` (10 px) em tudo | 8 px controles, 12 px cartões, 999 pílulas |
| Campo | `h-8` (32 px), sem fundo próprio, borda `input` fraca | ≈37 px, fundo "rebaixado", borda forte, foco com halo |
| Botão | shadcn `base-nova`, `h-8`, texto `text-sm` | primário âmbar com fonte display; variantes secundário/suave/perigo/sucesso |
| Status | `StatusDaNota` com `bg-*/15 text-*` e rótulo em texto | pílulas sólidas/contorno com tokens `*-strong`/`*-text` |
| Tabela | listas `<ul>` com `divide-y` (ex.: notas, inutilizações) | `DataTable` em cartão (cabeçalho em maiúsculas, esqueleto, vazio) |
| Menu | `w-60`, `text-sm`, ícones lucide, sem marca | 230 px, marca, item ativo com filete âmbar, badge, rodapé do usuário |
| Barra superior | `h-14`: nome da empresa + usuário + tema + sair | 60 px: migalhas, ação contextual, tema, avatar |
| Mobile | `BottomNav` fixa | gaveta (a decidir, D4) |
| Ícones | lucide | lucide (o Mecânica Pro usa emoji; não copiar) |

## 2. O que a medição do CRM revela (reduz o esforço)

- **Visual centralizado em primitivos:** `Button` em 37 arquivos, `Input` em 21, `Card` em 19, `Campo` em 22, `SelectNativo` em 13, `sonner` em 27. Só **8** `<input>/<select>/<button>` crus fora deles. Trocar os primitivos e os tokens muda quase tudo de uma vez (no Mecânica Pro isso exigiria editar 600 pontos).
- **Cores semânticas por utilitário:** 87 `text-muted-foreground`, 43 `text-danger`, 26 `bg-danger/10`, 6 `text-warning`, 4 `text-info`, 3 `text-success`. Com o mapeamento `--color-danger → danger-text` etc. (`02-tokens.md` §5) todos ficam acessíveis sem editar telas.
- **Testes:** 34 arquivos, **nenhum** depende de classe CSS (usam papéis e texto). O risco de quebrar teste por mudança visual é baixo.
- **Superfície:** 90 componentes/páginas `.tsx` (sem contar testes).

## 3. Decisões que dependem de você

| # | Decisão | Opções | Recomendação |
|---|---|---|---|
| D1 | **Tema padrão** | (a) escuro fixo como o Mecânica Pro, (b) escuro por padrão com alternância lembrada, (c) seguir o sistema | **(b)**: identidade escura e o claro continua disponível. Hoje o CRM segue o sistema |
| D2 | **Fidelidade** | (a) réplica exata do Mecânica Pro, (b) mesmo visual **com as 12 correções** de `01-analise-mecanicapro.md` §4 | **(b)**: no escuro é quase idêntico; no claro a réplica deixaria texto âmbar com 2:1 |
| D3 | **Marca do CRM** | nome e ícone do produto (hoje "Plataforma Fiscal") com a mesma cor e tipografia | Preciso do **nome** e, se houver, do logotipo |
| D4 | **Navegação no celular** | (a) gaveta lateral como o Mecânica Pro, (b) manter a barra inferior atual | **(a)**: o menu do proprietário já tem 8 itens; a barra inferior fica apertada e só piora quando o CRM crescer |
| D5 | **Ícones** | (a) lucide, (b) emoji como o Mecânica Pro | **(a)**: consistência e cor controlável; emoji muda em cada sistema |
| D6 | **Densidade do menu** | (a) regular do protótipo (14 px, ≈36 px por item), (b) compacta de produção (12 px, ≈26 px) | **(a)** enquanto o menu tiver até ~12 itens |

## 4. Fases e tarefas previstas

Cada fase termina verde (testes, tipos, lint, build) e pode ser vista no ambiente de acompanhamento da VPS antes de seguir.

**Fase 1: tokens, fontes e tema** (≈3 tarefas)
1. `globals.css`: tokens dos dois temas, `@theme inline`, `prefers-reduced-motion`, animações (`pulse`, `slide-in`).
2. `layout.tsx`/`providers.tsx`: `next/font` (3 famílias), tema padrão (D1).
3. Teste de contraste automatizado: lê os tokens do `globals.css` e verifica os pares de `02-tokens.md` (texto ≥ 4,5:1; borda de campo e foco ≥ 3:1). Falha o CI se alguém trocar uma cor e quebrar a acessibilidade.

**Fase 2: primitivos** (≈10 tarefas)
`Button` (variantes e tamanhos), `Input`/`SelectNativo`/`TextareaNativo` (altura, foco, erro, chevron), `Label`/`Campo` (rótulo padrão, dica, erro), `Card` e `CardTitulo`, `Pilula` (status, sólida e contorno), `StatCard`, `DataTable`, `Dialog` (modal), `Banner`, `BarraDeProgresso`, tema do `sonner`. Cada um com teste de comportamento e, quando houver estado visual relevante, teste de atributos (`aria-invalid`, `aria-current`, `role`).

**Fase 3: esqueleto** (≈5 tarefas)
`Sidebar` (marca, item ativo, badge, rodapé), `Topbar` (migalhas, ação contextual, tema, avatar), gaveta no mobile (D4), `AppShell`, componente `CabecalhoDePagina` (título, subtítulo, ações), layout de autenticação em dois painéis.

**Fase 4: migração das telas** (≈8 tarefas, por grupo)
Autenticação (login, cadastro, senha) · painel · cadastros (clientes, produtos, serviços, pendências, CSV) · notas (lista com `DataTable` e `Pilula`, formulário, detalhe, inutilização) · configurações fiscais e onboarding · usuários · área da plataforma (planos, empresas). Em cada grupo: trocar o markup ad-hoc por primitivos, sem mudar comportamento.

**Fase 5: verificação visual e fechamento** (≈2 tarefas)
Navegador real (Chrome) nos dois temas e em 360, 768 e 1280 px, em todas as rotas; checagem de foco por teclado; conferência contra `03-componentes.md`; atualização do ambiente de acompanhamento na VPS; registro em `PROGRESSO.md`.

Total estimado: **≈28 tarefas**, sem mudança de regra de negócio, de API ou de banco.

## 5. Como vamos verificar

- **Automático:** teste de contraste dos tokens; testes de comportamento dos primitivos; suíte existente (34 arquivos) verde; `tsc`, `eslint`, `next build`.
- **Visual (manual, no navegador):** matriz rota × tema × largura; capturas antes/depois guardadas no repositório de documentação; lista de conferência de `03-componentes.md` (altura do campo ≈37 px, raio, foco, hover, esqueleto, vazio).
- **Acessibilidade:** navegação só por teclado nas telas principais; foco visível; `prefers-reduced-motion`; modal com foco preso e `Esc`.

## 6. Riscos

| Risco | Efeito | Mitigação |
|---|---|---|
| Barlow é mais estreita que Geist | quebras de linha e larguras de coluna mudam | revisar tabelas e formulários na Fase 5; `min-w-0`/`truncate` onde faltar |
| Mapear `text-danger` para a variante `-text` | `bg-danger` sólido ficaria claro demais | fundo sólido só com `bg-*-strong` (regra documentada; revisar usos de `bg-danger` sem opacidade) |
| Troca de mecanismo de tema | flash de tema errado na carga | manter `next-themes` com classe e `suppressHydrationWarning` (já existem) |
| Modal novo | regressão em fluxos com foco | usar o `Dialog` do `@base-ui/react`, que já traz foco preso e `Esc` |
| Escopo "só visual" crescer | misturar mudança de comportamento | cada tarefa só troca apresentação; qualquer ajuste de regra vira item à parte |

## 7. Critério de pronto

Todas as rotas do CRM usam os tokens e primitivos novos; os dois temas passam no teste de contraste; as telas conferem com `03-componentes.md` nas três larguras; a suíte inteira passa; o ambiente de acompanhamento mostra o resultado para sua avaliação.

## 8. Próximo passo

Você responder D1 a D6 (principalmente **D3, o nome da marca**). Com isso eu escrevo a spec e o plano de implementação e executo por fases, mostrando cada fase no ambiente de acompanhamento.
