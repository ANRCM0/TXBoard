# Installation and read-only diagnostics

Production installation and image updates use the independent
`ANRCM0/TXBoard-Deploy` toolkit. Use its reviewed native installer, private
configuration directory and immutable Core image. The source repository's root
Compose file is not a replacement for the native preparation, Provider checks,
activation and durable recovery workflow.

## Diagnose an installation

The current Core image includes a local command which works in preparing,
active and unhealthy native states:

```sh
docker compose exec -T txboard php /www/artisan core:diagnose --json
```

The report uses `core-diagnostics/v1`. It reads effective Laravel configuration,
persisted installation authority, runtime readiness, verified Provider
registration metadata and the resolved module fingerprint. It does not contact
the Provider or external SMTP/HTTP services, activate an installation, clear
caches, change records or repair failed configuration. A nonzero exit preserves
the report and identifies failing sections with stable error codes. Other
sections remain available when an individual dependency fails.

The report deliberately omits APP_KEY, database and mail passwords, tokens,
private keys, certificate contents, private file paths and exception text.
Administrator entry settings are read without populating shared setting caches;
the report labels their database source rather than claiming the shared cache
was inspected. The administrator path is hidden by default. Reveal it only in
the operator's private terminal:

```sh
docker compose exec -T txboard php /www/artisan core:diagnose --json --show-admin-path
```

Do not publish output produced with `--show-admin-path`. The public site URL is
not the administrator URL, and `/admin/` must not be assumed to be the entry.

Updated Deploy tools expose the same report as `txboard core-diagnose`. This
dedicated command returns JSON; `txboard diagnose` also includes service,
capacity and backup checks and can include recent application logs. Treat those
combined logs as operator material rather than a sanitized support bundle.
`txboard native diagnose` retains its separate offline inspection of private
recovery and backup materials.

## When the application will not stay running

The updated Deploy helper uses a running container even if its healthcheck is
failing. Otherwise it can run the report against the existing local image with
the ordinary entrypoint disabled. It does not pull or build an image, start
dependencies, publish ports or run migrations. Keep the database available.
The temporary diagnostic container is removed after completion.

If the image predates `core:diagnose`, the helper reports it as unavailable.
Adopt a reviewed immutable image containing this command through the normal
update workflow; do not enable `tinker` or modify production container source.

## Recover an interrupted native installation

Keep the installation directory, private configuration, original operation ID,
APP_KEY and PKI. First inspect the diagnostic report and existing deployment
intent:

```sh
sudo txboard core-diagnose
sudo txboard native status
sudo txboard native diagnose
```

After correcting the reported prerequisite, use `sudo txboard native recover`
to resume the original intent. Do not rerun a fresh installer, replace its
inputs or discard the intent to work around an activation failure. An older
installation can obtain the diagnostic helper with `sudo txboard refresh-manager`;
that refresh alone does not upgrade the Core image.

## Compatibility and acceptance

`core:diagnose` belongs to the existing recovery-command admission path. It is
not added to `NativeRuntimeAdmission::COMMANDS`: changing that list would change
the digest stored by existing native activation receipts. The command does not
admit arbitrary PHP evaluation or relax runtime, writer or epoch validation.

API tests exercise privacy, partial failures, native preparing state, worker
epoch mismatch and zero database writes. The same cases run in the MySQL
runtime shard. Immutable-image acceptance executes the report after installation
and module updates. Local static checks are separate from functional acceptance
in GitHub Actions, as described in [the development guide](development.md).
