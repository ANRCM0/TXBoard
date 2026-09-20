# TXBoard Web

`web/` contains two independent Vite applications deployed behind one static gateway:

- `admin/` — React administration frontend using `/api/v2/<secure_path>`.
- `user/` — Vue user frontend using `/api/v1`.

## Development

Install both applications from the repository root:

```bash
npm install
npm run verify:web
```

Run one application directly:

```bash
npm run dev --workspace @txboard/admin
npm run dev --workspace @txboard/user
```

Environment examples are stored in `admin/.env.example` and `user/.env.example`.

## Build and deployment

`Dockerfile` builds both applications. The resulting Caddy image serves the user application at `/`, the admin application at `/admin/`, and proxies `/api/*` to the `api` service.

GitHub Actions are defined at repository root in `.github/workflows/ci-web.yml`, `.github/workflows/docker-web.yml`, and `.github/workflows/pages-preview.yml`.

## API compatibility

The maintained compatibility record lives at `../contracts/http/xboard-api-contract-audit.md`. API adapters belong in each application's `src/api/` directory; page components must not guess backend routes or response envelopes.
