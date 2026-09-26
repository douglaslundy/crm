# Backlog por fase

Detalhes em `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`, §15. Cada fase tem uma spec e um plano próprios antes de ter código.

- [x] **F0 Fundação:** repositório, Docker, CI, Laravel + Next, monólito modular, autenticação, tenancy com isolamento testado, layout responsivo com temas dark e light.
- [ ] **F1 Plataforma e planos:** admin do SaaS, planos (módulos e limites), onboarding de empresa, usuários e papéis, `EntitlementService`.
- [ ] **F2 Cadastros e emitente:** clientes, produtos, serviços, categorias fiscais, pendências, CSV, dados fiscais, certificado, CSC, séries.
- [ ] **F3 NF-e e NFC-e** (NFePHP): emissão, fila, reconciliação, PDF, cancelamento, inutilização, contingência, dashboard fiscal.
- [ ] **F4 NFS-e Nacional** (`nfse-php`): DANFSe, cancelamento, venda mista.
- [ ] **F5 Cobrança** (Mercado Pago): checkout transparente, assinatura, webhooks, inadimplência, excedente.
- [ ] **F6 API pública:** `/api/v1`, chaves live/test, idempotência, webhooks de saída, OpenAPI e guia.
- [ ] **F7 CRM:** funis, negócios, atividades, timeline, propostas → nota, relatórios, metas.
- [ ] **F8 Go-live:** auditoria fiscal, carga, backup e restauração, piloto em produção.

## Pendências herdadas da revisão da F0 (resolver no início da F1)
- [ ] Tirar `tenant_id` e `papel` de `Usuario::$fillable` (escalada de tenant ou privilégio por mass assignment).
- [ ] Pôr `DefinirTenantDoUsuario` na prioridade de middleware, antes do `SubstituteBindings` (route model binding de Models de tenant).
- [ ] Revalidar `ativo` do usuário a cada requisição (usuário desativado hoje mantém a sessão).
- [ ] Frontend: `MutationCache.onError` para 401, e revalidar `/me` com o app aberto.
- [ ] Acessibilidade: `aria-invalid` e `aria-describedby` em todos os campos. Form de redefinição deve mostrar erro de e-mail/token vindo do link.
- [ ] Testes de token de redefinição expirado e reutilizado. Job de CI com PostgreSQL.
- [ ] `.env.example`: `QUEUE_CONNECTION=sync` no local. Documentar `SESSION_DOMAIN`/`SESSION_SECURE_COOKIE` de produção. Origem não stateful devolve 500.
- [ ] Avaliar limite adicional por IP no login (spray de e-mails).
