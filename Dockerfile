# syntax=docker/dockerfile:1@sha256:ecfaec9ed6d810b56388c508f4121597bfbba70d41a6dfeee4d8cad5f295fc32
FROM composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer

FROM dunglas/frankenphp:1-php8.5-trixie@sha256:f92d81eb3fe4fd18b35d3d58192b7cc3acc8943817bbf39f2fcf0be02a3916dc AS tooling

ARG TARGETARCH
ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl libcap2-bin \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql intl zip mbstring

# Only Linux amd64 is supported and verified by this initial template.
RUN test "$TARGETARCH" = amd64 \
    && curl --fail --location --retry 3 --output /tmp/symfony.tar.gz \
        https://github.com/symfony-cli/symfony-cli/releases/download/v5.20.0/symfony-cli_linux_amd64.tar.gz \
    && printf '%s  %s\n' f50bfbbc1825a2f12a125ea4391f6f7c5b3d7e656901aeeeece0dd8bba135f36 /tmp/symfony.tar.gz | sha256sum -c - \
    && tar -xzf /tmp/symfony.tar.gz -C /usr/local/bin symfony \
    && rm /tmp/symfony.tar.gz

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN test "$APP_UID" -gt 0 && test "$APP_GID" -gt 0 \
    && (getent group "$APP_GID" || groupadd --gid "$APP_GID" app) \
    && (getent passwd "$APP_UID" || useradd --no-log-init --uid "$APP_UID" --gid "$APP_GID" --home-dir /home/app --shell /bin/sh app) \
    && mkdir -p /app/var /home/app /config/caddy /data/caddy \
    && chown -R "$APP_UID:$APP_GID" /app /home/app /config/caddy /data/caddy \
    && setcap -r /usr/local/bin/frankenphp

ENV HOME=/home/app
ENV XDG_CONFIG_HOME=/home/app/.config
ENV XDG_DATA_HOME=/home/app/.local/share
ENV XDG_CACHE_HOME=/home/app/.cache
WORKDIR /app
USER ${APP_UID}:${APP_GID}
ENTRYPOINT []
CMD ["php", "--version"]

FROM tooling AS dev
COPY --chmod=644 docker/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=644 docker/php/app.ini /usr/local/etc/php/conf.d/app.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/app-entrypoint
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

FROM dev AS test
ARG APP_UID=1000
ARG APP_GID=1000
COPY --chown=${APP_UID}:${APP_GID} composer.json composer.lock symfony.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts
COPY --chown=${APP_UID}:${APP_GID} . .
RUN APP_ENV=test APP_DEBUG=0 APP_SECRET=build-only-placeholder composer run-script auto-scripts \
    && APP_ENV=test APP_DEBUG=0 APP_SECRET=build-only-placeholder php bin/console asset-map:compile \
    && rm -rf var/cache var/share
