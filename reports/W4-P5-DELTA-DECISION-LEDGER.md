# W4-P5 Delta Decision Ledger

| ID | Decision / delta | Reason | Effect / status |
|---|---|---|---|
| DLG-501 | Reuse `verification_challenges` instead of creating a parallel Recovery Token table | Existing Migration 005 already defines `password_reset`, user/channel binding and `recovery_token_hash`, `recovery_expires_at`, `recovery_used_at`. | No schema migration added. |
| DLG-502 | Recovery lookup uses canonical Email or MR NEON account username; Telegram destination comes only from the verified server-side channel | A Telegram handle or client-supplied Chat ID cannot prove channel ownership. | Target resolution and destination lookup are separate; Telegram private-chat ID must match its verified provider subject; SMS is rejected. |
| DLG-503 | Return a stable HMAC-derived `challengeRef`, not the database Challenge ID | Needed to bind UI Verify request while reducing direct identifier leakage; ref is not authorization. | Uses OTP HMAC key through purpose-specific HKDF derivation; key rotation invalidates in-flight references. |
| DLG-504 | Reset-only token = 32 CSPRNG bytes, SHA-256 hash in existing challenge row | High-entropy opaque capability should not be stored raw. | 64 lowercase hex raw value shown once; header-only capability, one use. |
| DLG-505 | Reset authorization default TTL is 600 seconds, bounded 60–900 | No explicit duration was found in authoritative project contracts; this is a technical default, not an owner decision. | Separate from the fixed 120-second OTP TTL; configured through `MRNEON_RECOVERY_TOKEN_TTL_SECONDS`. |
| DLG-506 | Password update, token consumption, all registered-session revocation and Recovery Challenge invalidation share one DB transaction | Avoid password update without revocation or reusable token. | Implemented in code; live atomicity still BLOCKED by missing `pdo_mysql`. |
| DLG-507 | Session login is not created after Recovery | OTP proves channel control and the reset-only token only grants a reset operation. | Login remains a separate normal auth action; controller only destroys the current local PHP session if it belongs to the account just reset. |
| DLG-508 | Old JSON-backed and combined OTP+password handlers are fail-closed | Prevent old identifier/userId paths from bypassing the new flow. | Legacy action returns `RECOVERY_FLOW_UPDATED` / HTTP 410. |
| DLG-509 | Generic start result and one-second minimum time floor | Reduce simple account enumeration. | Does not guarantee constant-time behavior because Provider delivery remains synchronous; residual timing risk remains. |
| DLG-510 | Fail closed on `unknown` Provider delivery outcomes | Provider may have accepted a message despite timeout. | Automatic repeat delivery is blocked pending controlled reconciliation; requires operator/runbook handling. |

## Open decisions and Release Risks

- Live DB integration remains unverified by owner deferment; this execution additionally could not run it because `pdo_mysql` was unavailable.
- Synchronous delivery means generic start responses may still have distinguishable timing for known accounts; response status/content remains generic, but timing is not guaranteed constant.
- `unknown` delivery rows intentionally block blind retry. There is no operator reconciliation UI in this scope.
- Real Email/Telegram config and external provider delivery are not verified here.
- Production PHP runtime and production hosting configuration are not confirmed. This code was linted/run under PHP 8.4.24; host compatibility still needs a final runtime check.
- No Terms/Privacy, SMS, registration, Recovery Provider or W4-P6 redesign was added.
