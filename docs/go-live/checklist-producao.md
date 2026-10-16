# Checklist de go-live em produção

Escopo: colocar a plataforma na internet com HTTPS, cookies seguros e verificação TLS ligada nas chamadas fiscais. O staging (`deploy/deploy.sh`) não conhece estes arquivos e continua como está.

Arquivos: `deploy/.env.production.example`, `deploy/docker-compose.producao.yml`, `deploy/Caddyfile`.

## 1. Domínios

1. Escolha dois subdomínios do mesmo domínio, por exemplo `app.suaempresa.com.br` (sistema) e `api.suaempresa.com.br` (API).
2. Crie dois registros DNS `A` apontando para o IP da VPS. Aguarde propagar (`nslookup app.suaempresa.com.br`).
3. Libere as portas 80 e 443 no firewall da VPS (o Caddy usa a 80 para emitir o certificado).

## 2. Arquivo `.env`

```bash
cd ~/crm-plataforma/src/deploy
cp .env.production.example .env
chmod 600 .env
```

Preencher no `.env`:

- `APP_DOMAIN`, `API_DOMAIN`, `APP_URL`, `FRONTEND_URL`, `NEXT_PUBLIC_API_URL`, `SANCTUM_STATEFUL_DOMAINS` e `SESSION_DOMAIN` (com o ponto inicial, domínio pai) com os seus domínios.
- `DB_PASSWORD`: `openssl rand -hex 16`.
- `APP_KEY`: `base64:$(openssl rand -base64 32)`.
- Conferir `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `INSTALL_DEV=0`.

## 3. Bundle de CAs da SEFAZ e da ADN

A verificação TLS das chamadas fiscais está ligada (patch da F8b). A SEFAZ e a ADN usam a cadeia ICP-Brasil, que pode faltar no bundle do sistema.

1. Reúna a cadeia (raiz e intermediárias ICP-Brasil e as CAs dos hosts da SEFAZ do seu estado e da ADN) num único arquivo `.pem`.
2. Coloque o arquivo na VPS, monte no container `app` e `worker` (volume em um `docker-compose.override.yml` seu) e preencha `FISCAL_CA_BUNDLE` com o caminho dentro do container.
3. Conferir cada host, de dentro do container:

```bash
openssl s_client -connect <host-da-sefaz>:443 -CAfile <bundle> </dev/null 2>/dev/null | grep "Verify return code"
```

Esperado: `Verify return code: 0 (ok)`. Qualquer outro valor: falta uma CA no bundle. **Não desligue a verificação.**

## 4. Subir

```bash
cd ~/crm-plataforma/src/deploy
docker compose -f docker-compose.yml -f docker-compose.producao.yml build
docker compose -f docker-compose.yml -f docker-compose.producao.yml up -d db
docker compose -f docker-compose.yml -f docker-compose.producao.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.producao.yml up -d
```

Não rode o seeder de demonstração (`--seed-demo`). Crie o primeiro administrador da plataforma pelo fluxo de cadastro/convite.

## 5. Segurança da VPS

1. Troque a senha do usuário SSH e desative o login por senha (`PasswordAuthentication no`, só chave).
2. Confirme que não existem as contas de demonstração (`admin@plataforma.local`, `dono@empresa.local`); se existirem, remova.
3. `docker compose ps`: só o `caddy` publica portas (80/443). `app`, `web` e `db` não aparecem com portas no host.

## 6. Verificações depois de subir

- `curl -I https://API_DOMAIN/up` responde 200 e `https://APP_DOMAIN` abre a tela de login.
- Fazer login e conferir no navegador que o cookie de sessão tem `Secure` e `HttpOnly`.
- `curl -I http://APP_DOMAIN` redireciona para HTTPS.
- Emitir uma NF-e e uma NFS-e **em homologação** com o certificado real: sem erro `SSL certificate problem` (veja o roteiro `docs/auditoria/roteiro-homologacao-real.md`).

## 7. Virada para produção fiscal

Em Configurações > Fiscal, depois de ao menos uma NF-e de teste autorizada:

1. Em "Ir para produção", informe para cada série o **primeiro número livre** (a numeração de produção é independente da de homologação; padrão 1). Se a empresa já emitiu notas por outro sistema nessa série, confira o último número na SEFAZ e informe o seguinte.
2. Marque a caixa de entendimento e confirme.
3. Configure o CSC de produção (NFC-e) se aplicável.

## 8. Reversão

Para voltar ao modo de staging: `docker compose -f docker-compose.yml up -d` (sem o arquivo de produção) e reabra as portas conforme `deploy/README.md`. A virada fiscal para produção não se desfaz pela aplicação: notas de produção são definitivas.
