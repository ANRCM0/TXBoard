#!/usr/bin/env node
/**
 * Source-only runtime table-name readiness audit.
 * Intentionally reports blockers rather than attempting an unsafe partial rename.
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { pathToFileURL } from 'node:url';

export function auditRuntimeReferences(source) {
  if (!Array.isArray(source?.tables)) throw new Error('Invalid source inventory');
  const references = source.tables.flatMap(x => x.references ?? []).filter(x => !x.path.startsWith('api/database/migrations/'));
  const byKind = {}, byTable = {};
  const paths = new Set();
  for (const x of references) {
    byKind[x.kind] = (byKind[x.kind] ?? 0) + 1;
    byTable[x.table] = (byTable[x.table] ?? 0) + 1;
    paths.add(x.path);
  }
  const dynamic = (source.dynamicCallsNeedingManualReview ?? []).filter(x => !x.path.startsWith('api/database/migrations/'));
  return {
    schemaVersion: 1, readOnly: true, cutoverReady: references.length === 0 && dynamic.length === 0,
    remainingLiteralReferences: references.length, dynamicCallSitesToReview: dynamic.length,
    affectedFiles: [...paths].sort(), byKind, byTable,
    limitations: ['Static regex scan; dynamic SQL, package plugins, runtime-generated queries and external integrations need manual audit'],
  };
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const a = process.argv.slice(2);
    if (a.length !== 4 || a[0] !== '--source' || a[2] !== '--output') {
      throw new Error('Usage: node scripts/database-runtime-reference-gate.mjs --source SOURCE.json --output REPORT.json');
    }
    const report = auditRuntimeReferences(JSON.parse(readFileSync(a[1], 'utf8')));
    mkdirSync(dirname(resolve(a[3])), { recursive: true });
    writeFileSync(a[3], JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify({ cutoverReady: report.cutoverReady, remaining: report.remainingLiteralReferences, files: report.affectedFiles.length, dynamic: report.dynamicCallSitesToReview }));
  } catch (e) { console.error(e.message); process.exitCode = 1; }
}
