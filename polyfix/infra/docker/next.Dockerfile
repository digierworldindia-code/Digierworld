# =============================================================================
# POLYFIX MATTRESS — Next.js image (both the public website and the console)
# -----------------------------------------------------------------------------
# One Dockerfile, two images, selected by APP_DIR and APP_FILTER:
#
#   docker build -f infra/docker/next.Dockerfile \
#     --build-arg APP_DIR=apps/web --build-arg APP_FILTER=@polyfix/web \
#     -t polyfix/web .
#
#   docker build -f infra/docker/next.Dockerfile \
#     --build-arg APP_DIR=apps/app --build-arg APP_FILTER=@polyfix/console \
#     -t polyfix/console .
#
# Build from the REPOSITORY ROOT: both apps import workspace packages.
#
# NOTE ON BUILD-TIME ENVIRONMENT
#   NEXT_PUBLIC_* values are inlined into the client bundle at build time, so
#   they are baked into the image and cannot be changed by the orchestrator
#   afterwards. Pass them as build arguments. They are public by definition —
#   never pass a secret this way.
# =============================================================================
FROM node:22-bookworm-slim AS base
ENV PNPM_HOME=/pnpm PATH=/pnpm:$PATH
RUN corepack enable
WORKDIR /app

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
    pnpm install --frozen-lockfile

FROM deps AS build
ARG APP_DIR
ARG APP_FILTER
# Public, build-time values. Defaults keep an unconfigured build coherent
# rather than silently emitting localhost URLs into a production bundle.
ARG NEXT_PUBLIC_SITE_URL=https://polyfixmattress.com
ARG NEXT_PUBLIC_APP_URL=https://app.polyfixmattress.com
ARG NEXT_PUBLIC_SITE_NAME="POLYFIX MATTRESS"
ARG NEXT_PUBLIC_CONSOLE_NAME="POLYFIX MATTRESS Control"
ARG NEXT_PUBLIC_GA4_MEASUREMENT_ID=
ARG NEXT_PUBLIC_GSC_VERIFICATION=
ENV NEXT_PUBLIC_SITE_URL=$NEXT_PUBLIC_SITE_URL \
    NEXT_PUBLIC_APP_URL=$NEXT_PUBLIC_APP_URL \
    NEXT_PUBLIC_SITE_NAME=$NEXT_PUBLIC_SITE_NAME \
    NEXT_PUBLIC_CONSOLE_NAME=$NEXT_PUBLIC_CONSOLE_NAME \
    NEXT_PUBLIC_GA4_MEASUREMENT_ID=$NEXT_PUBLIC_GA4_MEASUREMENT_ID \
    NEXT_PUBLIC_GSC_VERIFICATION=$NEXT_PUBLIC_GSC_VERIFICATION \
    NEXT_TELEMETRY_DISABLED=1
COPY . .
RUN pnpm --filter @polyfix/database generate
RUN pnpm --filter "$APP_FILTER" build

# --- runtime -----------------------------------------------------------------
# `output: 'standalone'` (set in each next.config.ts) produces a tree containing
# a server and only the dependencies the app actually imports. `static` and
# `public` are not part of it and are copied separately — that is a Next.js
# convention, not an oversight.
FROM base AS runtime
ARG APP_DIR
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && groupadd --system --gid 1001 polyfix \
 && useradd --system --uid 1001 --gid polyfix polyfix

COPY --from=build --chown=polyfix:polyfix /app/${APP_DIR}/.next/standalone ./
COPY --from=build --chown=polyfix:polyfix /app/${APP_DIR}/.next/static      ./${APP_DIR}/.next/static
# The console has no public/ directory; the trailing wildcard keeps this
# single Dockerfile usable for both apps.
COPY --from=build --chown=polyfix:polyfix /app/${APP_DIR}/publi[c] ./${APP_DIR}/public

USER polyfix
ENV HOSTNAME=0.0.0.0 PORT=3000
EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||3000)+'/').then(r=>process.exit(r.status<500?0:1)).catch(()=>process.exit(1))"

# APP_DIR is a build argument, so it is not available at run time — bake the
# resolved path into the command now.
ENV APP_DIR=${APP_DIR}
CMD ["sh", "-c", "exec node ${APP_DIR}/server.js"]
