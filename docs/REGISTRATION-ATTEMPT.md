# Registration Attempt Contract — W4-P2

**Status:** W4-P2 implementation contract. Pending real database Integration; no OTP/transport behavior is implemented here.

## Endpoint

- **Method/action:** `POST /api.php?action=register`
- **Authentication:** Public registration; never assumes an authenticated user.
- **CSRF:** Existing anonymous-session CSRF and same-origin validation are mandatory.
- **Request body:** JSON object, at most 16 KiB.
- **Rate limits:** DB-backed IP bucket before field validation, then canonical username/phone/email/channel-destination buckets. Bucket identities are keyed-HMAC in `rate_limit_buckets`; raw identities are not persisted there.
- **Content types:** `application/json` request; Wave 3 `data/meta.requestId` success envelope or RFC 9457 problem response.
- **HTTP result:** `201` for a newly committed Attempt; `200` only for a safely recognized identical active request reusing its Attempt. The browser compatibility adapter exposes `{success, data}` to the legacy consumer.

## Request DTO allowlist

Required keys:

| Field | Type | Contract |
|---|---|---|
| `fullName` | string | Trimmed, UTF-8, 3–160 Unicode characters, no control characters |
| `username` | string | 4–20 ASCII letters/digits/underscore; edge whitespace is trimmed; comparison uses lowercase canonical form |
| `phone` | string | Required Iranian mobile; normalized to `+989` followed by nine digits |
| `password` | string | Server-only raw input used for hashing; minimum 15 Unicode characters; maximum 1024 bytes; never serialized/logged |
| `selectedChannel` | string | Exactly `telegram` or `email`; SMS and any other value are rejected |
| `termsAccepted` | boolean | Must be boolean `true`; strings such as `"true"` are invalid |

Optional key:

| Field | Type | Contract |
|---|---|---|
| `email` | string or null | Empty/null becomes absent. Otherwise validated and lowercased. Required when `selectedChannel=email`; optional for Telegram. |

All unknown fields are rejected. In particular, `role`, `userId`, `isAdmin`, `isVerified`, `selectedChannels`, `telegramId`, and client-supplied Terms versions are not accepted. A Telegram username is not proof of Telegram destination ownership and is not used as a verification identity.

## Canonicalization

- Username canonical form: ASCII lowercase.
- Phone canonical form: `+989xxxxxxxxx`. Persian/Arabic numerals are translated; accepted display separators are removed only after strict character checking. Letters or unrelated punctuation cause rejection instead of being silently stripped.
- Email canonical form: lowercase, in accordance with Wave 2 `UserChannelRepository` normalization. No provider-specific rewriting such as Gmail dot/plus rewriting is done.
- Repository duplicate checks and stored attempt fields use the same canonical values.

## Password policy and hashing

`PasswordPolicy` is the sole server-owned policy for new registration. Minimum 15 Unicode characters, max 1024 bytes, valid UTF-8 and no ASCII control characters, including line breaks. No trimming or truncation occurs. Argon2id is selected when available; otherwise the standard PHP password algorithm is used, and a runtime using bcrypt fallback rejects values over 72 bytes to avoid silent truncation. Existing account login policy/verification is untouched by this part.

## Server-owned Terms and lifetime

- `MRNEON_TERMS_VERSION` must contain the server-owned approved version. Missing/malformed value returns `503 TERMS_POLICY_UNAVAILABLE` before Attempt commit. No Terms version is accepted from the request.
- The final Terms/Privacy content/version remains an external decision. Until approved, keep the environment setting blank; new registration remains fail-closed.
- `MRNEON_REGISTRATION_ATTEMPT_TTL_SECONDS` defaults to a provisional 1800 seconds and is bounded to 300–86400. This expiry is **not** the 120-second OTP TTL; Challenge creation belongs to a later part.

## Persistence and duplicate behavior

1. `RegistrationService` enforces DB-backed rate limits and canonicalizes the request via `RegistrationDTO`.
2. It hashes the password before persistence and creates a 256-bit random Telegram link nonce only if Telegram was chosen. The DB receives only SHA-256 of that nonce.
3. It acquires MySQL named locks for canonical username/phone/email in deterministic order, then owns a PDO transaction.
4. It checks existing `users` identity uniqueness, existing email-channel uniqueness where email was supplied, and a live matching `registration_attempts` record.
5. A request that exactly matches a live Attempt (same normalized identities, full name, channel, server Terms version and verified password) reuses its ID. Telegram retry rotates the nonce hash and returns only the new raw nonce. Any other live collision returns a generic `409 REGISTRATION_CONFLICT`.
6. New Attempt fields are written via `RegistrationAttemptRepository::create`; a safe audit event is appended in the same transaction. Only after commit does the controller return success.
7. Duplicate-key SQLSTATE `23000` is translated to the same generic conflict. Unexpected persistence errors roll back and return a safe 503 without reflecting SQL/credentials/payload.

The existing `users` Unique Constraints remain authoritative. Pre-checks are not their replacement. Active-attempt query fields are not unique indexes; named locks reduce races among callers of this service. W4-P3 completion must coordinate with those locks and still treat the final `users` unique constraints as the final arbiter.

## State and privacy guarantees

- State: `pending_verification`; `verificationStatus: not_started`.
- No `users` row or `user_channels` row is created here.
- No `verification_challenges` row, OTP, Email send, Telegram send, webhook, or SMS action is performed.
- Phone/email/channel are not marked verified.
- Raw password is never part of DTO serialization, response or audit metadata; only its password hash is stored in `registration_attempts.password_hash`.
- Raw Telegram link nonce is returned only once in the success body for the future Telegram link flow; only its digest is persisted. It is not an OTP and does not prove Telegram ownership until the later server-verified flow succeeds.
- Audit metadata includes channel and created/reused outcome only, not phone, email, password, nonce or raw IP.

## Error codes in this part

| Code | HTTP | Meaning |
|---|---:|---|
| `UNEXPECTED_REGISTRATION_FIELDS` | 422 | Unapproved/privileged field in request |
| `REGISTRATION_FIELD_REQUIRED` | 422 | Required DTO field missing |
| `REGISTRATION_FIELD_TYPE_INVALID` | 422 | Required field has incorrect type |
| `TERMS_ACCEPTANCE_REQUIRED` | 422 | Consent must be boolean true |
| `FULL_NAME_INVALID` | 422 | Name outside allowed UTF-8/control/length constraints |
| `USERNAME_INVALID` | 422 | Username fails format/length rules |
| `PHONE_INVALID` | 422 | Phone not a recognized canonicalizable Iranian mobile |
| `EMAIL_INVALID` | 422 | Email invalid or of the wrong type |
| `EMAIL_REQUIRED_FOR_CHANNEL` | 422 | Email selected with missing/invalid destination |
| `VERIFICATION_CHANNEL_INVALID` | 422 | SMS, unknown, or malformed channel selection |
| `PASSWORD_POLICY_VIOLATION` | 422 | New password fails the central rule |
| `PASSWORD_TOO_LONG_FOR_RUNTIME` | 422 | Password exceeds the safe byte bound for the active fallback hash |
| `REGISTRATION_DESTINATION_REQUIRED` | 422 | Required bucket destination could not be resolved |
| `RATE_LIMITED` | 429 | A registration bucket has exceeded configured policy |
| `REGISTRATION_REQUEST_IN_PROGRESS` | 409 | A named identity lock could not be acquired within the short wait |
| `REGISTRATION_CONFLICT` | 409 | Existing identity or incompatible active Attempt collision, without identifying the field |
| `TERMS_POLICY_UNAVAILABLE` | 503 | Server-owned Terms version not configured |
| `REGISTRATION_UNAVAILABLE` | 503 | Unexpected DB/config/service failure; no success is asserted |
| `CSRF_VALIDATION_FAILED` / `ORIGIN_REJECTED` | 403 | Existing Wave 3 CSRF/origin policy rejected the request |
| `METHOD_NOT_ALLOWED` | 405 | Non-POST method |
| `UNSUPPORTED_MEDIA_TYPE` / `REQUEST_TOO_LARGE` | 415 / 413 | Incorrect request media or excessive JSON body |

## Environment settings

- `MRNEON_TERMS_VERSION` — leave blank until the legal version is approved.
- `MRNEON_REGISTRATION_ATTEMPT_TTL_SECONDS` — provisional 1800; 300–86400.
- `MRNEON_REGISTRATION_WINDOW_SECONDS` — default 3600 seconds.
- `MRNEON_REGISTRATION_IP_LIMIT` — default 10 attempts/window.
- `MRNEON_REGISTRATION_USERNAME_LIMIT`, `MRNEON_REGISTRATION_PHONE_LIMIT`, `MRNEON_REGISTRATION_DESTINATION_LIMIT` — default 5 attempts/window each.
- `MRNEON_RATE_LIMIT_HMAC_KEY` — required existing Wave 2 key, at least 32 bytes; keep secret and do not add it to any patch/log.

## W4-P3 boundary

W4-P3 may create/use verification Challenges only after validating the persisted Attempt and the chosen channel. It must not accept an attempt ID as proof of identity by itself. It must validate Telegram link nonce with constant-time digest comparison, bind Telegram provider subject/chat IDs only from a trusted provider update, and keep nonce/OTP delivery out of this part. Email transport and Challenge expiry policy must be explicit. The 120-second Challenge expiry belongs to Challenge creation, not to this 1800-second provisional Attempt lifetime.
