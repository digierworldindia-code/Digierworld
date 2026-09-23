# =============================================================================
# POLYFIX MATTRESS — API image
# -----------------------------------------------------------------------------
# Build from the REPOSITORY ROOT so the whole pnpm workspace is in context:
#   docker build -f infra/docker/api.Dockerfile -t polyfix/api .
#
# The API is TypeScript executed by tsx rather than a compiled bundle, so the
# runtime stage carries source plus production dependencies. That is a
# deliberate trade: one less build artifact to keep in sync, at the cost of a
# slightly larger image.
# =============================================================================
FROM node:22-bookworm-slim AS base
ENV PNPM_HOME=/pnpm PATH=/pnpm:$PATH
RUN corepack enable
WORKDIR /app

# --- dependencies ------------------------------------------------------------
# Manifests first: this layer is cached until a dependency actually changes.
FROM base AS deps
COPY pnpm-lock.yaml pnpm-workspace.yaml package.json .npmrc ./
COPY apps/api/package.json               apps/api/
COPY apps/web/package.json               apps/web/
COPY apps/app/package.json               apps/app/
COPY packages/auth/package.json          packages/auth/
COPY packages/brand/package.json         packages/brand/
COPY packages/config/package.json        packages/config/
COPY packages/database/package.json      packages/database/
COPY packages/ui/package.json            packages/ui/
COPY packages/validation/package.json    packages/validation/
RUN --mount=type=cache,id=pnpm,target=/pnpm/store \
    pnpm install --frozen-lockfile --filter @polyfix/api... --filter @polyfix/database...

# --- build -------------------------------------------------------------------
FROM deps AS build
COPY . .
# Generates the Prisma client into packages/database. No database is contacted:
# `prisma generate` reads the schema file only.
RUN pnpm --filter @polyfix/database generate
# Type check. The API ships as source, so this is the only thing standing
# between a type error and production.
RUN pnpm --filter @polyfix/api typecheck

# --- runtime -----------------------------------------------------------------
FROM base AS runtime
ENV NODE_ENV=production
# postgresql-client supplies pg_dump/pg_restore/psql, which scripts/backup.sh
# and scripts/restore.sh shell out to. openssl encrypts the dump at rest.
RUN apt-get update \
 && apt-get install -y --no-install-recommends postgresql-client openssl ca-certificates \
 && rm -rf /var/lib/apt/lists/*

COPY --from=build /app /app

# Never root. The image declares the user; the compose file and any orchestrator
# inherit it.
RUN groupadd --system --gid 1001 polyfix \
 && useradd --system --uid 1001 --gid polyfix polyfix \
 && mkdir -p /app/storage /app/backups \
 && chown -R polyfix:polyfix /app/storage /app/backups
USER polyfix

WORKDIR /app/apps/api
EXPOSE 4000
# Bind to every interface INSIDE the container. Exposure to the outside world is
# decided by the compose port mapping, which binds to loopback only.
ENV HOST=0.0.0.0 PORT=4000

# The API answers /health without touching the database and /health/ready with
# a database round trip. This checks readiness, so an unhealthy database marks
# the container unhealthy rather than letting traffic arrive at a broken one.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:4000/health/ready').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

# No --env-file here: the container's environment comes from the orchestrator,
# which is where production secrets belong.
CMD ["node", "--import", "tsx", "src/server.ts"]
