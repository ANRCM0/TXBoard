# Local Internal current order status v2

> 已退役（2026-10-16）：本文保留旧隔离切片的历史协议和验收说明。对应 Core/Gateway 运行入口、配置、SDK 原型消费者、夹具及专属 CI 已移除；下文 Current/local-testing 状态仅描述当时实现。正式 Internal API 仍属 C4 待交付，不提供旧路径或旧版本兼容。见 [当前清理边界](../../../docs/development/core-cleanup.md)。

Status: B5.6 implemented and verified, local/testing only. Fixed operation
`user.order-status.get` version `2` requires the current non-admin Sanctum User
and `user-gateway` service proof. Input contains only `trade_no`, 1..128 ASCII
letters/digits/underscore/hyphen. Output contains only the matching trade number
and the native integer status 0..4.

The Core delegates `AccountController::order`, including current ownership,
missing-order 404 and cancelled status. It does not require a surviving Plan,
initialize payments, execute fulfillment or infer financial facts. Gateway's
existing local Internal opt-in selects `/orders/{trade_no}/status`, preserving
its public `{tradeNo,status}` DTO. Missing/expired/banned/Admin actors, wrong
service, mismatched identifiers, invalid input/DTO and transport failure reject
without Native fallback. Native remains the default transport.

Existing replay, audit, response/deadline and production-404 gates remain.
This completes transport selection for the existing protected Gateway v1 reads.
The full Internal API, production PKI, Admin Gateway and write operations remain
separate gates.

Fixed Core `dd3b4fd` plus this slice passed 808 API tests (29 engine-specific
skips, 6544 assertions). Fixed Gateway `257ff7e` plus this slice passed 527
Gateway and 68 SDK tests, typecheck and build. All 35 actual Core/MariaDB/TLS1.3
integration checks passed, including status equality, missing-Plan status,
foreign-owner 404 and Admin 403 without Native fallback. Source and log hashes
are recorded in
[verification evidence](../../../docs/architecture/v2/internal-order-status-verification.json).
