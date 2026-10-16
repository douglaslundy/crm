#!/usr/bin/env bash
# Backup do banco (pg_dump formato custom), validado, com retenção de 14 dias. Só o banco: o app não grava arquivos
# em storage. Rode em qualquer pasta; agende por cron (docs/go-live/backup-e-restauracao.md).
set -euo pipefail
cd "$(dirname "$0")"

env_val() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | tr -d '\r'; }

[ -f .env ] || { echo "deploy/.env não encontrado." >&2; exit 1; }
DB_USERNAME="$(env_val DB_USERNAME)"
DB_DATABASE="$(env_val DB_DATABASE)"
[ -n "$DB_USERNAME" ] && [ -n "$DB_DATABASE" ] || { echo "DB_USERNAME ou DB_DATABASE vazio no deploy/.env." >&2; exit 1; }

BACKUP_DIR="${BACKUP_DIR:-$(cd .. && pwd)/backups}"
RETENCAO_DIAS=14

umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

final="$BACKUP_DIR/plataforma-$(date +%Y%m%d-%H%M%S).dump"
tmp="$final.tmp"
trap 'rm -f "$tmp"' EXIT

docker compose exec -T db pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > "$tmp"
[ -s "$tmp" ] || { echo "Dump vazio; backup abortado." >&2; exit 1; }
docker compose exec -T db pg_restore --list < "$tmp" > /dev/null || { echo "Dump ilegível; backup abortado." >&2; exit 1; }

mv "$tmp" "$final"
find "$BACKUP_DIR" -maxdepth 1 -name 'plataforma-*.dump' -mtime +"$RETENCAO_DIAS" -delete

echo "Backup ok: $final ($(du -h "$final" | cut -f1))"
