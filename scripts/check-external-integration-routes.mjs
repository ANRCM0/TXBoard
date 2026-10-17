#!/usr/bin/env node
/** Guard the machine-readable external route baseline against Laravel's registry. */
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { inventory } from './export-route-catalog.mjs';

const wire = {
  order_idempotency_header: 'Idempotency-Key',
  node_authorization_policy: 'native-provider-authorization/v2',
  node_usage_submit_path: '/txapi/node/v2/usage/submit',
  node_machine_id_header: 'X-TX-Machine-ID',
  node_websocket_path: '/txapi/node/v1/ws',
};

function exactKeys(value, keys) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    && Object.keys(value).sort().join('\n') === [...keys].sort().join('\n');
}

export function loadExternalContract() {
  return JSON.parse(readFileSync(new URL('./fixtures/external-integration-routes.json', import.meta.url), 'utf8'));
}

export function verifyExternalRoutes(routes, contract) {
  const labels = value => Array.isArray(value) && value.every(row => typeof row === 'string'
    && /^(GET|POST|PUT|PATCH|DELETE) \/txapi\/[A-Za-z0-9_{}./-]+$/.test(row))
    && new Set(value).size === value.length;
  if (!exactKeys(contract, ['schema_version', 'theme_routes', 'node_routes', 'wire'])
    || contract.schema_version !== 1 || !labels(contract.theme_routes) || !labels(contract.node_routes)
    || contract.theme_routes.length < 55 || contract.node_routes.length !== 8
    || !contract.node_routes.every(row => /^(GET|POST) \/txapi\/node\/v1\//.test(row))
    || !exactKeys(contract.wire, Object.keys(wire))
    || Object.entries(wire).some(([key, value]) => contract.wire[key] !== value)) {
    throw new Error('EXTERNAL_INTEGRATION_ROUTE_CONTRACT_INVALID');
  }
  const registered = inventory(routes);
  const live = new Set(registered.map(r => r.method + ' ' + r.path));
  const user = registered.filter(r => /(?:txapi\.user|App\\Http\\Middleware\\TxapiUser)/.test(r.middleware));
  const nodes = registered.filter(r => r.path.startsWith('/txapi/node/v1/'));
  const themeset = new Set(contract.theme_routes);
  const nodeset = new Set(contract.node_routes);
  const failures = {
    unlistedUser: user.filter(r => !themeset.has(r.method + ' ' + r.path)).map(r => r.method + ' ' + r.path),
    unlistedNode: nodes.filter(r => !nodeset.has(r.method + ' ' + r.path)).map(r => r.method + ' ' + r.path),
    staleContract: [...themeset, ...nodeset].filter(s => !live.has(s)),
  };
  if (user.length < 25 || nodes.length !== 8 || Object.values(failures).some(f => f.length)) {
    throw new Error('CURRENT external integration route contract out of date:\n'
      + JSON.stringify({ ...failures, userCount:user.length, nodeCount:nodes.length,
        expectedTheme:themeset.size, expectedNode:nodeset.size }, null, 2));
  }
  return { userCount:user.length, nodeCount:nodes.length,
    expectedTheme:themeset.size, expectedNode:nodeset.size };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const at = process.argv.indexOf('--routes');
    if (at < 0 || !process.argv[at + 1]) throw new Error('Usage: --routes <route-list.json>');
    const routes = JSON.parse(readFileSync(process.argv[at + 1], 'utf8'));
    process.stdout.write(JSON.stringify(verifyExternalRoutes(routes, loadExternalContract())) + '\n');
  } catch (error) {
    process.stderr.write(String(error.message || error) + '\n');
    process.exitCode = 1;
  }
}
