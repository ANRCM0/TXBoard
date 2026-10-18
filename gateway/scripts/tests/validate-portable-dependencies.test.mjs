import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { productionFingerprint, validatePortableDependencies } from '../validate-portable-dependencies.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const reviewedLock = JSON.parse(readFileSync(join(root, 'package-lock.json'), 'utf8'));
const fields = ['name', 'version', 'dependencies', 'optionalDependencies', 'peerDependencies', 'peerDependenciesMeta'];
const writeJson = (path, value) => writeFileSync(path, JSON.stringify(value));

// Complete reviewed lock graph with small portable package contents. The
// separate Linux prune gate checks the real locked npm packages as well.
function fixture(t) {
  const directory = mkdtempSync(join(tmpdir(), 'gateway-portable-'));
  t.after(() => rmSync(directory, { recursive: true, force: true }));
  writeJson(join(directory, 'package-lock.json'), reviewedLock);
  for (const [path, entry] of Object.entries(reviewedLock.packages)) {
    if (entry.dev || entry.link) continue;
    const target = join(directory, path);
    mkdirSync(target, { recursive: true });
    const manifest = Object.fromEntries(fields.filter(key => Object.hasOwn(entry, key)).map(key => [key, entry[key]]));
    manifest.name ??= path.slice(path.lastIndexOf('node_modules/') + 'node_modules/'.length);
    writeJson(join(target, 'package.json'), manifest);
    writeFileSync(join(target, 'index.js'), 'export const portable = true;\n');
  }
  for (const [path, entry] of Object.entries(reviewedLock.packages)) {
    if (!entry.link) continue;
    mkdirSync(dirname(join(directory, path)), { recursive: true });
    symlinkSync(join(directory, entry.resolved), join(directory, path), process.platform === 'win32' ? 'junction' : 'dir');
  }
  return directory;
}

test('accepts the complete reviewed portable production tree and workspace links', t => {
  const result = validatePortableDependencies(fixture(t));
  assert.equal(result.result, 'portable');
  assert.equal(result.installed_entries, 16);
  assert.equal(result.production_fingerprint, productionFingerprint(reviewedLock));
  assert.ok(result.scanned_files > 20);
});

test('development-only lock changes do not alter the reviewed production graph', t => {
  const directory = fixture(t);
  const lock = structuredClone(reviewedLock);
  lock.packages[''].devDependencies['dev-only-fixture'] = '1.0.0';
  lock.packages['node_modules/dev-only-fixture'] = { dev: true, version: '1.0.0', cpu: ['x64'], hasInstallScript: true };
  writeJson(join(directory, 'package-lock.json'), lock);
  assert.equal(validatePortableDependencies(directory).result, 'portable');
});

test('requires review when production version, integrity, graph or declaration changes', async t => {
  for (const change of ['version', 'integrity', 'dependency', 'installed-declaration']) {
    await t.test(change, child => {
      const directory = fixture(child);
      if (change === 'installed-declaration') {
        const manifestPath = join(directory, 'node_modules/hono/package.json');
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        manifest.dependencies = { unreviewed: '1.0.0' };
        writeJson(manifestPath, manifest);
        assert.throws(() => validatePortableDependencies(directory), /PACKAGE_DECLARATION/);
      } else {
        const lock = structuredClone(reviewedLock);
        if (change === 'dependency') lock.packages['apps/gateway'].dependencies.unreviewed = '1.0.0';
        else lock.packages['node_modules/hono'][change] = 'changed';
        writeJson(join(directory, 'package-lock.json'), lock);
        assert.throws(() => validatePortableDependencies(directory), /PRODUCTION_GRAPH_CHANGED/);
      }
    });
  }
});

test('rejects platform metadata and lifecycle hooks in both lock and actual manifests', async t => {
  for (const field of ['cpu', 'os', 'libc', 'gypfile', 'hasInstallScript', 'install', 'prepare']) {
    for (const source of ['lock', 'installed']) {
      await t.test(`${source}:${field}`, child => {
        const directory = fixture(child);
        const path = source === 'lock' ? 'package-lock.json' : 'node_modules/hono/package.json';
        const document = JSON.parse(readFileSync(join(directory, path), 'utf8'));
        const target = source === 'lock' ? document.packages['node_modules/hono'] : document;
        if (['install', 'prepare'].includes(field)) target.scripts = { [field]: 'node native-builder.js' };
        else target[field] = field === 'gypfile' || field === 'hasInstallScript' ? true : ['native'];
        writeJson(join(directory, path), document);
        assert.throws(() => validatePortableDependencies(directory), /PLATFORM_METADATA|INSTALL_HOOK/);
      });
    }
  }
});

test('rejects native artifacts including binaries disguised as JavaScript', async t => {
  const cases = [
    ['addon.node', Buffer.from('not actually compiled')],
    ['binding.gyp', Buffer.from('{}')],
    ['addon.wasm', Buffer.from('fixture')],
    ['elf.js', Buffer.from('7f454c46', 'hex')],
    ['pe.js', Buffer.from('4d5a0000', 'hex')],
    ['macho.js', Buffer.from('cffaedfe', 'hex')],
    ['fat-macho.js', Buffer.from('cafebabe', 'hex')],
  ];
  for (const [name, bytes] of cases) {
    await t.test(name, child => {
      const directory = fixture(child);
      writeFileSync(join(directory, 'node_modules/hono', name), bytes);
      assert.throws(() => validatePortableDependencies(directory), /NATIVE_ARTIFACT/);
    });
  }
});

test('rejects missing packages, wrong installed versions and retained development packages', async t => {
  for (const mode of ['missing', 'version', 'retained-dev']) {
    await t.test(mode, child => {
      const directory = fixture(child);
      if (mode === 'missing') {
        rmSync(join(directory, 'node_modules/hono'), { recursive: true });
      } else if (mode === 'version') {
        const path = join(directory, 'node_modules/hono/package.json');
        const manifest = JSON.parse(readFileSync(path, 'utf8'));
        manifest.version = '0.0.0';
        writeJson(path, manifest);
      } else {
        const path = join(directory, 'node_modules/esbuild');
        mkdirSync(path);
        writeJson(join(path, 'package.json'), { name: 'esbuild', version: '0.28.2' });
      }
      assert.throws(() => validatePortableDependencies(directory), /MISSING_PACKAGE|PACKAGE_IDENTITY|UNEXPECTED_PACKAGE/);
    });
  }
});

test('rejects unexpected bin entries and filesystem indirection', async t => {
  for (const mode of ['bin', 'external-link', 'inner-link']) {
    await t.test(mode, child => {
      const directory = fixture(child);
      if (mode === 'bin') {
        mkdirSync(join(directory, 'node_modules/.bin'));
        writeFileSync(join(directory, 'node_modules/.bin/native'), 'fixture');
      } else if (mode === 'external-link') {
        const link = join(directory, 'node_modules/@txboard/theme-sdk');
        rmSync(link, { recursive: false, force: true });
        symlinkSync(join(directory, 'apps/gateway'), link, process.platform === 'win32' ? 'junction' : 'dir');
      } else {
        symlinkSync(join(directory, 'apps/gateway'), join(directory, 'node_modules/hono/escape'), process.platform === 'win32' ? 'junction' : 'dir');
      }
      assert.throws(() => validatePortableDependencies(directory), /UNEXPECTED_BIN|WORKSPACE_LINK|UNREVIEWED_SYMLINK/);
    });
  }
});
