import test from 'node:test';
import assert from 'node:assert/strict';
import { compare, args } from '../database-mysql-parity.mjs';

const source = { version: 1, readOnly: true, issues: [], warnings: [], tables: [
  { oldName: 'v2_order', proposedName: 'tx_order', migrationCreators: ['initial.php'] },
  { oldName: 'v2_user', proposedName: 'tx_user', migrationCreators: [] },
] };
const mysql = { schema_version: 1, source: 'mysql-information-schema', tables: [
  { table: 'v2_order', columns: [{name:'id'}], indexes: [], foreign_keys: [] },
  { table: 'v2_user', columns: [{name:'id'}], indexes: [], foreign_keys: [] },
] };

test('existing MySQL tables are mapped without mutating the database', () => {
  const report = compare(source, mysql);
  assert.equal(report.cutoverPerformed, false);
  assert.equal(report.readyForAutomaticRename, false);
  assert.deepEqual(report.failures, []);
  assert.equal(report.mappings[0].columns, 1);
  assert.match(report.warnings.join(' '), /v2_user/);
});

test('missing old tables, preexisting targets and untracked old tables are surfaced', () => {
  const changed = { ...mysql, tables: [
    { ...mysql.tables[0], table: 'tx_order' },
    mysql.tables[1],
    { ...mysql.tables[1], table: 'v2_plugin_extra' },
  ] };
  const report = compare(source, changed);
  assert.match(report.failures.join(' '), /MySQL table missing: v2_order/);
  assert.match(report.failures.join(' '), /Target table already exists: tx_order/);
  assert.deepEqual(report.untrackedMysqlV2Tables, ['v2_plugin_extra']);
});

test('rejects invalid metadata, duplicate tables and unsafe mappings', () => {
  assert.throws(() => compare({}, mysql), /Invalid source/);
  assert.throws(() => compare(source, {}), /Invalid MySQL/);
  assert.throws(() => compare(source, {...mysql, tables:[mysql.tables[0],mysql.tables[0]]}), /duplicate/);
  assert.throws(() => compare({...source,tables:[{oldName:'v2_user',proposedName:'users;drop',migrationCreators:[]}]},mysql), /Unsafe/);
  assert.deepEqual(args(['--source','s','--mysql','m','--output','o','--check']), {source:'s',mysql:'m',output:'o',check:true});
});
