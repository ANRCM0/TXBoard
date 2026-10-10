#!/usr/bin/env node
/** Reject legacy naming in all first-party PHP and executable JS source. */
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, join, relative } from 'node:path';
import { pathToFileURL } from 'node:url';
const ignore = new Set(['.git', 'vendor', 'node_modules', 'dist', 'build', 'coverage', 'artifacts']);
const skip = new Set(['scripts/check-native-schema-only.mjs', 'scripts/tests/check-native-schema-only.test.mjs']);
export function check(root = '.') {
  const base = resolve(root), violations = [];
  function walk(dir) {
    for (const item of readdirSync(dir, { withFileTypes: true })) {
      if (ignore.has(item.name)) continue;
      const abs = join(dir, item.name);
      const path = relative(base, abs).replaceAll('\\', '/');
      if (item.isDirectory()) walk(abs);
      else if (item.isFile() && /\.(php|mjs|cjs|js|ts|tsx|vue|sql)$/.test(path) || (path.startsWith('api/plugins-core/') && path.endsWith('/config.json')) && !skip.has(path)) {
        const source = readFileSync(abs, 'utf8');
        const incompatible = /(?:v(?:2)_|NativeTableName|ResolvesNativeEloquentTable|TX_NATIVE_TABLES|migrateFromV2b)/g;
        const historical = /(?:v2board|xboard|current_theme|ModuleId::legacy)/gi;
        const scan = [incompatible, ...(path.startsWith('api/app/') || path.startsWith('api/plugins-core/') ? [historical] : [])];
        for (const pattern of scan) for (const m of source.matchAll(pattern)) {
          violations.push({ path, line: source.slice(0, m.index).split('\n').length, type: m[0] });
        }
      }
    }
  }
  walk(join(base, 'api'));
  walk(join(base, 'scripts'));
  return violations;
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const issues = check();
  console.log(JSON.stringify({ nativeOnly: issues.length === 0, count: issues.length, issues: issues.slice(0, 100) }));
  if (issues.length) process.exitCode = 1;
}
