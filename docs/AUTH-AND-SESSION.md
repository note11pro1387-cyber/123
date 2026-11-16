# MR NEON Authentication and Session Policy — Wave 3

**Status:** Implemented in source and covered by unit/local-HTTP tests. Database-backed login/session/MFA integration remains unverified in this runtime because `pdo_mysql`/MariaDB are unavailable. Do not treat this document as a production-readiness approval.

## Trusted identity

- Identity comes only from the server's PHP session plus the matching active row in Wave 2 `auth_sessions`.
- Browser-supplied `userId`, role, `isAdmin`, Local Storage values and bearer/API-key headers are not identity.
- User identity is reloaded from `users` on each authenticated request and the account must remain `active`.
- A customer session is not accepted by admin policy. User and admin principals have distinct `principal_type` values; no user-to-admin login fallback exists.

## Customer login

1. `POST api.php?action=login` must be JSON and carry the current session's CSRF token.
2. Username is normalized/validated against the existing supported username format; fields outside the allowlist are rejected.
3. A dummy password hash is checked for unknown/invalid username formats to reduce simple timing/account-enumeration differences. Error detail is generic.
4. `password_verify()` validates the stored password hash and only `account_status=active` is accepted.
5. DB-backed IP and account rate buckets are checked before password verification. If DB/limiter configuration is unavailable, login fails closed.
6. `password_needs_rehash()` is respected and the successful-login record plus auth-session registry insert run in a database transaction.
7. On success the session ID and CSRF token rotate. The response contains an allowlisted `UserDTO`, never the password hash or internal account status.

The new-account password policy is not applied retroactively to existing accounts by login. Wave 4 must implement the Handoff's new-account policy; the different password length suggestions in old collateral must not silently break existing logins.

## Independent administrator login

- `POST api.php?action=adminLogin` is separate from customer login and requires a private server-configured username, a password hash, and a six-digit TOTP.
- All three administrator settings are read only from process environment/private server configuration. The example configuration contains blank placeholders only.
- Successful admin sessions have `principal_type=admin`, a null customer `user_id`, and a server-side credential fingerprint so changes to the configured admin credentials invalidate existing sessions on revalidation.
- A successful TOTP counter is consumed through the Wave 2 DB rate-bucket table to reject replay. This depends on MariaDB's atomic upsert behavior and must pass the included DB integration suite before release.
- The current model supports one environment-configured admin identity. Whether multiple admins and their lifecycle belong in a dedicated DB table remains OPEN; do not assume that environment-only model satisfies future multi-admin needs.
- TOTP recovery codes are not yet implemented/persisted. This is a **Production release blocker** under Master Handoff and must be resolved before Production.

## Session lifecycle

- Cookie name: `MRNEONSESSID`; HttpOnly, SameSite=Lax, cookie-only IDs, strict mode, and URL session IDs disabled.
- Local XAMPP may use HTTP with `MRNEON_SESSION_COOKIE_SECURE=0`. Production must use HTTPS and Secure cookies; the code rejects an explicit attempt to disable Secure cookies in Production and does not trust `X-Forwarded-Proto` without a trusted-proxy contract.
- Default idle timeout: 1800 seconds. Default absolute timeout: 86400 seconds. Both are bounded and configurable with `MRNEON_SESSION_IDLE_TIMEOUT` and `MRNEON_SESSION_ABSOLUTE_TIMEOUT`.
- The PHP session file carries compact identity metadata. Wave 2 `auth_sessions` carries SHA-256 of the session identifier, principal metadata, timestamps, expiry and revocation state; raw session IDs are never persisted in that table.
- Every protected request revalidates registry state and user account state. Expiry, suspension or credential fingerprint changes invalidate the local session. Logout revokes the registry row; if DB revocation cannot be confirmed, the local cookie/session is destroyed but the API returns `503 SESSION_REVOCATION_UNCONFIRMED` rather than claiming confirmed logout.
- Session registry uses DB time in UTC; UI receives Unix expiry and formats locally.

## CSRF and request methods

- CSRF tokens are CSPRNG-generated 32-byte random values represented as 64 lowercase hex characters and bound to the PHP session.
- All POST/PATCH/PUT/DELETE actions marked state-changing require `X-CSRF-Token`; comparisons use `hash_equals()`.
- `Sec-Fetch-Site: cross-site` and a mismatched `Origin` are rejected as supplemental signals. These signals do not replace the CSRF token.
- GET/HEAD handlers in the route inventory are read-only. Mutations must not be moved to GET.
- Tokens are not placed in URLs, logs or browser storage. The browser obtains the token from the same-origin `getSession` response and sends it centrally.

## Rate limiting

- Wave 2 `rate_limit_buckets` is reused; no parallel in-memory limiter is created for active login. The public `getSession` bootstrap is also limited by remote IP (default 60 requests per 60 seconds). Anonymous logout churn is limited to 30 per IP per 60 seconds; an authenticated user is never prevented from logging out by a rate limit. If the DB limiter is unavailable, session bootstrap returns a safe 503 rather than issuing an authoritative-looking session/CSRF response.
- Default user login: 20 attempts per IP and 8 per syntactically valid normalized account per 300 seconds. Default admin login: 8 per IP and 5 per configured admin username per 300 seconds. Public session status defaults to 60 per IP per 60 seconds; repeated anonymous logout defaults to 30 per IP per 60 seconds. Limits are centrally configurable within hard bounds. Arbitrary invalid admin usernames do not create separate account buckets.
- IP attribution uses `REMOTE_ADDR`; forwarded headers are ignored until an explicit trusted-proxy list is approved.
- Exceeding a limit returns `429 RATE_LIMITED` and `Retry-After`. DB failure prevents login from proceeding without a limiter.

## Required production setup

Provide these values only through a private server environment/configuration mechanism; never put real values in this repository, chat, screenshots or Patch artifacts:

- `MRNEON_DB_*` settings for the chosen production database and verified TLS where required;
- `MRNEON_RATE_LIMIT_HMAC_KEY` with at least 32 bytes of high-entropy secret material;
- `MRNEON_ENV=production`, `MRNEON_SESSION_COOKIE_SECURE=1` and approved timeout values;
- `MRNEON_ADMIN_USERNAME`, `MRNEON_ADMIN_PASSWORD_HASH`, and `MRNEON_ADMIN_TOTP_SECRET`.

`.env.example` documents variable names only and PHP does **not** automatically load it. The real values must be injected into the PHP process by Apache/XAMPP configuration or another reviewed private configuration mechanism. Configure separate synthetic credentials in CI/test environments.
