#!/usr/bin/env node
/**
 * Static, read-only inventory for planning the eventual v2_* -> native SQL cutover.
 * It never connects to a database and never renames or deletes tables.
 * node scripts/database-schema-inventory.mjs --root . --output artifacts/database/schema-inventory.json --check
 */
import { readFileSync, writeFileSync, mkdirSync, readdirSync, statSync } from 'node:fs';
import { resolve, relative, join, dirname, extname } from 'node:path';
import { pathToFileURL } from 'node:url';

const ignored = new Set(['.git', 'vendor', 'node_modules', '.next', 'dist', 'build', 'coverage', 'artifacts']);
const patterns = [
  { kind: 'schema', regex: /Schema::(?:create|table|dropIfExists|drop|rename)\s*\(\s*['"]([^'"]+)['"]/g },
  { kind: 'db-table', regex: /DB::table\s*\(\s*['"]([^'"]+)['"]/g },
  { kind: 'model', regex: /(?:protected|public)\s+\$table\s*=\s*['"]([^'"]+)['"]/g },
  { kind: 'sql-literal', regex: /\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+[`'"]?(v2_[a-z][a-z0-9_]*)\b/gi },
];
const schemaCreation = /Schema::create\s*\(\s*['"]([^'"]+)['"]/g;
const dynamicSql = /(?:DB::table|Schema::(?:table|create|rename))\s*\(\s*(?!['"])/g;

function walk(dir) {
  const files = [];
  for (const entry of readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
    if (ignored.has(entry.name)) continue;
    const p = join(dir, entry.name);
    if (entry.isDirectory()) files.push(...walk(p));
    else if (entry.isFile() && ['.php', '.sql'].includes(extname(entry.name))) files.push(p);
  }
  return files;
}

export function inventory(root) {
  const base = resolve(root);
  const source = join(base, 'api');
  if (!statSync(source).isDirectory()) throw new Error('Missing api/ directory');
  const references = [];
  const created = new Map();
  const dynamic = [];
  for (const file of walk(source)) {
    const path = relative(base, file).replaceAll('\\', '/');
    const content = readFileSync(file, 'utf8');
    for (const { kind, regex } of patterns) {
      for (const match of content.matchAll(new RegExp(regex.source, regex.flags))) {
        const table = match[1].toLowerCase();
        if (!table.startsWith('v2_')) continue;
        const line = content.slice(0, match.index).split('\n').length;
        references.push({ table, kind, path, line });
      }
    }
    if (path.startsWith('api/database/migrations/')) {
      for (const match of content.matchAll(new RegExp(schemaCreation.source, schemaCreation.flags))) {
        const table = match[1].toLowerCase();
        if (table.startsWith('v2_')) {
          const entry = created.get(table) ?? [];
          entry.push(path);
          created.set(table, entry);
        }
      }
    }
    if (!path.startsWith('api/database/migrations/')) {
      for (const match of content.matchAll(new RegExp(dynamicSql.source, dynamicSql.flags))) {
        dynamic.push({ path, line: content.slice(0, match.index).split('\n').length });
      }
    }
  }
  references.sort((a, b) => a.table.localeCompare(b.table) || a.path.localeCompare(b.path) || a.line - b.line || a.kind.localeCompare(b.kind));
  const tables = [...new Set([...created.keys(), ...references.map(x => x.table)])].sort().map(table => ({
    oldName: table,
    proposedName: 'tx_' + table.slice(3),
    migrationCreators: [...new Set(created.get(table) ?? [])].sort(),
    references: references.filter(x => x.table === table),
  }));
  const issues = [];
  const warnings = [];
  for (const table of tables) {
    if (!table.migrationCreators.length) warnings.push('No migration creates ' + table.oldName);
    if (table.references.some(x => x.kind === 'model') && !table.migrationCreators.length) warnings.push('Active model lacks creation migration: ' + table.oldName);
  }
  if (new Set(tables.map(x => x.proposedName)).size !== tables.length) issues.push('Proposed native names collide');
  return {
    version: 1,
    readOnly: true,
    cutoverPerformed: false,
    generatedFrom: 'static source only; not a live database inspection',
    tableCount: tables.length,
    referenceCount: references.length,
    tables,
    dynamicCallsNeedingManualReview: dynamic.sort((a,b) => a.path.localeCompare(b.path) || a.line-b.line),
    warnings: [...new Set(warnings)].sort(),
    issues: [...new Set(issues)].sort(),
  };
}

export function parseArgs(argv) {
  const args = { root: '.', output: null, check: false };
  for (let i = 0; i < argv.length; i++) {
    const key = argv[i];
    if (key === '--root' || key === '--output') {
      if (!argv[i + 1]) throw new Error('Missing value for ' + key);
      args[key.slice(2)] = argv[++i];
    } else if (key === '--check') args.check = true;
    else throw new Error('Unknown argument: ' + key);
  }
  return args;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const args = parseArgs(process.argv.slice(2));
    const result = inventory(args.root);
    if (args.output) {
      mkdirSync(dirname(resolve(args.output)), { recursive: true });
      writeFileSync(args.output, JSON.stringify(result, null, 2) + '\n');
    }
    console.log(JSON.stringify({ tables: result.tableCount, references: result.referenceCount, dynamicCalls: result.dynamicCallsNeedingManualReview.length, warnings: result.warnings, issues: result.issues }));
    if (args.check && result.issues.length) process.exitCode = 1;
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
