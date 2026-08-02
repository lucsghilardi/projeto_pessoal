# projeto_pessoal

Base administrativa enxuta derivada do NitroGym, contendo **apenas o módulo de
usuários**:

- **Backend** — Laravel 12 / PHP 8.3 + JWT (`tymon/jwt-auth`), PostgreSQL.
  Endpoints: `POST /api/login`, `GET /api/health`, e (autenticados) `GET /api/me`,
  `POST /api/logout`, `GET|POST /api/users`, `PUT /api/users/{user}`.
- **Frontend** — Next.js 16 (App Router) / React 19 / Tailwind 4. Tela de **login** e
  **painel** com uma única seção: **Configurações › Usuários**.
- **Banco** — PostgreSQL 16 em container Docker isolado.

## Pré-requisitos

- Docker + Docker Compose.

## Como subir o ambiente

```bash
# 1. Subir o banco
docker compose up -d postgres

# 2. Instalar dependências do backend e gerar as chaves
docker compose run --rm backend composer install
docker compose run --rm backend php artisan key:generate
docker compose run --rm backend php artisan jwt:secret --force

# 3. Subir o backend e rodar as migrations (cria o admin a partir do .env)
docker compose up -d backend
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan storage:link

# 4. Subir o frontend (instala node_modules na primeira vez)
docker compose up -d frontend
```

## Acessos

| Serviço   | URL                                   |
|-----------|---------------------------------------|
| Frontend  | http://localhost:3001                 |
| Login     | http://localhost:3001/login           |
| Backend   | http://localhost:8001/api/health      |
| Postgres  | localhost:**5433** (usuário/db: `projeto_pessoal`) |

**Usuário administrador inicial:** `lucasghilardi@movidoaweb.com.br`
(a senha está em `backend/laravel/.env`, variável `ADMIN_PASSWORD`).

## Estrutura

```
projeto_pessoal/
├── docker-compose.yml       # dev: postgres + backend + frontend
├── docker-compose.prod.yml  # prod: postgres + php + backend (nginx) + frontend
├── deploy/                  # scripts e vhost de produção
├── backend/
│   ├── Dockerfile           # php:8.3-cli (dev)
│   ├── Dockerfile.prod      # php:8.3-fpm + nginx (prod)
│   ├── nginx/laravel.conf   # vhost interno do container `backend`
│   └── laravel/             # API
└── frontend/
    ├── Dockerfile           # build standalone (prod)
    └── ...                  # Next.js
```

## Comandos úteis

```bash
docker compose ps                                   # estado dos serviços
docker compose logs -f backend                      # logs do backend
docker compose exec backend php artisan migrate     # rodar migrations
docker compose down                                 # parar tudo
docker compose down -v                              # parar e apagar o volume do banco
```

## Deploy (produção)

Publicado em **https://sistemaraiz.movidoaweb.com.br**, no servidor `46.202.147.32`
(Ubuntu 24.04), em `/opt/sistemaraiz`. O mesmo servidor hospeda o `gestao.movidoaweb.com.br`.

Quem termina o TLS é o **nginx do host** (`deploy/nginx-sistemaraiz.conf` + certbot), que faz
proxy reverso para o Next em `127.0.0.1:3001`. O Laravel **não** é exposto à internet: só o Next
o alcança, pela rede interna do compose (`INTERNAL_API_URL=http://backend/api`).

| Container                    | Papel                        | Exposição            |
|------------------------------|------------------------------|----------------------|
| `sistemaraiz_prod_frontend`  | Next.js standalone           | `127.0.0.1:3001`     |
| `sistemaraiz_prod_backend`   | nginx servindo `public/`     | rede interna         |
| `sistemaraiz_prod_php`       | php-fpm                      | rede interna         |
| `sistemaraiz_prod_postgres`  | PostgreSQL 16                | rede interna         |

Volumes: `sistemaraiz_prod_pgdata` (banco) e `sistemaraiz_prod_storage` (anexos —
`storage/app/private/{receipts,consorcios}`). Perder o segundo deixa os registros do banco
apontando para arquivos inexistentes.

### Deployar

Automático a cada push na `main` (`.github/workflows/deploy-prod.yml`). Manualmente:

```bash
cd /opt/sistemaraiz && ./deploy/deploy.sh
```

### Editar o `.env` de produção

`backend/laravel/.env` é um bind mount de **arquivo único**, e o Docker o amarra ao inode.
`sed -i`, `mv` ou editor que salve criando arquivo novo trocam o inode: o host passa a ter o
conteúdo novo e o container segue lendo o antigo, **sem erro nenhum**. Preserve o inode:

```bash
tmp=$(mktemp); grep -v '^ADMIN_PASSWORD=' .env > "$tmp"; cat "$tmp" > .env; rm -f "$tmp"
```

Ou rode `./deploy/deploy.sh` depois de editar — ele recria o container `php`.

### Backup e restore

`deploy/backup.sh` roda no cron do root às 3h30 e guarda banco + anexos + `.env` em
`/var/backups/sistemaraiz` (retenção de 30 dias). Para restaurar:

```bash
cd /opt/sistemaraiz
docker compose -f docker-compose.prod.yml stop php backend frontend
docker compose -f docker-compose.prod.yml exec -T postgres \
  pg_restore -U projeto_pessoal -d projeto_pessoal --clean --no-owner --no-privileges \
  < /var/backups/sistemaraiz/db_<stamp>.dump
docker compose -f docker-compose.prod.yml exec -u root -T php \
  tar -xzf - -C /var/www/laravel/storage/app < /var/backups/sistemaraiz/storage_<stamp>.tar.gz
./deploy/deploy.sh
```

## Notas

- O token JWT é guardado em cookie **httpOnly** pelo Next; o browser fala apenas com o
  próprio Next (`/api/auth/*` e `/api/proxy/*`), que injeta o `Bearer` ao chamar a API.
- `.env`, `vendor/`, `node_modules/` e `.next/` são ignorados pelo git.
- Cadastro de usuário não fica disponível na tela de login — apenas um admin cria contas
  pelo painel (Configurações › Usuários).
