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

WORKDIR /app/frontend

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build

FROM alpine:3.21 AS release

RUN apk add --no-cache zip

WORKDIR /release

COPY --from=vendor /app /release/app
COPY --from=frontend /app/public/build /release/app/public/build

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
    && mkdir -p storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 775 {} \;

RUN cd /release \
    && zip -q -r /jotter-release.zip app \
    && sha256sum /jotter-release.zip > /jotter-release.zip.sha256

FROM scratch AS export

COPY --from=release /release/app /app
COPY --from=release /jotter-release.zip /jotter-release.zip
COPY --from=release /jotter-release.zip.sha256 /jotter-release.zip.sha256
