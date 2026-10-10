import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inventory, parseArgs } from '../database-schema-inventory.mjs';

test('static inventory maps created and referenced tables without changing data', t => {
  const root = mkdtempSync(join(tmpdir(), 'txboard-schema-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  mkdirSync(join(root, 'api/database/migrations'), { recursive: true });
  mkdirSync(join(root, 'api/app/Models'), { recursive: true });
  mkdirSync(join(root, 'api/app/Services'), { recursive: true });
  writeFileSync(join(root, 'api/database/migrations/initial.php'), "<?php Schema::create('v2_user', fn($t) => null); Schema::create('v2_order', fn($t) => null);");
  writeFileSync(join(root, 'api/app/Models/User.php'), "<?php protected $table = 'v2_user';");
  writeFileSync(join(root, 'api/app/Services/OrderService.php'), "<?php DB::table('v2_order'); DB::table($dynamic);");
  const result = inventory(root);
  assert.equal(result.readOnly, true);
  assert.equal(result.cutoverPerformed, false);
  assert.equal(result.tableCount, 2);
  assert.equal(result.issues.length, 0);
  assert.deepEqual(result.tables.map(t => t.proposedName), ['tx_order', 'tx_user']);
  assert.equal(result.tables.find(t => t.oldName === 'v2_order').references.some(r => r.kind === 'db-table'), true);
  assert.equal(result.dynamicCallsNeedingManualReview.length, 1);
});

test('unmapped model tables fail closed and arguments reject unknown flags', t => {
  const root = mkdtempSync(join(tmpdir(), 'txboard-schema-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  mkdirSync(join(root, 'api/app/Models'), { recursive: true });
  writeFileSync(join(root, 'api/app/Models/Unknown.php'), "<?php protected $table = 'v2_unknown';");
  assert.match(inventory(root).warnings.join(' '), /No migration creates v2_unknown/);
  assert.deepEqual(inventory(root).issues, []);
  assert.deepEqual(parseArgs(['--root', 'foo', '--check']), { root: 'foo', output: null, check: true });
  assert.throws(() => parseArgs(['--rename']), /Unknown argument/);
});

test('prefix-aware schema creators remain inventoried for MySQL parity', t => {
  const root = mkdtempSync(join(tmpdir(), 'txboard-schema-native-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  mkdirSync(join(root, 'api/database/migrations'), { recursive: true });
  writeFileSync(join(root, 'api/database/migrations/2026_10_11_create.php'),
    "<?php Schema::create(NativeTableName::runtime('v2_wallet_recharge'), fn($table) => null);");
  const result = inventory(root);
  assert.deepEqual(result.tables[0].migrationCreators, ['api/database/migrations/2026_10_11_create.php']);
  assert.equal(result.tables[0].oldName, 'v2_wallet_recharge');
});
