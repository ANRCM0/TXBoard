# Current mail send diagnostics

`MailService::sendEmail` retains the existing Laravel mailer, Blade/DB template rendering, `MailLog` fields and `SendEmailJob` retry behavior. This contract covers only an `Exception` caught by the existing `sendEmail` mail-send `try/catch`.

For that caught failure, Core calls `Log::error('MAIL_SEND_FAILED')` with one string argument and no context or throwable. The returned array's `error` and the persisted `MailLog.error` both contain `MAIL_SEND_FAILED`. Exception messages, previous exceptions and stack arguments do not enter those three diagnostic destinations, including when PHP's `zend.exception_ignore_args=0` exposes arguments in exception traces. Callers must use this fixed code rather than an SMTP provider's message.

The returned array still contains `email`, `subject`, `template_name`, `error` and the existing `config` JSON allowlist (`host`, `port`, `encryption`). Recipient and subject remain private native mail metadata; this change does not make the whole array suitable for a public or technical audit response. No message body, verification code, SMTP username or SMTP password is added to diagnostic fields. A successful send still returns `error: null` and records the existing template name.

`SendEmailJob` still releases a failed job when the returned `error` is nonempty, with its existing three tries, ten-second timeout and queue selection. This diagnostic change provides no delivery receipt, business idempotency, cancellation of an unknown send, or change to queue/mail transport selection.

The catch remains `Exception`, not `Throwable`. Work before the try (including configuration, placeholder preprocessing and template-cache/SQL lookup), `MailLog::create` after it, `processUserChunk`, queue failures and trusted plugins' own logs retain their existing behavior. This contract does not claim that every mail-related exception or the whole native HTTP handler is redacted. Mail body and recipient still reach the configured trusted mail transport as required for delivery.

Regression tests use a real Laravel mailer with a local throwing Symfony-compatible transport, rather than SMTP or external network access. They establish that the thrown exception and its previous exception/trace contain fixture secrets before verifying the fixed diagnostics; a real `ArrayTransport` also verifies successful Blade and DB-template rendering and the unchanged job release decision.

Fixed Core `d3ae864` plus this three-file change passed six focused tests with
89 assertions and the full API suite:925 passed,50 engine-only skips,8620
assertions. [Fixed-source and raw-log evidence](../../docs/architecture/v2/mail-diagnostics-verification.json)
records the precise caught-error scope and local-transport limitations.
