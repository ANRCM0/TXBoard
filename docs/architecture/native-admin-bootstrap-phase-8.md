# Native Admin Bootstrap and V2 Administrative Cleanup · Phase 8

> Code migration in development on October 10, 2026. Not equivalent to production rollout.

## Native first-party administrator sign-in

The administrator login now calls `POST /txapi/auth/admin/login`, and CAPTCHA site configuration loads from `GET /txapi/public/site-config`. Both are covered by the native request-ID envelope and existing AuthLogin/CaptchaService/LoginService policy. The admin-specific endpoint validates password and CAPTCHA before checking admin authorization; only an active administrator gets a Sanctum bearer and the current rotating `secure_path`, and no subscription token is emitted. Nonadmins never receive an admin bearer from that route. The normal user login `/txapi/auth/login` is intentionally unchanged and never exposes the rotating administrator path.

The React Admin login page and CAPTCHA bootstrap no longer use `/api/v2/passport/auth/login` or `/api/v2/guest/comm/config`. The public projection is reused from SiteConfigService, including the exact provider site keys and CAPTCHA enablement. Its client fails closed if the required CAPTCHA flag is absent instead of assuming CAPTCHA is disabled.

## Removed V2 administrator paths

- The V2 Notice/Knowledge `ContentRoute` group has been deregistered and its unused legacy controllers removed. Admin React uses `/txapi/admin/{admin_path}/content/notices` and `content/knowledge`.
- The V2 SystemRoute entries for config, mail templates and traffic reset have been deregistered. The remaining legacy `system/*` internal diagnostics continue temporarily under V2 until a separate caller audit and replacement. Native TXAPI settings/mail/traffic-reset implementations are authoritative.
- The route retirement test asserts absent V2 registrations and present native counterparts.
- This does **not** retire payments or callback handlers, subscription paths, node/agent runtime Wire protocols, remaining V2 `commerce/*`, `user/*`, or `system/*` diagnostics. Obsolete V2 ConfigController, MailTemplateController and TrafficResetController files have also been deleted after legacy PHP regression cases were migrated to TXAPI. The remaining SystemController diagnostic endpoints are a separate later audit.

## Release gates

CI: native login/CAPTCHA authz and protected secret tests, route registration regressions, native Admin TypeScript contracts, full PHP and MySQL regressions, strict P0 admin inventory and image checks. Representative 1Panel/MySQL/Redis deployment, backup+restore, real auth provider CAPTCHA, financial reconciliation and rollback remain pending on Issue #168.
