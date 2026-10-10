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

test('five protocol tables consolidated into v2_server are historical, not missing live tables', () => {
  const protocols = ['hysteria', 'shadowsocks', 'trojan', 'vless', 'vmess'];
  const expanded = { ...source, tables: [
    ...source.tables,
    ...protocols.map(name => ({ oldName: 'v2_server_' + name, proposedName: 'tx_server_' + name, migrationCreators: ['2023_initial.php'] })),
  ] };
  const report = compare(expanded, mysql);
  assert.deepEqual(report.failures, []);
  assert.equal(report.historicalConsolidatedTables.length, 5);
  assert.ok(report.mappings.filter(x => x.lifecycle === 'consolidated-into-v2_server').length === 5);
});

test('missing other migrated business tables remain hard failures', () => {
  const changed = { ...source, tables: [...source.tables, { oldName: 'v2_payment', proposedName: 'tx_payment', migrationCreators: ['initial.php'] }] };
  assert.match(compare(changed, mysql).failures.join(' '), /MySQL table missing: v2_payment/);
});

test('legacy protocol table unexpectedly still present remains visible for manual review', () => {
  const changed = { ...source, tables: [...source.tables, { oldName: 'v2_server_trojan', proposedName: 'tx_server_trojan', migrationCreators: ['initial.php'] }] };
  const report = compare(changed, { ...mysql, tables: [...mysql.tables, { table: 'v2_server_trojan', columns: [], indexes: [], foreign_keys: [] }] });
  assert.equal(report.mappings.find(x => x.oldName === 'v2_server_trojan').lifecycle, 'candidate');
  assert.deepEqual(report.historicalConsolidatedTables, []);
});

test('source-only model references with no create migration are not invented in fresh MySQL', () => {
  const names = ['v2_server_log', 'v2_server_stat', 'v2_stat_order'];
  const expanded = { ...source, tables: [...source.tables, ...names.map(oldName => ({
    oldName, proposedName: 'tx_' + oldName.slice(3), migrationCreators: [],
  }))] };
  const report = compare(expanded, mysql);
  assert.deepEqual(report.failures, []);
  assert.deepEqual(report.unverifiedSourceOnlyTables, names);
  assert.equal(report.mappings.filter(x => x.lifecycle === 'unverified-source-only').length, 3);
  assert.equal(report.readyForAutomaticRename, false);
});
