# Imagem de produção do Next.js 16 (build standalone).
# Em desenvolvimento o compose continua usando node:22-alpine com `npm run dev`;
# este Dockerfile é usado somente pelo docker-compose.prod.yml.

# ---------- deps ----------
FROM node:22-alpine AS deps
# libc6-compat cobre binários que esperam glibc (o SWC do Next é um deles).
RUN apk add --no-cache libc6-compat
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

# ---------- builder ----------
FROM node:22-alpine AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .

# NEXT_PUBLIC_* é embutido no bundle em tempo de build, não lido em runtime.
# Só serve de fallback: o tráfego autenticado usa INTERNAL_API_URL, que é
# resolvido no servidor (ver frontend/lib/auth.ts).
ARG NEXT_PUBLIC_API_URL
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}
ENV NEXT_TELEMETRY_DISABLED=1

RUN npm run build

# ---------- runner ----------
FROM node:22-alpine AS runner
WORKDIR /app

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV PORT=3000
ENV HOSTNAME=0.0.0.0

RUN addgroup --system --gid 1001 nodejs \
 && adduser --system --uid 1001 nextjs

# O standalone traz o server.js e as dependências mínimas; static e public
# precisam ser copiados à parte.
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static
COPY --from=builder --chown=nextjs:nodejs /app/public ./public

USER nextjs
EXPOSE 3000

CMD ["node", "server.js"]
