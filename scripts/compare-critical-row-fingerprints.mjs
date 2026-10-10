#!/usr/bin/env node
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export function compareRowFingerprints(before, after) {
  for (const snapshot of [before, after]) {
    if (snapshot?.schema_version !== 1 || snapshot.read_only !== true ||
        snapshot.kind !== 'critical-row-fingerprints' ||
        snapshot.table_prefix !== 'tx' || !snapshot.tables) {
      throw new Error('Invalid row fingerprint snapshot');
    }
  }
  const failures = [];
  for (const domain of new Set([...Object.keys(before.tables), ...Object.keys(after.tables)])) {
    const left = before.tables[domain], right = after.tables[domain];
    if (!left || !right) { failures.push('Missing domain: ' + domain); continue; }
    if (left.rows !== right.rows) failures.push('Row count mismatch: ' + domain);
    if (JSON.stringify(left.columns) !== JSON.stringify(right.columns)) failures.push('Column mismatch: ' + domain);
    if (!/^[a-f0-9]{64}$/.test(left.sha256) || !/^[a-f0-9]{64}$/.test(right.sha256) || left.sha256 !== right.sha256) {
      failures.push('Row digest mismatch: ' + domain);
    }
  }
  return { passed: failures.length === 0, failures, scope: 'critical-tables-only' };
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    if (process.argv.length !== 4) throw new Error('Usage: node scripts/compare-critical-row-fingerprints.mjs BEFORE.json AFTER.json');
    const report = compareRowFingerprints(...process.argv.slice(2).map(p => JSON.parse(readFileSync(p, 'utf8'))));
    console.log(JSON.stringify(report));
    if (!report.passed) process.exitCode = 1;
  } catch (e) { console.error(e.message); process.exitCode = 1; }
}
