#!/usr/bin/env node
/**
 * Fail closed if a modern migration bypasses prefix resolution. Historical
 * migration replay (before 2026-09-21) stays intact for legacy bootstrap.
 * Re-run on every PR; this intentionally does not inspect live data.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export const firstPrefixAwareMigration = '2026_09_21_';

export function auditMigrationSources(root = '.') {
  const dir = resolve(root, 'api/database/migrations');
  const violations = [];
  const checked = [];
  for (const file of readdirSync(dir).filter(x => x.endsWith('.php') && x >= firstPrefixAwareMigration).sort()) {
    checked.push(file);
    const source = readFileSync(join(dir, file), 'utf8');
    // Direct schema/table and validator lookups must never silently target V2.
    const patterns = [
      /\b(?:Schema::(?:create|table|drop|dropIfExists|rename|hasTable|hasColumn)|DB::table|Rule::(?:exists|unique))\s*\(\s*['"](v2_[a-z][a-z0-9_]*)['"]/g,
      /\b(?:FROM|JOIN|INTO|UPDATE|ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+TABLE)\s+[`'"]?(v2_[a-z][a-z0-9_]*)\b/gi,
    ];
    for (const pattern of patterns) {
      for (const match of source.matchAll(pattern)) {
        const line = source.slice(0, match.index).split('\n').length;
        violations.push({ file, line, table: match[1] });
      }
    }
  }
  return { schemaVersion: 1, readOnly: true, checkedMigrations: checked.length, violations };
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const argv = process.argv.slice(2);
    if (argv.length !== 3 || argv[0] !== '--root' || argv[2] !== '--check') throw new Error('Usage: node scripts/database-native-migration-gate.mjs --root ROOT --check');
    const result = auditMigrationSources(argv[1]);
    console.log(JSON.stringify(result));
    if (!result.checkedMigrations || result.violations.length) process.exitCode = 1;
  } catch (e) { console.error(e.message); process.exitCode = 1; }
}
