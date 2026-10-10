import test from 'node:test';
import assert from 'node:assert/strict';
import { inventory, classify, assertCurrentBoundaries, markdown, parseArgs } from '../export-route-catalog.mjs';

const required = [
  ['GET|HEAD', 'txapi/health'],
  ['GET|HEAD', 'txapi/public/site-config'],
  ['POST', 'txapi/auth/admin/login'],
  ['GET|HEAD', 'txapi/admin/{admin_path}/users', ['api', 'admin.path', 'admin', 'log']],
  ['POST', 'txapi/node/v1/handshake', ['api', 'App\\Http\\Middleware\\TxNodeAuth']],
  ['GET|HEAD', 'txapi/node/v1/config'],
  ['GET|HEAD', 'txapi/node/v1/users'],
  ['POST', 'txapi/node/v1/report'],
  ['GET|HEAD', 'txapi/node/v1/machine/nodes'],
  ['POST', 'txapi/node/v1/machine/status'],
  ['GET|HEAD', 'api/v2/agent/whoami', ['api', 'agent']],
  ['POST', 'api/v2/agent/pairings/redeem'],
  ['GET|HEAD', 'api/v1/client/subscribe'],
  ['POST', 'api/v1/guest/telegram/webhook'],
  ['GET|HEAD', 'api/v1/guest/payment/notify/{method}/{uuid}'],
  ['POST', 'api/v1/guest/payment/notify/{method}/{uuid}'],
  ['GET|HEAD', 'txapi/payment/webhook/{method}/{uuid}'],
  ['POST', 'txapi/payment/webhook/{method}/{uuid}'],
].map(([method,uri,middleware]) => ({method,uri,middleware:middleware || []}));

test('can export deterministic Laravel route registry and critical control-plane boundaries', () => {
  const out = inventory(required);
  assertCurrentBoundaries(out);
  assert.equal(out.filter(x => x.path === '/txapi/health').length, 1);
  assert.equal(out.find(x => x.path.endsWith('/whoami')).principal, 'agent');
  assert.equal(out.find(x => x.path === '/txapi/node/v1/report').principal, 'node');
  assert.match(markdown(out), /TXBoard HTTP 路由快照/);
  assert.ok(markdown(out).includes('external-adapter-current.md'));
});
test('rejects retired V2 Admin paths even if current endpoints still present', () => {
  const routes = inventory([...required, {method:'POST',uri:'api/v2/{admin_path}/order/update'}]);
  assert.throws(() => assertCurrentBoundaries(routes), /retired_admin_routes/);
});
test('rejects native Admin missing AdminPath or Admin guard', () => {
  const routes = inventory(required.map(r => r.uri.includes('admin/{admin_path}')
    ? {...r,middleware:['api','admin.path']} : r));
  assert.throws(() => assertCurrentBoundaries(routes), /unguarded_native_admin/);
});
test('distinguishes future BFF/Agent target and current Agent legacy', () => {
  assert.equal(classify('api/v2/agent/whoami')[0], 'Legacy Agent Ops (CURRENT)');
  assert.equal(classify('txapi/agent/v1/whoami')[0], 'TXAPI user/application');
  assert.equal(classify('txapi/node/v1/handshake')[0], 'TXAPI Node HTTP');
  assert.equal(classify('api/v1/guest/payment/notify/EPay/{uuid}')[1], 'provider-signature');
});
test('rejects duplicate route verbs and malformed input', () => {
  assert.throws(() => inventory([...required, ...required.slice(0,1)]), /Duplicate/);
  assert.throws(() => inventory([{}]), /Invalid/);
  assert.throws(() => parseArgs([]), /Missing --routes/);
});
