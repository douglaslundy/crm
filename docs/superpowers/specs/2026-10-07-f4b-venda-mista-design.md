# F4b: venda mista (NF-e + NFS-e independentes)

Data: 2026-10-07. Decisões do usuário: alcance **backend + tela**; emissão só com confirmação quando uma das notas está bloqueada; NFC-e fora da venda mista.

## Objetivo

Receber produtos e serviços numa só venda, com um desconto geral, e gerar uma **NF-e** e uma **NFS-e** independentes, com o desconto rateado entre elas ao centavo. As duas notas são `Nota` comuns: emissão, cancelamento, PDF, reconciliação e contingência continuam como estão.

**Fora da F4b:** cancelar a venda inteira de uma vez; mais de uma nota de produto ou de serviço por venda; NFC-e na venda mista (a NFS-e exige tomador identificado); e-mail ao cliente.

## Dados

- Tabela `vendas` (migration): `id` uuid, `tenant_id`, `cliente_id` (obrigatório), `desconto_centavos`, `subtotal_centavos`, `total_centavos`, `criada_por`, timestamps. Model `Venda` com Global Scope por `tenant_id` (`BelongsToTenant`) e `hasMany(Nota)`.
- `notas.venda_id` uuid nulável, FK para `vendas` (sem cascade; a Action exclui as notas antes).
- A venda guarda o que o usuário digitou. Os totais das notas continuam sendo a fonte fiscal.

## Rateio

`RateioDeDesconto::ratear($descontoGeral, [subtotalProdutos, subtotalServicos])` (função já existente) devolve o desconto de cada nota. A soma é igual ao desconto geral. Desconto negativo ou maior que o subtotal bloqueia com `FISCAL_DESCONTO_INVALIDO`. O rateio por item dentro de cada nota segue o `PreparadorDaNota*` atual.

## Actions (a tela e a API usam as mesmas)

- `CriarVendaMista::executar(array $dados, Usuario $autor): Venda`
  - `dados`: `cliente_id`, `itens_produtos[]` (`produto_id`, `quantidade`, `valor_unitario_centavos?`), `itens_servicos[]` (`servico_id`, `quantidade`, `valor_unitario_centavos?`), `desconto_centavos`, `pagamentos[]` (só da NF-e), `informacoes_complementares?`.
  - Exige ao menos um item de cada tipo (`FISCAL_VENDA_MISTA_INCOMPLETA`) e cliente (`FISCAL_VENDA_SEM_CLIENTE`).
  - Exige os módulos `FISCAL_NFE` e `FISCAL_NFSE` do plano.
  - Numa transação: calcula os subtotais, ratea, cria a `Venda` e chama `CriarRascunhoDeNota` duas vezes (NFE e DPS) com `venda_id`.
  - Os pagamentos têm de fechar com o total da NF-e (regra que `PreparadorDaNota` já aplica na emissão; a tela mostra o total da NF-e para o usuário conferir).
- `AtualizarVendaMista::executar(Venda, array $dados, Usuario): Venda`: só com as duas notas em rascunho (`FISCAL_VENDA_NAO_EDITAVEL`); refaz rateio e itens das duas notas, na mesma transação.
- `ExcluirVendaMista::executar(Venda)`: só com as duas notas em rascunho; exclui as notas (via `ExcluirRascunhoDeNota`) e a venda.
- `EmitirVendaMista::executar(Venda, Usuario, bool $emitirApenasValidas): ResultadoDaVenda`
  - Valida as duas notas **sem emitir**, por um método novo `IniciarEmissao::verificar(Nota)` extraído da validação atual (mesmas regras, nenhum efeito colateral).
  - As duas aptas: emite as duas com `IniciarEmissao::executar`, cada uma na sua transação.
  - Uma bloqueada e `emitirApenasValidas=false`: não emite nenhuma e devolve o bloqueio de cada nota (409, código `FISCAL_VENDA_NOTA_BLOQUEADA`, com a mensagem acionável de cada uma).
  - Uma bloqueada e `emitirApenasValidas=true`: emite a outra e devolve o bloqueio da primeira.
  - Falha na hora de emitir (exceção de uma delas) não impede a outra; o resultado traz o que aconteceu com cada nota. Nunca se desfaz nota emitida por causa da outra.
  - Notas que já saíram do rascunho (retentativa) são puladas.
- `ResultadoDaVenda`: por nota, `{nota_id, modelo, status, bloqueio?: {codigo, mensagem}}`.

## Fronteiras com o fluxo atual

- Editar, atualizar e excluir uma nota com `venda_id` pelas rotas de nota bloqueia com `FISCAL_NOTA_DA_VENDA` (o rateio entre as duas notas deixaria de valer). Emitir, cancelar, baixar PDF/XML e retransmitir seguem normais.
- A política de papéis é a das notas (`garantirPodeRascunhar`/`garantirPodeEmitir`).

## API (`/api/app`)

- `POST /vendas`, `PUT /vendas/{id}`, `DELETE /vendas/{id}`, `GET /vendas/{id}` (venda + as duas notas com status), `POST /vendas/{id}/emitir` (`emitir_apenas_validas` opcional).
- `SalvarVendaRequest` valida formato e limites (mesmos limites de itens, quantidade e desconto das notas).
- Isolamento por tenant: `VendaIsolamentoTest` (listar/ler/alterar/emitir/excluir venda de outro tenant dá 404).

## Frontend

- Rota `/vendas/nova` (e `/vendas/[id]/editar`): cliente, bloco de itens de produto, bloco de itens de serviço, desconto geral, pagamentos, informações complementares. Mostra a prévia do rateio (desconto de cada nota, total da NF-e e da NFS-e) calculada com a mesma regra do backend (centavos inteiros).
- Rota `/vendas/[id]`: as duas notas lado a lado (status, número, link para o detalhe de cada uma), botão "Emitir as duas". Se a resposta for 409 de bloqueio, lista a mensagem de cada nota e oferece "Emitir só a válida".
- Reaproveita os componentes de itens de `NotaForm`/`NotaDeServicoForm`. Menu "Notas" ganha o atalho "Nova venda (produtos e serviços)".
- TypeScript strict, zod, react-hook-form, TanStack Query, como o restante.

## Testes

- Unidade: rateio entre duas notas (soma exata, resíduo, desconto 0, desconto igual ao subtotal, subtotal de um lado zero não ocorre pela exigência dos dois tipos).
- Feature: criar (duas notas, `venda_id`, totais, descontos que somam o geral); exigências (sem serviço, sem produto, sem cliente, módulo ausente); atualizar refaz o rateio; excluir; emitir as duas; uma bloqueada sem e com confirmação; falha de uma não afeta a outra; edição direta de nota da venda bloqueia; isolamento por tenant; zero é desconto válido.
- Frontend (Vitest): schema, prévia do rateio, fluxo de emissão com bloqueio.

## Pendências a registrar em TAREFAS.md

Cancelar a venda inteira; NFC-e na venda mista; várias notas por venda; a homologação real da venda mista depende da homologação das duas notas (F3a e F4a).
