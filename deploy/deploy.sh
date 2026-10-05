#!/usr/bin/env bash
# Deploy do ambiente de acompanhamento. Rode DENTRO de deploy/ na VPS:
#   ./deploy.sh                     # build + migrate + up
#   ./deploy.sh --seed-demo         # idem, e cria as contas de demonstração se o banco estiver vazio
#   HOST=meu.dominio ./deploy.sh    # na primeira vez, define o endereço de acesso (padrão: IP da VPS)
set -euo pipefail
cd "$(dirname "$0")"

SEED_DEMO=0
[ "${1:-}" = "--seed-demo" ] && SEED_DEMO=1

if [ ! -f .env ]; then
  HOST="${HOST:-$(hostname -I | awk '{print $1}')}"
  echo ">> Criando deploy/.env para HOST=${HOST} (segredos gerados agora; não vão para o git)"
  tr -d '\r' < .env.example | sed "s/192\.168\.0\.233/${HOST}/g" > .env
  sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 16)|" .env
  sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
  chmod 600 .env
fi

sed -i 's/\r$//' .env   # tolera .env salvo com CRLF (Windows)

DC="docker compose"

echo ">> Build das imagens (a primeira vez demora)"
$DC build

echo ">> Banco"
$DC up -d db
$DC run --rm app php artisan migrate --force

if [ "$SEED_DEMO" = "1" ]; then
  EXISTENTES=$($DC run --rm app php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo Illuminate\Support\Facades\DB::table("usuarios")->count();' | tail -1 | tr -d '[:space:]')
  if [ "$EXISTENTES" = "0" ]; then
    echo ">> Banco vazio: criando contas de demonstração (o seeder só roda com APP_ENV=local, forçado só neste comando)"
    $DC run --rm -e APP_ENV=local app php artisan db:seed --force
  else
    echo ">> Já existem ${EXISTENTES} usuários: seed de demonstração ignorado"
  fi
fi

echo ">> Subindo os serviços"
$DC up -d

# shellcheck disable=SC1091
. ./.env
echo
echo "Pronto."
echo "  Sistema (front): http://${HOST}:${WEB_PORT}"
echo "  API:             http://${HOST}:${API_PORT}"
$DC ps
