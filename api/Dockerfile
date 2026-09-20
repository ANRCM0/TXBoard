# syntax=docker/dockerfile:1.7
#
# All-in-one control-plane image: Octane (Swoole), Horizon, an embedded Redis,
# the WebSocket server and Caddy, supervised by supervisord.
#
# Layer order below is deliberate. Dependencies are installed before the
# application source is copied, and APP_COMMIT is set last, so editing
# application code or cutting a new commit reuses the cached vendor layer
# instead of re-resolving and re-downloading every package.

# Pinned to an exact Swoole/PHP line rather than the floating `php8.2-alpine`,
# which moves with every Swoole release: a plain rebuild could otherwise swap
# the PHP/Swoole ABI under a running panel. 6.2.2 is the release this branch is
# tested against; bump it deliberately.
FROM phpswoole/swoole:6.2.2-php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Extensions and packages in a single layer. `patch`, `shadow` and `mysql-dev`
# are gone: `patch` is only needed to apply source diffs at build time (nothing
# here patches), Alpine's busybox already provides adduser/addgroup, and the
# MySQL client headers are a build-time-only dependency.
# `git` stays on purpose -- app/Services/UpdateService.php shells out to it for
# the in-panel updater, so removing it would silently break self-update.
RUN install-php-extensions pcntl bcmath zip redis && \
    apk add --no-cache sqlite mysql-client mariadb-connector-c supervisor redis caddy git && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

# Runtime configuration is copied straight to its destinations. The previous
# `COPY .docker /` dumped supervisor/, caddy/ and php/ into the image root as
# well, duplicating these files and leaving three stray directories behind.
# Nothing under .docker changes as often as the source, so this caches well.
COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /www

# Dependencies before source. Copying the whole tree first (the previous order)
# meant any edit to any file invalidated the composer layer and forced a full
# re-resolution, which is the single biggest build-time cost in this image.
# --no-scripts defers artisan-based scripts until the source tree exists, and the
# cache mount keeps downloads out of the image and reusable across builds.
# --no-autoloader is required here, not cosmetic: composer.json declares
# database/seeders and database/factories as explicit classmap paths, and Composer
# scans those whenever it writes the autoloader, so generating it before the
# source is copied fails outright. `dump-autoload` does it further down instead.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader \
        --no-security-blocking

COPY --chown=www:www . /www

# Now that the source exists: build the autoloader, discover packages, link
# public storage, and create the writable tree. Ownership is limited to the
# paths the application actually writes to instead of `chown -R /www`, which
# rewrote the whole vendor tree and left it group-writable.
# The `rm` drops log files produced by the artisan runs above: with no .env yet,
# every hash() call on the null APP_KEY logs a deprecation, and shipping those
# logs would both bloat the image and look like a real runtime warning.
RUN composer dump-autoload --no-dev --optimize --no-interaction && \
    php artisan package:discover --ansi && \
    mkdir -p storage/app/public \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
             /data && \
    php artisan storage:link && \
    rm -f storage/logs/*.log && \
    chown -R www:www storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache && \
    chown redis:redis /data

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

# Set last so a new commit hash only rebuilds this final metadata layer.
ARG APP_COMMIT=unknown
ENV APP_COMMIT=${APP_COMMIT}

# Internal Caddy listener. The public port is published by deploy/compose.yaml.
EXPOSE 7001

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
