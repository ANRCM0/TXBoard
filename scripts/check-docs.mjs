#!/usr/bin/env node
// Dependency-free local Markdown relative-path validation.
// Scope covers public docs and versioned contracts; external URLs, anchor IDs,
// reference-style links and generated paths require human review.
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, extname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const args = process.argv.slice(2);
const rootIndex = args.indexOf('--root');
if (args.some((arg, index) => arg !== '--has-docs' && arg !== '--root' && !(rootIndex !== -1 && index === rootIndex + 1)) ||
    (rootIndex !== -1 && (!args[rootIndex + 1] || args[rootIndex + 1].startsWith('--')))) {
  console.error('Usage: check-docs.mjs [--root directory] [--has-docs]');
  process.exit(1);
}
const root = rootIndex === -1
  ? resolve(dirname(fileURLToPath(import.meta.url)), '..')
  : resolve(args[rootIndex + 1]);
const inputs = ['README.md', 'README.en.md', 'AGENTS.md', 'CONTRIBUTING.md',
  '.github', 'api/app/Domains', 'api/plugins-core', 'docs', 'contracts', 'packages', 'tools', 'examples', 'node', 'web'];
const excludedDirectories = new Set(['.git', 'node_modules', 'vendor', 'dist',
  'build', 'coverage', '.next', '.turbo', '.cache']);
const files = [];
const errors = [];
let checked = 0;

function collect(relative) {
  const abs = resolve(root, relative);
  if (!existsSync(abs)) {
    return;
  }
  if (statSync(abs).isDirectory()) {
    // Vendored upstream documentation is not maintained by this project.
    if (relative.replaceAll('\\', '/') === 'node/internal/deps') return;
    for (const child of readdirSync(abs)) {
      if (excludedDirectories.has(child)) continue;
      collect(join(relative, child));
    }
  } else if (extname(abs) === '.md') {
    files.push(abs);
  }
}

for (const entry of inputs) collect(entry);

// Discovery must not validate links: CI uses this to distinguish skipped checks.
if (args.includes('--has-docs')) {
  console.log(files.length > 0 ? 'true' : 'false');
  process.exit(0);
}
if (files.length === 0) {
  console.log('SKIP: no first-party Markdown documentation found; link validation not run.');
  process.exit(0);
}

for (const file of files) {
  const source = readFileSync(file, 'utf8');
  const lines = source.split(/\r?\n/);
  let fenced = false;
  let fenceChar = '';
  let fenceLength = 0;

  lines.forEach((line, i) => {
    const opening = line.match(/^\s*(```+|~~~+)/);
    if (opening) {
      const marker = opening[1];
      if (!fenced) {
        fenced = true;
        fenceChar = marker[0];
        fenceLength = marker.length;
      } else if (marker[0] === fenceChar && marker.length >= fenceLength) {
        fenced = false;
      }
      return;
    }
    if (fenced) return;

    // Remove inline code to avoid treating sample Markdown as real links.
    const prose = line.replace(/`[^\x60\n]+`/g, '');
    for (const match of prose.matchAll(/!?\[[^\]\n]*\]\(([^)]+)\)/g)) {
      let target = match[1].trim();
      if (target.startsWith('<')) {
        const pos = target.indexOf('>');
        target = pos > -1 ? target.slice(1, pos) : target;
      } else {
        target = target.split(/\s+["']/)[0];
      }
      if (!target || /^(?:[a-z][a-z0-9+.-]*:|#|\/\/)/i.test(target) || target.startsWith('/')) continue;

      const noFragment = target.split('#')[0].split('?')[0];
      if (!noFragment) continue;
      let decoded;
      try { decoded = decodeURIComponent(noFragment); }
      catch {
        errors.push(`${file}:${i + 1}: invalid URL encoding: ${target}`);
        continue;
      }
      const dest = resolve(dirname(file), decoded);
      if (existsSync(dest)) {
        checked += 1;
        continue;
      }
      const fallback = join(dest, 'README.md');
      if (existsSync(fallback)) {
        checked += 1;
        continue;
      }
      errors.push(`${file.slice(root.length + 1)}:${i + 1}: broken link ${target}`);
    }
  });
}

if (errors.length) {
  for (const error of errors) console.error(error);
  console.error(`Documentation check failed: ${errors.length} issue(s).`);
  process.exitCode = 1;
} else {
  console.log(`Documentation paths OK: ${files.length} Markdown files, ${checked} relative links checked.`);
}
