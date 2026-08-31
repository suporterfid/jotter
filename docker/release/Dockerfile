# syntax=docker/dockerfile:1

FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM node:22-bookworm-slim AS frontend

ARG RELEASE_VERSION=0.0.0-dev

WORKDIR /app/frontend

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
ENV VITE_APP_VERSION="${RELEASE_VERSION}"
RUN npm run build

FROM alpine:3.21 AS release

# Git tag when HEAD is tagged, otherwise 0.0.0-<short sha>. Written to VERSION
# inside the artifact and exposed by GET /api/auth/config and GET /api/health.
ARG RELEASE_VERSION=0.0.0-dev

RUN apk add --no-cache zip

WORKDIR /release

COPY --from=vendor /app /release/app
COPY --from=frontend /app/public/build /release/app/public/build
COPY --from=frontend /app/public/manifest.webmanifest /release/app/public/manifest.webmanifest
COPY --from=frontend /app/public/offline.html /release/app/public/offline.html
COPY --from=frontend /app/public/service-worker.js /release/app/public/service-worker.js

RUN printf '%s\n' "${RELEASE_VERSION}" > /release/app/VERSION

RUN cd /release/app \
    && rm -rf \
        .git \
        .github \
        docker \
        docs \
        frontend \
        node_modules \
        scripts \
        tests \
        database/factories \
        resources/css \
        resources/js \
        compose.yaml \
        compose.ci.yaml \
        Makefile \
        phpunit.xml \
        .dockerignore \
        .editorconfig \
        .gitattributes \
        .gitignore \
        AGENTS.md \
        CLAUDE.md \
        STATUS.md \
        BACKLOG.md \
    && rm -f .env .env.backup .env.production auth.json \
    # Defense in depth on top of .dockerignore: a stale bootstrap/cache/*.php
    # from the build context (e.g. a local `artisan optimize` run with dev
    # dependencies installed) would hardcode dev-only service providers
    # (e.g. laravel/pail) into the release, fataling on the --no-dev vendor
    # at runtime. Guarantee an empty cache regardless of build-context state.
    && rm -f bootstrap/cache/*.php \
    && mkdir -p storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 775 {} \;

RUN cd /release \
    && zip -q -r "/jotter-release-${RELEASE_VERSION}.zip" app \
    && cd / \
    && sha256sum "jotter-release-${RELEASE_VERSION}.zip" > "jotter-release-${RELEASE_VERSION}.zip.sha256"

FROM scratch AS export

COPY --from=release /release/app /app
COPY --from=release /jotter-release-*.zip /
COPY --from=release /jotter-release-*.zip.sha256 /
