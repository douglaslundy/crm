# 0002: Isolamento de tenant por coluna, fail-closed

- **Status:** aceito (2026-09-25).
- **Decisão:**
  - Banco único com `tenant_id` em toda tabela de tenant.
  - Trait `BelongsToTenant` com Global Scope que, **sem contexto**, devolve zero linhas.
  - Criar registro sem contexto, ou com um `tenant_id` diferente do contexto, lança `TenantNaoDefinidoException`.
  - Acesso cross-tenant só via `withoutTenantScope()`, de forma explícita e auditável.
- **Contexto do tenant:**
  - Na requisição autenticada, definido pelo middleware `DefinirTenantDoUsuario`.
  - Em jobs, o tenant é passado pelo construtor (a partir da F3).
- **Exceção:** o `Usuario` não usa o trait. O login precisa localizar o usuário antes de haver tenant, e o admin da plataforma não tem tenant. Listagens de usuários do tenant (F1) filtram explicitamente por `tenant_id`.
