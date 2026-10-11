# Admin Experience Phase B — Alert Triage and Guided Empty States

The dashboard shows **all-clear** only if all four sources (tickets, pending commission, fleet health and pending approvals) return confirmed zeros. Missing, failed and loading values remain visibly unknown; nonzero tasks take visual priority.

Income trend with no positive paid amount shows an explanation and an order-management link instead of a blank chart. Empty traffic rankings explain the lack of period-specific results. Nodes distinguish a brand new dataset from filtered no-results with a primary action to create a node or clear filters.

This work changes no API contract, RBAC, machine credentials, runtime update operations, database or TX-Node integration.

Verification: `npm ci --no-audit --no-fund && npm run verify:web` from repository root. Check dashboard all-zero/partial-error/alert, node first-run/filtered-empty/loading/error, keyboard operation, light/dark mode, and screen widths 390/768/1024/1440 before merge.
