# 01. Análise do Mecânica Pro

## 1. Como é construído

- **Frontend:** `mecanicapro/frontend`, Next 16.2, React 19, Tailwind 4, `next-themes`, `lucide-react` (usado em só 3 arquivos; os ícones do menu e dos indicadores são emoji), `recharts` para gráficos, shadcn estilo `base-nova` com `@base-ui/react`.
- **Backend:** Laravel (fora do escopo desta análise).
- **Protótipo original:** `oficina-system.html` (80 KB), HTML/CSS estático com a mesma paleta e classes (`.btn`, `.panel`, `.form-grid`, `.alert-banner`, `.stat-card`). Serve de **fonte de intenção** do design: define foco, hover e a paleta ampliada que o React perdeu.
- **Tema:** atributo `data-theme` no `<html>` via `next-themes`, `defaultTheme="dark"`, `enableSystem={false}`. As variáveis de cor ficam em `:root` (escuro) e `[data-theme='light']` (claro), só em `app/globals.css` (192 linhas).
- **Como o estilo é escrito (importante):** quase tudo é **estilo inline** (`style={{...}}`) nos componentes e páginas, lendo as variáveis CSS. Os componentes shadcn de `components/ui` (`button`, `input`, `select`, `card`...) usam tokens (`bg-primary`, `border-input`, `ring`) que o `globals.css` **nem define**, e quase não são usados: `button` aparece em 1 import; `input`, `select`, `textarea`, `card`, `dialog` em nenhum. As telas usam `<input>`, `<select>` e `<button>` crus.

| Elemento no código das telas | Ocorrências |
|---|---|
| `<input>` cru | 214 |
| `<select>` cru | 55 |
| `<button>` cru | 329 |
| `<textarea>` cru | 13 |
| `StatusPill` (importado) | 14 |
| `DataTable` | 7 |
| `StatCard` | 3 |

Consequência para o CRM: o "design" não está num componente, está **repetido**. Esta documentação extrai o padrão dominante de cada elemento para virar componente.

## 2. Números medidos (todo o código)

**Raio de borda:** 8 px (254 usos) · 6 (123) · 7 (76) · 10 (73) · 12 (54) · 999 (33) · 4 (18) · 14 (18).
**Tamanho de fonte:** 14 px (385) · 13 (370) · 12 (296) · 11 (126) · 16 (60) · 20 (50) · 15 (50) · 28 (40) · 18 (36).
**Peso:** 700 (250) · 600 (202) · 800 (172) · 500 (8) · 400 (4). Corpo é 400 por padrão; destaques usam 600 a 800.
**Espaçamento entre itens (gap):** 12 (80) · 8 (73) · 10 (54) · 16 (51) · 6 (26).
**Padding em texto livre:** `12px 16px` (82, células e cartões) · `9px 12px` (50, campos) · `10px 14px` (49) · `8px 12px` (37) · `4px 10px` (26, botões pequenos) · `11px 14px` (23) · `5px 14px` (21, itens do menu).
**Largura de borda:** 1 px (595 usos) · 2 px (7: filete colorido no topo de cartões, destaque do item ativo, estilos de impressão) · 3 px (2). Altura resultante: campo ≈37 px (padding 9+9, texto 14 px, borda 1+1); linha de tabela ≈42 px (padding 12+12, texto 14 px, borda 1).
**Camadas (z-index):** 1000 (29, modais) · 9999 (9, toast) · 999 · 2000 · 100 (menu) · 50 (barra superior) · 99 (véu do menu mobile).
**Sombras:** só em elementos flutuantes: `0 4px 20px rgba(0,0,0,.35)` (6), toast `0 4px 12px rgba(0,0,0,.3)`, modais grandes `0 12px 48px rgba(0,0,0,.5)`. Cartões e campos não têm sombra.
**Uso de variáveis de cor:** `--muted` 766 · `--border` 573 · `--text` 388 · `--accent` 331 · `--danger` 296 · `--card` 193 · `--success` 131 · `--bg` 77 · `--info` 48 · `--surface` 30.
**Cores fixas fora das variáveis:** `#000` (100, texto sobre âmbar) · `#fff` (42, texto sobre fundo sólido) · `rgba` de tons de marca para fundos translúcidos (por exemplo `rgba(229,57,53,.1)` 23×, `rgba(245,166,35,.15)` 16×).

## 3. Inventário de telas e padrões

- **Área logada** (`app/(dashboard)`): dashboard, clientes, veículos, produtos, serviços, venda balcão (PDV), contas a receber, ordens de serviço, agendamentos, fiscal (emitir, histórico), relatórios, alertas, faturas, usuários, empresa, auditoria, configurações, meus dados.
- **Autenticação** (`app/(auth)`): login, esqueci a senha, redefinir senha; layout dividido em dois painéis.
- **Administração do SaaS** (`app/saas-admin`): login próprio, oficinas, planos, cobranças, notificações, backup, VPS.
- **Padrões recorrentes:** página com título, subtítulo e busca; tabela dentro de cartão; formulário em cartão estreito centralizado; modal central; pílulas de status; cartões de indicadores; faixa de alerta; barra de uso do plano; gráfico de barras.

## 4. O que NÃO copiar (defeitos encontrados)

| # | Defeito | Evidência | Efeito | Tratamento no CRM |
|---|---|---|---|---|
| 1 | **Campos sem estado de foco** | Estilo inline com `outline: 'none'` e sem `:focus`. O protótipo definia borda âmbar e halo de 3 px; o React perdeu. | Usuário de teclado não vê onde está (WCAG 2.4.7). | Foco visível: borda e halo na cor da marca (`02-tokens.md`). |
| 2 | **Tema claro com cores semânticas do escuro** | `[data-theme='light']` só troca fundos, texto e borda. Âmbar como texto sobre branco dá **2,03:1**; verde 3,30:1; azul 3,68:1; vermelho 4,23:1. | Links, pílulas de contorno, números de estoque ilegíveis no claro (WCAG 1.4.3). | Tokens `*-text` próprios do tema claro. |
| 3 | **Borda de campo muito fraca** | `--border` sobre o cartão: 1,21:1 (escuro) e 1,27:1 (claro). | Campo some no fundo (WCAG 1.4.11 pede 3:1 para identificar o controle). | Token `border-strong` (≥3:1) só nos campos. |
| 4 | **Cinza secundário no limite** | `--muted` sobre `--card` escuro: 4,23:1; claro sobre fundo: 4,43:1. | Rótulos e placeholders ligeiramente abaixo de 4,5:1. | Cinza levemente mais claro/escuro (5,2:1 e 5,3:1). |
| 5 | **Texto branco em pílulas e toasts sólidos** | Branco sobre `#43a047` = 3,30:1; sobre `#1e88e5` = 3,68:1; sobre `#e53935` = 4,23:1. | Texto pequeno (11 px) abaixo do mínimo. | Fundos "fortes" (`#2e7d32`, `#1565c0`, `#c62828`) com branco ≥ 5:1. |
| 6 | **`<select>` com `appearance: none` sem seta** | Modais do SaaS admin usam `appearance: none` sem ícone substituto. | Não parece clicável. | Seta (chevron) própria. |
| 7 | **Ícones em emoji** | Menu, indicadores, logo. | Renderizam diferente em cada sistema operacional; sem controle de cor/tamanho. | `lucide-react` (já no CRM). |
| 8 | **Hover por JavaScript** | `onMouseEnter/Leave` alterando `style`. | Sem hover no toque e difícil de testar. | `:hover` em CSS/Tailwind. |
| 9 | **Item de menu pequeno** | Texto de 12 px, `padding: 5px 14px` (≈26 px de altura). | Alvo de toque pequeno. | 14 px e ≈36 px (versão do protótipo). |
| 10 | **Fonte via `@import` do Google** | Primeira linha do `globals.css`. | Bloqueia a renderização; depende de terceiro. | `next/font/google` (auto-hospedada no build). |
| 11 | **`pulse` e `slide-in` sem `prefers-reduced-motion`** | Animações sempre ligadas. | Incomoda quem reduz movimento. | Respeitar a preferência. |
| 12 | **Variante `dark:` do Tailwind morta** | `@custom-variant dark (&:is(.dark *))` enquanto o tema usa `data-theme`. | Classes `dark:` dos componentes shadcn nunca ativam. | Um único mecanismo de tema (classe `.dark`, já usado pelo CRM). |
