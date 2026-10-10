import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { auditMigrationSources } from '../database-native-migration-gate.mjs';

test('prefix-aware modern migration is accepted and older replay is excluded', t => {
  const root = mkdtempSync(join(tmpdir(), 'tx-migration-gate-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  const dir = join(root, 'api/database/migrations');
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, '2023_01_01_old.php'), "Schema::create('v2_user');");
  writeFileSync(join(dir, '2026_10_11_new.php'), "Schema::table(NativeTableName::runtime('v2_user'), fn($t) => null);");
  assert.deepEqual(auditMigrationSources(root).violations, []);
  assert.equal(auditMigrationSources(root).checkedMigrations, 1);
});

test('direct schema, SQL and query builder access after cutover-aware baseline fail', t => {
  const root = mkdtempSync(join(tmpdir(), 'tx-migration-gate-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  const dir = join(root, 'api/database/migrations');
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, '2026_10_11_new.php'),
    "Schema::hasTable('v2_order'); DB::table('v2_user'); DB::statement('ALTER TABLE v2_plan ADD col INT');");
  const violations = auditMigrationSources(root).violations;
  assert.deepEqual(violations.map(x => x.table), ['v2_order', 'v2_user', 'v2_plan']);
});
