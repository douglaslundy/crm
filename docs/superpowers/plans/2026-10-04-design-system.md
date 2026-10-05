# Design system no CRM: plano de implementação

> **Para executores:** SUB-SKILL OBRIGATÓRIA: `superpowers:executing-plans` (modo Native). Passos com `- [ ]`.

**Objetivo:** aplicar no frontend do CRM o layout do Mecânica Pro (tokens, tipografia, primitivos, esqueleto, telas) com as correções de acessibilidade.

**Arquitetura:** os tokens ficam em `app/globals.css` (`:root` claro, `.dark` escuro) e são expostos ao Tailwind 4 por `@theme inline`. Os primitivos de `components/ui` concentram o visual; as telas só os compõem. Nenhuma regra de negócio muda.

**Stack:** Next 16, React 19, Tailwind 4, `next-themes`, `@base-ui/react`, `class-variance-authority`, lucide, vitest + Testing Library. **Antes de escrever código Next, consultar `frontend/node_modules/next/dist/docs/` (AGENTS.md do frontend).**

**Spec:** `docs/superpowers/specs/2026-10-04-design-system-design.md`. **Base normativa:** `docs/design-system/02-tokens.md` (CSS pronto, §5) e `03-componentes.md` (medidas).

## Global Constraints

- Só apresentação: rotas, textos, fluxos, API e regras não mudam.
- Nenhum hex fora de `globals.css` e do teste de contraste.
- Texto semântico: `text-danger|success|info|warning`; fundo sólido: `bg-*-strong`.
- Sem `any`; TypeScript strict. Imports com `@/…` e `cn` de `@/lib/utils` (o `button.tsx` atual importa `"cn"`; manter o que lá estiver).
- Comandos rodam em `frontend/`: `npm run test`, `npm run typecheck`, `npm run lint`, `npm run build`.
- Sem push. Commits locais na `master`, um por tarefa.

## Review Focus

- Troca de padrão para escuro: primeira visita sem preferência salva abre escuro; quem já escolheu claro continua claro (`next-themes` lembra).
- `text-warning` passa a `primary-text` (marrom no claro): conferir que alerta continua legível e distinto de erro.
- Barlow é mais estreita que Geist: tabelas e formulários sem quebra feia a 360 px.
- Item ativo do menu: mais específico vence (`/admin` não acende em `/admin/planos`).
- Gaveta no celular: abre por botão, fecha com `Esc`, véu e ao navegar; foco volta ao botão.
- Campo com erro: `aria-invalid` visível sem depender só de cor (mensagem em texto continua).

---

## Fase 1: tokens, fontes e tema

### Tarefa 1: tokens e teste de contraste

**Arquivos:** modificar `frontend/app/globals.css`; criar `frontend/app/tokens.test.ts`.

**Produz:** variáveis CSS `--surface --hover --primary-hover --primary-text --success|danger|info -strong|-text --muted-strong`; utilitários Tailwind `bg-surface bg-hover text-primary-text bg-danger-strong text-danger-text` etc.

- [ ] **Passo 1: escrever o teste (RED).** Criar `frontend/app/tokens.test.ts`:

```ts
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const css = readFileSync(join(__dirname, 'globals.css'), 'utf8');

function bloco(abertura: string): Record<string, string> {
  const inicio = css.indexOf(`\n${abertura} {`);
  if (inicio < 0) throw new Error(`bloco ${abertura} ausente em globals.css`);
  const fim = css.indexOf('\n}', inicio);
  const corpo = css.slice(inicio, fim);
  const vars: Record<string, string> = {};
  for (const m of corpo.matchAll(/--([\w-]+):\s*(#[0-9a-fA-F]{6})\b/g)) vars[m[1]] = m[2].toLowerCase();
  return vars;
}

function luminancia(hex: string): number {
  const canais = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
  const [r, g, b] = canais.map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contraste(a: string, b: string): number {
  const [x, y] = [luminancia(a), luminancia(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}

const BRANCO = '#ffffff';

type Par = [rotulo: string, frente: string, fundo: string, minimo: number];

function pares(t: Record<string, string>): Par[] {
  const v = (nome: string) => {
    const valor = nome === 'branco' ? BRANCO : t[nome];
    if (!valor) throw new Error(`token --${nome} ausente`);
    return valor;
  };
  const p = (rotulo: string, frente: string, fundo: string, minimo: number): Par => [rotulo, v(frente), v(fundo), minimo];
  return [
    p('texto/cartão', 'foreground', 'card', 4.5),
    p('texto/fundo', 'foreground', 'background', 4.5),
    p('secundário/cartão', 'muted-foreground', 'card', 4.5),
    p('secundário/fundo', 'muted-foreground', 'background', 4.5),
    p('secundário/menu', 'muted-foreground', 'surface', 4.5),
    p('secundário/hover', 'muted-foreground', 'hover', 4.5),
    p('texto sobre marca', 'primary-foreground', 'primary', 4.5),
    p('marca como texto/cartão', 'primary-text', 'card', 4.5),
    p('marca como texto/fundo', 'primary-text', 'background', 4.5),
    p('erro/cartão', 'danger-text', 'card', 4.5),
    p('sucesso/cartão', 'success-text', 'card', 4.5),
    p('info/cartão', 'info-text', 'card', 4.5),
    p('branco/danger-strong', 'branco', 'danger-strong', 4.5),
    p('branco/success-strong', 'branco', 'success-strong', 4.5),
    p('branco/info-strong', 'branco', 'info-strong', 4.5),
    p('branco/muted-strong', 'branco', 'muted-strong', 4.5),
    p('borda de campo/cartão', 'input', 'card', 3),
    p('borda de campo/fundo', 'input', 'background', 3),
    p('foco/cartão', 'ring', 'card', 3),
    p('foco/fundo', 'ring', 'background', 3),
  ];
}

describe.each([
  ['claro', ':root'],
  ['escuro', '.dark'],
])('contraste dos tokens (%s)', (_tema, abertura) => {
  const tokens = bloco(abertura);
  it.each(pares(tokens))('%s ≥ mínimo', (rotulo, frente, fundo, minimo) => {
    expect(contraste(frente, fundo), `${rotulo}: ${frente} sobre ${fundo}`).toBeGreaterThanOrEqual(minimo);
  });
});
```

- [ ] **Passo 2: ver falhar.** `npm run test -- app/tokens.test.ts`. Esperado: FAIL ("token --primary-text ausente" etc.; os tokens atuais são `oklch`, o regex só lê hex).

- [ ] **Passo 3: reescrever `globals.css`.** Manter as 3 linhas `@import` e `@custom-variant dark` do topo. Substituir todo o resto por: (a) o bloco `:root`, o bloco `.dark` e o `@theme inline` de `docs/design-system/02-tokens.md` §5, copiados literalmente; (b) no `@theme inline`, **acrescentar** as linhas que o arquivo atual já tinha e o §5 não repete: `--color-background`, `--color-foreground`, `--color-ring`, `--color-input`, `--color-border`, `--color-destructive`, `--color-accent(-foreground)`, `--color-muted(-foreground)`, `--color-secondary(-foreground)`, `--color-primary(-foreground)`, `--color-popover(-foreground)`, `--color-card(-foreground)`, `--color-sidebar*`, `--color-chart-1..5`, `--radius-sm|md|lg` (`calc(var(--radius) * 0.6|0.8|1)`), `--font-heading: var(--font-display)`; (c) manter `--chart-1..5` nos dois blocos (valores `oklch` atuais; não são testados); (d) no fim, o bloco `@layer base` atual (`* { border-border outline-ring/50 }`, `body`, `html font-sans`) e:

```css
@keyframes pulse-suave { 50% { opacity: 0.5; } }
@keyframes entrar-pela-direita { from { transform: translateX(16px); opacity: 0; } to { transform: none; opacity: 1; } }
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
}
```

  Observação: `--font-sans: var(--font-barlow)` no `@theme inline` do §5 substitui a linha antiga `--font-sans: var(--font-sans)`; apagar a antiga e `--font-mono: var(--font-geist-mono)`.

- [ ] **Passo 4: ver passar.** `npm run test -- app/tokens.test.ts`. Esperado: 40 testes verdes (20 pares × 2 temas). Se algum par falhar por pouco, **não** afrouxar o mínimo: ajustar o token e registrar no `02-tokens.md`.

- [ ] **Passo 5: suíte e build.** `npm run test && npm run typecheck && npm run build`. Esperado: tudo verde (as telas ficam âmbar/azuladas sem editar nada).

- [ ] **Passo 6: commit.** `git add frontend/app/globals.css frontend/app/tokens.test.ts && git commit -m "feat(ui): tokens do design system e teste de contraste"` (com a linha de atribuição do harness).

### Tarefa 2: fontes e tema padrão

**Arquivos:** modificar `frontend/app/layout.tsx`, `frontend/app/providers.tsx`; criar `frontend/app/providers.test.tsx`.

- [ ] **Passo 1: teste (RED).** `providers.test.tsx`: renderiza `<Providers><span>x</span></Providers>` com `localStorage` limpo e afirma `document.documentElement.classList.contains('dark')` verdadeiro (usar `waitFor`; `next-themes` aplica a classe no efeito). Mockar `@/lib/sessao` e `@/components/ui/sonner` se o import falhar em jsdom. Esperado: FAIL (hoje o padrão é `system` e o jsdom não declara `prefers-color-scheme: dark`).
- [ ] **Passo 2: implementar.** Em `providers.tsx`: `defaultTheme="dark"` e `enableSystem={false}`. Em `layout.tsx`: trocar Geist por

```tsx
import { Barlow, Barlow_Condensed, JetBrains_Mono } from 'next/font/google';

const barlow = Barlow({ variable: '--font-barlow', subsets: ['latin'], weight: ['400', '500', '600'] });
const barlowCondensed = Barlow_Condensed({ variable: '--font-barlow-condensed', subsets: ['latin'], weight: ['700', '800'] });
const jetbrainsMono = JetBrains_Mono({ variable: '--font-jetbrains-mono', subsets: ['latin'], weight: ['400', '500'] });
```

  e `className={`${barlow.variable} ${barlowCondensed.variable} ${jetbrainsMono.variable}`}` no `<html>`.
- [ ] **Passo 3: verde.** `npm run test && npm run typecheck && npm run build`. Esperado: tudo verde; no build as fontes são baixadas pelo `next/font` (precisa de rede; se falhar offline, registrar e repetir com rede).
- [ ] **Passo 4: commit** `feat(ui): fontes Barlow e tema escuro por padrão`.

---

## Fase 2: primitivos

Cada tarefa: teste primeiro (RED), implementação (GREEN), `npm run test` completo, commit. Os testes verificam **comportamento e atributos**, não pixels. As medidas vêm de `03-componentes.md`.

### Tarefa 3: Button

**Arquivos:** modificar `components/ui/button.tsx`; criar `components/ui/button.test.tsx`.

**Produz:** `Button` com `variant: default | outline | secondary | ghost | destructive | success | link` e `size: default | xs | sm | lg | icon | icon-xs | icon-sm | icon-lg`. **Os nomes atuais permanecem** (37 arquivos os usam).

- [ ] **Passo 1: teste.** (a) `default` tem `bg-primary` e `font-display`; (b) `outline` tem `border-border`; (c) `destructive` tem `text-danger`; (d) `success` existe e tem `text-success`; (e) `disabled` não dispara `onClick`; (f) `size="icon"` tem `size-9`. Esperado: FAIL em (a), (d), (f).
- [ ] **Passo 2: implementar** (apenas o objeto `variants` do `cva`; o resto do arquivo fica):
  - base: trocar `rounded-lg … text-sm font-medium` por `rounded-lg text-sm font-medium`, `focus-visible:ring-3 focus-visible:ring-ring/50` por `focus-visible:ring-[3px] focus-visible:ring-primary/25 focus-visible:border-ring`, `disabled:opacity-50 disabled:cursor-not-allowed` (remover `disabled:pointer-events-none`; o `disabled` nativo já bloqueia o clique) e retirar `aria-invalid:*`.
  - `default`: `bg-primary text-primary-foreground hover:bg-primary-hover font-display font-bold tracking-[0.03em]`.
  - `outline`: `border-border text-muted-foreground hover:bg-hover hover:text-foreground aria-expanded:bg-hover`.
  - `secondary`: `bg-primary/12 border-primary/60 text-primary-text hover:bg-primary/20`.
  - `ghost`: `text-muted-foreground hover:bg-hover hover:text-foreground aria-expanded:bg-hover`.
  - `destructive`: `bg-danger/10 border-danger/30 text-danger hover:bg-danger-strong hover:text-white`.
  - `success`: `bg-success/10 border-success/30 text-success hover:bg-success-strong hover:text-white`.
  - `link`: `text-primary-text underline-offset-4 hover:underline`.
  - tamanhos: `default: h-[37px] gap-2 px-5`; `xs: h-6 gap-1 rounded-md px-2 text-xs`; `sm: h-8 gap-1.5 rounded-md px-4 text-[13px]`; `lg: h-11 gap-2 px-7 text-base`; `icon: size-9`; `icon-xs: size-6 rounded-md`; `icon-sm: size-8 rounded-md`; `icon-lg: size-11`.
- [ ] **Passo 3: suíte completa** `npm run test && npm run typecheck`. Esperado: verde.
- [ ] **Passo 4: commit** `feat(ui): Button no padrão do design system`.

### Tarefa 4: Input, SelectNativo, TextareaNativo

**Arquivos:** modificar `components/ui/input.tsx`, `components/ui/campos-nativos.tsx`; criar `components/ui/campos.test.tsx`.

**Produz:** `const CLASSE_CAMPO` exportada de `campos-nativos.tsx` (uma só definição do visual de campo) reutilizada por `Input`.

- [ ] **Passo 1: teste.** (a) `Input` renderiza `textbox` e aceita digitação (`userEvent`); (b) com `aria-invalid` mantém o atributo; (c) `SelectNativo` renderiza `combobox` com as opções e dispara `onChange`; (d) `SelectNativo` renderiza um ícone de seta (`data-testid="seta-select"`), pois `appearance-none` sem seta é defeito #6; (e) `TextareaNativo` é `textbox` multilinha. Esperado: FAIL em (d).
- [ ] **Passo 2: implementar.**

```tsx
// components/ui/campos-nativos.tsx
import { ChevronDown } from 'lucide-react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export const CLASSE_CAMPO =
  'w-full min-w-0 rounded-lg border border-input bg-background px-3 py-[9px] text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-primary/25 disabled:cursor-not-allowed disabled:opacity-60 read-only:opacity-80 aria-invalid:border-danger aria-invalid:ring-[3px] aria-invalid:ring-danger/20';

export function SelectNativo({ className, children, ...props }: ComponentProps<'select'>) {
  return (
    <div className="relative">
      <select className={cn(CLASSE_CAMPO, 'h-[37px] appearance-none py-0 pr-9', className)} {...props}>
        {children}
      </select>
      <ChevronDown data-testid="seta-select" aria-hidden className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground" />
    </div>
  );
}

export function TextareaNativo({ className, ...props }: ComponentProps<'textarea'>) {
  return <textarea className={cn(CLASSE_CAMPO, 'min-h-[80px]', className)} {...props} />;
}
```

  `input.tsx`: manter o `InputPrimitive`, `className={cn(CLASSE_CAMPO, 'h-[37px] py-0 file:…', className)}` onde `file:…` mantém `file:inline-flex file:h-6 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground`.
- [ ] **Passo 3:** `npm run test && npm run typecheck` (as telas com `SelectNativo` herdam o wrapper `div`; se algum teste ou layout quebrar por causa do wrapper, ajustar o wrapper com `className` repassado ao `div` apenas para largura, registrando um Ruling).
- [ ] **Passo 4: commit** `feat(ui): campos no padrão (altura 37, borda forte, foco, seta no select)`.

### Tarefa 5: Label e Campo

**Arquivos:** modificar `components/ui/label.tsx`, `components/form/Campo.tsx`; criar `components/form/Campo.test.tsx` se não existir (verificar com `ls`).

- [ ] **Passo 1: teste.** (a) o rótulo é associado ao controle (`getByLabelText`); (b) com `erro`, o controle recebe `aria-invalid="true"` e `aria-describedby` apontando para a mensagem; (c) a mensagem tem `role` implícito de texto e aparece; (d) `obrigatorio` acrescenta `*` com `aria-hidden` e o texto "obrigatório" para leitor de tela (`sr-only`). Esperado: FAIL em (d).
- [ ] **Passo 2: implementar.** `Label`: `text-[13px] font-normal text-muted-foreground leading-none` (trocar `font-medium text-sm`); `Campo` ganha a prop opcional `obrigatorio?: boolean` e renderiza `<span aria-hidden className="ml-0.5 text-danger">*</span><span className="sr-only"> (obrigatório)</span>` dentro do `Label`; espaçamento `space-y-1`; dica `text-[11px] text-muted-foreground`; erro `text-xs text-danger` (mantém `id`).
- [ ] **Passo 3:** `npm run test && npm run typecheck`. **Passo 4: commit** `feat(ui): Label e Campo no padrão`.

### Tarefa 6: Card

**Arquivos:** modificar `components/ui/card.tsx`; criar `components/ui/card.test.tsx`.

- [ ] **Passo 1: teste.** `CardTitle` renderiza o texto; `Card` com `size="sm"` tem `data-size="sm"`; `Card` tem `border` e `bg-card`. FAIL em `border`.
- [ ] **Passo 2: implementar.** `Card`: trocar `ring-1 ring-foreground/10` por `border border-border`, `[--card-spacing:--spacing(6)]` (24 px), `data-[size=sm]:[--card-spacing:--spacing(4)]`; `CardTitle`: `font-display text-lg font-extrabold leading-tight` (remover `font-heading font-medium text-base`); `CardFooter`: `bg-hover/50`.
- [ ] **Passo 3:** `npm run test && npm run typecheck`. **Passo 4: commit** `feat(ui): Card no padrão`.

### Tarefa 7: Pilula e StatusDaNota

**Arquivos:** criar `components/ui/pilula.tsx`, `components/ui/pilula.test.tsx`; modificar `features/notas/components/StatusDaNota.tsx`.

**Produz:** `Pilula({ tom, contorno?, children })`, `tom: 'sucesso' | 'perigo' | 'info' | 'alerta' | 'neutro'`; expõe `data-tom` e `data-contorno`.

- [ ] **Passo 1: teste.** Renderiza o texto; `data-tom="perigo"`; `contorno` põe `data-contorno="true"`; sólida de `sucesso` usa `bg-success-strong text-white`; a de `alerta` usa `bg-primary text-primary-foreground`. Esperado: FAIL (arquivo não existe).
- [ ] **Passo 2: implementar.**

```tsx
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type TomDaPilula = 'sucesso' | 'perigo' | 'info' | 'alerta' | 'neutro';

const SOLIDA: Record<TomDaPilula, string> = {
  sucesso: 'bg-success-strong text-white',
  perigo: 'bg-danger-strong text-white',
  info: 'bg-info-strong text-white',
  alerta: 'bg-primary text-primary-foreground',
  neutro: 'bg-muted-strong text-white',
};
const CONTORNO: Record<TomDaPilula, string> = {
  sucesso: 'border-success text-success',
  perigo: 'border-danger text-danger',
  info: 'border-info text-info',
  alerta: 'border-primary text-primary-text',
  neutro: 'border-muted-strong text-muted-foreground',
};

/** O rótulo é sempre texto: a cor nunca é a única informação. */
export function Pilula({ tom, contorno = false, children }: { tom: TomDaPilula; contorno?: boolean; children: ReactNode }) {
  return (
    <span
      data-tom={tom}
      data-contorno={contorno ? 'true' : undefined}
      className={cn(
        'inline-flex items-center rounded-full px-[11px] py-[3px] font-display text-[11px] font-bold tracking-[0.04em] uppercase',
        contorno ? cn('border-[1.5px] bg-transparent', CONTORNO[tom]) : SOLIDA[tom],
      )}
    >
      {children}
    </span>
  );
}
```

  `StatusDaNota`: trocar o mapa `COR` por `TOM: Record<StatusNota, { tom: TomDaPilula; contorno?: boolean }>` = RASCUNHO neutro; PROCESSANDO alerta; AUTORIZADA sucesso; REJEITADA perigo; ERRO perigo; CANCELADA perigo **contorno**; CANCELANDO alerta contorno. O ambiente: PRODUCAO `perigo` contorno, HOMOLOGACAO `info` contorno. O texto (`ROTULO_STATUS`) não muda.
- [ ] **Passo 3:** `npm run test` (os testes de `StatusDaNota`/lista de notas usam texto e continuam verdes) `&& npm run typecheck`. **Passo 4: commit** `feat(ui): Pilula e StatusDaNota`.

### Tarefa 8: Banner, BarraDeProgresso, StatCard

**Arquivos:** criar `components/ui/banner.tsx`, `barra-de-progresso.tsx`, `stat-card.tsx` e um `.test.tsx` de cada.

**Produz:** `Banner({ tom: 'perigo'|'alerta'|'info'|'sucesso', onDispensar?, children })` com `role="alert"` para `perigo` e `role="status"` nos demais; `BarraDeProgresso({ valor, maximo, rotulo })` com `role="progressbar"`, `aria-valuenow/min/max` e cor por faixa (≥100% `bg-danger`, ≥80% `bg-primary`, senão `bg-success`); `StatCard({ titulo, valor, legenda?, tom?, href? })` (valor em `font-mono`, filete de 2 px no topo; vira `Link` quando há `href`).

- [ ] **Passo 1: testes.** Banner: papéis certos; botão "Dispensar" só com `onDispensar` e o chama. Barra: `aria-valuenow`, e `data-faixa` = `perigo` (100%), `alerta` (80–99%), `ok` (<80%); `maximo` 0 não divide por zero (faixa `ok`, largura 0). StatCard: mostra título/valor; com `href` é `link`. Esperado: FAIL.
- [ ] **Passo 2: implementar** conforme `03-componentes.md` §4, §7, §8 (banner: `rounded-lg border px-4 py-2.5 text-[13px] font-semibold` com `bg-danger/10 border-danger/30 text-danger` e equivalentes; barra: `h-1.5 rounded-[3px] bg-border` com preenchimento `transition-[width] duration-[400ms]`).
- [ ] **Passo 3:** `npm run test && npm run typecheck`. **Passo 4: commit** `feat(ui): Banner, BarraDeProgresso e StatCard`.

### Tarefa 9: DataTable

**Arquivos:** criar `components/ui/data-table.tsx`, `data-table.test.tsx`.

**Produz:**

```ts
export interface Coluna<T> { chave: string; titulo: string; render: (linha: T) => ReactNode; numerica?: boolean }
export interface DataTableProps<T> {
  colunas: Coluna<T>[]; linhas: T[]; chaveDaLinha: (linha: T) => string;
  carregando?: boolean; vazio?: string; aoAbrir?: (linha: T) => void; rotulo: string;
}
export function DataTable<T>(props: DataTableProps<T>): JSX.Element
```

- [ ] **Passo 1: testes.** (a) `table` com `aria-label=rotulo`, cabeçalhos `columnheader` e uma `row` por linha; (b) sem linhas mostra `vazio` (padrão "Nada por aqui ainda."); (c) `carregando` mostra 5 linhas de esqueleto e `aria-busy="true"`; (d) com `aoAbrir`, a linha é alcançável por teclado (botão/`tabIndex=0`) e Enter e clique chamam `aoAbrir(linha)`; (e) coluna `numerica` usa `font-mono`. Esperado: FAIL.
- [ ] **Passo 2: implementar** conforme `03-componentes.md` §5 (contêiner `overflow-x-auto rounded-xl border bg-card`; `th` `px-4 py-2.5 text-xs font-semibold uppercase tracking-[0.05em] text-muted-foreground`; `td` `px-4 py-3 text-sm`; `tr` clicável `hover:bg-hover cursor-pointer`; esqueleto `h-3.5 w-[70%] animate-[pulse-suave_1.5s_ease-in-out_infinite] rounded bg-border`). Para a linha clicável, colocar o `onClick` e `onKeyDown` (Enter/Espaço) no `tr` com `tabIndex={0}`.
- [ ] **Passo 3:** `npm run test && npm run typecheck`. **Passo 4: commit** `feat(ui): DataTable`.

### Tarefa 10: tema do sonner

**Arquivos:** modificar `components/ui/sonner.tsx`, `app/providers.tsx`.

- [ ] **Passo 1:** sem teste novo (o `sonner` não é verificável em jsdom além do que a suíte atual já cobre). Conferir por `grep -rn "toast" --include=*.test.tsx` que nenhum teste lê classe do toast.
- [ ] **Passo 2:** em `Toaster`, tirar `richColors` de `providers.tsx`, e mapear as cores por variáveis: `--normal-bg: var(--card)`, `--normal-text: var(--foreground)`, `--normal-border: var(--border)`, `--success-bg: var(--success-strong)`, `--success-text: #fff`, `--success-border: var(--success-strong)`, idem `error` com `danger-strong` e `info` com `info-strong`; `--border-radius: 8px`. Posição `bottom-right`. Fontes herdam o corpo (Barlow).
- [ ] **Passo 3:** `npm run test && npm run typecheck && npm run build`. **Passo 4: commit** `feat(ui): toast no padrão`.

---

## Fase 3: esqueleto

### Tarefa 11: menu lateral, gaveta e Topbar

**Arquivos:** modificar `components/layout/{AppShell,Sidebar,Topbar,nav-items}.tsx`, `AppShell.test.tsx`; remover `components/layout/BottomNav.tsx`; criar `components/layout/MenuMobile.tsx` e `MenuMobile.test.tsx`; modificar `components/theme/ThemeToggle.tsx` (tamanho do ícone, sem mudar a lógica).

**Interfaces:** `Sidebar({ itens, aoNavegar? })` (conteúdo do menu, reutilizado pela gaveta); `MenuMobile({ itens })` com botão "Abrir menu" (`aria-expanded`, `aria-controls`), painel `role="dialog" aria-modal="true" aria-label="Menu"`, véu que fecha, `Esc` fecha, fecha ao navegar, foco volta ao botão.

- [ ] **Passo 1: testes (RED).** (1) `AppShell.test.tsx`: trocar a asserção da navegação inferior por: existe `button` "Abrir menu"; ao clicar, aparece o `dialog` "Menu" com os mesmos links; `Esc` o fecha; continua existindo a navegação "Navegação principal" com `aria-current="page"` em "Início"; "Usuários" só para PROPRIETARIO/ADMIN (já testado). (2) `MenuMobile.test.tsx`: abrir/fechar por botão, véu e `Esc`; o foco volta ao botão "Abrir menu". (3) `nav-items.test.ts`: caso novo, `estaAtivo` com `/configuracoes/fiscal` e `/configuracoes/usuarios` não acendem juntos (já cobertos por `startsWith`; acrescentar o caso do mais específico: em `/configuracoes/fiscal` só um item do conjunto fica ativo). Esperado: FAIL.
- [ ] **Passo 2: implementar.**
  - `Sidebar`: `aside` `hidden md:flex w-[230px] shrink-0 flex-col border-r bg-surface`; marca no topo (`h-[60px]`, quadrado 30×30 `rounded-[7px] bg-primary` com ícone lucide `Receipt` 16 px preto, nome "Plataforma Fiscal" em `font-display text-base font-extrabold`); itens `mx-1.5 flex items-center gap-2.5 rounded-[7px] px-3.5 py-2 text-sm font-medium text-muted-foreground hover:bg-hover hover:text-foreground` com ativo `bg-primary/8 border-l-2 border-primary text-foreground font-semibold`; rodapé com avatar (inicial do nome), nome truncado e papel; o botão "Sair" mantém o `aria-label="Sair"` **na Topbar** para não quebrar o teste atual (o rodapé mostra só nome e papel).
  - Item ativo "o mais específico vence": em `nav-items.ts` `estaAtivo(item, pathname, todos)` considera ativo só o item cujo `href` é o maior prefixo de `pathname`; manter a assinatura compatível (`todos` opcional, padrão `[item]`) para os testes atuais.
  - `Topbar`: `h-[60px] bg-surface border-b px-4 md:px-6`; à esquerda `MenuMobile` (só `<md`) e o texto atual (empresa/usuário); à direita `ThemeToggle` e "Sair" (mantém os `aria-label`).
  - `AppShell`: `main` com `p-4 md:p-6` (sem `pb-24`); remover `BottomNav`.
  - `MenuMobile`: estado `aberto`; o painel renderiza `<Sidebar>` interno em `fixed inset-y-0 left-0 z-[100] w-[230px] bg-surface transition-transform duration-[250ms]`, véu `fixed inset-0 z-[99] bg-black/50`; `useEffect` para `keydown` `Escape` e para fechar quando o `pathname` muda; foco inicial no primeiro link, devolvido ao botão ao fechar.
- [ ] **Passo 3:** `npm run test && npm run typecheck && npm run lint`. Esperado: verde. `git rm components/layout/BottomNav.tsx`.
- [ ] **Passo 4: commit** `feat(ui): menu lateral, gaveta no celular e barra superior`.

### Tarefa 12: CabecalhoDePagina e layout de autenticação

**Arquivos:** criar `components/layout/CabecalhoDePagina.tsx` e `.test.tsx`; modificar `features/auth/components/AuthCard.tsx`, `app/(publico)/layout.tsx`; criar `features/auth/components/AuthCard.test.tsx` se não existir.

**Produz:** `CabecalhoDePagina({ titulo, subtitulo?, acoes? })` com `h1` `font-display text-[28px] font-extrabold`, subtítulo `text-sm text-muted-foreground`, ações à direita (`flex-wrap`, empilha a 360 px).

- [ ] **Passo 1: testes.** Cabeçalho: `heading` nível 1 com o título; subtítulo e ações presentes. `AuthCard`: mantém `titulo` como `heading` e `descricao`; mostra o painel de marca (texto "Plataforma Fiscal") com `aria-hidden` ou como `aside` oculto em telas pequenas. Esperado: FAIL no cabeçalho.
- [ ] **Passo 2: implementar.** `AuthCard`: `main` `flex min-h-dvh`; coluna esquerda `hidden lg:flex flex-1 flex-col justify-center bg-surface p-14` com gradiente `bg-[radial-gradient(circle_at_30%_50%,color-mix(in_oklab,var(--primary)_8%,transparent),transparent_60%)]`, quadrado 72×72 `rounded-[18px] bg-primary`, título 36/800 display e subtítulo; coluna direita `flex w-full items-center justify-center p-6 lg:w-[480px] lg:p-12` com o `Card` atual (`max-w-sm`). Layout público: cabeçalho `bg-surface h-[60px]` e a marca em `font-display font-extrabold`.
- [ ] **Passo 3:** `npm run test && npm run typecheck`. **Passo 4: commit** `feat(ui): CabecalhoDePagina e autenticação em dois painéis`.

### Tarefa 13: ponto de entrega da Fase 3 no staging

- [ ] **Passo 1:** `npm run test && npm run typecheck && npm run lint && npm run build` (tudo verde).
- [ ] **Passo 2:** atualizar o staging da VPS com `deploy/deploy.sh` (ver `deploy/README.md`; credenciais SSH o usuário fornece na hora, nunca gravadas) e conferir por `curl` que `http://192.168.0.233:3200/login` responde 200.
- [ ] **Passo 3:** registrar em `PROGRESSO.md` o que mudou e pedir ao usuário que olhe o staging antes da Fase 4. **Parada natural** (a conferência visual é do usuário). Commit `docs: fase 3 do design system registrada`.

---

## Fase 4: migração das telas

Regra: em cada tarefa, abrir os arquivos do grupo, trocar markup ad-hoc por primitivos (`CabecalhoDePagina`, `DataTable`, `Pilula`, `Banner`, `Campo`+`Input`/`SelectNativo`, `Button`), **sem mudar texto, rota ou comportamento**. Rodar a suíte do grupo e a completa. O teste de cada tela existente é o contrato: se um teste quebrar, o markup mudou o que não devia.

| Tarefa | Grupo | Arquivos (a abrir) | Padrão de `04-layout-e-responsividade.md` §5 |
|---|---|---|---|
| 14 | Autenticação | `app/(auth)/**`, `features/auth/components/*` | autenticação; `Campo` com `obrigatorio` |
| 15 | Painel | `app/(app)/dashboard`, `features/assinatura/components/*` | KPIs com `StatCard` (consumo do plano com `BarraDeProgresso`), avisos com `Banner` |
| 16 | Cadastros | `features/{clientes,produtos,servicos,pendencias-fiscais}/**` e as páginas | lista com `CabecalhoDePagina` + `DataTable`; formulário em cartão `max-w-[760px]`, grade `grid-cols-1 sm:grid-cols-2 gap-4` |
| 17 | Notas | `features/notas/**`, `app/(app)/notas/**` | lista com `DataTable` e `StatusDaNota`; detalhe com `Pilula` e blocos em `Card`; inutilização com formulário + lista lado a lado (`lg:grid-cols-2`) |
| 18 | Configurações fiscais e onboarding | `features/emitente/**`, `app/(app)/{configuracoes/fiscal,onboarding}` | duas colunas de cartões; faixa de ambiente com `Banner` (`alerta` em homologação, `perigo` em produção) |
| 19 | Usuários | `features/usuarios/**` | lista + formulário |
| 20 | Área da plataforma e públicas | `features/admin/**`, `features/cadastro/**`, `app/(admin)/**`, `app/(publico)/**` | lista; cartões de plano; rótulo "Plataforma" visível na marca da área admin |

- [ ] **Passos por tarefa:** (1) `grep -rn "<input\|<select\|<button\|<textarea" <arquivos>` para achar markup cru; (2) migrar; (3) `npm run test -- <pasta>` e `npm run test`; (4) `npm run typecheck && npm run lint`; (5) commit `refactor(ui): <grupo> no design system`; (6) uma linha no `PROGRESSO.md`.
- [ ] **Tarefa 20, fim:** `grep -rn "<input\|<select\|<button\|<textarea" app features components --include=*.tsx | grep -v test | grep -v "components/ui/"` deve listar só o que não tem primitivo equivalente (checkbox/radio nativos); registrar o resto como Ruling.

---

## Fase 5: verificação e fechamento

### Tarefa 21: verificação visual real

- [ ] **Passo 1:** subir o frontend local (`npm run build && npm run start` ou o staging) e, no Chrome, percorrer todas as rotas de `04-layout-e-responsividade.md` §5 nos temas escuro e claro e nas larguras 360, 768 e 1280, procurando: texto cortado, rolagem horizontal da página, contraste ruim, foco invisível, `text-warning` ilegível no claro. Capturas em `docs/design-system/capturas/` (pasta nova; sem dados reais).
- [ ] **Passo 2:** navegação só por teclado em login, lista de notas, formulário de nota e menu/gaveta.
- [ ] **Passo 3:** corrigir cada achado com teste quando for comportamento, ou ajuste de classe quando for visual; um commit por correção.

### Tarefa 22: fechamento

- [ ] **Passo 1:** `npm run test && npm run typecheck && npm run lint && npm run build` verdes.
- [ ] **Passo 2:** atualizar `docs/design-system/00-INDICE.md` e `05-plano-de-aplicacao.md` (estado: aplicado), `PROGRESSO.md` (marcar a tarefa, "Próxima tarefa" volta para a F3b2) e `TAREFAS.md`.
- [ ] **Passo 3:** atualizar o staging; recomendar `/compact`. Commit `docs: design system aplicado`. **Nenhum push sem confirmação do usuário.**
