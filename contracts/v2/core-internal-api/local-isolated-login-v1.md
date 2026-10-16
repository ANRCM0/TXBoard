# Isolated local account login over Internal mTLS v1

> 已退役（2026-10-16）：本文保留旧隔离切片的历史协议和验收说明。对应 Core/Gateway 运行入口、配置、SDK 原型消费者、夹具及专属 CI 已移除；下文 Current/local-testing 状态仅描述当时实现。正式 Internal API 仍属 C4 待交付，不提供旧路径或旧版本兼容。见 [当前清理边界](../../../docs/development/core-cleanup.md)。

Status: B5.9 implemented and verified with synthetic isolated data. See the
[fixed-source verification](../../../docs/architecture/v2/internal-isolated-login-verification.json).
Fixed `auth.login` version1 uses user-gateway service identity, absent
Authorization and the empty-actor proof hash. This is a credential submission,
with a separate strict default-off `internal_api_slice.login_enabled` gate,
local/testing environment and `core_shadow.isolated_copy=true`. Public read
opt-in cannot enable login. No registration, mail, admin-login or password
recovery operation is added.

Input contains required string email/password and only optional existing
turnstile_token, recaptcha_v3_token, recaptcha_data and email_code fields.
Transport limits and current Gateway input bounds remain; Core also executes
the real AuthLogin validation. Fresh native request normalization preserves
password whitespace and applies original trim/null behavior to root fields.
The mapped request is bound only during native execution and restored in
finally; cached authenticated actors are cleared before the credential step.

Login delegates AuthController, LoginService and AuthService, preserving
current password algorithms, account banning, password-attempt cache, login
hook, last_login_at and one-year Sanctum issuance. Generic native login can
issue a session for an Admin account; protected User operations still reject
that role, and no Admin Gateway or secure admin path is supplied. HTTP200
success contains only auth_data and exact
`meta={current_only:true,slice:"internal-isolated-login/1"}`. Audit actor_id=0
identifies the unauthenticated submission, not the eventual account; neither
email/password, CAPTCHA material, token nor private subscription fields enter
the technical audit or safe exception envelope.

The original ThrottleRequests(10,1) runs before FormRequest validation with
the same native domain/IP key and no new prefix. TLS forwards no client IP or
visitor identity header; Core observes the local boundary address and shares
that budget with direct native requests from the same address. This does not
prove end-user IP attribution or cluster limiter behavior.

This slice accepts only a known disabled CAPTCHA policy. A local adapter
checks the current SQL captcha_enable, settings-cache array and loaded native
values at execution; enabled/drifting CAPTCHA rejects without external
provider traffic. The fixed password-limit values must agree between settings
cache and the loaded request policy; dependency failure refuses rather than
guessing that an unavailable policy is disabled. Original CaptchaService is
called only when the disabled policy is known. External CAPTCHA verification
is a separate gate; native routes keep their existing behavior.

Gateway requires its extra exact default-off login flag and existing local
Internal transport, and rejects simultaneously enabled account workflows.
Service identity or service-budget rejection is an infrastructure failure,
distinct from native wrong-credential401 or native login-budget429; it does not
consume the Gateway's password-failure counters. Existing login protection/challenge handling and public
DTO remain; selected Internal requests never retry or fall back to Native.
Deadline, audit failure or a lost reply can leave last_login_at and a session
committed without returning its token. No token rollback or original-token
recovery is promised: existing native session listing/revocation and expiry
remain the cleanup mechanisms. Another deliberate login is another session.
The local dispatcher catches policy and native execution exceptions before the
inner Laravel routing pipeline can report credential-bearing argument traces.
It preserves safe FormRequest/throttle errors and restores the outer request in
finally; this does not change native-route exception handling or prevent trusted
in-process plugins from writing their own logs.

Fixed Core `ceea19b` and Gateway `19cf371` plus this slice: Core focused19/237
assertions, full906 passed/46 engine-only skipped/8237 assertions; Gateway671,
SDK68, typecheck/build passed. Actual Core/MariaDB/TLS1.3 integration passed57
checks. The runtime/test hashes match both fixed snapshots; only the TLS test
expectation changed after full gates, preserving the existing forged-proxy403.
Production PKI, real CAPTCHA, SC-v2 consumers and remaining auth workflows
require separate implementation and verification.
