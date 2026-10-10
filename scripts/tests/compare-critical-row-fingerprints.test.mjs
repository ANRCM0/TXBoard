import test from 'node:test';
import assert from 'node:assert/strict';
import { compareRowFingerprints } from '../compare-critical-row-fingerprints.mjs';
const digest = 'a'.repeat(64);
const snap = (prefix, digestValue = digest) => ({
  schema_version: 1, read_only: true, kind: 'critical-row-fingerprints',
  table_prefix: prefix, tables: { user: { rows: '2', columns: ['balance', 'id'], sha256: digestValue } },
});
test('matching native rows pass', () => {
  assert.equal(compareRowFingerprints(snap('tx'), snap('tx')).passed, true);
});
test('different row content fails even if counts match', () => {
  assert.deepEqual(compareRowFingerprints(snap('tx'), snap('tx', 'b'.repeat(64))).failures, ['Row digest mismatch: user']);
});
test('different schema columns fail', () => {
  const after = snap('tx'); after.tables.user.columns = ['id'];
  assert.equal(compareRowFingerprints(snap('tx'), after).passed, false);
});
test('rejects invalid evidence', () => {
  assert.throws(() => compareRowFingerprints(snap('tx'), {}), /Invalid row fingerprint/);
});
