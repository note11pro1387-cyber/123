# MR NEON — Email/SMTP Integration (W4-P4)

## Adapter

`SmtpVerificationTransport` implements the existing W4-P3 `VerificationTransportInterface`. It sends only the server-provided destination and the short-lived OTP held in `VerificationDelivery`; callers cannot override the recipient. It uses a compact SMTP client for authenticated SMTP `AUTH LOGIN` over either implicit TLS (`smtps`, usually port 465) or STARTTLS (usually port 587). Clear-text SMTP is intentionally unsupported. TLS certificate and peer-name verification remain enabled; an optional CA bundle path can be provided for private PKI/testing.

The inspected project has no `composer.json`, Composer executable, or installed PHPMailer, so this patch does not silently invent a dependency-management/vendor architecture. The native SMTP implementation is a constrained adapter, not a general-purpose mail library: `AUTH LOGIN` only, no OAuth2, no attachments, and a real SMTP Integration test is required before release. Security/dependency review checked the official PHPMailer repository and release list: as of 2026-10-12, the latest tagged release listed is v7.1.1 (released 2026-05-18); its documentation recommends using a maintained library instead of hand-writing SMTP. The package archive could not be retrieved by this execution environment for verifiable vendoring, and no incomplete/copy-fragment dependency has been added. Before production, adopt PHPMailer through an approved Composer workflow or complete a separately reviewed, integrity-checked official-source integration, then repeat real SMTP tests. Reference: https://github.com/PHPMailer/PHPMailer/releases and https://github.com/PHPMailer/PHPMailer.

## Runtime variables

- `MRNEON_EMAIL_HOST`
- `MRNEON_EMAIL_PORT`
- `MRNEON_EMAIL_USERNAME`
- `MRNEON_EMAIL_PASSWORD` (read without trimming)
- `MRNEON_EMAIL_ENCRYPTION` (`starttls` or `smtps`; never `none`)
- `MRNEON_EMAIL_FROM_ADDRESS`
- `MRNEON_EMAIL_FROM_NAME`
- `MRNEON_EMAIL_HELO_NAME` (required explicit SMTP EHLO identity; valid FQDN or bracketed IPv4/IPv6 address-literal; `.local` and `.invalid` are rejected)
- `MRNEON_EMAIL_TIMEOUT_SECONDS` (3–15 seconds)
- `MRNEON_EMAIL_TLS_CA_FILE` (optional readable CA bundle path; empty uses the PHP/OpenSSL trust store)

All values come from process/Apache environment or private runtime configuration. `MRNEON_EMAIL_HELO_NAME` is required and is sent unchanged in both EHLO exchanges (before and after STARTTLS); it is not inferred from the sender address. No live SMTP config was present during development; the production Email adapter therefore returns `not_configured` until all required values validate. The example file contains only empty placeholders.

## Result semantics

- Final SMTP `250` after message DATA terminator: `accepted` (server accepted the message; inbox delivery/read is not guaranteed).
- Explicit SMTP response rejecting an operation/message: `rejected`.
- Lost/invalid final response after the message terminator may have reached the server: `unknown`.
- Missing/invalid configuration: `not_configured`.

There is no automatic retry. A `unknown` result must be reconciled by an operational process before any send is reattempted. OTPs are MIME-base64-encoded for transfer, never logged or stored by the adapter, and are absent from Subject and headers.

## Testing

`php tests/Unit/run-w4p4-unit.php` checks transport status mapping with explicit fakes and runs loopback SMTP fixtures for both implicit TLS (`smtps`) and STARTTLS with a temporary test certificate. These fixtures validate local protocol negotiation and TLS verification; they are not a real SMTP provider/account test. No real email is sent by the Unit suite.

A deliberately opt-in real-provider test is available only for an owner-controlled test inbox:

```powershell
$env:MRNEON_ALLOW_LIVE_EMAIL_TEST = '1'
$env:MRNEON_TEST_EMAIL_TO = 'owner-controlled-test-inbox@example.test'
php tests/Integration/run-w4p4-email-delivery-integration.php
Remove-Item Env:MRNEON_ALLOW_LIVE_EMAIL_TEST -ErrorAction SilentlyContinue
Remove-Item Env:MRNEON_TEST_EMAIL_TO -ErrorAction SilentlyContinue
```

The script also requires all valid private SMTP configuration variables listed above. Exit code `77` means the live test did not run. Exit code `75` means the SMTP result is ambiguous; do not rerun blindly. Exit code `0` proves only that the SMTP server returned final acceptance, not inbox delivery or reading. The generated code is synthetic, exists only in process memory, and is not tied to a live Registration Attempt or Challenge.
