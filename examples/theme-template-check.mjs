#!/usr/bin/env node
// Structural assertions for the Vue/React standalone theme templates (GW-504/505).
// Guards the hard rules: templates must ONLY talk to the Gateway via
// @txboard/theme-sdk — never to Laravel, the legacy /api/v1 or /txapi namespaces,
// and never carry admin tokens or hard-coded secrets.
//
// Usage: node examples/theme-template-check.mjs
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

const ROOT = new URL('..', import.meta.url).pathname
const TEMPLATES = ['vue-theme', 'react-theme', 'next-theme']

const FORBIDDEN_PATTERNS = [
  // Any direct upstream/Laravel coupling is a hard failure.
  { re: /fetch\s*\(\s*['"`][^'"`]*laravel/i, why: 'fetch to a Laravel host literal' },
  { re: /['"`]https?:\/\/[^'"`]*laravel/i, why: 'Laravel URL literal' },
  { re: /['"`][^'"`]*\/api\/v\d[^'"`]*['"`]/i, why: 'legacy Laravel /api/vN path' },
  { re: /['"`][^'"`]*\/txapi[^'"`]*['"`]/i, why: 'TXAPI BFF namespace (TARGET only, not themes)' },
  { re: /admin_?token|ADMIN_TOKEN/i, why: 'admin token material' },
  { re: /api[_-]?secret|APP_KEY|master[_-]?key/i, why: 'hard-coded secret' },
]

const REQUIRED = [
  { re: /@txboard\/theme-sdk/, why: 'must use the theme SDK' },
]

function walk(dir) {
  const out = []
  for (const entry of readdirSync(dir)) {
    if (entry === 'node_modules' || entry === 'dist') continue
    const full = join(dir, entry)
    if (statSync(full).isDirectory()) out.push(...walk(full))
    else if (/\.(ts|tsx|vue|js|jsx|css|json|html)$/.test(entry)) out.push(full)
  }
  return out
}

let failed = 0
for (const template of TEMPLATES) {
  const dir = join(ROOT, 'examples', template)
  const files = walk(dir)
  let hasSdkImport = false
  for (const file of files) {
    const text = readFileSync(file, 'utf8')
    // The bootstrap bootstrap path (gateway/v1) is the only allowed upstream.
    for (const { re, why } of FORBIDDEN_PATTERNS) {
      if (re.test(text)) {
        console.error(`FAIL ${file}: forbidden ${why}`)
        failed++
      }
    }
    if (/@txboard\/theme-sdk/.test(text)) hasSdkImport = true
  }
  for (const { re, why } of REQUIRED) {
    if (!hasSdkImport) {
      console.error(`FAIL examples/${template}: ${why}`)
      failed++
      break
    }
  }
  console.log(`checked examples/${template}: ${files.length} files, SDK import: ${hasSdkImport}`)
}

// Both templates must define the same gateway surface set: the SDK entry file
// must exist with the same export names on both sides (byte-level parity of
// the security semantics is enforced here structurally).
const EXPECTED_EXPORTS = ['createTXBoardClient']
for (const template of TEMPLATES) {
  // The Next SSR template keeps SDK access in lib/server.ts ('server-only');
  // SPA templates keep it in src/api.ts. Both are the single SDK entry point.
  const apiCandidates = ['src/api.ts', 'src/lib/server.ts']
  const apiFile = apiCandidates
    .map(p => join(ROOT, 'examples', template, p))
    .find(p => { try { return !!readFileSync(p, 'utf8') } catch { return false } })
  if (!apiFile) {
    console.error(`FAIL examples/${template}: no SDK entry file (src/api.ts or src/lib/server.ts)`)
    failed++
    continue
  }
  const text = readFileSync(apiFile, 'utf8')
  for (const name of EXPECTED_EXPORTS) {
    if (!text.includes(name)) {
      console.error(`FAIL ${apiFile}: expected ${name}`)
      failed++
    }
  }
}

// SSR-specific guardrails (GW-506): the Next template must never expose the
// session token to the browser and must never use a module-level SDK client.
{
  const serverFile = join(ROOT, 'examples', 'next-theme', 'src', 'lib', 'server.ts')
  const text = readFileSync(serverFile, 'utf8')
  if (!/'server-only'/.test(text)) {
    console.error(`FAIL ${serverFile}: must be marked 'server-only'`)
    failed++
  }
  if (!/cookies\(\)\.get\(SESSION_COOKIE\)/.test(text) && !/cookies\(\)\.get\(SESSION_COOKIE\)\?\.value/.test(text)) {
    console.error(`FAIL ${serverFile}: token must be read per-request from cookies()`)
    failed++
  }
  // No module-level createTXBoardClient() call: every client must be
  // created inside a function (request scope).
  if (/^const \w+ = createTXBoardClient\(/m.test(text)) {
    console.error(`FAIL ${serverFile}: module-level SDK singleton is forbidden (cross-request pollution)`)
    failed++
  }
  const cookieFile = join(ROOT, 'examples', 'next-theme', 'src', 'app', 'login', 'actions.ts')
  const actionText = readFileSync(cookieFile, 'utf8')
  if (!/httpOnly:\s*true/.test(actionText)) {
    console.error(`FAIL ${cookieFile}: session cookie must be httpOnly`)
    failed++
  }
  if (/localStorage/.test(actionText)) {
    console.error(`FAIL ${cookieFile}: token must never touch localStorage in the SSR theme`)
    failed++
  }
}

if (failed) {
  console.error(`\n${failed} template guardrail assertion(s) failed`)
  process.exit(1)
}
console.log('\nAll GW-504/505 theme template guardrails passed.')
