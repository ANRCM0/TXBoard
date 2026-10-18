#!/usr/bin/env node
// Run after npm prune --omit=dev. Copying this tree across architectures is
// permitted only for the reviewed production graph and its portable files.
import { createHash } from 'node:crypto';
import { closeSync, lstatSync, openSync, readFileSync, readSync, readdirSync, realpathSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

// Re-review production package contents before changing this fingerprint.
// Development-only dependencies do not affect it.
// First-party Core SDK 0.1.1 and matching workbench pin; all third-party production versions and integrity pins are unchanged.
const REVIEWED_GRAPH = '9ae4793dd673803a0339035cade1488ec7c246d4517cee0bee0209aa7b6c5e5f';
const lifecycle = ['preinstall', 'install', 'postinstall', 'preprepare', 'prepare', 'postprepare'];
const platformFields = ['cpu', 'os', 'libc', 'gypfile', 'hasInstallScript'];
const packageFields = ['name', 'version', 'dependencies', 'optionalDependencies', 'peerDependencies', 'peerDependenciesMeta'];

function fail(code, path) { throw new Error(`${code}: ${path}`); }
function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
  }
  return value;
}
function readJson(path) { return JSON.parse(readFileSync(path, 'utf8')); }
function portableMetadata(manifest, path) {
  if (platformFields.some(key => Object.hasOwn(manifest, key))) fail('PLATFORM_METADATA', path);
  if (lifecycle.some(key => Object.hasOwn(manifest.scripts ?? {}, key))) fail('INSTALL_HOOK', path);
}
function packageProjection(manifest) {
  return Object.fromEntries(packageFields.filter(key => Object.hasOwn(manifest, key)).map(key => [key, manifest[key]]));
}
export function productionFingerprint(lock) {
  const entries = Object.entries(lock.packages).filter(([, entry]) => entry.dev !== true);
  const projection = Object.fromEntries(entries.map(([path, entry]) => {
    const { devDependencies, ...production } = entry;
    return [path, production];
  }));
  return createHash('sha256').update(JSON.stringify(canonical(projection))).digest('hex');
}

export function validatePortableDependencies(directory = process.cwd()) {
  const root = realpathSync(directory);
  const lock = readJson(join(root, 'package-lock.json'));
  if (lock.lockfileVersion !== 3 || !lock.packages) fail('LOCK_FORMAT', 'package-lock.json');
  const production = new Map(Object.entries(lock.packages).filter(([, entry]) => entry.dev !== true));
  for (const [path, entry] of production) portableMetadata(entry, path || 'package-lock.json');
  if (productionFingerprint(lock) !== REVIEWED_GRAPH) fail('PRODUCTION_GRAPH_CHANGED', 'package-lock.json');

  const installed = new Set();
  let files = 0;
  function scanFiles(path) {
    const stat = lstatSync(path);
    if (stat.isSymbolicLink()) fail('UNREVIEWED_SYMLINK', relative(root, path));
    if (stat.isDirectory()) {
      for (const child of readdirSync(path)) scanFiles(join(path, child));
      return;
    }
    if (!stat.isFile()) fail('SPECIAL_FILE', relative(root, path));
    if (/\.(?:node|gyp|so|dll|dylib|exe|a|o|wasm)$/i.test(path)) fail('NATIVE_ARTIFACT', relative(root, path));
    const fd = openSync(path, 'r');
    const magic = Buffer.alloc(4);
    try { readSync(fd, magic, 0, 4, 0); } finally { closeSync(fd); }
    const hex = magic.toString('hex');
    if (hex === '7f454c46' || hex.startsWith('4d5a')
      || ['feedface', 'feedfacf', 'cefaedfe', 'cffaedfe', 'cafebabe', 'bebafeca'].includes(hex)) {
      fail('NATIVE_ARTIFACT', relative(root, path));
    }
    files++;
  }
  function inspectPackage(path) {
    const key = relative(root, path).split(sep).join('/');
    const entry = production.get(key);
    if (!entry) fail('UNEXPECTED_PACKAGE', key);
    installed.add(key);
    let target = path;
    if (entry.link) {
      if (!lstatSync(path).isSymbolicLink()) fail('WORKSPACE_LINK', key);
      target = realpathSync(path);
      if (target !== resolve(root, entry.resolved) || !production.has(entry.resolved)) fail('WORKSPACE_LINK', key);
      if (!target.startsWith(root + sep)) fail('WORKSPACE_LINK', key);
    } else if (lstatSync(path).isSymbolicLink()) {
      fail('UNREVIEWED_SYMLINK', key);
    }
    const manifest = readJson(join(target, 'package.json'));
    portableMetadata(manifest, key);
    const expected = entry.link ? production.get(entry.resolved) : entry;
    const name = expected.name ?? key.slice(key.lastIndexOf('node_modules/') + 'node_modules/'.length);
    if (manifest.name !== name || manifest.version !== expected.version) fail('PACKAGE_IDENTITY', key);
    if (JSON.stringify(canonical(packageProjection(manifest))) !== JSON.stringify(canonical(packageProjection({ ...expected, name })))) {
      fail('PACKAGE_DECLARATION', key);
    }
    scanFiles(target);
  }
  const modules = join(root, 'node_modules');
  for (const child of readdirSync(modules)) {
    const path = join(modules, child);
    if (child === '.package-lock.json') {
      if (!lstatSync(path).isFile()) fail('SPECIAL_FILE', child);
      continue;
    }
    if (child === '.bin') {
      if (!lstatSync(path).isDirectory() || readdirSync(path).length) fail('UNEXPECTED_BIN', child);
      continue;
    }
    if (child.startsWith('@')) {
      if (!lstatSync(path).isDirectory() || lstatSync(path).isSymbolicLink()) fail('UNREVIEWED_SYMLINK', child);
      for (const pkg of readdirSync(path)) inspectPackage(join(path, pkg));
    } else {
      inspectPackage(path);
    }
  }
  for (const [path, entry] of production) {
    if (path.startsWith('node_modules/') && !installed.has(path)) fail('MISSING_PACKAGE', path);
    if (!path.startsWith('node_modules/')) {
      const manifest = readJson(join(root, path, 'package.json'));
      portableMetadata(manifest, path || 'package.json');
      if (JSON.stringify(canonical(packageProjection(manifest))) !== JSON.stringify(canonical(packageProjection(entry)))) {
        fail('PACKAGE_DECLARATION', path || 'package.json');
      }
    }
  }
  return { result: 'portable', production_fingerprint: REVIEWED_GRAPH, installed_entries: installed.size, scanned_files: files };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try { console.log(JSON.stringify(validatePortableDependencies())); }
  catch (error) { console.error(error.message); process.exitCode = 1; }
}
