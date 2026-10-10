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

test('rejects retired application aliases and bundled plugin metadata', t => {
  const dir = mkdtempSync(join(tmpdir(), 'tx-native-runtime-'));
  t.after(() => rmSync(dir, { recursive: true, force: true }));
  mkdirSync(join(dir, 'api/app'), { recursive: true });
  mkdirSync(join(dir, 'api/plugins-core/Telegram'), { recursive: true });
  mkdirSync(join(dir, 'scripts'), { recursive: true });
  writeFileSync(join(dir, 'api/app/Theme.php'), "<?php admin_setting('current_" + "theme');");
  writeFileSync(join(dir, 'api/plugins-core/Telegram/config.json'), '{"require":{"x' + 'board":">=1.0.0"}}');
  const findings = check(dir);
  assert.equal(findings.length, 2);
  assert.deepEqual(findings.map(x => x.path).sort(), [
    'api/app/Theme.php', 'api/plugins-core/Telegram/config.json',
  ]);
});

test('web runtime and retired Compose projects are audited', t => {
  const dir = mkdtempSync(join(tmpdir(), 'tx-native-web-'));
  t.after(() => rmSync(dir, { recursive: true, force: true }));
  mkdirSync(join(dir, 'api'), { recursive: true });
  mkdirSync(join(dir, 'scripts'), { recursive: true });
  mkdirSync(join(dir, 'web/user/src'), { recursive: true });
  writeFileSync(join(dir, 'web/user/src/index.ts'), "const oldTheme = 'v" + "2board'");
  writeFileSync(join(dir, 'compose.yaml'), 'name: deploy\nservices:\n  txboard-mcp:\n');
  const issues = check(dir);
  assert.equal(issues.length, 3);
});
