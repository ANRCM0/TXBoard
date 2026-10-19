import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const repository = fileURLToPath(new URL('../../', import.meta.url));
const validators = ['scripts/check-docs.mjs', 'gateway/scripts/check-docs.mjs'];

function fixture(t, files = {}) {
  const root = mkdtempSync(join(tmpdir(), 'tx-doc-validator-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  for (const [name, source] of Object.entries(files)) {
    const destination = join(root, name);
    mkdirSync(dirname(destination), { recursive: true });
    writeFileSync(destination, source);
  }
  return root;
}

function run(script, root, ...args) {
  const result = spawnSync(process.execPath, [join(repository, script), '--root', root, ...args], {
    encoding: 'utf8',
  });
  assert.ifError(result.error);
  return result;
}

for (const script of validators) {
  test(`${script}: empty documentation explicitly skips`, (t) => {
    const root = fixture(t);
    const result = run(script, root);
    assert.equal(result.status, 0);
    assert.match(result.stdout, /^SKIP:/);
    assert.doesNotMatch(result.stdout, /paths OK/);
    assert.equal(run(script, root, '--has-docs').stdout.trim(), 'false');
  });

  test(`${script}: partial docs without entry-point files still validate`, (t) => {
    const root = fixture(t, { 'docs/guide.md': '[target](target.txt)', 'docs/target.txt': 'ok' });
    const result = run(script, root);
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /1 Markdown files, 1 relative links checked/);
    assert.equal(run(script, root, '--has-docs').stdout.trim(), 'true');
  });

  test(`${script}: broken links fail but discovery does not validate`, (t) => {
    const root = fixture(t, { 'docs/guide.md': '[missing](missing.md)' });
    const result = run(script, root);
    assert.equal(result.status, 1);
    assert.match(result.stderr, /broken link missing\.md/);
    const discovery = run(script, root, '--has-docs');
    assert.equal(discovery.status, 0);
    assert.equal(discovery.stdout.trim(), 'true');
    assert.equal(discovery.stderr, '');
  });

  test(`${script}: third-party dependency documents are excluded`, (t) => {
    const root = fixture(t, {
      'node/internal/deps/upstream/README.md': '[bad](missing.md)',
      'docs/node_modules/upstream/README.md': '[bad](missing.md)',
      'docs/vendor/upstream/README.md': '[bad](missing.md)',
      'gateway/node_modules/upstream/README.md': '[bad](missing.md)',
    });
    const result = run(script, root);
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /^SKIP:/);
    assert.equal(run(script, root, '--has-docs').stdout.trim(), 'false');
  });

  test(`${script}: valid document links and code samples retain their semantics`, (t) => {
    const root = fixture(t, {
      'README.md': '[guide](docs/guide.md#section)\n[external](https://example.test)\n`[sample](missing.md)`\n```md\n[example](missing.md)\n```',
      'docs/guide.md': '[back](../README.md)',
    });
    const result = run(script, root);
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /2 Markdown files, 2 relative links checked/);
  });
}

test('repository validator discovers frontend-only documentation and rejects its broken links', (t) => {
  const root = fixture(t, {
    'web/README.md': '[SDK development](../gateway/packages/core-sdk/missing.md)',
    'web/node_modules/dependency/README.md': '[ignored](also-missing.md)',
  });
  const script = 'scripts/check-docs.mjs';
  const discovery = run(script, root, '--has-docs');
  assert.equal(discovery.status, 0);
  assert.equal(discovery.stdout.trim(), 'true');
  assert.equal(discovery.stderr, '');
  const validation = run(script, root);
  assert.equal(validation.status, 1);
  assert.match(validation.stderr, /web[/\\]README\.md.*broken link \.\.\/gateway\/packages\/core-sdk\/missing\.md/);
  assert.doesNotMatch(validation.stderr, /also-missing/);
});

test('repository validator resolves frontend development links relative to their document', (t) => {
  const root = fixture(t, {
    'web/README.md': '[SDK development](../gateway/packages/core-sdk/README.md)',
    'gateway/packages/core-sdk/README.md': 'SDK development entry point.',
  });
  const result = run('scripts/check-docs.mjs', root);
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /2 Markdown files, 1 relative links checked/);
});
