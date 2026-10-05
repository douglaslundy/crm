# Aplicação do design system no CRM: spec

**Data:** 2026-10-04. **Base normativa:** `docs/design-system/00..05` (tokens, componentes, layout, análise do Mecânica Pro, decisões D1 a D6). Esta spec não repete esses valores; ela fixa **o escopo, as regras e o critério de pronto** da aplicação no frontend do CRM.

## 1. Objetivo

Trocar a aparência do frontend (`frontend/`) pelo layout do Mecânica Pro (âmbar sobre cinza-azulado, Barlow, escuro por padrão), **com as correções de acessibilidade** documentadas. Nenhuma regra de negócio, rota, API, schema ou texto funcional muda.

## 2. Decisões já tomadas (2026-10-04)

| # | Decisão |
|---|---|
| D1 | Escuro por padrão, alternância lembrada (`next-themes`, classe `.dark`) |
| D2 | Visual do Mecânica Pro com as correções (foco visível, tokens `*-text`, borda de campo forte, pílulas `*-strong`) |
| D3 | Nome "Plataforma Fiscal", sem logotipo novo; ícone de marca em lucide |
| D4 | Gaveta lateral no celular; a `BottomNav` sai |
| D5 | Ícones lucide |
| D6 | Menu regular (14 px, itens de ≈36 px) |

## 3. Escopo

**Dentro:** `app/globals.css`, `app/layout.tsx`, `app/providers.tsx`; primitivos em `components/ui` e `components/form`; esqueleto em `components/layout`; as telas de `app/**` e `features/**` que usem markup ad-hoc em vez de primitivos; testes novos dos primitivos e do contraste.

**Fora:** backend; textos, rotas e fluxos; gráficos (`recharts` ainda não é dependência: entra quando o painel tiver gráfico); `Modal`/`Dialog` (nenhuma tela usa modal hoje; criar quando surgir a primeira, sobre `@base-ui/react/dialog`); impressão/DANFE; internacionalização.

## 4. Regras de execução

1. **Só apresentação.** Cada tarefa troca classes e markup; qualquer ajuste de comportamento vira item à parte.
2. **Tokens, não valores soltos.** Nenhum hex fora de `globals.css`. Cor semântica como texto usa `text-danger|success|info|warning` (já mapeados para as variantes `-text`); como fundo sólido usa `bg-*-strong`.
3. **Primitivos primeiro.** Nenhuma tela ganha `<input>`, `<select>` ou `<button>` cru; usar `Input`, `SelectNativo`, `TextareaNativo`, `Button`.
4. **Estados por atributo e CSS**, não por JavaScript: `:hover`, `focus-visible`, `aria-invalid`, `aria-current`, `disabled`.
5. **Movimento respeita `prefers-reduced-motion`.**
6. **Testes:** a suíte existente (34 arquivos, nenhum depende de classe CSS) continua verde a cada tarefa; primitivos novos ganham teste de comportamento e de atributos; os tokens ganham teste automatizado de contraste.
7. **Mobile:** nenhuma rolagem horizontal da página em 360 px.

## 5. Critério de pronto

- Todas as rotas listadas em `04-layout-e-responsividade.md` §5 usam os tokens e primitivos novos.
- Os dois temas passam no teste de contraste (texto ≥ 4,5:1; contorno de campo e foco ≥ 3:1).
- Verificação visual feita em navegador real: tema escuro e claro × 360, 768 e 1280 px, em todas as rotas, e navegação por teclado nas telas principais.
- `npm run test`, `typecheck`, `lint` e `build` verdes; staging da VPS atualizado.

## 6. Riscos e mitigação

Os da §6 de `05-plano-de-aplicacao.md`. Acrescenta-se: o único teste que importa o tema (`AppShell.test.tsx`) mocka `next-themes`, então a troca de padrão para `dark` não o afeta; a conferência visual real fica para a Fase 5 porque o ambiente de testes (jsdom) não renderiza CSS.
