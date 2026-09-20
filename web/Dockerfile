FROM node:22-alpine AS build
WORKDIR /workspace

COPY package.json package-lock.json ./
COPY web/admin/package.json web/admin/package.json
COPY web/user/package.json web/user/package.json
RUN npm ci --no-audit --no-fund

COPY web/admin web/admin
COPY web/user web/user
RUN VITE_BASE_PATH=/admin/ npm run build --workspace @txboard/admin && \
    VITE_BASE_PATH=/ npm run build --workspace @txboard/user

FROM caddy:2-alpine
COPY web/Caddyfile /etc/caddy/Caddyfile
COPY --from=build /workspace/web/admin/dist /srv/admin
COPY --from=build /workspace/web/user/dist /srv/user
EXPOSE 80
