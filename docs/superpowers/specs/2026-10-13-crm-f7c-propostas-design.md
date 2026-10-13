# F7c: Propostas, aceite público e nota em 1 clique

Data: 2026-10-13. Fase da F7 (CRM). Depende da F7a (funis e negócios) e da F7b (timeline). Spec mestre: `2026-09-25-plataforma-fiscal-crm-design.md` (§9: "Propostas e orçamentos em PDF, com link público para aceite. Aceite → negócio ganho → emissão da nota em 1 clique").

## Objetivo

Permitir que o vendedor gere uma proposta a partir de um negócio, envie um link público ao cliente, receba o aceite ou a recusa sem login, e, com o aceite, ter o negócio ganho e um rascunho de nota fiscal pré-preenchido a um clique de distância. Tudo aparece na timeline do cliente.

## Decisões (todas confirmadas com o usuário)

1. **Aceite → nota:** o aceite ganha o negócio e libera o botão "Emitir nota", que cria um rascunho com cliente e itens da proposta e abre o editor fiscal. Modelo da nota e pagamento não são chutados: o vendedor completa no editor e emite. Nenhuma emissão automática.
2. **Link público:** página com itens e total, download do PDF e botões Aceitar e Recusar (nome do responsável pelo aceite obrigatório; motivo da recusa opcional). Sem login.
3. **Modelo:** N propostas por negócio, cada uma com snapshot dos itens do negócio na criação, congelado ao enviar. Aceitar uma cancela as demais abertas do mesmo negócio.
4. **Envio:** o vendedor copia o link ou usa os botões de WhatsApp/e-mail da F7b (que já registram o contato). Sem envio de e-mail pelo servidor. Validade em dias definida na proposta (padrão 15).
5. **Aceite ganha o negócio automaticamente**, com autor "aceite do cliente" atribuído ao responsável do negócio.

Fora de escopo: envio de e-mail pelo servidor, assinatura eletrônica, versionamento com diff, templates de PDF por tenant, desconto por item, emissão automática da nota.

## Dados

Migration nova, tabelas com `tenant_id` e Global Scope (`BelongsToTenant`):

- `crm_propostas`: `id` (uuid), `tenant_id`, `negocio_id`, `cliente_id`, `numero` (inteiro sequencial por tenant, índice único `tenant_id+numero`), `status` (`RASCUNHO`, `ENVIADA`, `ACEITA`, `RECUSADA`, `CANCELADA`), `validade_dias`, `validade_em` (nulo até enviar), `desconto_centavos` (inteiro, padrão 0), `observacoes` (nulável), `token_hash` (sha256 do token, único, nulo até enviar), `token` (criptografado, para reexibir o link), `enviada_em`, `respondida_em`, `aceite_nome`, `aceite_ip`, `motivo_recusa`, `nota_id` (nulável, sem FK entre módulos: id solto), `criado_por`, timestamps.
- `crm_itens_da_proposta`: `proposta_id`, `tipo`, `item_id`, `descricao`, `quantidade`, `valor_unitario_centavos`, `ordem`.

Dinheiro sempre em centavos inteiros. Total = soma dos itens − desconto; desconto maior que o subtotal é bloqueado com mensagem acionável. Zero é válido (desconto 0).

"Expirada" não é status gravado: a leitura calcula `ENVIADA` com `validade_em < agora` e apresenta `EXPIRADA`, sem job (mesmo padrão do negócio parado). Aceitar e recusar bloqueiam proposta expirada.

## Application (Actions em `Modules/Crm`)

- `CriarProposta(Usuario, Negocio, dados)`: exige negócio aberto com ao menos um item; copia os itens; numera com lock; grava timeline.
- `AtualizarProposta`: só em `RASCUNHO` (desconto, observações, validade_dias).
- `EnviarProposta`: congela, gera token de 256 bits, define `validade_em`, status `ENVIADA`; grava `PROPOSTA_ENVIADA`.
- `CancelarProposta`: de `RASCUNHO` ou `ENVIADA`; invalida o link.
- `AceitarProposta(token, nome, ip)` e `RecusarProposta(token, motivo?, ip)`: públicas. Transação com `lockForUpdate`; exige `ENVIADA` e não expirada; aceitar marca `ACEITA`, ganha o negócio (reuso da regra de `GanharNegocio` sem exigir `Usuario` autenticado: extrair o núcleo para uma classe interna compartilhada), cancela as outras propostas abertas do negócio, grava `PROPOSTA_ACEITA`. Aceite repetido responde de forma idempotente com o estado atual.
- `GerarRascunhoDeNotaDaProposta(Usuario, Proposta)`: exige `ACEITA` e módulo Fiscal no plano; chama as Actions existentes do Fiscal (`CriarRascunhoDeNota`; venda mista se houver produto e serviço) por um contrato em `Crm/Domain/Contracts` implementado no módulo Fiscal, como já é feito com Catalog e Customers. Grava `nota_id` e `PROPOSTA_NOTA_GERADA`. Segunda chamada devolve o mesmo rascunho. Pagamento fica vazio no rascunho: a emissão bloqueia com a mensagem existente.
- `GeradorDePdfDaProposta`: dompdf, a partir do snapshot e dos dados da empresa; sem cálculo fiscal.
- Permissões: criar, editar, enviar e cancelar seguem `PoliticaDoCrm::garantirPodeOperarNegocios`; vendedor só vê propostas dos seus negócios (mesma regra `restritoParaVendedor`).

## API

Autenticada, sob `api/app/crm`: `GET/POST negocios/{id}/propostas`, `GET/PATCH propostas/{id}`, `POST propostas/{id}/enviar`, `POST propostas/{id}/cancelar`, `POST propostas/{id}/nota`, `GET propostas/{id}/pdf`.

Pública, sob `api/public/propostas/{token}`: `GET` (dados), `GET pdf`, `POST aceitar`, `POST recusar`. Regras: sem contexto de tenant na entrada; o tenant é resolvido pelo `token_hash` e fixado antes de qualquer leitura; token inválido, cancelado ou de outro formato responde 404 genérico (sem diferenciar); throttle por IP; resposta só com campos da proposta (empresa, cliente, itens, totais, validade, status), nunca ids internos de usuário. `aceite_ip` é guardado para auditoria e citado na política de privacidade.

## Timeline

Novos tipos em `TipoDeRegistro`: `PROPOSTA_ENVIADA`, `PROPOSTA_ACEITA`, `PROPOSTA_RECUSADA`, `PROPOSTA_NOTA_GERADA`. Gravados pelo `RegistradorDaTimeline` dentro das Actions (o aceite público não depende de listener).

## Frontend

- Aba "Propostas" no `DetalheDoNegocio`: lista com número, status, total e validade; criar a partir do negócio, editar rascunho, enviar, copiar link, abrir WhatsApp/e-mail (hooks de contato existentes), cancelar, baixar PDF, "Emitir nota" (só `ACEITA` e plano com Fiscal) que leva ao editor da nota.
- Página pública `app/p/[token]/page.tsx`, fora de `(app)`, sem menu nem sessão: itens, total, validade, PDF, Aceitar (pede nome) e Recusar (motivo opcional), estados enviada/aceita/recusada/expirada/cancelada.
- Timeline do cliente exibe os novos registros.

## Erros e regras invioláveis

Dado ausente bloqueia com mensagem acionável (negócio sem itens, desconto maior que o subtotal, plano sem Fiscal). `=== null` para opcionais. Nenhuma regra tributária na proposta: ela é comercial, a nota é o Fiscal. Todo endpoint novo tem teste de isolamento (incluindo a rota pública: token de um tenant nunca lê outro).

## Testes

Backend: criação copia itens e numera sem colisão; rascunho editável e enviada imutável; token inválido/cancelado/expirado → 404 ou bloqueio; aceite duplo concorrente grava um só aceite e um só ganho; aceite ganha negócio, cancela irmãs e grava timeline; recusa não mexe no negócio; rascunho de nota idempotente e sem pagamento; PDF gera; isolamento de tenant em toda rota; vendedor não vê proposta de colega. Frontend: aba, ações por status, página pública (aceitar, recusar, expirada), botão "Emitir nota" condicionado ao plano.

## Pendências a decidir no plano

Forma exata do contrato Crm→Fiscal para venda mista; onde fica a rota pública no kernel de tenancy (padrão do webhook de billing); texto de aviso de privacidade na página pública.
