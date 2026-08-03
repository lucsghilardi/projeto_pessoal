#!/usr/bin/env bash
# Cria o banco `evolution` usado pelo container da Evolution API (módulo
# WhatsApp). O entrypoint do postgres só executa este diretório quando o volume
# de dados nasce vazio; em volumes já existentes, rodar o equivalente à mão:
#   docker exec <container_postgres> createdb -U "$POSTGRES_USER" evolution
# (em produção o deploy/deploy.sh já garante o banco antes das migrações).
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
	SELECT 'CREATE DATABASE evolution OWNER ' || quote_ident(current_user)
	WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'evolution')\gexec
EOSQL
