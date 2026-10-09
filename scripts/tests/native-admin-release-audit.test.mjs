import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'
import {
  COMPLETED_ADMIN_MODULES, buildNativeAdminInventory, scanLegacyClientCalls,
} from '../native-admin-release-audit.mjs'

test('detects direct legacy V2 call sites including TypeScript type arguments', () => {
  const calls = scanLegacyClientCalls(
    "const a = await apiClient.get('/config/fetch')\n" +
    "const b = await apiClient.post<Result>('/coupon/fetch', params)\n" +
    "const c = await nativeApiClient.get('/admin/path/tickets')\n",
  )
  assert.deepEqual(calls, [
    { line: 1, endpoint: '/config/fetch' },
    { line: 2, endpoint: '/coupon/fetch' },
  ])
})

test('inventory separates unfinished modules from fully-native guards', () => {
  const dir = mkdtempSync(join(tmpdir(), 'txboard-admin-release-'))
  try {
    writeFileSync(join(dir, 'ticket.ts'), 'nativeApiClient.get("/admin/safe/tickets")')
    writeFileSync(join(dir, 'content.ts'), 'nativeApiClient.post("/admin/safe/content")')
    writeFileSync(join(dir, 'config.ts'), 'apiClient.get("/config/fetch")')
    writeFileSync(join(dir, 'ticket.test.ts'), 'apiClient.post("/wrong")')
    writeFileSync(join(dir, 'client.ts'), 'apiClient.post("/internal")')
    const report = buildNativeAdminInventory(dir)
    assert.equal(report.totals.scanned_modules, 3)
    assert.equal(report.totals.remaining_modules, 1)
    assert.equal(report.totals.legacy_call_sites, 1)
    assert.equal(report.totals.converted_module_regressions, 0)
    assert.deepEqual(report.remaining_modules[0].endpoints, [
      { line: 1, endpoint: '/config/fetch' },
    ])
  } finally {
    rmSync(dir, { recursive: true, force: true })
  }
})

test('formerly-native module V2 regression is flagged, never hidden by other modules', () => {
  const dir = mkdtempSync(join(tmpdir(), 'txboard-admin-regression-'))
  try {
    assert.ok(COMPLETED_ADMIN_MODULES.includes('traffic-reset'))
    assert.ok(COMPLETED_ADMIN_MODULES.includes('payment'))
    assert.ok(COMPLETED_ADMIN_MODULES.includes('queueMonitor'))
    assert.ok(COMPLETED_ADMIN_MODULES.includes('coupon'))
    assert.ok(COMPLETED_ADMIN_MODULES.includes('mail'))
    writeFileSync(join(dir, 'traffic-reset.ts'), "apiClient.post('/traffic-reset/reset-user')")
    const report = buildNativeAdminInventory(dir)
    assert.deepEqual(report.regressions, ['traffic-reset'])
    assert.equal(report.totals.strict_release_ready, false)
  } finally {
    rmSync(dir, { recursive: true, force: true })
  }
})
