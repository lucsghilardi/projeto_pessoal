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
├── docker-compose.yml      # postgres + backend + frontend
├── backend/
│   ├── Dockerfile          # php:8.3-cli (dev)
│   └── laravel/            # API (apenas auth + usuários)
└── frontend/               # Next.js (login + admin › usuários)
```

## Comandos úteis

```bash
docker compose ps                                   # estado dos serviços
docker compose logs -f backend                      # logs do backend
docker compose exec backend php artisan migrate     # rodar migrations
docker compose down                                 # parar tudo
docker compose down -v                              # parar e apagar o volume do banco
```

## Notas

- O token JWT é guardado em cookie **httpOnly** pelo Next; o browser fala apenas com o
  próprio Next (`/api/auth/*` e `/api/proxy/*`), que injeta o `Bearer` ao chamar a API.
- `.env`, `vendor/`, `node_modules/` e `.next/` são ignorados pelo git.
- Cadastro de usuário não fica disponível na tela de login — apenas um admin cria contas
  pelo painel (Configurações › Usuários).
