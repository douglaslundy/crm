# 0001: Monólito modular em Laravel

- **Status:** aceito (2026-09-25).
- **Contexto:** equipe pequena, deploy único, transações que cruzam módulos (Fiscal, CRM, Billing).
- **Decisão:** o código de negócio fica em `app/Modules/<Modulo>/{Domain,Application,Http,Providers}`. Um módulo não acessa as tabelas nem os Models de outro. A integração é por Actions ou contratos públicos e por eventos de domínio. Cada módulo registra as próprias rotas no seu ServiceProvider.
- **Consequências:**
  - Deploy simples e refatoração segura.
  - Dá para extrair um serviço no futuro se houver necessidade.
  - A disciplina de fronteira é cobrada em code review.
