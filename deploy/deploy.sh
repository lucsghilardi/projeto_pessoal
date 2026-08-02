#!/usr/bin/env bash
# Deploy do Sistema Raiz em produção. Rodar no servidor, dentro do diretório da
# aplicação:  ./deploy/deploy.sh
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="docker compose -f docker-compose.prod.yml"
ENV_FILE="$APP_DIR/backend/laravel/.env"

cd "$APP_DIR"

echo "==> Verificando pré-requisitos"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERRO: $ENV_FILE não existe. Copie de backend/laravel/.env.production.example," >&2
  echo "      preencha, e gere a APP_KEY antes do primeiro deploy." >&2
  exit 1
fi

if ! grep -qE '^APP_KEY=base64:.+' "$ENV_FILE"; then
  echo "ERRO: APP_KEY vazia ou inválida em $ENV_FILE." >&2
  echo "      Gere com:" >&2
  echo "      docker run --rm php:8.3-cli php -r 'echo \"base64:\".base64_encode(random_bytes(32)).\"\\n\";'" >&2
  exit 1
fi

# O .env é montado no container, que roda como www-data (uid 33). Se o arquivo
# ficar root:root com modo 600, o Laravel não consegue lê-lo e cai no default
# (sqlite) SEM avisar — as migrações vão para um banco que não existe. Manter a
# posse em 33:33 preserva o 600 e o root continua lendo normalmente.
if [[ "$(stat -c %u "$ENV_FILE")" != "33" ]]; then
  echo "==> Ajustando posse do .env para uid 33 (www-data)"
  chown 33:33 "$ENV_FILE"
  chmod 600 "$ENV_FILE"
fi

# Se o diretório for um repositório git, atualiza. Caso contrário assume que o
# código já foi sincronizado (rsync) e apenas reconstrói.
if [[ -d .git ]]; then
  if [[ -z "${DEPLOY_REEXEC:-}" ]]; then
    echo "==> Atualizando código (git)"
    git pull --ff-only
    # O pull pode ter reescrito ESTE arquivo. O bash lê o script por offset
    # enquanto executa, então continuar aqui rodaria uma mistura da versão
    # velha com a nova. Re-executa uma única vez, já na versão atualizada.
    export DEPLOY_REEXEC=1
    exec "$0" "$@"
  fi
  echo "==> Código já atualizado (re-exec após git pull)"
else
  echo "==> Sem repositório git aqui; usando o código já presente no diretório."
fi

echo "==> Construindo imagens"
$COMPOSE build

echo "==> Subindo containers"
$COMPOSE up -d --remove-orphans

# O .env é um bind mount de ARQUIVO ÚNICO, e o Docker amarra esse tipo de mount
# ao inode. Editores que substituem o arquivo em vez de reescrevê-lo (sed -i,
# vim com backup, mv) trocam o inode: o host passa a ter o conteúdo novo e o
# container segue lendo o arquivo antigo, sem erro nenhum. Recriar o serviço a
# cada deploy religa o mount ao arquivo atual.
echo "==> Religando o mount do .env"
$COMPOSE up -d --force-recreate --no-deps php

echo "==> Aguardando o php-fpm ficar pronto"
for _ in $(seq 1 30); do
  if $COMPOSE exec -T php php -v >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "==> Migrações"
$COMPOSE exec -T php php artisan migrate --force

echo "==> Regerando caches (com o .env já no lugar)"
$COMPOSE exec -T php php artisan config:cache
$COMPOSE exec -T php php artisan route:cache
$COMPOSE exec -T php php artisan view:cache
# Sem `storage:link` de propósito: nada é servido por HTTP a partir de
# public/storage. Comprovantes e propostas de consórcio saem por
# Storage::disk('local')->response(), lendo de storage/app/private, e os
# diretórios já são criados pelo Dockerfile.prod dentro do volume.

echo "==> Limpando imagens órfãs"
docker image prune -f >/dev/null

echo "==> Estado final"
$COMPOSE ps

echo
echo "Deploy concluído. Verifique: https://sistemaraiz.movidoaweb.com.br"
