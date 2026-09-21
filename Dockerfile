# syntax=docker/dockerfile:1.7

# Build both SPAs once, then copy only their static output into the final
# control-plane image. Node never reaches production.
FROM node:22.23.2-alpine AS web-build
WORKDIR /workspace

COPY package.json package-lock.json ./
COPY web/shared/package.json web/shared/package.json
COPY web/admin/package.json web/admin/package.json
COPY web/user/package.json web/user/package.json
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

COPY web/shared web/shared
COPY web/admin web/admin
COPY web/user web/user
RUN VITE_BASE_PATH=/admin/ npm run build --workspace @txboard/admin && \
    VITE_BASE_PATH=/ npm run build --workspace @txboard/user

# One production image for the entire TXBoard control plane:
# Caddy + Admin/User SPAs + Octane + Horizon + Redis + WebSocket server.
FROM phpswoole/swoole:6.2.2-php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pcntl bcmath zip redis && \
    apk add --no-cache sqlite mysql-client mariadb-connector-c supervisor redis caddy git && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

COPY api/.docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY api/.docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY api/.docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini
COPY api/.docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh && \
    TXBOARD_SITE_ADDRESS=:80 SUBSCRIBE_PATH=s caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile

WORKDIR /www

COPY api/composer.json api/composer.lock ./
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

COPY --chown=www:www api/ /www/
COPY --from=web-build /workspace/web/admin/dist /srv/admin
COPY --from=web-build /workspace/web/user/dist /srv/user

# AccessAudit remains a normal TXBoard plugin, but the canonical in-repo
# version is shipped with the image so production no longer needs a source-tree
# bind mount just to make the bundled integration available.
COPY integrations/AccessAudit /opt/txboard/integrations/AccessAudit

RUN composer dump-autoload --no-dev --optimize --no-interaction && \
    php artisan package:discover --ansi && \
    mkdir -p storage/app/public \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
             plugins \
             /data && \
    php artisan storage:link && \
    rm -f storage/logs/*.log && \
    chown -R www:www storage bootstrap/cache plugins && \
    chown redis:redis /data

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true \
    XDG_DATA_HOME=/caddy-data \
    XDG_CONFIG_HOME=/caddy-config

ARG APP_COMMIT=unknown
ENV APP_COMMIT=${APP_COMMIT}

EXPOSE 80 443

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
