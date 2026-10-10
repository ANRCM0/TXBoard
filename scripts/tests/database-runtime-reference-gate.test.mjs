import test from 'node:test';
import assert from 'node:assert/strict';
import { auditRuntimeReferences } from '../database-runtime-reference-gate.mjs';
test('separates historical migration declarations from active runtime references', () => {
  const report = auditRuntimeReferences({ references: [
    { table: 'v2_order', kind: 'schema', path: 'api/database/migrations/old.php' },
    { table: 'v2_order', kind: 'model', path: 'api/app/Models/Order.php' },
    { table: 'v2_order', kind: 'db-table', path: 'api/app/Services/Billing.php' },
  ], dynamicCalls: [] });
  assert.equal(report.remainingLiteralReferences, 2);
  assert.equal(report.byTable.v2_order, 2);
  assert.equal(report.cutoverReady, false);
});
test('dynamic runtime SQL blocks readiness even with zero literal references', () => {
  const report = auditRuntimeReferences({ references: [], dynamicCalls: [{ path: 'api/app/Services/Dynamic.php' }] });
  assert.equal(report.cutoverReady, false);
  assert.equal(report.dynamicCallSitesToReview, 1);
});
test('empty runtime inventory is only a static-scan pass', () => {
  const report = auditRuntimeReferences({ references: [], dynamicCalls: [] });
  assert.equal(report.cutoverReady, true);
  assert.ok(report.limitations.length);
});
