#!/usr/bin/env node
/** Guard published TXAPI theme/Node tables with Laravel route:list --json. */
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { inventory } from './export-route-catalog.mjs';

export function themeRows(doc) {
  const rows = [];
  for (const line of doc.split('\n')) {
    const match = line.match(/^\|\s*(GET|POST|PATCH|DELETE)(?:\s*\/\s*(GET|POST|PATCH|DELETE))?\s*\|\s*\x60(\/[^\x60]+)\x60\s*\|/)
      || line.match(/^\|\s*[^|]+\s*\|\s*(GET|POST|PATCH|DELETE)(?:\s*\/\s*(GET|POST|PATCH|DELETE))?\s*\x60(\/[^\x60]+)\x60\s*\|/);
    if (match) for (const method of [match[1], match[2]].filter(Boolean)) {
      rows.push(method + ' /txapi' + match[3]);
    }
  }
  return rows;
}

export function nodeRows(doc) {
  const rows = [];
  for (const line of doc.split('\n')) {
    const match = line.match(/^\|\s*(GET|POST)\s*\|\s*\x60(\/txapi\/node\/v1\/[^\x60]+)\x60\s*\|/);
    if (match) rows.push(match[1] + ' ' + match[2]);
  }
  return rows;
}

export function verifyGuideRoutes(routes, theme, node) {
  const registered = inventory(routes);
  const live = new Set(registered.map(r => r.method + ' ' + r.path));
  const themeDeclared = themeRows(theme);
  const nodeDeclared = nodeRows(node);
  const user = registered.filter(r => /(?:txapi\.user|App\\Http\\Middleware\\TxapiUser)/.test(r.middleware));
  const nodes = registered.filter(r => r.path.startsWith('/txapi/node/v1/'));
  const themeset = new Set(themeDeclared);
  const nodeset = new Set(nodeDeclared);
  const failures = {
    undocumentedUser: user.filter(r => !themeset.has(r.method + ' ' + r.path)).map(r => r.method + ' ' + r.path),
    undocumentedNode: nodes.filter(r => !nodeset.has(r.method + ' ' + r.path)).map(r => r.method + ' ' + r.path),
    staleDocumentation: [...themeset, ...nodeset].filter(s => !live.has(s)),
  };
  if (user.length < 25 || nodes.length !== 8 || themeset.size < 55 || nodeset.size !== 8
    || themeset.size !== themeDeclared.length || nodeset.size !== nodeDeclared.length
    || !theme.includes('Idempotency-Key') || !node.includes('traffic_batch_id')
    || !node.includes('X-TX-Machine-ID') || !node.includes('/txapi/node/v1/ws')
    || Object.values(failures).some(f => f.length)) {
    throw new Error('CURRENT external integration route docs out of date:\n'
      + JSON.stringify({ ...failures, userCount:user.length, nodeCount:nodes.length,
        documentedTheme:themeset.size, documentedNode:nodeset.size }, null, 2));
  }
  return { userCount:user.length, nodeCount:nodes.length,
    documentedTheme:themeset.size, documentedNode:nodeset.size };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const at = process.argv.indexOf('--routes');
    if (at < 0 || !process.argv[at + 1]) throw new Error('Usage: --routes <route-list.json>');
    const routes = JSON.parse(readFileSync(process.argv[at + 1], 'utf8'));
    const theme = readFileSync(new URL('../contracts/http/theme-integration-current.md', import.meta.url), 'utf8');
    const node = readFileSync(new URL('../contracts/node-protocol/txnode-integration-current.md', import.meta.url), 'utf8');
    process.stdout.write(JSON.stringify(verifyGuideRoutes(routes, theme, node)) + '\n');
  } catch (error) {
    process.stderr.write(String(error.message || error) + '\n');
    process.exitCode = 1;
  }
}
