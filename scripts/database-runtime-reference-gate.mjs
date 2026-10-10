#!/usr/bin/env node
/**
 * Source-only native table guard. A model's legacy declaration is NOT a blocker
 * when its Eloquent getTable() is translated by ResolvesNativeEloquentTable.
 * Literal runtime SQL is a blocker. Dynamic calls remain manual sign-off items.
 * This does not approve schema cutover or inspect installed third-party plugins.
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { pathToFileURL } from 'node:url';

const runtimePath = p => /^api\/(?:app|plugins-core)\//.test(p);

export function auditRuntimeReferences(source, { root = '.', readSource = p => readFileSync(resolve(root, p), 'utf8') } = {}) {
  if (!Array.isArray(source?.tables)) throw new Error('Invalid source inventory');
  const all = source.tables.flatMap(x => x.references ?? []).filter(x => runtimePath(x.path));
  let adaptedModelDeclarations = 0;
  const references = [];
  for (const x of all) {
    if (x.kind === 'model' && x.path.startsWith('api/app/Models/')) {
      // Failure to load the model, or loss of the trait, is a blocker.
      let adapted = false;
      try { adapted = /\buse\s+(?:\\App\\Support\\Database\\)?ResolvesNativeEloquentTable\s*;/.test(readSource(x.path)); }
      catch { adapted = false; }
      if (adapted) { adaptedModelDeclarations++; continue; }
    }
    references.push(x);
  }
  const byKind = {}, byTable = {};
  const paths = new Set();
  for (const x of references) {
    byKind[x.kind] = (byKind[x.kind] ?? 0) + 1;
    byTable[x.table] = (byTable[x.table] ?? 0) + 1;
    paths.add(x.path);
  }
  const dynamic = (source.dynamicCallsNeedingManualReview ?? []).filter(x => runtimePath(x.path));
  return {
    schemaVersion: 1, readOnly: true,
    literalGatePassed: references.length === 0,
    cutoverReady: references.length === 0 && dynamic.length === 0,
    adaptedModelDeclarations,
    remainingLiteralReferences: references.length, dynamicCallSitesToReview: dynamic.length,
    affectedFiles: [...paths].sort(), byKind, byTable,
    limitations: [
      'Static scan only: dynamic SQL and plugin-provided queries require manual review',
      'Passing the literal gate is NOT production approval; inspect workers, external writers and full-schema migrations',
    ],
  };
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const argv = process.argv.slice(2);
    const checked = argv.at(-1) === '--check';
    const args = checked ? argv.slice(0, -1) : argv;
    if (args.length !== 4 || args[0] !== '--source' || args[2] !== '--output') {
      throw new Error('Usage: node scripts/database-runtime-reference-gate.mjs --source SOURCE.json --output REPORT.json [--check]');
    }
    const report = auditRuntimeReferences(JSON.parse(readFileSync(args[1], 'utf8')));
    mkdirSync(dirname(resolve(args[3])), { recursive: true });
    writeFileSync(args[3], JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify({ literalGatePassed: report.literalGatePassed, cutoverReady: report.cutoverReady, remaining: report.remainingLiteralReferences, adaptedModels: report.adaptedModelDeclarations, files: report.affectedFiles.length, dynamic: report.dynamicCallSitesToReview }));
    if (checked && !report.literalGatePassed) process.exitCode = 1;
  } catch (e) { console.error(e.message); process.exitCode = 1; }
}
