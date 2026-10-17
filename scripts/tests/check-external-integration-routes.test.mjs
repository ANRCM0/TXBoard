import test from 'node:test';
import assert from 'node:assert/strict';
import { loadExternalContract, verifyExternalRoutes } from '../check-external-integration-routes.mjs';

const contract = loadExternalContract();
const fixture = () => [...contract.theme_routes, ...contract.node_routes].map(label => {
  const space = label.indexOf(' ');
  const method = label.slice(0, space);
  const uri = label.slice(space + 2);
  const protectedPath = /^txapi\/(?:me(?:\/|$)|orders(?:\/|$)|billing\/|tickets(?:\/|$)|traffic\/|knowledge(?:\/|$)|notices(?:\/|$)|invites(?:\/|$)|gift-cards\/)/.test(uri);
  return { method, uri, middleware: protectedPath ? ['api','txapi.user'] : ['api'] };
});

test('the machine-readable theme and TXNode baseline has full current route tables', () => {
  const result = verifyExternalRoutes(fixture(), contract);
  assert.ok(result.userCount >= 25);
  assert.equal(result.nodeCount, 8);
  assert.ok(contract.node_routes.includes('POST /txapi/node/v1/audit/report'));
  assert.ok(contract.node_routes.includes('GET /txapi/node/v1/audit/rules'));
  assert.ok(contract.theme_routes.includes('POST /txapi/billing/recharges'));
  assert.ok(contract.node_routes.includes('POST /txapi/node/v1/report'));
});

test('new user routes require a baseline update', () => {
  const routes = [...fixture(), {method:'GET',uri:'txapi/me/new-feature',middleware:['txapi.user']}];
  assert.throws(() => verifyExternalRoutes(routes, contract), /unlistedUser/);
});

test('removed Node routes must not be advertised', () => {
  const routes = fixture().filter(r => !(r.method === 'POST' && r.uri === 'txapi/node/v1/report'));
  assert.throws(() => verifyExternalRoutes(routes, contract), /staleContract/);
});

test('new native Node routes require baseline updates', () => {
  const routes = [...fixture(), {method:'POST',uri:'txapi/node/v1/extra',middleware:['api']}];
  assert.throws(() => verifyExternalRoutes(routes, contract), /unlistedNode/);
});

test('wire metadata must retain exact idempotency, signed authority, receipt, machine and WSS bindings', () => {
  for (const field of Object.keys(contract.wire)) {
    const altered = structuredClone(contract);
    altered.wire[field] = '';
    assert.throws(() => verifyExternalRoutes(fixture(), altered), /CONTRACT_INVALID/);
  }
});

test('malformed, duplicate, incomplete and expanded contracts are rejected', () => {
  const cases = [null, {}, {...contract, schema_version: 2}, {...contract, unexpected: true},
    {...contract, theme_routes: []}, {...contract, theme_routes: [...contract.theme_routes, contract.theme_routes[0]]},
    {...contract, node_routes: contract.node_routes.slice(1)},
    {...contract, theme_routes: ['GET https://upstream.invalid/txapi/me', ...contract.theme_routes.slice(1)]},
    {...contract, wire: {...contract.wire, unexpected: true}},
  ];
  for (const value of cases) {
    assert.throws(() => verifyExternalRoutes(fixture(), value), /CONTRACT_INVALID/);
  }
});
