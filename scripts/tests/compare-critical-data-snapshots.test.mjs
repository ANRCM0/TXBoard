import test from 'node:test';
import assert from 'node:assert/strict';
import { compareCriticalSnapshots } from '../compare-critical-data-snapshots.mjs';
const before = { schema_version: 1, read_only: true, tables: { user: { rows: '2', sum_balance: '9007199254740993' }, traffic_batch: { rows: '4' } } };
test('identical critical aggregate snapshots pass without unsafe number conversion', () => {
  assert.deepEqual(compareCriticalSnapshots(before, structuredClone(before)), { passed: true, failures: [], notRowLevelProof: true });
});
test('missing domains and changed financial amounts fail', () => {
  const changed = structuredClone(before);
  changed.tables.user.sum_balance = '9007199254740992';
  delete changed.tables.traffic_batch;
  const result = compareCriticalSnapshots(before, changed);
  assert.equal(result.passed, false);
  assert.match(result.failures.join(' '), /user.sum_balance/);
  assert.match(result.failures.join(' '), /traffic_batch/);
});
test('rejects unsupported or malformed snapshots', () => {
  assert.throws(() => compareCriticalSnapshots({}, before), /Invalid/);
});
