import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { inventory, classify, assertCurrentBoundaries, markdown, parseArgs } from '../export-route-catalog.mjs';

const required = [
  ['GET|HEAD', 'txapi/health'],
  ['GET|HEAD', 'txapi/readiness'],
  ['GET|HEAD', 'txapi/public/site-config'],
  ['POST', 'txapi/auth/admin/login'],
  ['GET|HEAD', 'txapi/admin/{admin_path}/users', ['api', 'admin.path', 'admin', 'log']],
  ['POST', 'txapi/node/v1/handshake', ['api', 'App\\Http\\Middleware\\TxNodeAuth']],
  ['GET|HEAD', 'txapi/node/v1/config'],
  ['GET|HEAD', 'txapi/node/v1/users'],
  ['POST', 'txapi/node/v1/report'],
  ['GET|HEAD', 'txapi/node/v1/machine/nodes'],
  ['POST', 'txapi/node/v1/machine/status'],
  ['POST', 'txapi/integrations/telegram/webhook'],
  ['GET|HEAD', 'txapi/payment/webhook/{method}/{uuid}'],
  ['POST', 'txapi/payment/webhook/{method}/{uuid}'],
  ['POST', 'txapi/internal/native/v1/{domain}/operations/{operation}', ['api', 'throttle:native-internal-ingress', 'App\\Http\\Middleware\\NativeServiceIdentity']],
  ['POST', 'txapi/subscription-links/{linkRef}/redeem', ['api', 'throttle:30,1']],
].map(([method,uri,middleware]) => ({method,uri,middleware:middleware || (uri.startsWith('txapi/node/') ? ['api','App\\Http\\Middleware\\TxNodeAuth'] : [])}));

test('can export deterministic Laravel route registry and critical control-plane boundaries', () => {
  const out = inventory(required);
  assertCurrentBoundaries(out);
  assert.equal(out.filter(x => x.path === '/txapi/health').length, 1);
  assert.equal(out.find(x => x.path === '/txapi/readiness').principal, 'public');
  assert.equal(out.find(x => x.path === '/txapi/node/v1/report').principal, 'node');
  assert.match(markdown(out), /TXBoard HTTP 路由快照/);
  assert.ok(markdown(out).includes('external-integration-routes.json'));
});
test('rejects all legacy V1/V2 routes even if current endpoints still present', () => {
  const routes = inventory([...required,
    {method:'POST',uri:'api/v2/{admin_path}/order/update'},
    {method:'GET|HEAD',uri:'api/v1/guest/plan/fetch'}]);
  assert.throws(() => assertCurrentBoundaries(routes), /legacy_routes/);
});
test('rejects native Admin missing AdminPath or Admin guard', () => {
  const routes = inventory(required.map(r => r.uri.includes('admin/{admin_path}')
    ? {...r,middleware:['api','admin.path']} : r));
  assert.throws(() => assertCurrentBoundaries(routes), /unguarded_native_admin/);
});
test('classifies native Node and Telegram endpoints', () => {
  assert.equal(classify('txapi/integrations/telegram/webhook')[0], 'Native Telegram webhook');
  assert.equal(classify('txapi/node/v1/handshake')[0], 'TXAPI Node HTTP');
  assert.equal(classify('txapi/node/v2/usage/submit')[1], 'node');
  assert.equal(classify('txapi/node/v2/usage/batch/get')[1], 'node');
});
test('rejects registered retired Agent endpoints and guide', () => {
  for (const uri of ['txapi/agent/v1/whoami', 'txapi/agent/v1/pairings/redeem',
    'txapi/admin/{admin_path}/agents', 'txapi/admin/{admin_path}/agents/actions',
    '.well-known/txboard-agent-connect', '.well-known/txboard-agent-connect.md']) {
    assert.equal(classify(uri)[1], 'forbidden');
    assert.throws(() => assertCurrentBoundaries(inventory([...required, {method:'GET',uri}])), /legacy_routes/);
  }
});
test('requires node guard for the isolated usage receipt routes', () => {
  const routes = inventory([...required, {method:'POST',uri:'txapi/node/v2/usage/submit',middleware:['api']}]);
  assert.throws(() => assertCurrentBoundaries(routes), /unguarded_native_node/);
});
test('rejects duplicate route verbs and malformed input', () => {
  assert.throws(() => inventory([...required, ...required.slice(0,1)]), /Duplicate/);
  assert.throws(() => inventory([{}]), /Invalid/);
  assert.throws(() => parseArgs([]), /Missing --routes/);
});

test('retired internal operations cannot be registered with any middleware', () => {
  for (const middleware of [[], ['api', 'user'], ['api', 'App\\Http\\Middleware\\InternalServiceProof']]) {
    const route = {method:'POST', uri:'txapi/internal/v1/operations/{operation}', middleware};
    assert.equal(classify(route.uri)[1], 'forbidden');
    assert.throws(() => assertCurrentBoundaries(inventory([...required, route])), /legacy_routes/);
  }
});

test('only the exact formal native Internal API is admitted with independent service and actor checks', () => {
  const uri = 'txapi/internal/native/v1/{domain}/operations/{operation}';
  assert.equal(classify(uri)[1], 'service-and-actor');
  assertCurrentBoundaries(inventory(required));
  for (const middleware of [[], ['api', 'txapi.user'], ['api', 'admin'],
    ['api', 'App\\Http\\Middleware\\InternalServiceProof'], ['api', 'App\\Http\\Middleware\\NativeServiceIdentity']]) {
    assert.throws(() => assertCurrentBoundaries(inventory(required.map(route =>
      route.uri === uri ? {...route, middleware} : route))), /unguarded_native_internal/);
  }
  assert.throws(() => assertCurrentBoundaries(inventory([...required,
    {method:'GET', uri, middleware:['App\\Http\\Middleware\\NativeServiceIdentity']}]
  )), /unguarded_native_internal/);
  for (const path of ['txapi/internal/native/v2/{domain}/operations/{operation}',
    'txapi/internal/native/v1/{operation}', 'txapi/internal/native/v1/user/sql']) {
    assert.equal(classify(path)[1], 'forbidden');
  }
  assert.equal(classify('txapi/subscription-links/{linkRef}/redeem')[1], 'link-proof');
});

test('split Caddy exposes only the native TX-Node WebSocket Upgrade path', () => {
  const caddy = readFileSync(new URL('../../api/.docker/caddy/Caddyfile.split', import.meta.url), 'utf8');
  assert.match(caddy, /@nativeNodeWs path \/txapi\/node\/v1\/ws/);
  assert.doesNotMatch(caddy, /@ws path \/ws(?:\s|$)/);
  assert.match(caddy, /@retiredMcp path \/mcp \/mcp\/\*/);
  assert.match(caddy, /respond @retiredMcp 404/);
});

test('route classifier never labels removed V1/V2 routes as current', () => {
  assert.equal(classify('api/v2/server/handshake')[1], 'forbidden');
  assert.equal(classify('api/v1/user/info')[1], 'forbidden');
});

test('rejects retired reusable subscription and duplicate theme entrypoints', () => {
  for (const uri of ['s/{token}', '{subscribe_path}/{token}', 'txapi/me/nodes',
    'txapi/me/subscription-credentials/rotate',
    'txapi/admin/{admin_path}/users/{id}/subscription-link',
    'txapi/admin/{admin_path}/users/{id}/subscription-credentials/rotate',
    'txapi/admin/{admin_path}/themes/v2/upload']) {
    assert.equal(classify(uri)[1], 'forbidden');
    assert.throws(() => assertCurrentBoundaries(inventory([...required, {method:'GET', uri}])), /legacy_routes/);
  }
});
