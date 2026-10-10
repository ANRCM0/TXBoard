import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { themeRows, nodeRows, verifyGuideRoutes } from '../check-external-integration-routes.mjs';

const theme = readFileSync(new URL('../../contracts/http/theme-integration-current.md', import.meta.url), 'utf8');
const node = readFileSync(new URL('../../contracts/node-protocol/txnode-integration-current.md', import.meta.url), 'utf8');
const fixture = () => [...themeRows(theme), ...nodeRows(node)].map(label => {
  const space = label.indexOf(' ');
  const method = label.slice(0, space);
  const uri = label.slice(space + 2);
  const protectedPath = /^txapi\/(?:me(?:\/|$)|orders(?:\/|$)|billing\/|tickets(?:\/|$)|traffic\/|knowledge(?:\/|$)|notices(?:\/|$)|invites(?:\/|$)|gift-cards\/)/.test(uri);
  return { method, uri, middleware: protectedPath ? ['api','txapi.user'] : ['api'] };
});

test('theme and TXNode docs have full current route tables', () => {
  const result = verifyGuideRoutes(fixture(), theme, node);
  assert.ok(result.userCount >= 25);
  assert.equal(result.nodeCount, 8);
  assert.ok(nodeRows(node).includes('POST /txapi/node/v1/audit/report'));
  assert.ok(nodeRows(node).includes('GET /txapi/node/v1/audit/rules'));
  assert.ok(themeRows(theme).includes('POST /txapi/billing/recharges'));
  assert.ok(nodeRows(node).includes('POST /txapi/node/v1/report'));
});

test('new user routes require a documentation update', () => {
  const routes = [...fixture(), {method:'GET',uri:'txapi/me/new-feature',middleware:['txapi.user']}];
  assert.throws(() => verifyGuideRoutes(routes, theme, node), /undocumentedUser/);
});

test('removed Node routes must not be advertised', () => {
  const routes = fixture().filter(r => !(r.method === 'POST' && r.uri === 'txapi/node/v1/report'));
  assert.throws(() => verifyGuideRoutes(routes, theme, node), /staleDocumentation/);
});

test('new native Node routes require documentation updates', () => {
  const routes = [...fixture(), {method:'POST',uri:'txapi/node/v1/extra',middleware:['api']}];
  assert.throws(() => verifyGuideRoutes(routes, theme, node), /undocumentedNode/);
});
