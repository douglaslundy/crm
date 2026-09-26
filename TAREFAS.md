# Backlog por fase

Detalhes em `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`, §15. Cada fase tem uma spec e um plano próprios antes de ter código.

- [ ] **F0 Fundação:** repositório, Docker, CI, Laravel + Next, monólito modular, autenticação, tenancy com isolamento testado, layout responsivo com temas dark e light.
- [ ] **F1 Plataforma e planos:** admin do SaaS, planos (módulos e limites), onboarding de empresa, usuários e papéis, `EntitlementService`.
- [ ] **F2 Cadastros e emitente:** clientes, produtos, serviços, categorias fiscais, pendências, CSV, dados fiscais, certificado, CSC, séries.
- [ ] **F3 NF-e e NFC-e** (NFePHP): emissão, fila, reconciliação, PDF, cancelamento, inutilização, contingência, dashboard fiscal.
- [ ] **F4 NFS-e Nacional** (`nfse-php`): DANFSe, cancelamento, venda mista.
- [ ] **F5 Cobrança** (Mercado Pago): checkout transparente, assinatura, webhooks, inadimplência, excedente.
- [ ] **F6 API pública:** `/api/v1`, chaves live/test, idempotência, webhooks de saída, OpenAPI e guia.
- [ ] **F7 CRM:** funis, negócios, atividades, timeline, propostas → nota, relatórios, metas.
- [ ] **F8 Go-live:** auditoria fiscal, carga, backup e restauração, piloto em produção.
