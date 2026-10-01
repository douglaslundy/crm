# F2: Cadastros e emitente (spec da fase)

- **Status:** APROVADA (2026-10-01).
- **Data:** 2026-10-01.
- **Spec mestre:** `2026-09-25-plataforma-fiscal-crm-design.md`, §3, §4, §5, §6, §8 e §15 (linha F2).
- **Critério de pronto (da spec mestre):** o tenant cadastra tudo o que a emissão exige, e as pendências aparecem.

## 1. Decisões do usuário (2026-10-01)

| Tema | Decisão |
|---|---|
| Módulo do emitente | Novo módulo `Fiscal`, separado do `Tenancy`. O `Tenancy` fica só com identidade (razão social, fantasia, CNPJ) e situação da assinatura; o `Fiscal` ganha a fatia de emitente/certificado/séries desta fase (motores e notas ficam para F3/F4). |
| Fluxo do emitente | Wizard guiado logo após a ativação do tenant, cobrindo os passos 4 a 8 do §5 da spec mestre (dados da empresa, dados fiscais, certificado, CSC, séries). Dá para sair e retomar depois; os mesmos dados continuam editáveis em Configurações. |
| Validação do certificado A1 | Parse nativo com `openssl_pkcs12_read()` no upload, sem antecipar a dependência `nfephp-org/sped-nfe` (essa só entra na F3). Senha errada ou arquivo corrompido rejeita na hora; sucesso extrai validade e titular. |
| Papéis × emitente | `PROPRIETARIO`, `ADMIN` e `FISCAL` editam os dados fiscais do emitente (empresa, certificado, CSC, séries). `VENDEDOR` e `LEITURA` não têm acesso de escrita. |
| Papéis × cadastros | `ADMIN`, `FISCAL` e `VENDEDOR` cadastram e editam clientes, produtos e serviços. `LEITURA` só lê. |
| Importação CSV | Cria e atualiza por chave: linha com CPF/CNPJ (cliente) ou SKU (produto) já existente atualiza o registro; linha nova cria. |
| Ciclo de vida do cliente | A F2 constrói a transição `LEAD → CLIENTE` (ação explícita), não só o campo. O CRM (F7) reaproveita o mesmo campo nos funis. |

## 2. Escopo

**Entra:**
- Módulo `Customers`: clientes (PF/PJ) e contatos.
- Módulo `Catalog`: produtos, serviços, categorias fiscais padrão, tela de pendências fiscais.
- Módulo `Fiscal` (só a fatia de emitente nesta fase): dados da empresa e endereço, dados fiscais (regime, IE, IM, CNAE), certificado A1, CSC, séries e numeração por documento.
- Wizard de onboarding pós-ativação para o emitente.
- Importação por CSV de clientes, produtos e serviços.
- `Shared`: value object `Cpf` e serviço `ConsultaCep` (ViaCEP).
- Endpoints equivalentes em `/api/v1` para `clientes`, `produtos` e `servicos`, reaproveitando as mesmas Actions (sem lógica própria na API).
- Aplicação de `EntitlementService::garantirCapacidade()` para os recursos `CLIENTES`, `PRODUTOS` e `SERVICOS` nas Actions de criação.

**Não entra:**
- Qualquer motor de emissão (NFePHP), fila, notas fiscais, PDF, contingência ou reconciliação (F3/F4).
- Validação do CSC contra a SEFAZ (sem motor, não há como).
- Passo 9 do onboarding ("emissão de teste em homologação"), que depende do motor.
- Importação de XML de compra para preencher produtos (§12 da spec mestre, fora do escopo inicial).
- Funis, negócios e demais telas do CRM (F7); a F2 só entrega o campo e a transição `LEAD → CLIENTE`.

## 3. Módulos e limites

- Novos módulos em `backend/app/Modules/{Customers,Catalog,Fiscal}/{Domain,Application,Http,Database,Tests}`, seguindo o padrão de fronteiras já estabelecido (um módulo não acessa Models de outro; comunicação por Actions/eventos).
- `Fiscal` depende de `Catalog` e `Customers` (conforme a tabela de dependências da spec mestre), mas nesta fase isso ainda não se manifesta em código — só se manifesta quando a emissão (F3) precisar ler produto/cliente.
- Todo Model novo tem `tenant_id`, Global Scope e teste de isolamento (ADR 0002).
- `Recurso::Clientes|Produtos|Servicos` já existem no enum da F1; as Actions de criação chamam `EntitlementService::garantirCapacidade()` antes de persistir. Limite atingido devolve `422` com `codigo: LIMITE_DO_PLANO`, igual ao padrão da F1.
- O módulo `FISCAL_NFCE` do plano decide se o wizard exige CSC; os demais campos de emitente não dependem de módulo contratado.

## 4. `Shared`: novos utilitários

**`Cpf`** (`App\Modules\Shared\Domain\Cpf`), espelhando `Cnpj`:
- `de(string): self` normaliza (remove `.` e `-`), valida os 2 dígitos verificadores pelo módulo 11 e rejeita sequências de dígito repetido.
- `tentar(string): ?self` e `formatado(): string` (`000.000.000-00`), mesma forma que `Cnpj`.
- Lança `CpfInvalidoException` (nova, ao lado de `CnpjInvalidoException`).

**`ConsultaCep`** (`App\Modules\Shared\Infrastructure\ConsultaCep`):
- Espelha `ConsultaCnpj`: consulta `viacep.com.br/ws/{cep}/json`, timeout de 5 s, cache de 24 h por CEP.
- Resposta: `{logradouro, bairro, cidade, uf, codigo_ibge}`, ou `null` se o CEP não existir.
- Falha do serviço não bloqueia o formulário: lança `ConsultaCepIndisponivelException`, e o frontend libera o preenchimento manual (mesmo padrão do CNPJ na F1).

## 5. `Customers`: clientes e contatos

**`clientes`**
- `id` (uuid), `tenant_id`, `tipo` (`PF|PJ`), `nome`, `cpf_cnpj` (string, sem máscara), `inscricao_estadual` (nullable), `ie_isento` (bool, default `false`).
- `email` (nullable), `telefone` (nullable).
- Endereço: `logradouro, numero, bairro, cidade, uf, cep, codigo_ibge` (todos nullable — lead pode não ter nada disso).
- `tags` (json, lista de strings livres), `origem` (string nullable, texto livre, ex. "WhatsApp", "Indicação").
- `estagio` (`LEAD|CLIENTE`, default `LEAD`).
- Timestamps.
- Índice único composto `(tenant_id, cpf_cnpj)` quando `cpf_cnpj` não é nulo (permite cadastrar lead só com nome, mas não duplicar CPF/CNPJ).

**`contatos`**
- `id`, `tenant_id`, `cliente_id` (FK), `nome`, `cargo` (nullable), `email` (nullable), `telefone` (nullable).
- Só faz sentido para cliente `PJ`; a regra é validada na Request, não travada no banco.

**Nenhuma exigência fiscal no cadastro.** `cpf_cnpj`, quando preenchido, é validado pelo DV (`Cpf`/`Cnpj` conforme `tipo`), mas pode ficar vazio enquanto `estagio=LEAD`. O bloqueio de dado fiscal ausente só existe na emissão (F3), nunca aqui.

**Transição de estágio:**
- `POST clientes/{id}/converter-em-cliente`: muda `LEAD → CLIENTE`. Sem pré-requisito de dado fiscal (a spec mestre é explícita: a exigência é só na emissão). Transição contrária (`CLIENTE → LEAD`) não existe nesta fase.

## 6. `Catalog`: produtos, serviços e categorias fiscais

**`produtos`**
- `id`, `tenant_id`, `sku` (único por tenant), `nome`, `unidade`, `preco` (centavos, `Dinheiro`), `gtin` (nullable).
- Fiscais: `ncm` (nullable, 8 dígitos), `cest` (nullable, 7 dígitos), `origem` (smallint nullable, 0–8 — **`0` é valor válido**), `tributacao_icms` (`NORMAL|ST`, nullable).
- `fiscal_fonte` (`MANUAL|PADRAO`; o valor `XML` fica reservado para a importação de XML de compra, fora do escopo — ver §2), `fiscal_revisado_em` (nullable).

**`servicos`**
- `id`, `tenant_id`, `nome`, `preco` (centavos), `codigo_lc116`, `c_trib_nac` (6 dígitos), `codigo_municipal` (nullable, 3 dígitos), `aliquota_iss` (decimal), `nbs` (nullable).

**`categorias_fiscais_padrao`**
- `id`, `tenant_id`, `categoria` (label), `ncm`, `origem`, `tributacao_icms`.
- Ação `POST produtos/{id}/aplicar-categoria/{categoria_id}`: preenche **só os campos vazios** do produto (nunca sobrescreve um valor já preenchido) e marca `fiscal_fonte=PADRAO`, `fiscal_revisado_em=null`.

**Pendências fiscais** (produtos e serviços sem dado obrigatório, ou com `fiscal_fonte=PADRAO` ainda não revisado):
- `GET produtos/pendencias-fiscais`.
- `POST produtos/{id}/marcar-revisado` → grava `fiscal_revisado_em = now()`.
- Serviços entram na mesma lista quando faltar `codigo_lc116`, `c_trib_nac` ou `aliquota_iss`.

**Validação fiscal** (`ValidadorCamposFiscais`, reaproveitando a regra já documentada em `06-regras-dados-e-cadastros.md`): NCM com 8 dígitos, CEST com 7, origem de 0 a 8. Valor malformado vira `null` (nunca o valor cru). **Regra de ouro:** `=== null`, nunca `empty()` ou `?? 0`.

## 7. `Fiscal`: emitente (1:1 com o tenant)

**`emitentes`**
- `id`, `tenant_id` (único — 1:1).
- Endereço: `logradouro, numero, bairro, cidade, uf, cep, codigo_ibge`.
- Dados fiscais: `regime_tributario`, `inscricao_estadual`, `inscricao_municipal`, `cnae`.
- `ambiente_fiscal` (`HOMOLOGACAO|PRODUCAO`), sempre nasce `HOMOLOGACAO`. Ir para `PRODUCAO` exige confirmação explícita (endpoint próprio) e fica auditado.
- Certificado: `certificado_pfx_encrypted`, `certificado_senha_encrypted` (ambos `Crypt`), `certificado_validade` (date), `certificado_titular`, `certificado_status` (`PENDENTE|VALIDO|VENCIDO|INVALIDO`).
- CSC: `csc_id_homologacao`, `csc_token_homologacao_encrypted`, `csc_id_producao`, `csc_token_producao_encrypted` — exigidos pelo wizard só quando o plano do tenant tem o módulo `FISCAL_NFCE`.

**`emitente_series`**
- `id`, `tenant_id`, `emitente_id`, `modelo` (`NFE|NFCE|DPS`), `serie`, `proximo_numero`.
- Índice único `(tenant_id, modelo)`. Uma linha por modelo — mais simples que os pares de coluna do Mecânica Pro, porque aqui só existe um motor (NFePHP), sem contador por vendor.
- Editável livremente nesta fase (não há nota emitida ainda); a trava contra edição depois da primeira emissão é responsabilidade da F3.

**Upload do certificado** (`POST emitente/certificado`, multipart `arquivo` + `senha`):
1. Lê o `.pfx` em memória (nunca grava em disco fora de um arquivo temporário `0600`, apagado no `finally`).
2. `openssl_pkcs12_read($conteudo, $certs, $senha)`. Falha → `422 CERTIFICADO_SENHA_INVALIDA` ou `422 CERTIFICADO_ARQUIVO_INVALIDO`.
3. Sucesso: extrai validade (`openssl_x509_parse`) e titular (`CN`), grava `certificado_status` (`VALIDO` se a validade é futura, `VENCIDO` se já passou), criptografa e salva arquivo + senha.
4. Alertas de vencimento (90/60/30/14/7 dias) ficam de infraestrutura para a F3, quando existir um comando agendado rodando de verdade (nesta fase só o campo `certificado_validade` já existe para sustentar isso depois).

## 8. Wizard de onboarding do emitente

Sequência exibida uma vez após a ativação do tenant (situação `TESTE` ou `ATIVA`), reentrante — o tenant pode sair e retomar, e os mesmos dados continuam editáveis depois em `Configurações › Empresa/Fiscal/Certificado/Séries`:

1. **Dados da empresa:** endereço (CEP com `ConsultaCep` autocompletando logradouro/bairro/cidade/UF/IBGE, editável).
2. **Dados fiscais:** regime tributário, IE, IM, CNAE.
3. **Certificado A1:** upload + senha (fluxo do §7).
4. **CSC:** só aparece se o plano tem `FISCAL_NFCE`; IDs e tokens de homologação e produção.
5. **Séries:** série e próximo número por modelo contratado (`NFE` sempre; `NFCE` só se o módulo estiver no plano; `DPS` só se `FISCAL_NFSE` estiver no plano).

Cada passo salva isoladamente (sem transação única cobrindo o wizard inteiro); o tenant pode avançar com um passo pendente e completar depois. A tela de pendências do dashboard (reaproveitando o padrão de faixas da F1) aponta o que falta do emitente, além das pendências fiscais de produtos/serviços do §6.

## 9. Papéis e permissões

| Área | PROPRIETARIO | ADMIN | FISCAL | VENDEDOR | LEITURA |
|---|---|---|---|---|---|
| Emitente (empresa, fiscal, certificado, CSC, séries) | escreve | escreve | escreve | — | lê |
| Clientes, produtos, serviços, categorias | escreve | escreve | escreve | escreve | lê |
| Pendências fiscais / marcar revisado | escreve | escreve | escreve | — | lê |
| Importação CSV | escreve | escreve | escreve | — | — |

`LEITURA` nunca escreve em nada desta fase. `VENDEDOR` não toca no emitente nem nas pendências fiscais (ação mais técnica, de ADMIN/FISCAL), mas cadastra e edita clientes/produtos/serviços no dia a dia.

## 10. Importação CSV

- Um endpoint por recurso: `POST clientes/importar`, `POST produtos/importar`, `POST servicos/importar` (multipart, arquivo `.csv`).
- Processamento síncrono linha a linha (volumes pequenos nesta fase; se crescer, vira fila numa fase posterior — não antecipar).
- Chave de casamento: `cpf_cnpj` para clientes, `sku` para produtos, e **nome** para serviços (não há campo único natural; linha com nome igual ao de um serviço existente atualiza).
- Linha com chave existente → `update()`; linha nova → `create()`. Mesmas regras de validação da tela (incluindo `EntitlementService` ao criar, nunca ao atualizar).
- Linha inválida (campo obrigatório ausente, CPF/CNPJ malformado, etc.) entra no relatório de erros com o número da linha e o motivo; **não aborta o lote**.
- Resposta: `{importados, atualizados, erros: [{linha, motivo}]}`.
- Limite de linhas por arquivo a definir no plano de implementação (proteção contra upload gigante), mas sem acoplar ao limite do plano de assinatura (`EntitlementService` barra na criação linha a linha, não no tamanho do arquivo).

## 11. API pública (`/api/v1`)

- `clientes`, `produtos`, `servicos`: CRUD completo, reaproveitando **as mesmas Actions** do frontend (`Application`), como define o §8 da spec mestre. Nenhuma regra nova no controller da API.
- Autenticação e formato de erro (RFC 9457) já definidos na spec mestre; esta fase só liga os recursos às Actions existentes. A infraestrutura de API key, idempotência e rate limit propriamente ditos é da F6 — nesta fase os endpoints ficam definidos mas atrás do mesmo guard de sessão usado pelo frontend (`empresa`), até a F6 trocar por chave de API.

## 12. Testes (mínimo por área)

- **Isolamento de tenant** para `Cliente`, `Contato`, `Produto`, `Servico`, `CategoriaFiscalPadrao`, `Emitente`, `EmitenteSerie`.
- **`Cpf`:** válido, inválido, dígitos repetidos — mesma cobertura que `Cnpj` já tem.
- **`ConsultaCep`:** sucesso, CEP inexistente (404), serviço indisponível (`Http::fake()`).
- **Certificado:** senha certa, senha errada, arquivo corrompido, certificado já vencido (`certificado_status=VENCIDO`).
- **Validação fiscal de produto:** `origem=0` tratado como preenchido (nunca como ausente); NCM/CEST malformado vira `null`, nunca o valor cru.
- **Categoria fiscal padrão:** aplicar categoria preenche só os campos vazios; não sobrescreve um campo já preenchido.
- **Pendências fiscais:** produto com campo faltante aparece na lista; `marcar-revisado` tira da lista.
- **CSV:** criação, atualização por chave, linha inválida não derruba o lote, limite do plano (`EntitlementService`) barrando a criação no meio do arquivo.
- **Transição de estágio:** `LEAD → CLIENTE` sem exigir dado fiscal.
- **Papéis:** matriz do §9 testada nos endpoints de emitente e de cadastros (cada papel tentando escrever onde não pode → `403`).
- **Limite de plano:** criação de cliente/produto/serviço acima do limite → `422 LIMITE_DO_PLANO`, igual ao padrão da F1.

## 13. Riscos

- **ViaCEP é um serviço comunitário**, sem SLA — por isso nunca é obrigatório e tem cache, igual à BrasilAPI na F1.
- **Parse de certificado sem a lib do motor** cobre validade/senha, mas não substitui a validação completa que o `sped-nfe` fará na F3 (cadeia de confiança ICP-Brasil, por exemplo). Aceito nesta fase porque o objetivo é feedback rápido no upload, não validação fiscal completa.
- **Séries editáveis livremente nesta fase** é seguro porque não existe nota emitida ainda; a F3 precisa adicionar a trava (não permitir diminuir o próximo número, ou editar depois da primeira emissão).
- **CSV sem fila:** processamento síncrono é aceitável para o volume esperado nesta fase; arquivos grandes podem exigir mover para fila numa fase posterior.
