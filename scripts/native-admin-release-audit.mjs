#!/usr/bin/env node
/**
 * Repeatable native-admin cutover evidence. This is a static call-site audit,
 * not proof that an endpoint is safe to remove: runtime/plugins and
 * third-party clients still require separately documented compatibility.
 */
import { readFileSync, readdirSync, mkdirSync, writeFileSync } from 'node:fs'
import { resolve, join, dirname, basename } from 'node:path'
import { fileURLToPath } from 'node:url'

// These full UI modules already run exclusively through native TXAPI.
// Mixed modules (finance/user-admin/statistics) remain intentionally excluded.
export const COMPLETED_ADMIN_MODULES = Object.freeze([
  'content', 'ticket', 'traffic-reset', 'payment', 'queueMonitor', 'coupon', 'mail', 'config', 'server-groups', 'server-routes',
])

export function scanLegacyClientCalls(source) {
  const calls = []
  const pattern = /\bapiClient\s*\.\s*(?:get|post|put|patch|delete|request)\s*(?:<[^();]*>)?\s*\(/g
  for (const match of source.matchAll(pattern)) {
    const following = source.slice(match.index + match[0].length)
    const literal = following.match(/^\s*(['"\x60])([^'"\x60]{1,180})\1/)
    calls.push({
      line: source.slice(0, match.index).split('\n').length,
      endpoint: literal?.[2] ?? '[dynamic or nonliteral]',
    })
  }
  return calls
}

export function buildNativeAdminInventory(apiDir) {
  const records = readdirSync(apiDir)
    .filter(name => name.endsWith('.ts') &&
      !name.endsWith('.test.ts') && name !== 'client.ts')
    .sort()
    .map(name => {
      const content = readFileSync(join(apiDir, name), 'utf8')
      const calls = scanLegacyClientCalls(content)
      return {
        module: name.slice(0, -3),
        legacy_call_count: calls.length,
        legacy_calls: calls,
      }
    })

  const remaining = records.filter(item => item.legacy_call_count > 0)
  const regressions = records.filter(item =>
    COMPLETED_ADMIN_MODULES.includes(item.module) && item.legacy_call_count > 0)

  return {
    schema_version: 1,
    scope: 'React Admin TypeScript direct apiClient call sites only',
    native_api_root: '/txapi',
    legacy_api_root: '/api/v2/{secure_path}',
    totals: {
      scanned_modules: records.length,
      remaining_modules: remaining.length,
      legacy_call_sites: remaining.reduce((sum, item) => sum + item.legacy_call_count, 0),
      converted_module_regressions: regressions.length,
      strict_release_ready: remaining.length === 0,
    },
    migrated_guard_modules: [...COMPLETED_ADMIN_MODULES],
    remaining_modules: remaining.map(item => ({
      module: item.module,
      calls: item.legacy_call_count,
      endpoints: item.legacy_calls,
    })),
    regressions: regressions.map(item => item.module),
  }
}

function cli(argv) {
  const base = resolve(dirname(fileURLToPath(import.meta.url)), '..')
  const dir = join(base, 'web', 'admin', 'src', 'api')
  const inventory = buildNativeAdminInventory(dir)
  const outputAt = argv.indexOf('--output')
  if (outputAt >= 0) {
    const file = argv[outputAt + 1]
    if (!file || file.startsWith('--')) {
      throw new Error('--output requires a file path')
    }
    const out = resolve(file)
    mkdirSync(dirname(out), { recursive: true })
    writeFileSync(out, JSON.stringify(inventory, null, 2) + '\n')
  }
  console.log('TXBoard React Admin native release inventory')
  console.log(JSON.stringify(inventory.totals, null, 2))
  for (const module of inventory.remaining_modules) {
    console.log('  ' + module.module + ': ' + module.calls + ' legacy call sites')
  }

  if (argv.includes('--guard-native') && inventory.regressions.length) {
    console.error('Native-only modules regressed to V2: ' + inventory.regressions.join(', '))
    process.exitCode = 1
  }
  if (argv.includes('--strict') && !inventory.totals.strict_release_ready) {
    console.error('Release is not native-only: unresolved React Admin V2 call sites')
    process.exitCode = 1
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  cli(process.argv.slice(2))
}
