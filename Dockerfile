# syntax=docker/dockerfile:1.7

# Build both SPAs once, then copy only their static output into the final
# control-plane image. Node never reaches production.
FROM --platform=$BUILDPLATFORM node:22.23.2-alpine AS web-build
WORKDIR /workspace

COPY package.json package-lock.json ./
COPY web/shared/package.json web/shared/package.json
COPY web/admin/package.json web/admin/package.json
COPY web/user/package.json web/user/package.json
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --no-audit --no-fund

COPY web/shared web/shared
COPY web/admin web/admin
COPY web/user web/user
# The admin bundle's Vite base is asset-only. Browser routing is mounted at
# the instance-specific secure_path at runtime, while Caddy exposes only the
# hashed bundle files below this private static namespace (never index.html).
RUN VITE_BASE_PATH=/.txboard-admin/ npm run build --workspace @txboard/admin && \
    VITE_BASE_PATH=/ npm run build --workspace @txboard/user

# Build the optional MCP protocol adapter separately. The final image keeps only
# the compiled gateway and production dependencies; TypeScript tooling stays out
# of the runtime image.
FROM node:24-alpine AS mcp-build
WORKDIR /workspace/mcp
COPY mcp/package.json mcp/tsconfig.json ./
RUN npm install --no-audit --no-fund
COPY mcp/src ./src
RUN npm run build && npm prune --omit=dev

# One production image for the entire TXBoard control plane:
# Caddy + Admin/User SPAs + Octane + Horizon + Redis + WebSocket server
# + optional MCP Gateway.
FROM phpswoole/swoole:6.2.2-php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pcntl bcmath zip redis && \
    apk add --no-cache sqlite-libs mariadb-connector-c supervisor redis caddy nodejs && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis) && \
    rm -f /usr/local/bin/install-php-extensions

COPY api/.docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY api/.docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY api/.docker/php/zz-txboard.ini /usr/local/etc/php/conf.d/zz-txboard.ini
COPY api/.docker/entrypoint.sh /entrypoint.sh
COPY api/.docker/healthcheck.php /opt/txboard/healthcheck.php
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
COPY --from=mcp-build /workspace/mcp/package.json /opt/txboard-mcp/package.json
COPY --from=mcp-build /workspace/mcp/node_modules /opt/txboard-mcp/node_modules
COPY --from=mcp-build /workspace/mcp/dist /opt/txboard-mcp/dist

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
    ENABLE_MCP=false \
    ENABLE_CADDY=true \
    MCP_HOST=127.0.0.1 \
    MCP_PORT=3000 \
    NODE_ENV=production \
    XDG_DATA_HOME=/caddy-data \
    XDG_CONFIG_HOME=/caddy-config

ARG APP_COMMIT=unknown
ENV APP_COMMIT=${APP_COMMIT}

EXPOSE 80 443

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
