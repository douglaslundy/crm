# F8b: pendências de go-live (design)

Data: 2026-10-16. Caminho: arquitetural (spec, plano, execução inline na master). Origem: `TAREFAS.md` F8b e achado de segurança da F8.

## Objetivo

Remover os três bloqueios de código/configuração para operar em produção: verificação TLS desligada nas libs fiscais, contador de numeração compartilhado entre homologação e produção, e ausência de configuração de produção (HTTPS, domínio, cookies).

## Decisões do usuário (2026-10-16)

1. TLS: patch via `cweagans/composer-patches`, não fork nem risco aceito.
2. Contador: um contador por ambiente, com número inicial de produção informado na virada.
3. Produção: template de env + Caddy no Compose (perfil `producao`) + runbook. O staging atual não muda.

## Fatia 1: TLS

**Problema.** `nfse-nacional/nfse-php` (`AdnClient`, `CncClient`, `SefinClient`) e `nfephp-org/sped-common` (`Soap/SoapCurl.php`, 2 pontos) fixam `CURLOPT_SSL_VERIFYPEER=0` e `VERIFYHOST=0`. Um MITM leria ou alteraria a DPS/NF-e, que viajam por mTLS com o A1 do CNPJ. `sped-gtin` (`Consulta.php`) tem o mesmo defeito, mas o projeto não o usa para emitir; fica fora, só verificado.

**Solução.**
- `composer require cweagans/composer-patches`; patches em `backend/patches/*.patch`, declarados em `composer.json` (`extra.patches`).
- Patches ligam `VERIFYPEER=1`, `VERIFYHOST=2` e aplicam `CURLOPT_CAINFO` com o bundle configurável `FISCAL_CA_BUNDLE` (env; vazio = bundle do sistema).
- Cadeia ICP-Brasil: SEFAZ e ADN usam CAs que podem faltar no bundle padrão. O bundle é responsabilidade do deploy; o runbook e o roteiro de homologação ganham um passo de conferência (`openssl s_client`).

**Testes.**
- Teste de arquitetura: lê os 4 arquivos do `vendor` e falha se existir `VERIFYPEER, 0|false` ou `VERIFYHOST, 0|false`. Detecta patch não aplicado e atualização de lib que desfaça o patch.
- `composer install` falha se um patch não aplicar (comportamento padrão do plugin; `COMPOSER_PATCHES_FATAL_FAIL`).

**Limite.** Sem SEFAZ/ADN reais não dá para provar a cadeia: fica no roteiro do usuário.

## Fatia 2: contador por ambiente

**Hoje.** `emitente_series.proximo_numero` é único; `IniciarEmissao` (linhas ~118 a 125), `AtualizarSerie` e `InutilizarNumeracao` leem e gravam essa coluna sem olhar o ambiente. Ao virar para produção, a numeração herda a de homologação.

**Mudança.**
- Migration: `emitente_series.proximo_numero_producao` (unsigned int, default 1). Backfill: 1 para toda linha.
- `proximo_numero` passa a valer só para homologação (comentário na coluna e no model).
- Helper no `EmitenteSerie`: `proximoNumeroDo(AmbienteFiscal)` e `avancarNumero(AmbienteFiscal)` escolhem a coluna; nenhum chamador monta o nome da coluna.
- `IniciarEmissao`, `AtualizarSerie` (inclusive a guarda A-03 `FISCAL_NUMERO_JA_EMITIDO`, que passa a filtrar por ambiente da série editada), `InutilizarNumeracao` e o resource/tela da série usam o ambiente do emitente.
- `ConfirmarProducao` recebe `numeros_iniciais` por modelo/série (padrão 1) e grava em `proximo_numero_producao`. Zero é inválido (número mínimo 1); ausente = 1, valor explícito do contrato e não default fiscal silencioso: a tela mostra o campo preenchido com 1 e o usuário confirma.
- Se já houver nota de produção (reentrada), `ConfirmarProducao` não sobrescreve o contador.

**Testes (RED→GREEN).**
- Emissão em homologação não move `proximo_numero_producao` e vice-versa.
- Virada com número inicial 500: primeira nota de produção sai 500.
- A-03 por ambiente: número já emitido em homologação não bloqueia produção.
- Inutilização usa o ambiente do emitente.
- Isolamento de tenant mantido (todo model já tem Global Scope).

## Fatia 3: configuração de produção

- `deploy/.env.production.example`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`/`FRONTEND_URL` com `https://` e domínios, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` do domínio pai, `SANCTUM_STATEFUL_DOMAINS` e CORS por domínio, `INSTALL_DEV=0`, `FISCAL_CA_BUNDLE`.
- `deploy/docker-compose.yml`: perfil `producao` com serviço `caddy` (TLS automático, proxy para front e API, cabeçalhos de segurança). Staging (sem perfil) permanece igual.
- `deploy/Caddyfile` com placeholders de domínio via env.
- Runbook `docs/go-live/checklist-producao.md`: DNS, domínio, trocar senhas SSH e contas de demonstração, segredos, bundle de CAs, "Ir para produção" com número inicial, verificação pós-deploy.
- Teste: um teste simples confere que o `.env.production.example` tem `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false` e URLs `https://`.

## Fora do escopo

Backup e restauração (F8c), testes de carga (F8d), pinning de certificado da SEFAZ, aplicar HTTPS no staging atual, patch do `sped-gtin`.

## Riscos

- Bundle de CAs incompleto derruba a emissão: mitigado por configuração + passo no roteiro; não verificável localmente.
- Patch deixa de aplicar após atualização das libs: o teste de arquitetura e o `composer install` falham alto.
- Migration de contador em PostgreSQL real nunca rodou com este backfill: o `ALTER` é simples, mas fica registrado como não verificado fora do SQLite.

## Critério de pronto

Backend verde (testes, Pint, PHPStan), patches aplicados e cobertos, contador independente por ambiente com testes, template e runbook de produção commitados, `PROGRESSO.md` e `TAREFAS.md` atualizados.

## Ajustes na implementação

- A migration herda `proximo_numero` para os emitentes que já estão em PRODUCAO (o spec dizia backfill 1, o que repetiria número na SEFAZ).
- Produção usa a camada `deploy/docker-compose.producao.yml` (com `!reset` das portas) em vez de perfil do Compose, porque perfil não remove as portas publicadas pelo `app` e pelo `web`.
- O `proximo_numero` do resource da série é o contador do ambiente em que o emitente opera agora; o contrato do frontend não mudou.
- `AplicarResultadoDaEmissao` (539, número ocupado) usa o ambiente da nota, não o do emitente.
