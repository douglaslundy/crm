# Design system do CRM (baseado no Mecânica Pro)

Documentação do layout do **Mecânica Pro** (`C:\Users\dougl\workspace\mecanicapro`, em produção, somente leitura) e do plano para aplicá-lo à Plataforma Fiscal + CRM. Levantamento feito em 2026-10-04 sobre 103 arquivos `.tsx`, o `globals.css` e o protótipo `oficina-system.html`.

| Arquivo | Conteúdo |
|---|---|
| [`01-analise-mecanicapro.md`](01-analise-mecanicapro.md) | Como o Mecânica Pro é construído, números medidos no código, inventário de telas e defeitos encontrados (o que **não** copiar). |
| [`02-tokens.md`](02-tokens.md) | Cores dos temas escuro e claro com contraste (WCAG), tipografia, espaçamento, raios, sombras, camadas, movimento, e o CSS pronto para o `globals.css` do CRM. |
| [`03-componentes.md`](03-componentes.md) | Especificação exata de cada elemento: campos, rótulos, botões, pílulas, cartões, tabela, modal, toast, banners, barras, gráfico, menu lateral e barra superior. |
| [`04-layout-e-responsividade.md`](04-layout-e-responsividade.md) | Estrutura da página, grades, pontos de quebra, padrões de página (lista, formulário, painel, login). |
| [`05-plano-de-aplicacao.md`](05-plano-de-aplicacao.md) | Comparação CRM atual × alvo, decisões que dependem de você, fases, testes e riscos. **Nada foi alterado no CRM ainda.** |

## Resumo em dez linhas

1. **Identidade:** escuro por padrão, âmbar `#f5a623` como cor de marca, superfícies em cinza-azulado quase preto, tipografia Barlow (texto), Barlow Condensed (títulos e botões) e JetBrains Mono (números).
2. **Densidade:** compacta. Texto de 12 a 14 px, campos de ≈37 px, tabelas com linhas de ≈42 px, menu lateral de 230 px.
3. **Forma:** raio de 8 px nos controles, 12 px nos cartões, pílulas totalmente arredondadas, bordas de 1 px.
4. **Campos:** fundo mais escuro que o cartão (efeito "rebaixado"), borda de 1 px, rótulo de 13 px cinza acima.
5. **Botão primário:** âmbar sólido, texto preto, fonte condensada em negrito.
6. **Status:** sempre em pílulas coloridas (preenchida ou só contorno), nunca só a cor da linha.
7. **Esqueleto:** menu lateral fixo à esquerda, barra superior de 60 px com migalhas, conteúdo com 24 px de respiro.
8. **Mobile:** abaixo de 768 px o menu vira gaveta e o conteúdo passa para 16 px de respiro.
9. **O que corrigir ao copiar:** foco visível nos campos, cores semânticas do tema claro (falham no contraste), bordas de campo (1,2:1) e o menu com texto de 12 px.
10. **Como aplicar:** tokens no `globals.css`, primitivos reutilizáveis (hoje o original repete estilo inline em 214 campos e 329 botões), depois o esqueleto e por fim as telas.
