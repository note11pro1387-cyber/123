# Reset-only Authorization — W4-P5

The capability issued after a successful Recovery-purpose OTP is a separate authorization type. It must never be interpreted as a normal session or accepted by login, project, profile, admin or notification endpoints.

- Generator: `ResetTokenGenerator::generate()` uses 32 bytes from `random_bytes()`, represented as 64 lowercase hexadecimal characters (256 bits of random material).
- Persistence: only `SHA-256(rawToken)` is placed in the existing `verification_challenges.recovery_token_hash`; the raw token is returned once through the API response and retained only in the active page's JavaScript memory.
- Binding: the row is `purpose='password_reset'`, `subject_type='user'`, points to the server-resolved user and verified user channel, and is transitioned to `verified_reset` only when the correct context-bound OTP is verified.
- Expiry: `MRNEON_RECOVERY_TOKEN_TTL_SECONDS`; default 600 seconds, allowed 60–900. This TTL is independent of the fixed 120-second OTP lifetime. There is no client-controlled expiry.
- Consumption: conditional SQL update checks token hash, purpose, state, unused marker and expiry, then moves the Challenge to `used` and stamps `recovery_used_at`. Password update and token consumption share the enclosing transaction.
- Replay/cross-account protection: the client supplies no user ID; the token hash resolves its owner inside the database, a per-user mutex serializes reset operations, and conditional consumption can succeed only once.
- Browser exposure: the UI sends the capability using `X-Reset-Authorization`, does not put it in a URL or Web Storage, and clears it on success, flow close or explicit reset-flow clearing. The server returns no-store headers via the central API responder.
- Post-reset rights: `authenticated=false` and `sessionEstablished=false`; login is a separate, explicit operation.

If the token expires, is malformed, has been consumed, or is not associated with an active account, the API returns a generic invalid/expired authorization error. Only its SHA-256 representation exists in storage; logs and Audit metadata contain no token or token hash.
