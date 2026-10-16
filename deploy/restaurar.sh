#!/usr/bin/env bash
# Restaura o banco a partir de um dump gerado por backup.sh. SOBRESCREVE o banco atual.
#   ./restaurar.sh ../backups/plataforma-20261016-030000.dump
# Antes de tocar no banco, valida o arquivo, pede confirmação e grava um dump de segurança do estado atual.
set -euo pipefail
cd "$(dirname "$0")"

arquivo="${1:-}"
[ -n "$arquivo" ] && [ -f "$arquivo" ] || { echo "Uso: ./restaurar.sh <arquivo.dump>" >&2; exit 1; }

env_val() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | tr -d '\r'; }

[ -f .env ] || { echo "deploy/.env não encontrado." >&2; exit 1; }
DB_USERNAME="$(env_val DB_USERNAME)"
DB_DATABASE="$(env_val DB_DATABASE)"
[ -n "$DB_USERNAME" ] && [ -n "$DB_DATABASE" ] || { echo "DB_USERNAME ou DB_DATABASE vazio no deploy/.env." >&2; exit 1; }

BACKUP_DIR="${BACKUP_DIR:-$(cd .. && pwd)/backups}"

docker compose exec -T db pg_restore --list < "$arquivo" > /dev/null || { echo "Dump ilegível; nada foi alterado." >&2; exit 1; }

echo "ATENÇÃO: isto SOBRESCREVE o banco '$DB_DATABASE' com o conteúdo de $arquivo."
echo "Os serviços app, worker e scheduler ficam parados durante a restauração."
read -r -p 'Digite RESTAURAR para continuar: ' resposta
[ "$resposta" = "RESTAURAR" ] || { echo "Cancelado; nada foi alterado." >&2; exit 1; }

umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
seguranca="$BACKUP_DIR/pre-restauracao-$(date +%Y%m%d-%H%M%S).dump"
docker compose exec -T db pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > "$seguranca"
[ -s "$seguranca" ] || { echo "Dump de segurança vazio; nada foi alterado." >&2; exit 1; }
echo "Dump de segurança do estado atual: $seguranca"

docker compose stop app worker scheduler
docker compose exec -T db pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" --clean --if-exists --no-owner --single-transaction < "$arquivo" || {
  echo "Restauração falhou e foi desfeita (transação única). Serviços continuam parados; dump de segurança: $seguranca" >&2
  exit 1
}
docker compose up -d app worker scheduler

tabelas="$(docker compose exec -T db psql -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc "select count(*) from information_schema.tables where table_schema = 'public'")"
echo "Restauração concluída. Tabelas no schema public: $tabelas"
