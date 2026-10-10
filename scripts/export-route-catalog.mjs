#!/usr/bin/env node
/**
 * Exports the live Laravel route registry, not guessed controller definitions.
 * Usage: node scripts/export-route-catalog.mjs --routes route-list.json
 *   --json artifacts/routes.json --markdown artifacts/routes.md --check
 *
 * Laravel route:list --json is authoritative for HTTP only. WebSocket
 * Workerman paths, plugin-created runtime routes and Edge/Gateway proxy
 * mappings must be reviewed separately.
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { pathToFileURL } from 'node:url';

export const classify = (uri) => {
  const p = '/' + String(uri).replace(/^\/+/, '');
  if (p.startsWith('/txapi/admin/')) return ['TXAPI admin', 'admin'];
  if (p.startsWith('/txapi/node/v1/')) return ['TXAPI Node HTTP', 'node'];
  if (p.startsWith('/txapi/payment/webhook/')) return ['Native payment callback', 'provider-signature'];
  if (p.startsWith('/txapi/auth/')) return ['TXAPI authentication', 'mixed'];
  if (p.startsWith('/txapi/public/') || p === '/txapi/plans' || p === '/txapi/health') return ['TXAPI public', 'public'];
  if (p.startsWith('/txapi/')) return ['TXAPI user/application', 'user'];
  if (p.startsWith('/api/v2/agent/')) return ['Legacy Agent Ops (CURRENT)', 'agent'];
  if (p.startsWith('/api/v2/server/')) return ['Legacy Node/Machine (CURRENT)', 'node'];
  if (p.startsWith('/api/v1/server/')) return ['Legacy Node (CURRENT)', 'node'];
  if (p.startsWith('/api/v1/guest/payment/')) return ['Legacy payment callback (CURRENT)', 'provider-signature'];
  if (p.startsWith('/api/v1/') || p.startsWith('/api/v2/')) return ['Legacy V1/V2 application', 'legacy'];
  if (/^\/(?:s|\{[^}]+\})\/\{token\}$/.test(p) || p === '/api/v1/client/subscribe') return ['Subscription delivery', 'subscription-credential'];
  return ['Web/other', 'mixed'];
};

const methodNames = method => String(method || '').split('|')
  .filter(v => v && v !== 'HEAD' && v !== 'OPTIONS')
  .map(v => v.toUpperCase());

export function inventory(routes) {
  if (!Array.isArray(routes)) throw new Error('Expected Laravel route:list --json array');
  const out = [];
  const seen = new Set();
  for (const row of routes) {
    if (!row || typeof row.uri !== 'string' || typeof row.method !== 'string') {
      throw new Error('Invalid Laravel route row: uri/method required');
    }
    const uri = '/' + row.uri.replace(/^\/+/, '');
    const [family, principal] = classify(uri);
    const middleware = Array.isArray(row.middleware) ? row.middleware.join(',') :
      String(row.middleware ?? '');
    for (const method of methodNames(row.method)) {
      const id = method + ' ' + uri;
      if (seen.has(id)) throw new Error('Duplicate registered HTTP route: ' + id);
      seen.add(id);
      out.push({
        method, path: uri, family, principal,
        middleware: middleware || 'none',
        name: typeof row.name === 'string' ? row.name : '',
      });
    }
  }
  out.sort((a,b) => a.path.localeCompare(b.path, 'en') ||
    a.method.localeCompare(b.method, 'en'));
  return out;
}

export function assertCurrentBoundaries(items) {
  const paths = new Set(items.map(x => x.method + ' ' + x.path));
  const required = [
    'GET /txapi/health',
    'GET /txapi/public/site-config',
    'POST /txapi/auth/admin/login',
    'GET /txapi/admin/{admin_path}/users',
    'POST /txapi/node/v1/handshake',
    'GET /txapi/node/v1/config',
    'GET /txapi/node/v1/users',
    'POST /txapi/node/v1/report',
    'GET /txapi/node/v1/machine/nodes',
    'POST /txapi/node/v1/machine/status',
    'GET /api/v2/agent/whoami',
    'POST /api/v2/agent/pairings/redeem',
    'GET /api/v1/client/subscribe',
    'POST /api/v1/guest/telegram/webhook',
    'GET /api/v1/guest/payment/notify/{method}/{uuid}',
    'POST /api/v1/guest/payment/notify/{method}/{uuid}',
    'GET /txapi/payment/webhook/{method}/{uuid}',
    'POST /txapi/payment/webhook/{method}/{uuid}',
  ];
  const missing = required.filter(x => !paths.has(x));
  const obsolete = items.filter(x => /^\/api\/v2\/\{admin_path\}(?:\/|$)/.test(x.path));
  const unguarded = items.filter(x => x.family === 'TXAPI admin' &&
    (!x.middleware.includes('admin.path') || !/(^|,)admin(,|$)/.test(x.middleware)));
  if (missing.length || obsolete.length || unguarded.length) {
    throw new Error(JSON.stringify({
      missing_current_routes: missing,
      retired_admin_routes: obsolete.map(x => x.method+' '+x.path),
      unguarded_native_admin: unguarded.map(x => x.method+' '+x.path),
    }, null, 2));
  }
}

function escapeCell(value) {
  return String(value).replaceAll('|', '\\|').replaceAll('\n', ' ');
}

export function markdown(items) {
  const summary = new Map();
  for (const item of items) summary.set(item.family, (summary.get(item.family) || 0) + 1);
  const lines = [
    '# TXBoard HTTP 路由快照（自动生成）', '',
    '> Source: `php artisan route:list --json`. Each row is one HTTP verb, excluding implicit HEAD/OPTIONS.',
    '> This is a **server code registry**, not evidence that a deployment is live or that a client/provider is compatible.',
    '> Workerman WebSocket `/txapi/node/v1/ws`, legacy `/ws`, proxy/Caddy mappings, runtime plugin routes and BFF targets are **not** established by this listing.',
    '> Paths with `{admin_path}` and `{token}` are placeholders. Do not substitute a real secret in source control.',
    '', '## Counts by route family', '', '| Family | HTTP methods |', '|---|---:|',
    ...[...summary].sort(([a],[b]) => a.localeCompare(b, 'en')).map(([family,count]) => `| ${escapeCell(family)} | ${count} |`),
    '', '## Registered HTTP methods', '',
    '| Method | Path | Family | Principal | Laravel middleware |', '|---|---|---|---|---|',
    ...items.map(x => `| ${escapeCell(x.method)} | \`${escapeCell(x.path)}\` | ${escapeCell(x.family)} | ${escapeCell(x.principal)} | \`${escapeCell(x.middleware)}\` |`),
    '', 'Integration rules, errors, security, payment callback behavior and rollout status:',
    'see [External Adapter Integration Guide](../../contracts/http/external-adapter-current.md).', '',
  ];
  return lines.join('\n');
}

export function parseArgs(args) {
  const flags = {};
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--check') { flags.check = true; continue; }
    if (!['--routes','--json','--markdown'].includes(arg) || !args[i+1]) {
      throw new Error('Usage: --routes <route-list.json> [--json <file>] [--markdown <file>] [--check]');
    }
    flags[arg.slice(2)] = args[++i];
  }
  if (!flags.routes) throw new Error('Missing --routes');
  return flags;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const flags = parseArgs(process.argv.slice(2));
    const routes = JSON.parse(readFileSync(flags.routes, 'utf8'));
    const data = inventory(routes);
    if (flags.check) assertCurrentBoundaries(data);
    const outputs = [
      [flags.json, JSON.stringify({
        schema_version: 1,
        source: 'Laravel route:list --json',
        scope: 'registered HTTP only; no external runtime acceptance',
        routes: data,
      }, null, 2) + '\n'],
      [flags.markdown, markdown(data)],
    ];
    for (const [file,content] of outputs) {
      if (!file) continue;
      mkdirSync(dirname(file), {recursive:true});
      writeFileSync(file, content, {mode:0o600});
    }
    process.stdout.write(`Registered ${data.length} HTTP methods; ${new Set(data.map(x=>x.family)).size} route families; boundary check ${flags.check?'passed':'not requested'}.\n`);
  } catch (err) {
    process.stderr.write(String(err.message || err) + '\n');
    process.exitCode = 1;
  }
}
