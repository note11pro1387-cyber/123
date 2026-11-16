# MR NEON — Security & Deployment Boundaries (Wave 1–2)

## Confirmed in this Patch

- No static API key, Admin password, or Telegram token is defined in `config.php` or public JavaScript. Runtime provider settings are read from environment variables.
- The API no longer reflects arbitrary `Origin` values and does not emit credentialed CORS headers. Same-origin is the default. Origin/CORS is not authentication.
- All known API handler actions return a specific `503` until the server-side authorization/domain contracts of their owning Wave are implemented. The API key gate was not removed in favor of a weaker browser token; the handlers are explicitly fail-closed.
- Telegram cURL and stream transports require certificate-chain and hostname verification, fixed HTTPS, and bounded timeouts. Transport diagnostics avoid raw URLs and payloads. OTP-to-admin delivery is disabled until Wave 4 links verified Telegram identities.
- Root, `data/`, `core/`, `app/`, `database/`, `bin/`, `tests/` and `docs/` Apache rules block direct HTTP access when Apache honors these `.htaccess` files. PHP includes and CLI entry points remain available as designed.

## Environment that remains unverified

The working environment available for this Patch does not include a running XAMPP/Apache server, a GitHub remote, production hosting, SMTP credentials, or an approved Telegram live-test window. Therefore HTTP denial, GitHub Actions execution, TLS to the real provider, and live delivery are **NOT RUN**. A static rule is not proof that the actual server honors it.

## Nginx / production equivalents

`.htaccess` is Apache-only. For Nginx, merge equivalent deny rules into the actual `server` block after reviewing intended public routes:

```nginx
location ~* ^/(?:\.git|\.github|app|bin|core|data|database|docs|reports|tests|tools)(?:/|$) { return 404; }
location ~* ^/(?:config(?:\.[^.]+)?\.php|\.env(?:\..*)?|composer\.(?:json|lock)|phpunit\.xml(?:\.dist)?|.*\.(?:md|markdown|py|csv|sha256|sql|log|sqlite|db|bak|backup|dump|key|p12|pfx|jks|keystore))$ { return 404; }
```

Keep public `assets/`, `css/`, `js/`, intended component fragments, fonts and images reachable. Do not broadly deny all `.pem` files because public CA certificates may be required; place private backup/config roots outside the public tree instead. Verify effective Nginx configuration with `nginx -T` and test representative denied and allowed paths over HTTP before release.

For Apache, the root `.htaccess` denies internal project directories and sensitive source/manifest/database files only when Apache honors the relevant directives through `AllowOverride`. Inspect the effective VirtualHost/Directory configuration; file presence alone does not prove enforcement.

`PrivateStorage` canonicalizes paths, rejects paths inside `MRNEON_PROJECT_ROOT`, and rejects existing symlink path components. This is defense in depth, not a race-free filesystem sandbox: TOCTOU races, bind mounts, Windows junction/reparse behavior and concurrent symlink replacement remain host/OS concerns. The code does not infer actual `DocumentRoot` from CLI `$_SERVER`. The owner must set `MRNEON_BACKUP_DIR` outside both actual DocumentRoot and Project Root, then verify permissions and HTTP denial on the target host. Status until that evidence exists: `BLOCKED_PENDING_HOST_VERIFICATION`.

## Release blockers / next waves

- Wave 2: choose DB/storage only after host/environment inspection; identify the missing source sets without inventing them; define backup/restore/dry-run migration gates.
- Wave 3: replace the old token/local-storage approach with secure server-side sessions, role-specific authorization, CSRF controls, Admin MFA, and tests; then remove only the relevant fail-closed gates after acceptance tests pass.
- Wave 4: registration/OTP/Recovery and verified Telegram account linking; Email remains disabled until a configured provider passes real Staging send/receive checks.
- Wave 5: project persistence, state transitions, internal/customer messaging separation, event/outbox/idempotency.
- Wave 8: mandatory independent security/regression and deployment gates. No Production deployment is authorized by this Patch.


## Wave 2 database safeguards

- PDO is configured for exception mode, associative fetches, native prepared statements and UTC database session time. DB credentials are process/private-environment values; there is no `.env` parser.
- Remote DB connections require a readable CA path and server-certificate verification by default. Empty password opt-in is refused for non-loopback hosts.
- Rate-limit bucket identities are stored as HMAC-SHA-256 with a separate private `MRNEON_RATE_LIMIT_HMAC_KEY`; the key must be at least 32 bytes and is not stored in DB.
- Backups must be written outside the application directory and include a checksum sidecar. Restore requires a matching migration ledger, checksum-valid backup, explicit empty target, and a test/restore/sandbox DB name unless separately authorized.
- A successful Wave 2 schema/import/backup contract has **not** been verified against a live DB in this execution environment. Production deployment remains blocked until DB/runtime versions, hosting paths, secure DB credentials/TLS, a successful independent restore test and Release Gates are established.
