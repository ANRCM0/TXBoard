import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { check } from '../check-native-schema-only.mjs';

test('native names pass and legacy table names are rejected', t => {
  const dir = mkdtempSync(join(tmpdir(), 'tx-schema-'));
  t.after(() => rmSync(dir, { recursive: true, force: true }));
  mkdirSync(join(dir, 'api/app'), { recursive: true });
  mkdirSync(join(dir, 'scripts'), { recursive: true });
  const path = join(dir, 'api/app/User.php');
  writeFileSync(path, "<?php protected $table = 'tx_user';");
  assert.deepEqual(check(dir), []);
  writeFileSync(path, "<?php protected $table = 'v" + "2_user';");
  assert.equal(check(dir).length, 1);
});
