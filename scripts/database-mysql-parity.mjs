#!/usr/bin/env node
/**
 * Compare source migration references with a MySQL information_schema artifact.
 * Read-only. Neither input contains application rows, and no DB connection is made.
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { pathToFileURL } from 'node:url';

export function compare(source, mysql) {
  if (source?.version !== 1 || source.readOnly !== true || !Array.isArray(source.tables)) {
    throw new Error('Invalid source schema inventory');
  }
  if (mysql?.schema_version !== 1 || mysql.source !== 'mysql-information-schema' || !Array.isArray(mysql.tables)) {
    throw new Error('Invalid MySQL metadata inventory');
  }
  const actual = new Map();
  for (const table of mysql.tables) {
    if (typeof table.table !== 'string' || actual.has(table.table)) throw new Error('Invalid or duplicate MySQL table');
    actual.set(table.table, table);
  }
  const mappings = [];
  const missing = [];
  const collisions = [];
  const missingCreators = [];
  for (const entry of source.tables) {
    const oldName = entry.oldName;
    const newName = entry.proposedName;
    if (!/^v2_[a-z][a-z0-9_]*$/.test(oldName) || !/^tx_[a-z][a-z0-9_]*$/.test(newName)) {
      throw new Error('Unsafe proposed table mapping');
    }
    const exists = actual.has(oldName);
    const targetExists = actual.has(newName);
    if (!exists && entry.migrationCreators?.length) missing.push(oldName);
    if (targetExists) collisions.push(newName);
    if (!entry.migrationCreators?.length) missingCreators.push(oldName);
    mappings.push({
      oldName, proposedName: newName, existsInMysql: exists, targetAlreadyExists: targetExists,
      columns: exists ? actual.get(oldName).columns.length : null,
      indexes: exists ? actual.get(oldName).indexes.length : null,
      foreignKeys: exists ? actual.get(oldName).foreign_keys.length : null,
    });
  }
  const failures = [...(source.issues ?? []), ...missing.map(x => 'MySQL table missing: ' + x), ...collisions.map(x => 'Target table already exists: ' + x)];
  const warnings = [...(source.warnings ?? []), ...missingCreators.map(x => 'No source creation migration (verify live DB): ' + x)];
  return {
    schemaVersion: 1,
    readOnly: true,
    cutoverPerformed: false,
    sourceTableCount: mappings.length,
    mysqlTableCount: mysql.tables.length,
    mappings,
    untrackedMysqlV2Tables: [...actual.keys()].filter(x => x.startsWith('v2_') && !mappings.some(m => m.oldName === x)).sort(),
    warnings: [...new Set(warnings)].sort(),
    failures: [...new Set(failures)].sort(),
    readyForAutomaticRename: false,
  };
}

export function args(argv) {
  const options = { source: null, mysql: null, output: null, check: false };
  for (let i = 0; i < argv.length; i++) {
    const key = argv[i];
    if (['--source', '--mysql', '--output'].includes(key)) {
      if (!argv[i + 1]) throw new Error('Missing ' + key);
      options[key.slice(2)] = argv[++i];
    } else if (key === '--check') options.check = true;
    else throw new Error('Unknown argument: ' + key);
  }
  if (!options.source || !options.mysql || !options.output) throw new Error('Required --source, --mysql and --output');
  return options;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const opts = args(process.argv.slice(2));
    const report = compare(JSON.parse(readFileSync(opts.source, 'utf8')), JSON.parse(readFileSync(opts.mysql, 'utf8')));
    mkdirSync(dirname(resolve(opts.output)), { recursive: true });
    writeFileSync(opts.output, JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify({ mapped: report.sourceTableCount, mysql: report.mysqlTableCount, failures: report.failures, warnings: report.warnings, untracked: report.untrackedMysqlV2Tables }));
    if (opts.check && report.failures.length) process.exitCode = 1;
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
