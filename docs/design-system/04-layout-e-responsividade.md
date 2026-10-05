# 04. Layout e responsividade

## 1. Esqueleto da página (área logada)

```
┌────────────┬────────────────────────────────────────────────┐
│            │ Topbar 60px (sticky, z50)                       │
│  Sidebar   ├────────────────────────────────────────────────┤
│  230px     │ main  padding 24px (16px no mobile)             │
│  fixa      │   [banners de alerta]                           │
│  (z100)    │   Título da página (28/800 display)             │
│            │   conteúdo                                      │
└────────────┴────────────────────────────────────────────────┘
```

- Fundo da página `background`; menu e barra em `surface`; conteúdo em cartões `card`.
- O conteúdo ocupa toda a largura restante (`margin-left: 230px`). **Não há largura máxima global**: formulários de cadastro ficam em cartão estreito centralizado (`max-width: 760px`), listas e painéis usam a largura toda.
- Banners (alerta de estoque, notas de terceiros) ficam **dentro** do `main`, acima do título, e podem ser dispensados.
- Mobile (< 768 px): menu vira gaveta com véu, a barra superior ganha o botão `☰`, o `main` passa a 16 px.
- **No CRM** o esqueleto atual (`AppShell`) já tem menu lateral (`w-60` = 240), barra superior (`h-14` = 56) e **navegação inferior no celular** (`BottomNav`). Decisão a tomar (ver `05-plano-de-aplicacao.md`): manter a barra inferior (melhor ergonomia no celular, só vale se o menu tiver ≤ 5–6 itens) ou adotar a gaveta do Mecânica Pro.

## 2. Pontos de quebra

| Largura | O que muda |
|---|---|
| **< 768 px** | menu em gaveta; `main` com padding 16; botão `☰` na barra superior (decisão em JS no layout: `innerWidth < 768`) |
| ≤ 900 px | grades `rcols-4/5/6` caem para 3 colunas; `rsplit` e `rsplit-2-1` viram 1 coluna; agenda semanal vira 1 coluna; painel de marca do login some |
| ≤ 640 px | `rcols-3..6` caem para 2 colunas; `rform`, `rform-3`, `rform-auto` viram 1 coluna |
| ≤ 620 px | vista mensal da agenda rola na horizontal (`min-width 560`) em vez de espremer 7 colunas |
| ≤ 480 px | login: `padding 24` |
| ≤ 420 px | `rcols-2` e `rcols-3` viram 1 coluna |

Regra do original: **colunas cheias no desktop, colapso progressivo até o celular**. No CRM, expressar o mesmo com utilitários do Tailwind (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-6`), sem JavaScript de medição quando possível (o CSS resolve; JS só para a gaveta).

## 3. Grades

| Padrão | Uso | Definição |
|---|---|---|
| `rcols-6` | KPIs do painel (compactos) | 6 colunas, `gap 12`, `margin-bottom 24` |
| `rsplit-2-1` | gráfico (2/3) + alertas (1/3) | `2fr 1fr`, `gap 24` |
| `rsplit` | dois painéis de conteúdo | `1fr 1fr` |
| `rform` / `rform-3` | formulário | 2 ou 3 colunas, `gap 16` |
| `rform-auto` | campo + campo + botão | `1fr 1fr auto` |
| `week-grid` / `month-grid` | agenda | 7 colunas; célula do dia `min-height 280` |

## 4. Padrões de página

**Lista (ex.: Clientes):**
1. Linha de cabeçalho `display:flex; justify-content:space-between; margin-bottom:16`: à esquerda `h1` (28/800 display) e embaixo o subtítulo "N registros" (14 `muted`, `margin-top 4`); à direita a **busca** (280 px) e, no topo da barra, a **ação contextual** "+ Novo X".
2. (Opcional) barra de filtros: `display:flex; gap:10; flex-wrap:wrap; align-items:center; margin-bottom:20` com busca (240), `select` de status, intervalo "De [data] até [data]", caixa "Mostrar canceladas" e botão "Limpar" quando há filtro ativo.
3. (Opcional) barra de uso do plano, `margin-bottom: 20`.
4. `DataTable` em cartão (§5 de `03-componentes.md`); linha clicável abre o detalhe.

**Formulário de cadastro (ex.: Novo Cliente):** `h1` com `margin-bottom: 24`; cartão `max-width 760`, padding 32, centralizado; grade de 2 colunas; seções internas com rótulo de seção; botão primário `lg` alinhado à direita, `margin-top: 24`.

**Painel (dashboard):** `h1`; KPIs `rcols-6`; `rsplit-2-1` (gráfico + alertas); cartão "Últimas…" com título 18/800 e tabela dentro. Enquanto carrega: seis cartões esqueleto (altura 118, `pulse`).

**Detalhe:** cabeçalho com título e pílula de status à direita; blocos em cartões; ações no rodapé do bloco ou na barra superior.

**Modal de edição:** §6 de `03-componentes.md`.

**Login:** §11 de `03-componentes.md`.

## 5. Mapeamento para o CRM atual (rotas existentes)

| Tela do CRM | Padrão a usar |
|---|---|
| `/dashboard` | Painel (KPIs: consumo do plano, documentos do mês, clientes, pendências fiscais) |
| `/clientes`, `/produtos`, `/servicos`, `/notas`, `/pendencias-fiscais`, `/admin/empresas`, `/admin/planos`, `/configuracoes/usuarios` | Lista |
| `/notas/nova`, `/onboarding`, `/configuracoes/fiscal`, `/admin/planos/novo` | Formulário em cartão (a tela fiscal em duas colunas de cartões, como hoje) |
| `/notas/[id]`, `/admin/empresas/[id]` | Detalhe |
| `/notas/inutilizar` | Formulário + lista lado a lado (`rsplit`) |
| `/login`, `/cadastro`, `/esqueci-senha`, `/redefinir-senha`, `/definir-senha` | Autenticação em dois painéis; `/cadastro` e `/planos` precisam de variante de largura maior |
| `(admin)` (área da plataforma) | Mesmo esqueleto, menu próprio; destacar que é outra área (faixa ou rótulo na marca) |
