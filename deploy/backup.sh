#!/usr/bin/env bash
# Backup diário do Sistema Raiz. Instalar no cron do root:
#   30 3 * * * /opt/sistemaraiz/deploy/backup.sh >> /var/log/sistemaraiz_backup.log 2>&1
#
# Guarda três coisas, e as três são necessárias para uma restauração completa:
#   1. dump do Postgres
#   2. storage/app/private (comprovantes e propostas de consórcio) — o banco só
#      guarda o caminho do arquivo; sem estes anexos os registros ficam órfãos
#   3. o .env — APP_KEY, JWT_SECRET e as senhas do banco
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="docker compose -f $APP_DIR/docker-compose.prod.yml"
BACKUP_DIR="/var/backups/sistemaraiz"
RETENTION_DAYS=30
STAMP="$(date +%Y-%m-%d_%H%M)"

mkdir -p "$BACKUP_DIR"
cd "$APP_DIR"

DB_NAME="$(grep -E '^DB_DATABASE=' backend/laravel/.env | cut -d= -f2-)"
DB_USER="$(grep -E '^DB_USERNAME=' backend/laravel/.env | cut -d= -f2-)"

echo "[$(date -Is)] iniciando backup"

# 1. Banco
$COMPOSE exec -T postgres pg_dump -U "$DB_USER" -d "$DB_NAME" --format=custom \
  > "$BACKUP_DIR/db_${STAMP}.dump"

# 2. Anexos (ficam no volume sistemaraiz_prod_storage)
$COMPOSE exec -T php tar -czf - -C /var/www/laravel/storage/app private \
  > "$BACKUP_DIR/storage_${STAMP}.tar.gz"

# 3. .env (contém APP_KEY e JWT_SECRET)
cp backend/laravel/.env "$BACKUP_DIR/env_${STAMP}"

chmod 600 "$BACKUP_DIR"/*_"${STAMP}"* 2>/dev/null || true

# Retenção
find "$BACKUP_DIR" -type f -mtime +$RETENTION_DAYS -delete

echo "[$(date -Is)] backup local concluído em $BACKUP_DIR"

# ---------------------------------------------------------------------------
# Cópia para fora do servidor. Backup que só existe no mesmo VPS não é backup:
# se o disco morrer ou o servidor for comprometido, os dois somem juntos.
#
# Configure UM destes e descomente:
#
#   rclone (Google Drive, S3, Backblaze...):
#     rclone copy "$BACKUP_DIR" remoto:sistemaraiz-backups --max-age 24h
#
#   outro servidor via SSH:
#     rsync -az --delete "$BACKUP_DIR/" usuario@host:/caminho/sistemaraiz/
# ---------------------------------------------------------------------------
echo "AVISO: cópia externa não configurada — edite o fim deste script."
