# Local public display over Internal mTLS v1

> 已退役（2026-10-16）：本文保留旧隔离切片的历史协议和验收说明。对应 Core/Gateway 运行入口、配置、SDK 原型消费者、夹具及专属 CI 已移除；下文 Current/local-testing 状态仅描述当时实现。正式 Internal API 仍属 C4 待交付，不提供旧路径或旧版本兼容。见 [当前清理边界](../../../docs/development/core-cleanup.md)。

Status: B5.8 implemented and verified for local/testing only. Two fixed read operations
`public.site-config.get` and `public.plan.list`, version1, require the existing
`user-gateway` service proof and an additional strict default-off
`internal_api_slice.public_read_enabled` gate. They delegate current
`PublicController`/`SiteConfigService`/`PlanCatalog`, preserving current theme,
hooks, plan visibility, capacity, ordering and quote display. They have no User
actor. No login, registration, email delivery or payment method becomes anonymous.

Only these registered anonymous operations accept absent Authorization. Any
Authorization header, including an empty value, is refused. Service proof binds
`actor_sha256` to SHA256 of the empty bytes; its existing certificate/key epoch,
body/path binding, nonce and service rate limit remain mandatory. All User/Admin
operations still require their actual current Sanctum principal. Supplied actor,
certificate or forwarding headers do not create authority. The existing audit
schema uses `actor_id=0` as an explicit anonymous marker for these two operation
names; it is neither a User nor an authenticated actor identifier.

Input is exactly `{}`. Success is HTTP200 with current TXAPI envelope and exact
`meta={current_only:true,slice:"internal-public-readonly/1"}`. Site output is only
the existing Gateway Native allowlist: app_name, app_description, app_url, logo,
frontend_theme, theme_config, is_captcha, captcha_type, turnstile_site_key,
recaptcha_site_key, recaptcha_v3_site_key. Missing optional display fields retain
their current behavior. Theme config remains the existing trusted public theme
extension; unrelated hook fields and secret-key settings are not forwarded.
Plans retain exactly the existing Native catalog fields, with canonical decimal
strings for IDs, byte counts, integer limits and price minor amounts; original
native quote conversion is authoritative. Gateway validates exact DTOs, unique
plan IDs and price periods, and safe integer conversion before projecting into
its unchanged public v1 DTOs. No price, entitlement or availability is calculated
by Gateway.

Gateway selects these two operations only when its additional exact opt-in
`GATEWAY_INTERNAL_PUBLIC_READ_ENABLED=true` and existing local Internal User
transport are configured. Misconfigured or production opt-in fails startup.
Default public reads use Native. Selected Internal reads never fall back, retry
automatically or forward a visitor Bearer. Existing public caching behavior
remains subject to its configured TTL; metadata is not an authorization decision.
Transport retains 16KiB input, 64KiB response, 5s total deadline, TLS1.3 CA/CRL/SAN
validation, safe errors and no-secret audit. Large catalogs or invalid display
data can refuse rather than release partial data. Production PKI and remaining
authentication/write operations require separate implementation and gates.

Fixed Core `a7e73b1` and Gateway `8c03a78` plus sixteen reviewed source files:
13 focused Core tests / 166 assertions, full API 872 passed / 40 engine-specific
skipped / 7697 assertions, Gateway 596 and SDK 68 tests, typecheck/build and
48 actual Core/MariaDB/TLS1.3 checks passed. The TLS fixture clears the existing
shared display cache for boundary assertions and independently verifies one
MISS then HIT with exactly one public SQL audit. Prior authenticated User/Admin
requests do not leave a User in subsequent anonymous guest hooks. Temporary
keys and credentials were removed. Exact source and log hashes are in the
[verification report](../../../docs/architecture/v2/internal-public-read-verification.json).
