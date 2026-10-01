# Backlog por fase

Detalhes em `docs/superpowers/specs/2026-09-25-plataforma-fiscal-crm-design.md`, §15. Cada fase tem uma spec e um plano próprios antes de ter código.

- [x] **F0 Fundação:** repositório, Docker, CI, Laravel + Next, monólito modular, autenticação, tenancy com isolamento testado, layout responsivo com temas dark e light.
- [x] **F1 Plataforma e planos:** admin do SaaS, planos (módulos e limites), onboarding de empresa, usuários e papéis, `EntitlementService`.
- [ ] **F2 Cadastros e emitente** (tarefas 1 a 24 de 28 prontas; faltam 25 a 28): clientes, produtos, serviços, categorias fiscais, pendências, CSV, dados fiscais, certificado, CSC, séries.
- [ ] **F3 NF-e e NFC-e** (NFePHP): emissão, fila, reconciliação, PDF, cancelamento, inutilização, contingência, dashboard fiscal.
- [ ] **F4 NFS-e Nacional** (`nfse-php`): DANFSe, cancelamento, venda mista.
- [ ] **F5 Cobrança** (Mercado Pago): checkout transparente, assinatura, webhooks, inadimplência, excedente.
- [ ] **F6 API pública:** `/api/v1`, chaves live/test, idempotência, webhooks de saída, OpenAPI e guia.
- [ ] **F7 CRM:** funis, negócios, atividades, timeline, propostas → nota, relatórios, metas.
- [ ] **F8 Go-live:** auditoria fiscal, carga, backup e restauração, piloto em produção.

## Pendências herdadas da revisão da F0 (resolver no início da F1)
- [x] Tirar `tenant_id` e `papel` de `Usuario::$fillable` (escalada de tenant ou privilégio por mass assignment).
- [x] Pôr `DefinirTenantDoUsuario` na prioridade de middleware, antes do `SubstituteBindings` (route model binding de Models de tenant).
- [x] Revalidar `ativo` do usuário a cada requisição (usuário desativado hoje mantém a sessão).
- [x] Frontend: `MutationCache.onError` para 401, e revalidar `/me` com o app aberto.
- [x] Acessibilidade: `aria-invalid` e `aria-describedby` em todos os campos. Form de redefinição deve mostrar erro de e-mail/token vindo do link.
- [x] Testes de token de redefinição expirado e reutilizado. Job de CI com PostgreSQL.
- [x] `.env.example`: `QUEUE_CONNECTION=sync` no local. Documentar `SESSION_DOMAIN`/`SESSION_SECURE_COOKIE` de produção. Origem não stateful devolve 500.
- [x] Avaliar limite adicional por IP no login (spray de e-mails).

## Pendências da revisão da F1

Achados menores adiados e uma decisão de produto parcada durante a revisão das 18 tarefas da F1 (ledger em `.superpowers/sdd/2026-09-27-f1-plataforma-e-planos/progress.md`). Nenhum bloqueia a F1; ficam como candidatos a limpeza ou decisão futura.

**Decisão de produto parcada:**
- [x] Papel PROPRIETARIO irreversível: decidido manter (2026-10-01).

**Cosméticos e dívidas técnicas menores:**
- [x] `backend/bootstrap/app.php` sem `declare(strict_types=1)` (pré-existente).
- [ ] `Cnpj`: arrays `PESOS_DV1`/`PESOS_DV2` quase idênticos.
- [ ] Sem teste HTTP específico de `CnpjInvalidoException` (422 `CNPJ_INVALIDO`).
- [x] Migrations publicadas do pacote `activitylog` sem `declare(strict_types=1)`.
- [ ] `ExpirarTestesCommand` conta `$total` por referência (cosmético).
- [x] Sem teste cross-tenant para `ContadorDeUsuariosAtivos`.
- [ ] `limite()` e `consumo()` do `EntitlementService` com expressões quase idênticas.
- [ ] `AssinaturaController`: `firstOrFail` com tenant órfão devolve 404 com mensagem em inglês.
- [ ] Bloco `/** @var Usuario $autor */` repetido nos controllers de admin.
- [ ] Busca de empresas sem dígitos ainda adiciona `OR cnpj LIKE` (inofensivo; CNPJ alfanumérico pode casar letras).
- [ ] `CadastrarEmpresa`: `firstOrFail` em corrida de plano desativado devolve 404 genérico em inglês.
- [ ] BrasilAPI: resposta 200 com corpo vazio é cacheada como "encontrado".
- [ ] `GET /api/publico/planos` sem throttle.
- [x] Reativar usuário já ativo não é barrado (422 enganoso ou auditoria espúria).
- [ ] `unique` de e-mail fora do lock: corrida de convites para o mesmo e-mail pode dar 500.
- [ ] Lacunas de teste em convites: POST de PROPRIETARIO por ADMIN, reuso de token de convite, token do broker `users` no aceitar-convite, papéis FISCAL/LEITURA.
- [ ] Workaround `forgetGuards`/`flushSession` repetido nos testes — criar helper no `TestCase`.
- [ ] Listagem de usuários da empresa sem paginação (plano ilimitado).
- [x] `EditarUsuario`/`DesativarUsuario` gravam e auditam fora de transação.
- [ ] Commit de correção `83bf928` com mensagem em inglês.
- [ ] `Campo` sem teste do caso sem dica e sem erro.
- [ ] Faixas de cor do `ConsumoDoPlano` (80%/100%) e ramos INADIMPLENTE/CANCELADA da `FaixaSituacao` sem teste.
- [ ] Warning de lint `react-hooks/incompatible-library` por `form.watch` no `CadastroForm` (trocar por `useWatch`).
- [ ] Checkbox de aceite de termos fora do componente `Campo`.
- [ ] `ListaDePlanosAdmin` sem estado vazio.
- [ ] `TrocarPlanoForm` sem tratamento de erro ao carregar planos.
- [ ] `EditarUsuarioForm` sem teste.
- [ ] Mensagem de convite inválido duplicada entre frontend e backend, sem teste de contrato entre os dois.
