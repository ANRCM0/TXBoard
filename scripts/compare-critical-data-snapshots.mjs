#!/usr/bin/env node
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export function compareCriticalSnapshots(before, after) {
  if (before?.schema_version !== 1 || after?.schema_version !== 1 ||
      before.read_only !== true || after.read_only !== true ||
      !before.tables || !after.tables) throw new Error('Invalid critical-data snapshot');
  const failures = [];
  // Prefixes may differ across a coordinated rename; compare domain metrics only.
  for (const snapshot of [before, after]) {
    if (snapshot.table_prefix !== undefined && !['v2', 'tx'].includes(snapshot.table_prefix)) {
      throw new Error('Invalid critical-data table prefix');
    }
  }
  const domains = new Set([...Object.keys(before.tables), ...Object.keys(after.tables)]);
  for (const domain of [...domains].sort()) {
    const left = before.tables[domain], right = after.tables[domain];
    if (!left || !right) { failures.push('Missing domain: ' + domain); continue; }
    for (const key of new Set([...Object.keys(left), ...Object.keys(right)])) {
      if (left[key] === undefined || right[key] === undefined || String(left[key]) !== String(right[key])) {
        failures.push('Aggregate mismatch: ' + domain + '.' + key);
      }
    }
  }
  return { passed: failures.length === 0, failures, notRowLevelProof: true };
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    if (process.argv.length !== 4) throw new Error('Usage: node scripts/compare-critical-data-snapshots.mjs BEFORE.json AFTER.json');
    const report = compareCriticalSnapshots(...process.argv.slice(2).map(p => JSON.parse(readFileSync(p, 'utf8'))));
    console.log(JSON.stringify(report));
    if (!report.passed) process.exitCode = 1;
  } catch (e) { console.error(e.message); process.exitCode = 1; }
}
