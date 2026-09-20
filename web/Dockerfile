# syntax=docker/dockerfile:1.7
#
# Two stages: build the two SPAs with Node, then ship only the static output
# behind Caddy. The builder never reaches the final image.

# Pinned for the same reason as the API image: `node:22-alpine` moves with every
# Node patch and Alpine release, so an unrelated rebuild could otherwise change
# the toolchain that produced the shipped bundles.
FROM node:22.23.2-alpine AS build
WORKDIR /workspace

# Manifests before source, so editing a component does not re-run `npm ci`.
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

# Pinned to an exact Caddy release for the same reproducibility reason.
FROM caddy:2.11.4-alpine
COPY web/Caddyfile /etc/caddy/Caddyfile
COPY --from=build /workspace/web/admin/dist /srv/admin
COPY --from=build /workspace/web/user/dist /srv/user

# 443 only carries traffic once TXBOARD_SITE_ADDRESS names a real hostname and
# Caddy provisions a certificate; publishing it unconditionally is harmless.
EXPOSE 80 443
