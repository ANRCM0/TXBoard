#!/usr/bin/env node
/**
 * Conservative, deterministic cutover planner. Produces a reviewable plan only.
 * Never connects to MySQL, executes DDL, or silently includes source-only tables.
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { pathToFileURL } from 'node:url';

export function planCutover(parity) {
  if (parity?.schemaVersion !== 1 || parity.readOnly !== true ||
      parity.cutoverPerformed !== false || !Array.isArray(parity.mappings)) {
    throw new Error('Invalid read-only MySQL parity artifact');
  }
  const blockers = [...(parity.failures ?? [])];
  const review = [];
  const renames = [];
  const old = new Set(), next = new Set();
  for (const row of parity.mappings) {
    if (!/^v2_[a-z][a-z0-9_]*$/.test(row.oldName) ||
        row.proposedName !== 'tx_' + row.oldName.slice(3) ||
        old.has(row.oldName) || next.has(row.proposedName)) {
      throw new Error('Invalid or duplicate table mapping');
    }
    old.add(row.oldName);
    next.add(row.proposedName);
    if (row.targetAlreadyExists) blockers.push('Occupied target: ' + row.proposedName);
    if (row.lifecycle === 'consolidated-into-v2_server' && !row.existsInMysql) {
      review.push({ table: row.oldName, reason: 'historical-protocol-consolidation' });
    } else if (row.lifecycle === 'unverified-source-only' && !row.existsInMysql) {
      review.push({ table: row.oldName, reason: 'no-creation-migration' });
    } else if (row.existsInMysql && !row.targetAlreadyExists) {
      renames.push({ from: row.oldName, to: row.proposedName });
    } else {
      blockers.push('Unclassified or missing table: ' + row.oldName);
    }
  }
  if (parity.untrackedMysqlV2Tables?.length) blockers.push('Untracked live V2 tables require manual review');
  // The current Laravel code still references V2 names. A plan is NOT permission
  // to run DDL until all runtime queries, plugins, workers and deploys are switched.
  blockers.push('Application runtime table references not yet migrated to tx_*');
  blockers.push('Live database backup, maintenance freeze, restore drill and row-level checks not verified');
  return {
    schemaVersion: 1, kind: 'native-table-cutover-plan', executable: false,
    requiresManualApproval: true, proposedRenames: renames.sort((a,b)=>a.from.localeCompare(b.from)),
    excludedFromAutomaticRename: review.sort((a,b)=>a.table.localeCompare(b.table)),
    blockers: [...new Set(blockers)].sort(),
  };
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const argv = process.argv.slice(2);
    if (argv.length !== 4 || argv[0] !== '--parity' || argv[2] !== '--output') {
      throw new Error('Usage: node scripts/database-native-cutover-plan.mjs --parity PARITY.json --output PLAN.json');
    }
    const plan = planCutover(JSON.parse(readFileSync(argv[1], 'utf8')));
    mkdirSync(dirname(resolve(argv[3])), { recursive: true });
    writeFileSync(argv[3], JSON.stringify(plan, null, 2) + '\n');
    console.log(JSON.stringify({ proposed: plan.proposedRenames.length, excluded: plan.excludedFromAutomaticRename.length, blockers: plan.blockers.length, executable: plan.executable }));
  } catch (e) { console.error(e.message); process.exitCode = 1; }
}
