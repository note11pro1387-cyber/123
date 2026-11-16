# W4-P4 Acceptance Matrix

| Criterion | Result | Evidence / Reason |
|---|---|---|
| Existing transport type reused | PASS | `CompositeVerificationTransport` and provider adapters implement W4-P3 interface. |
| Telegram positive acceptance checks `ok`, `message_id`, matching chat ID | PASS | `TelegramBotApiClient::sendVerificationMessage`; unit tests. |
| Telegram 4xx/reject/unknown/config states mapped correctly | PASS | Explicit 4xx is rejected; 408/5xx, transport failure and malformed success responses map to `unknown`; 38 unit assertions include these cases. No provider description is returned. |
| TLS verification enabled for Telegram HTTPS | PASS | `NativeHttpsJsonClient` always sets `verify_peer`, `verify_peer_name`, no redirects. |
| OTP absent from request URL | PASS | Telegram OTP travels in POST JSON body; unit assertion. |
| Telegram link token CSPRNG / 64-hex / single-use | PASS WITH NOTES | Existing W4-P2 CSPRNG nonce and hash reused; DB compare-and-consume is implemented and linked identities are immutable at Repository/Service boundaries, but not DB-tested here. |
| Only private `/start` updates bind | PASS | Strict `TelegramUpdateParser`; unit tests cover private/group/wrong bot/malformed token. |
| Secret webhook header comparison | PASS | Controller enforces `X-Telegram-Bot-Api-Secret-Token` with `hash_equals`; not exercised through a live HTTP server. |
| `update_id` idempotency with body hash | PASS WITH NOTES | Existing `idempotency_keys` table reused in transaction; DB concurrency not verified. |
| Prevent cross-Attempt Telegram identity collision | PASS WITH NOTES | Named identity lock + active-Attempt check; concurrent DB behavior not tested. |
| CSRF-protected Telegram link-status API | PASS | New action uses SessionManager and Csrf and returns boolean only; HTTP smoke not run. |
| No admin Chat fallback | PASS | OTP adapter accepts only W4-P3 delivery destination; no admin config reference. |
| SMTP TLS/peer verification always on | PASS | `NativeSmtpClient`; local SMTPS and STARTTLS fixtures successfully negotiated verified TLS. |
| SMTP final provider acceptance result | PASS WITH NOTES | Local TLS server fixture returned final 250; real SMTP service not configured. |
| SMTP explicit reject/ambiguous mapping | PASS | Fake SMTP client tests. |
| Email config defaults to not_configured when missing | PASS | Unit test; no SMTP credentials present. |
| Exact 120-second Challenge TTL preserved | PASS BY SCOPE / NOT REIMPLEMENTED | W4-P3 remains owner; W4-P3 suite is run separately. No live provider acceptance tested. |
| Telegram real API send | NOT RUN | No credentials; test did not call Telegram. |
| Email real SMTP send | NOT RUN | No SMTP host/credentials; no real message sent. Guarded opt-in runner exits 77 without explicit test-inbox opt-in/config. |
| Webhook registration / public HTTPS reachability | NOT RUN | No final public deployment URL / credentials supplied. |
| DB-backed Telegram linking/idempotency Integration | BLOCKED | Current runtime has no `pdo_mysql`; exact runner exits 77 before database mutations. |
| Wave 2/3 DB/Auth Integration | NOT VERIFIED / DEFERRED | `OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED`. |
| Prevent replacing a linked Telegram destination | PASS WITH NOTES | Compare-and-consume SQL has NULL guards; idempotent registration retry reuses current binding; actual DB behavior remains unverified. |
| Live SMTP opt-in safety | PASS (GUARDED) | Requires explicit `MRNEON_ALLOW_LIVE_EMAIL_TEST=1`, owner-controlled test inbox, and valid SMTP config; not enabled during this run. |

| PHPMailer dependency strategy | PASS WITH NOTES | Official latest-tag research checked (v7.1.1); no Composer/vendor architecture exists and upstream package could not be integrity-checked into this runtime, so no partial vendoring was attempted. Native SMTP remains a release risk until approved library integration or reviewed acceptance. |
