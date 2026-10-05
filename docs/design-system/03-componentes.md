# 03. Componentes

Medidas do Mecânica Pro (px) e a receita equivalente em Tailwind v4 para o CRM. "Original" = como está em produção; "CRM" = o que adotar (diferenças marcadas com ⚠ e explicadas em `01-analise-mecanicapro.md` §4). Tokens citados estão em `02-tokens.md`.

## 1. Campos de formulário

### 1.1 Campo de texto (`<input>`, `<select>` nativo, `<textarea>`)

| Propriedade | Original | CRM (Tailwind) |
|---|---|---|
| Largura | `100%` | `w-full` |
| Padding | `9px 12px` | `px-3 py-[9px]` |
| Altura resultante | ≈37 px | `h-[37px]` (ou deixar o padding definir). `textarea`: `min-h-[80px]` |
| Fonte | Barlow 14 px, peso 400 | `text-sm` |
| Raio | 8 px | `rounded-lg` |
| Borda | `1px solid var(--border)` | ⚠ `border border-input` (**`border-strong`**, ≥ 3:1) |
| Fundo | `var(--bg)` (mais escuro que o cartão: efeito rebaixado); em login, busca e filtros: `var(--card)` | `bg-background` (formulário) · `bg-card` (busca/filtro/login) |
| Texto | `var(--text)` | `text-foreground` |
| Placeholder | cinza do navegador | `placeholder:text-muted-foreground` |
| Foco | ⚠ **nenhum** (`outline: none`). Protótipo: borda `accent` + halo `0 0 0 3px rgba(245,166,35,.12)` | `focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-primary/25 outline-none`, `transition-colors` 0,18 s |
| Erro | só `border-color: var(--danger)` | `aria-invalid:border-danger aria-invalid:ring-[3px] aria-invalid:ring-danger/20` + mensagem (§1.3) |
| Desabilitado | `opacity: .5–.6; cursor: not-allowed` | `disabled:opacity-60 disabled:cursor-not-allowed` |
| Somente leitura | mesmo visual do desabilitado | idem |
| `<select>` | `appearance: none` ⚠ sem seta | `appearance-none pr-9` + chevron `lucide ChevronDown` (16 px, `muted-foreground`) posicionado à direita; `<option>` com fundo `card` |
| Com ícone/ação à direita (senha) | `padding-right: 44px`, botão absoluto `right: 12px` | idem |
| Campo de busca | `padding 8px 14px`, largura 280 (OS: 240), fundo `card` | `h-9 px-3.5 w-[280px]` |

### 1.2 Rótulo

| Variante | Medidas | Uso |
|---|---|---|
| **Padrão** | 13 px, peso 400, `muted-foreground`, `display:block`, `margin-bottom: 4px` (6 no login) | formulários de cadastro e login |
| **Modal/Admin** | 12 px, peso 700, MAIÚSCULAS, `letter-spacing .06em`, `muted`, `gap 6px` até o campo | modais do painel administrativo |
| **Seção** | 11–12 px, peso 600–700, MAIÚSCULAS, `.06–.08em`, `muted`, `margin-top 4–8px` | título de bloco dentro do formulário ("Dados da oficina", "Veículos") |

Obrigatório: asterisco `*` no fim do rótulo; no modal, `*` em `danger-text` com `margin-left 3px`. Dica: 11 px `muted`, abaixo do campo.

### 1.3 Erro de campo

Mensagem logo abaixo: 12 px, `danger-text`, `margin-top 4px`. Caixa de erro do formulário (login, modal): fundo `danger/10`, borda 1 px `danger`, raio 8, padding `10px 14px`, texto 14 (modal 13) `danger-text`, `margin-bottom 20`.

### 1.4 Checkbox e radio

Nativo com `accent-color: var(--primary)`; tamanho 14–16 px; rótulo ao lado em 13–14 px `muted`, `gap 8px`, cursor pointer.

### 1.5 Grade de formulário

`display:grid; gap:16px`. Duas colunas no desktop (`.rform`), três quando couber (`.rform-3`), linha "campo + campo + botão" (`.rform-auto` = `1fr 1fr auto`). Campos largos ocupam a linha toda (`grid-column: 1 / -1`). Abaixo de 640 px: uma coluna. Linha de itens repetidos (ex.: veículos): colunas `2fr 80px 100px 1fr auto`, `gap 8px`, `align-items:end`, rótulo só na primeira linha.

## 2. Botões

| Variante | Original | CRM |
|---|---|---|
| **Primário** | fundo `accent`, texto `#000`, `border: none`, raio 8, `padding 10px 28px` (formulário, 16 px display 800) · `9px 24px` (modal, 14 px display 700) · `12px` largura total (login, 17 px) · `8px 16px` (barra superior, 14 px display 700). Hover (protótipo): `#e8902a`, sobe 1 px, sombra `0 4px 14px rgba(245,166,35,.3)` | `bg-primary text-primary-foreground hover:bg-primary-hover font-display tracking-[0.03em] rounded-lg` · tamanhos: `lg` (10×28/16 px), `md` (9×24/14 px), `sm` (8×16/14 px) · `active:translate-y-px` |
| **Secundário/fantasma** | transparente, borda `1px solid var(--border)`, texto `muted`, raio 8, `padding 9px 20px`, 14 px (modal: "Cancelar") | `border border-border text-muted-foreground hover:bg-hover hover:text-foreground` |
| **Ação de tabela** ("Ver") | `background:none`, borda `border`, `muted`, raio 6, `padding 4px 12px`, 13 px | variante `sm` do secundário, raio 6 |
| **Suave de marca** ("＋ Adicionar") | fundo `primary` 12%, borda `1px solid accent`, texto `accent`, raio 6, `padding 4px 12px`, 13 px | `bg-primary/12 border-primary text-primary-text rounded-md px-3 py-1 text-[13px]` (⚠ texto em `primary-text` para o claro) |
| **Perigo** | protótipo: fundo `danger-bg` (`#2a1212`), texto `danger`, borda `danger` 30%; hover fundo `danger` + texto branco. Produção: só ícone `✕` em `danger`, 18 px | `bg-danger/10 border border-danger/30 text-danger-text hover:bg-danger-strong hover:text-white` |
| **Sucesso** | protótipo: espelho do perigo com `success` | idem com `success` |
| **Ícone** (✕, ☰) | sem fundo/borda, 18–20 px, `muted`, padding 4 | `size-8 rounded-md hover:bg-hover` |
| **Desabilitado/carregando** | fundo `muted` (login/formulário) ou `accent` 40% (modal), `cursor: not-allowed`; texto "Salvando..." / "⟳ Verificando..." | `disabled:opacity-50 disabled:cursor-not-allowed`; ícone `Loader2` girando |
| **Sucesso transitório** | login vira verde com "✓ Acesso liberado!" | `bg-success-strong text-white` |

Foco de botão: o original não tem. CRM: `focus-visible:ring-[3px] ring-ring/50`.

## 3. Pílulas de status (`StatusPill`)

- Forma: `inline-flex`, `padding 3px 11px`, raio 999, **Barlow Condensed 11 px, peso 700, MAIÚSCULAS, `letter-spacing .04em`**.
- **Sólida** (padrão): fundo + texto: `success` → branco; `danger` → branco; `info` → branco; `accent` → `#1a1400`; `muted` → branco. ⚠ No CRM os fundos sólidos usam `*-strong` (branco ≥ 5,1:1) e `muted-strong`; a sólida âmbar mantém texto preto (10,4:1).
- **Contorno** (`pill-outline`): fundo transparente, borda 1,5 px e texto na cor semântica. Para distinguir dois status que dividem a mesma cor (CANCELADA × ORÇAMENTO_RECUSADO). ⚠ Texto em `*-text`.
- **Mapa de cor por estado** (mesma lógica para o CRM): ativo/regular/concluída/autorizada/paga/normal → verde; devedor/vencida/cancelada/rejeitada/erro/crítico/sem estoque → vermelho; aberta → azul; em andamento/baixo/processando/pendente/contingência → âmbar; inativo/rascunho/aguardando/sem chave → cinza. O rótulo **sempre vem em texto**; a cor nunca é a única informação.

## 4. Cartões

| Tipo | Medidas |
|---|---|
| **Cartão padrão** | `bg-card`, borda 1 px `border`, raio 12, padding 24 |
| Cartão de formulário | idem com padding 32, `max-width 760px`, centralizado |
| Painel (protótipo) | raio 10, padding `20px 22px`, `margin-bottom 18` |
| Título do cartão | `h3` Barlow Condensed 18/800, `margin-bottom 16` (20 sobre gráfico) |
| **KPI normal** | cartão com filete de 2 px no topo na cor semântica; título 12/600 MAIÚSCULAS `.05em` `muted`; valor JetBrains Mono 26/700; legenda 12 `muted`; ícone decorativo 28 px a 12% de opacidade, canto direito |
| **KPI compacto** | raio 10, padding `26px 14px`; título 10, valor 18, legenda 10, ícone 20; hover: borda vira a cor do KPI (só se for link) |
| Banner de plano | cartão raio 10, padding `12px 16px`, `max-width 380px`, linha com rótulo 13 `muted` e `N / limite` em mono 13/600; barra abaixo (§8) |

## 5. Tabela de dados (`DataTable`)

- Contêiner: `bg-card`, borda 1 px, raio 12, `overflow:hidden`; rolagem horizontal interna.
- Cabeçalho (`th`): padding `10px 16px`, 12 px, peso 600, MAIÚSCULAS, `letter-spacing .05em`, `muted`, fundo `card`, borda inferior 1 px, `white-space: nowrap`.
- Célula (`td`): padding `12px 16px`, 14 px, `text`, borda inferior 1 px.
- Linha clicável: `cursor:pointer`. ⚠ O original **não tem hover** de linha; CRM: `hover:bg-hover`.
- Linha com débito/erro: fundo `danger` 6%.
- Vazio: uma célula `colSpan` centralizada, padding 40, `muted`.
- Carregando: 5 linhas de esqueleto (barra `height 14`, largura 70%, fundo `border`, raio 4, `pulse 1.5s`).
- Números e documentos: `font-mono`. Ação por linha: botão "Ver" (§2).
- Dentro de um cartão: o original aninha tabela (com borda) dentro de cartão (com borda). ⚠ CRM: remover a borda externa da tabela quando ela estiver dentro de cartão.

## 6. Modal

Véu `rgba(0,0,0,.6)` em tela cheia (`z 1000`), centraliza, `padding 24`; clique no véu fecha. Caixa: `bg-card`, borda 1 px, raio 12, `width:100%; max-width:560px` (formulários maiores: 640–760), `max-height: calc(100vh - 48px)` com rolagem, padding `28px 32px`. Cabeçalho: título display 20/800 e botão `✕` 20 px à direita, `margin-bottom 24`. Rodapé: `display:flex; gap:12; justify-content:flex-end; margin-top:8; padding-top:16; border-top:1px solid border`; ordem: secundário "Cancelar", depois primário. Estados de erro como §1.3. Acessibilidade ⚠: no modal lido por inteiro (`EditOficinaModal`, um dos 29 usos da camada 1000) não há foco preso nem `Esc`; no CRM usar o `Dialog` do `@base-ui/react` (já no projeto), com `role="dialog"`, `aria-labelledby` e foco inicial no primeiro campo.

## 7. Toast e banners

**Toast:** `position:fixed; bottom:24; right:24; z 9999`, empilhados com `gap 8`. Cada um: `padding 12px 20px`, raio 8, 14 px peso 500, texto branco, largura 260–400, sombra `0 4px 12px rgba(0,0,0,.3)`, entra por `slide-in 0.2s`, some ao clicar. Fundo: sucesso `success-strong`, erro `danger-strong`, info `info-strong` (⚠ originais `#43a047`/`#e53935`/`#1e88e5` com branco ficam abaixo de 4,5:1). O CRM já usa `sonner`: aplicar os mesmos tokens e medidas ao tema do `sonner`.

**Banner de alerta:** fundo `danger` 10%, borda 1 px `danger` 30%, raio 8, padding `10px 16px` (protótipo 12×16), `margin-bottom 20`. Conteúdo: ícone `⚠` 16 px + texto 13/600 em `danger-text` + link "Ver todos →" em `primary-text` 13 + botão `×` 18 px `muted` à direita (dispensável). Para aviso (âmbar) e info (azul) trocar a cor semântica.

## 8. Barras de progresso e gráfico

- **Barra de uso do plano:** altura 6, raio 3, trilho `border`, preenchimento com `width` em %, `transition width .4s ease`. Cor por faixa: ≥ 100% `danger`, ≥ 80% `primary`, senão `success`.
- **Barra de estoque:** altura 4, raio 2, mesma lógica; estado crítico pulsa (`pulse-bar 1.2s`); número ao lado em mono 13 na cor do estado; largura mínima 120.
- **Gráfico de barras** (`recharts`): altura 200, `barCategoryGap 30%`, topo arredondado `[4,4,0,0]`; a maior barra em `primary`, as demais `primary` 30%; eixo X com tick 12 px `muted`, sem linha de eixo nem de tick; eixo Y oculto; tooltip em cartão (`card`, borda, raio 8, padding `10px 16px`, valor `primary-text` peso 700).

## 9. Menu lateral (`Sidebar`)

- **Contêiner:** `position:fixed; left:0; top:0; height:100vh; width:230px`, fundo `surface`, borda direita 1 px, `z 100`. Mobile (< 768 px): fora da tela (`translateX(-100%)`), abre com `translateX(0)` em 0,25 s, com véu `rgba(0,0,0,.5)` (`z 99`).
- **Marca:** bloco `padding 12px 16px 10px` (protótipo `18px 20px`), borda inferior; quadrado 30×30 (protótipo 34), raio 7 (6), fundo `primary`, emoji/ícone 15; nome Barlow Condensed 16/800 (protótipo 20/800).
- **Item:** `display:flex; gap:9`; padding `5px 14px` (compacto, produção) ou `9px 14px` com `margin 1px 6px` e raio 7 (protótipo); fonte 12 (protótipo 14/500); cor `muted`; **ativo**: texto `text`, peso 600, fundo `primary` 8% (protótipo: gradiente `rgba(245,166,35,.15) → .04`), `border-left: 2px solid primary`; hover (protótipo): fundo `card`, texto `text`; transição 0,15–0,18 s. ⚠ **CRM adota a versão do protótipo (14 px, ≈36 px de altura)**; usar a compacta só se o menu passar de ~12 itens.
- **Ícone:** 15–16 px, `width 20`, centralizado. ⚠ CRM: `lucide-react`.
- **Badge numérico:** fundo `danger-strong`, texto branco, 11/700, raio 999, `padding 1px 6px`, `min-width 18`, "99+".
- **Item ativo:** o mais específico vence (maior `href`), para `/configuracoes` não acender junto com `/configuracoes/whatsapp`.
- **Rodapé do usuário:** `padding 8px 14px`, borda superior; avatar 28 px redondo (fundo `card`, borda, inicial 12); nome 12/600 truncado, papel 10 `muted`; botão "Sair" largura total, borda `border`, `muted`, raio 6, `padding 4px 0`, 12 px.

## 10. Barra superior (`Topbar`)

`height:60px` (protótipo 58), `position:sticky; top:0; z 50`, fundo `surface`, borda inferior 1 px, `padding 0 24px`, `justify-content:space-between`. Esquerda: botão `☰` (só mobile, 20 px) + **migalhas** 14 px (`muted`, separador `/` 12, **última em `text` peso 600**). Direita (`gap 10`): botão de tema (borda `border`, raio 6, `padding 4px 8px`, 16 px, ☀️/🌙 ⚠ → ícones `Sun`/`Moon`); **ação contextual** da página (botão primário `sm`, display 700/14, ex.: "+ Novo Cliente"); **avatar com nome** (pílula: borda 1 px, raio 20, `padding 4px 10px 4px 4px`; círculo 28 com inicial em `primary-text`; nome 12/600 e "Meus dados" 10 `muted`; hover/ativo: borda e fundo da marca).

## 11. Telas de autenticação

Duas colunas (`display:flex; min-height:100vh`). **Esquerda** (flex 1, fundo `surface`, `padding 60`, centralizada em coluna; abaixo de 900 px some): gradiente radial `circle at 30% 50%, primary 8% → transparente 60%`; quadrado 72×72 raio 18 em `primary` com ícone; título 36/800 display; subtítulo 16 `muted` (`margin-top 8`); lista de benefícios (`margin-top 40`, `gap 12`, 15 px `muted`, `✓` em `primary`). **Direita** (`width:480px`, `padding 48`, centraliza o formulário de `max-width 380`; abaixo de 480 px `padding 24`). Título 28/800; subtítulo 14 `muted` `margin-bottom 32`; campos com `padding 10px 14px`, 15 px, fundo `card`; `gap 16`; "Lembrar de mim" e "Esqueci minha senha" na mesma linha (14 px `muted`); botão primário largura total `padding 12`, display 17/800.
