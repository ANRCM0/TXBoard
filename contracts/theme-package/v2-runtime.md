# Theme v2 SPA Runtime — GW-502 phase 2

This is the current standalone static SPA theme runtime. It is the only
third-party theme format; the native TXBoard user SPA remains built in.
The old Blade package loader and duplicate `/themes/v2/upload` entry are removed.

## Trusted version sources (required)

Before installing a v2 theme, set the following **deployment-owned** values
to the exact compatible release versions and reload Laravel config/Octane:

- THEME_V2_TXBOARD_VERSION=3.6.0
- THEME_V2_GATEWAY_VERSION=1.0.0
- THEME_V2_SDK_VERSION=0.1.0

Values above are illustrative, **not** claims about the version currently
deployed. Determine the actual version from the deployed artifacts / SDK
release, not the uploaded package. An unset or invalid version rejects
install/activation (fail closed). Never derive a trusted runtime version
from txboard-theme.json. The current Gateway SDK is 0.x; declare the actual
verified version until the 1.0 public release is available.

## Installation and activation

- POST /txapi/admin/{admin_path}/themes/upload — Admin Bearer + secure path,
  multipart form-data file=ZIP, maximum 10 MiB compressed.
- Upload validates the ZIP before extraction and stages all contents under
  storage/theme/.unpack-*/.stage-* on the same filesystem.
- Fresh uploads remain **inactive**. Existing active v2 themes publish their
  upgrade without changing frontend_theme.
- Activating a v2 theme through the existing, audited frontend_theme setting
  updates only the canonical active theme after staging static assets.
- GET / serves a strict-CSP HTML shell with integrity-checked module and CSS
  references. Static assets are published under public/theme/{name}/dist and
  public/theme/{name}/assets; the manifest and schema never become public.
- The SPA runtime currently supports root/hash navigation. History-mode nested
  route fallback needs a separate protected routing contract; this phase
  does not alter dynamic admin secure-path routing.

## Version rollback

- POST /txapi/admin/{admin_path}/themes/{name}/rollback
- Only an installed v2 theme with a previous-version slot can be rolled back.
- A successful version upgrade retains exactly **one** previous version at
  storage/theme/.rollback/{name}. No user/order/payment tables are touched.
- Rollback swaps the current/previous versions. If the theme is active, it also
  republishes assets. It does not change frontend_theme.
- On file promotion or integrity failure, restore the prior snapshot and/or
  public publication. Manual recovery diagnostics are logged if the host
  filesystem fails during compensation.
- Rollback of an incompatible package is rejected, with the current installed
  version retained.

## Server-owned public config

For v2, effective public configuration is:

    stored/default config
      INTERSECT manifest config.public
      INTERSECT config/theme_v2.php public_fields

The allowlist in config/theme_v2.php is **server-owned**.
Unknown fields, SMTP/admin secrets or the theme's own requested public fields
cannot enlarge that list. Configuration is exposed through the existing
TXBoard native public-site projection (which the external Gateway may consume).
No new direct access to admin or Gateway authorization is granted.

## Security boundaries and non-goals

- SSR is **rejected**. Never execute arbitrary uploaded server-side JS.
- Manifest capabilities are UI hints; never grant Laravel/Gateway authority.
- Uploaded theme JS is *not sandboxed*. CSP and SRI are valuable defenses, but
  an administrator must trust/review the package author before enabling a
  same-origin theme. Dedicated untrusted-origin isolation/signature policy is
  not provided in this phase.
- Static inspection does not prove bundled JS free of all remote loader
  behaviors; no claim of cryptographic publisher authentication is made.
- A strict CSP blocks inline scripts and third-party code; themes needing
  those features are incompatible.
- SPA theme asset URLs and Gateway calls must be same-origin.
- No DB migrations, account/session changes, payment changes, or Gateway route
  modifications are performed here.
- Do **not** close issue #220 before package/SDK compatibility, browser CSP,
  signed package policy (if approved), and staging E2E are accepted.

## Validation

    cd api
    php artisan test --filter=ThemeV2RuntimeTest
    php artisan test --filter=ThemeV2PackageTest

Batch acceptance runs the affected API, MySQL, Web and image checks in GitHub Actions; do not repeat local heavy tests.
