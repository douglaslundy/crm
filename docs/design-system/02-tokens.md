# 02. Tokens de design

Valores **originais** do Mecânica Pro e valores **adotados no CRM**. Onde diferem, a coluna "Por quê" explica (quase sempre contraste). Razões de contraste calculadas pela fórmula de luminância relativa do WCAG 2.x. Alvos: texto normal ≥ 4,5:1; contorno de controle e foco ≥ 3:1.

## 1. Cores

### 1.1 Tema escuro (padrão do Mecânica Pro)

| Token | Original | CRM | Contraste (CRM) | Uso |
|---|---|---|---|---|
| `background` (`--bg`) | `#0e0f11` | `#0e0f11` | base | fundo da página e **dentro dos campos** |
| `surface` | `#161719` | `#161719` | base | menu lateral, barra superior |
| `card` | `#1c1e21` | `#1c1e21` | base | cartões, tabelas, modais, popovers |
| `hover` | (não existia) | `#23262b` | texto 12,6:1 · muted 4,75:1 | fundo de hover em itens e botões fantasma |
| `border` | `#2a2d33` | `#2a2d33` | decorativa | divisórias, contorno de cartão |
| `border-strong` | (não existia) | `#636978` | 3,04:1 no cartão · 3,49:1 no fundo | **contorno de campos** (WCAG 1.4.11) |
| `foreground` (`--text`) | `#e8eaf0` | `#e8eaf0` | 13,9:1 cartão · 15,9:1 fundo | texto |
| `muted-foreground` (`--muted`) | `#7a8090` | `#8a90a0` | 5,2:1 cartão · 6,0:1 fundo · 5,6:1 surface | rótulos, subtítulos, placeholder |
| `primary` (`--accent`) | `#f5a623` | `#f5a623` | 8,2:1 no cartão | marca: botão primário, item ativo, links |
| `primary-hover` | `#e8902a` (protótipo) | `#e8902a` | — | hover do botão primário |
| `primary-foreground` | `#000` | `#000000` | 10,4:1 sobre `#f5a623` | texto sobre âmbar |
| `ring` | (não existia) | `#f5a623` | 8,2:1 | foco |
| `danger` | `#e53935` | `#e53935` | preenchimento | contorno/ícone/barra |
| `danger-strong` | (não existia) | `#c62828` | branco 5,6:1 | fundo sólido de pílula e toast |
| `danger-text` | `#e53935` | `#f06260` | 5,3:1 cartão · 4,8:1 em banner | texto de erro |
| `success` / `success-strong` / `success-text` | `#43a047` | `#43a047` / `#2e7d32` / `#5cb860` | branco 5,1:1 · texto 6,8:1 · banner 5,9:1 | idem |
| `info` / `info-strong` / `info-text` | `#1e88e5` | `#1e88e5` / `#1565c0` / `#4dabf5` | branco 5,8:1 · texto 6,7:1 · banner 6,0:1 | idem |
| `warning` | = `accent` | = `primary` | — | alerta, estoque baixo, "em andamento" |
| `muted-strong` | `#7a8090` (pílula) | `#5f6673` | branco 5,8:1 | fundo sólido de pílula neutra |

### 1.2 Tema claro

| Token | Original | CRM | Contraste (CRM) | Uso |
|---|---|---|---|---|
| `background` | `#f4f5f7` | `#f4f5f7` | base | fundo da página e dos campos |
| `surface` / `card` | `#ffffff` | `#ffffff` | base | menu, barra, cartões |
| `hover` | (não existia) | `#eef0f3` | texto 14,9:1 · muted 5,1:1 | hover |
| `border` | `#e2e4e9` | `#e2e4e9` | decorativa | divisórias |
| `border-strong` | (não existia) | `#868b97` | 3,41:1 no cartão · 3,13:1 no fundo | contorno de campos |
| `foreground` | `#1a1c21` | `#1a1c21` | 17,1:1 cartão · 15,6:1 fundo | texto |
| `muted-foreground` | `#6b7280` | `#5f6673` | 5,8:1 cartão · 5,3:1 fundo | secundário (o original dava 4,43:1 no fundo) |
| `primary` | `#f5a623` | `#f5a623` | **só como preenchimento** (texto sobre ele é preto: 10,4:1) | botão, item ativo (fundo) |
| `primary-text` (novo) | `#f5a623` (2,03:1 no branco!) | `#92400e` | 7,1:1 cartão · 6,5:1 fundo | links, números e pílulas de contorno em âmbar |
| `ring` | — | `#92400e` | 7,1:1 | foco (borda); o halo continua âmbar translúcido |
| `danger-text` | `#e53935` (4,23:1) | `#b71c1c` | 6,6:1 cartão · 5,7:1 em banner | texto de erro |
| `success-text` | `#43a047` (3,30:1) | `#1b5e20` | 7,9:1 · 7,1:1 em banner | |
| `info-text` | `#1e88e5` (3,68:1) | `#0d47a1` | 8,6:1 · 7,7:1 em banner | |
| `*-strong` | — | iguais às do escuro | branco ≥ 5,1:1 | fundo sólido |

**Regra de uso:** cor semântica **como fundo ou contorno/ícone** usa `danger`/`success`/`info`/`primary`. Cor semântica **como texto** usa sempre `*-text` (no claro ela é bem mais escura que a de marca).

### 1.3 Translúcidos (idênticos nos dois temas)

Fundos suaves por cor semântica, escritos como a cor + opacidade (no Tailwind v4: `bg-primary/10`):

| Uso | Opacidade |
|---|---|
| Linha de tabela com débito (`danger-row`) | `danger` 6% |
| Item ativo do menu, hover do avatar, contorno do botão "adicionar" | `primary` 8% (menu) · 12% (botão suave) |
| Banner de alerta | fundo `danger` 10%, borda `danger` 30% |
| Caixa de erro de formulário | fundo `danger` 10%, borda `danger` 100% |
| Halo de foco | `primary` 12% (original) → **25%** no CRM (visível no claro) |
| Véu do modal | preto 60% (menu mobile: 50%) |
| Barra do gráfico não destacada | `primary` 30% |

## 2. Tipografia

| Papel | Família | Pesos | Onde |
|---|---|---|---|
| Texto | **Barlow** | 400, 500, 600 | corpo, campos, tabelas |
| Títulos e botões | **Barlow Condensed** (`font-display`) | 700, 800 | título da página 28/800, título de cartão 18/800, título de modal 20/800, botões primários, pílulas, nome da marca |
| Números | **JetBrains Mono** (`font-mono`) | 400, 500 | valores em R$, CPF/CNPJ, quantidades, KPI |

Escala usada (px → função):

| Tamanho | Uso | Peso típico |
|---|---|---|
| 36 | título do painel de marca (login) | 800 display |
| 28 | **título da página** (`h1`), título do login | 800 display |
| 26 | valor do KPI normal | 700 mono |
| 20 | título de modal | 800 display |
| 18 | título de cartão (`h3`); valor do KPI compacto | 800 display · 700 mono |
| 16 | botão primário grande, marca do menu | 800 display |
| 15 | texto do painel de marca, campos do login | 400 |
| **14** | **texto base**: campo, célula de tabela, subtítulo, botão secundário | 400 |
| **13** | **rótulo de campo**, botão pequeno, texto de banner, aviso | 400–600 |
| 12 | cabeçalho de tabela (maiúsculas), menu lateral, mensagem de erro de campo, legenda de KPI | 600 |
| 11 | pílula, dica de campo, rótulo de seção (maiúsculas) | 700 |
| 10 | KPI compacto (título/legenda), papel do usuário | 600 |

`letter-spacing`: cabeçalho de tabela e rótulo de KPI `0.05em`; rótulo de seção `0.06–0.08em`; pílula `0.04em`; botão display `0.03em`. Altura de linha: padrão do navegador (≈1,2 para Barlow).

## 3. Espaçamento

Base de 4 px; os valores realmente usados:

| Contexto | Valor |
|---|---|
| Entre campos de um formulário (grade) | **16** (14 no protótipo) |
| Entre campos de uma mesma linha compacta | 8 |
| Entre KPIs | 12 |
| Entre cartões e seções | 24 |
| Padding de cartão | **24** · formulário em cartão 32 · modal 28 × 32 · painel do protótipo 20 × 22 |
| Padding de célula | **12 × 16** (cabeçalho 10 × 16) |
| Padding de campo | **9 × 12** (busca/filtro 8 × 14 · login 10 × 14) |
| Padding da página (`main`) | **24** (16 no mobile) |
| Título → conteúdo | `mb 24` (16 quando há filtros logo abaixo) |

## 4. Raios, bordas, sombras, camadas, movimento

| Item | Valor |
|---|---|
| Raio | **8** campos, botões, banners, toasts · **12** cartões, tabelas, modais · 10 KPI compacto e caixas pequenas · 6–7 botões pequenos e seta de ação · 999 pílulas, badge do menu, avatar redondo = 50% |
| Borda | **1 px** em tudo (`border` para estrutura, `border-strong` em campos). 2 px só em indicador do item ativo e no filete de topo do KPI |
| Sombras | só flutuantes: dropdown/popover `0 4px 20px rgba(0,0,0,.35)`; toast `0 4px 12px rgba(0,0,0,.3)`; modal grande `0 12px 48px rgba(0,0,0,.5)`. Cartões e campos: sem sombra |
| Camadas | menu 100 · barra superior 50 · véu do menu mobile 99 · modal 1000 · toast 9999 |
| Transições | cor/borda `0.15–0.18s`; menu mobile `transform 0.25s ease`; entrada do toast `slide-in 0.2s`; barra de estoque `width 0.4s`. Esqueleto: `pulse 1.5s`, crítico `1.2s`. **No CRM:** desligar `pulse`/`slide-in` com `prefers-reduced-motion` |

## 5. CSS pronto para o `globals.css` do CRM

Não aplicado ainda (ver `05-plano-de-aplicacao.md`). Mantém a convenção atual do CRM (`:root` claro, `.dark` escuro) e os nomes que o shadcn espera, acrescentando os tokens da marca.

```css
:root {
  --background: #f4f5f7;
  --foreground: #1a1c21;
  --surface: #ffffff;
  --card: #ffffff;
  --card-foreground: #1a1c21;
  --popover: #ffffff;
  --popover-foreground: #1a1c21;
  --hover: #eef0f3;
  --muted: #eef0f3;
  --muted-foreground: #5f6673;
  --secondary: #eef0f3;
  --secondary-foreground: #1a1c21;
  --accent: #eef0f3;              /* shadcn: superfície de hover (não é a marca) */
  --accent-foreground: #1a1c21;
  --primary: #f5a623;             /* marca */
  --primary-hover: #e8902a;
  --primary-foreground: #000000;
  --primary-text: #92400e;
  --border: #e2e4e9;
  --input: #868b97;               /* contorno de campo (border-strong) */
  --ring: #92400e;
  --destructive: #e53935;
  --success: #43a047;  --success-strong: #2e7d32;  --success-text: #1b5e20;
  --danger:  #e53935;  --danger-strong:  #c62828;  --danger-text:  #b71c1c;
  --info:    #1e88e5;  --info-strong:    #1565c0;  --info-text:    #0d47a1;
  --warning: #f5a623;
  --muted-strong: #5f6673;
  --radius: 0.5rem;               /* 8px = rounded-lg (campos, botões). Cartões: rounded-xl = 12px, ver @theme */
  --sidebar: #ffffff;
  --sidebar-foreground: #1a1c21;
  --sidebar-border: #e2e4e9;
  --sidebar-primary: #f5a623;
  --sidebar-primary-foreground: #000000;
  --sidebar-accent: #fef8ed;      /* primary 8% sobre a superfície */
  --sidebar-accent-foreground: #1a1c21;
  --sidebar-ring: #92400e;
}

.dark {
  --background: #0e0f11;
  --foreground: #e8eaf0;
  --surface: #161719;
  --card: #1c1e21;
  --card-foreground: #e8eaf0;
  --popover: #1c1e21;
  --popover-foreground: #e8eaf0;
  --hover: #23262b;
  --muted: #23262b;
  --muted-foreground: #8a90a0;
  --secondary: #23262b;
  --secondary-foreground: #e8eaf0;
  --accent: #23262b;
  --accent-foreground: #e8eaf0;
  --primary: #f5a623;
  --primary-hover: #e8902a;
  --primary-foreground: #000000;
  --primary-text: #f5a623;
  --border: #2a2d33;
  --input: #636978;
  --ring: #f5a623;
  --destructive: #e53935;
  --success: #43a047;  --success-strong: #2e7d32;  --success-text: #5cb860;
  --danger:  #e53935;  --danger-strong:  #c62828;  --danger-text:  #f06260;
  --info:    #1e88e5;  --info-strong:    #1565c0;  --info-text:    #4dabf5;
  --warning: #f5a623;
  --muted-strong: #5f6673;
  --sidebar: #161719;
  --sidebar-foreground: #e8eaf0;
  --sidebar-border: #2a2d33;
  --sidebar-primary: #f5a623;
  --sidebar-primary-foreground: #000000;
  --sidebar-accent: #28221a;      /* primary 8% sobre a superfície */
  --sidebar-accent-foreground: #e8eaf0;
  --sidebar-ring: #f5a623;
}

@theme inline {
  /* Utilitários de texto semântico já seguros: o CRM tem 43 usos de `text-danger`, 6 de `text-warning`,
     4 de `text-info` e 3 de `text-success`. Apontar a cor "padrão" para a variante `-text` os corrige
     nos dois temas sem editar nenhuma tela. Fundo sólido usa `bg-*-strong` (branco ≥ 5,1:1). */
  --color-success: var(--success-text);
  --color-danger: var(--danger-text);
  --color-info: var(--info-text);
  --color-warning: var(--primary-text);
  /* além do bloco já existente: */
  --color-surface: var(--surface);
  --color-hover: var(--hover);
  --color-primary-hover: var(--primary-hover);
  --color-primary-text: var(--primary-text);
  --color-success-strong: var(--success-strong);  --color-success-text: var(--success-text);
  --color-danger-strong: var(--danger-strong);    --color-danger-text: var(--danger-text);
  --color-info-strong: var(--info-strong);        --color-info-text: var(--info-text);
  --color-muted-strong: var(--muted-strong);
  --radius-xl: 0.75rem;           /* 12px exatos (o padrão do shadcn daria 11,2px) */
  --font-sans: var(--font-barlow);
  --font-display: var(--font-barlow-condensed);
  --font-mono: var(--font-jetbrains-mono);
}
```

Fontes (em `app/layout.tsx`, substituindo Geist): `Barlow` (400, 500, 600), `Barlow_Condensed` (700, 800) e `JetBrains_Mono` (400, 500) via `next/font/google`, expostas nas variáveis `--font-barlow`, `--font-barlow-condensed` e `--font-jetbrains-mono`.

**Verificação:** os números desta página saem de um script de contraste. No plano de aplicação há um teste automatizado que recalcula esses pares a partir do `globals.css`, para nenhuma mudança futura de cor quebrar a acessibilidade sem aviso.
