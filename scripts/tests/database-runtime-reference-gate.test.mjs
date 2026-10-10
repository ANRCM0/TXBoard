import test from 'node:test';
import assert from 'node:assert/strict';
import { auditRuntimeReferences } from '../database-runtime-reference-gate.mjs';
test('separates historical migration declarations from active runtime references', () => {
  const report = auditRuntimeReferences({ tables: [{ references: [
    { table: 'v2_order', kind: 'schema', path: 'api/database/migrations/old.php' },
    { table: 'v2_order', kind: 'model', path: 'api/app/Models/Order.php' },
    { table: 'v2_order', kind: 'db-table', path: 'api/app/Services/Billing.php' },
  ] }], dynamicCallsNeedingManualReview: [] });
  assert.equal(report.remainingLiteralReferences, 2);
  assert.equal(report.byTable.v2_order, 2);
  assert.equal(report.cutoverReady, false);
});
test('dynamic runtime SQL blocks readiness even with zero literal references', () => {
  const report = auditRuntimeReferences({ tables: [], dynamicCallsNeedingManualReview: [{ path: 'api/app/Services/Dynamic.php' }] });
  assert.equal(report.cutoverReady, false);
  assert.equal(report.dynamicCallSitesToReview, 1);
});
test('empty runtime inventory is only a static-scan pass', () => {
  const report = auditRuntimeReferences({ tables: [], dynamicCallsNeedingManualReview: [] });
  assert.equal(report.cutoverReady, true);
  assert.ok(report.limitations.length);
});

test('native model declarations are not blockers when the resolver trait is present', () => {
  const source = { tables: [{ references: [
    { table: 'v2_user', kind: 'model', path: 'api/app/Models/User.php' },
    { table: 'v2_order', kind: 'db-table', path: 'api/app/Services/OrderService.php' },
  ] }], dynamicCallsNeedingManualReview: [] };
  const report = auditRuntimeReferences(source, {
    readSource: () => '<?php class User { use \\App\\Support\\Database\\ResolvesNativeEloquentTable; protected $table = \'v2_user\'; }',
  });
  assert.equal(report.adaptedModelDeclarations, 1);
  assert.equal(report.remainingLiteralReferences, 1);
  assert.equal(report.literalGatePassed, false);
});

test('historical migration and fixture SQL do not cause runtime false positives', () => {
  const source = { tables: [{ references: [
    { table: 'v2_order', kind: 'schema', path: 'api/database/migrations/2023_01_01.php' },
    { table: 'v2_order', kind: 'sql-literal', path: 'api/scripts/probe.php' },
    { table: 'v2_user', kind: 'model', path: 'api/app/Models/User.php' },
  ] }], dynamicCallsNeedingManualReview: [] };
  const report = auditRuntimeReferences(source, {
    readSource: () => 'use \\App\\Support\\Database\\ResolvesNativeEloquentTable;',
  });
  assert.equal(report.remainingLiteralReferences, 0);
  assert.equal(report.literalGatePassed, true);
});
