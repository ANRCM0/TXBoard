import test from 'node:test';
import assert from 'node:assert/strict';
import { planCutover } from '../database-native-cutover-plan.mjs';
const parity = { schemaVersion: 1, readOnly: true, cutoverPerformed: false, failures: [],
  untrackedMysqlV2Tables: [], mappings: [
    { oldName: 'v2_user', proposedName: 'tx_user', existsInMysql: true, targetAlreadyExists: false, lifecycle: 'candidate' },
    { oldName: 'v2_server_trojan', proposedName: 'tx_server_trojan', existsInMysql: false, targetAlreadyExists: false, lifecycle: 'consolidated-into-v2_server' },
    { oldName: 'v2_server_log', proposedName: 'tx_server_log', existsInMysql: false, targetAlreadyExists: false, lifecycle: 'unverified-source-only' },
  ] };
test('builds a non-executable plan and excludes historical references', () => {
  const p = planCutover(parity);
  assert.equal(p.executable, false);
  assert.deepEqual(p.proposedRenames, [{ from: 'v2_user', to: 'tx_user' }]);
  assert.equal(p.excludedFromAutomaticRename.length, 2);
  assert.ok(p.blockers.some(x => /runtime/.test(x)));
});
test('rejects target collisions and unexpected live tables', () => {
  const p = planCutover({ ...parity, untrackedMysqlV2Tables: ['v2_custom'], mappings: [{ ...parity.mappings[0], targetAlreadyExists: true }] });
  assert.ok(p.blockers.some(x => /Occupied target/.test(x)));
  assert.ok(p.blockers.some(x => /Untracked live/.test(x)));
});
test('rejects duplicate mappings and invalid input', () => {
  assert.throws(() => planCutover({}), /Invalid/);
  assert.throws(() => planCutover({ ...parity, mappings: [parity.mappings[0], parity.mappings[0]] }), /duplicate/);
});
