# MR NEON — Local Setup & Secrets (Wave 1–2)

## Current state

This repository snapshot has not been connected to a Production host. Wave 1 deliberately fails closed for API flows while the secure session/authorization, registration/recovery, and project contracts are implemented in later Waves. A `503` with a stable `code` means the operation is intentionally unavailable; it is not a successful submission.

## Local typography assets (W7-P14)

The project-provenanced Vazirmatn v33.003 WOFF2 family is included locally under `assets/fonts/Vazirmatn/webfonts/`; its existing `@font-face` declarations and page preload paths continue to use these bundled files. The accompanying `assets/fonts/Vazirmatn/OFL.txt` and `AUTHORS.txt` preserve the font license and author notice. No separate font download or manual asset copy is required for Vazirmatn. Latin UI text uses the platform-native system sans-serif stack (`--font-latin`) and does not request a third-party font stylesheet or font file. See `docs/ASSET-LICENSES.md` for font license and provenance details.

## XAMPP / Apache

1. Preserve the original ZIP and work on a separate extracted copy. Do not publish the `data/` folder or its JSON/log files.
2. Place the project under the local XAMPP document root only for development. Keep the original snapshot and runtime-data files backed up separately.
3. Configure `MRNEON_PUBLIC_BASE_URL` and provider variables through the process/Apache environment, not in `config.php`, HTML, JavaScript, a committed `.env`, or browser storage. With XAMPP/mod_php, one option is to put `SetEnv` directives with locally supplied values in the private Apache VirtualHost configuration under the XAMPP installation directory, outside the site's document root. A private PHP config file is not loaded by this application by itself; it is usable only when a host-level bootstrap explicitly exports its values to PHP before requests execute. Do not commit that Apache/private configuration or share screenshots containing values. Restart Apache after environment changes.
4. `.env.example` documents variable names only. **PHP does not load `.env.example` or `.env` automatically**; Wave 1 uses `getenv()` for the variables listed there.
4a. The `MRNEON_ENV=local` and `MRNEON_SESSION_COOKIE_SECURE=0` values in `.env.example` are local-development examples only. Do not copy them unchanged to Production. Production must be served over HTTPS and must set `MRNEON_SESSION_COOKIE_SECURE=1`. Keep the existing `SessionManager` Production guards; do not weaken or bypass them to accommodate an incorrect Host configuration.
5. `MRNEON_PUBLIC_BASE_URL` must be an explicit URL. HTTPS is required except `http://localhost`, `http://127.0.0.1` or `http://[::1]` for local development. If unset/invalid, it resolves to an empty value; request `Host` headers are never used to invent a public base URL.
5a. Public indexability, canonical/Open Graph absolute URLs and Organization schema are enabled only when `MRNEON_PUBLIC_BASE_URL` is a valid explicitly configured HTTPS URL (local HTTP is accepted for development); otherwise the homepage is marked `noindex` and absolute metadata is omitted. `/sitemap.php` returns only the public homepage and remains unavailable until the same variable is configured. On Apache 2.4, enable `mod_rewrite` and allow the `.htaccess` `FileInfo` overrides: `/robots.txt` is then served by `robots.php`, which emits an absolute `Sitemap:` directive only from that verified setting and scopes disallow rules to the configured base path. Keep the static `robots.txt` as fallback when rewrite support is unavailable; Nginx/other servers need an equivalent `/robots.txt` → `robots.php` mapping. Never derive public URLs from `HTTP_HOST`. Organization schema intentionally omits phone, address and social `sameAs` until verified.
6. `MRNEON_TELEGRAM_BOT_TOKEN` and `MRNEON_TELEGRAM_ADMIN_CHAT_ID` remain unset until privately configured. Missing values cause Telegram delivery to fail closed. Do not perform live delivery tests during Wave 1. OTP delivery to a default admin destination is explicitly disabled pending verified account linking in Wave 4.
7. Use Apache 2.4 `Require all denied` rules in `.htaccess` to block direct requests to `config.php`, `data/`, and `core/`. They do not stop PHP's server-side `include`/`require`. Apache must allow the required overrides for these rules to apply. If `AllowOverride` disables them, configure equivalent rules in the VirtualHost instead.
8. Never replace or clear existing `data/*.json` or logs as a setup step. Wave 2 is responsible for data mapping and migration planning on a controlled copy.

## Git and Secret hygiene

- `.gitignore` prevents matching, untracked files from being added accidentally. It does **not** remove files that were already committed or are already tracked. Review tracked paths (for example with `git ls-files`) and the complete available history before any Push or release.
- The CI `secret-scan` job runs the pinned Gitleaks CLI against both the current working tree and all history available from fetched branches/tags. A finding fails that job; output is fully redacted. Do not add broad path exclusions or public allowlists to make a scan green.
- A local ZIP without `.git` cannot prove that prior commits, remote branches/tags, or previously shared ZIP files are clean. Run the History scan from the actual repository and investigate every finding privately.
- Never place Secret values in chat, Issues, screenshots, logs, source files, test fixtures that resemble usable credentials, reports, or Patch ZIPs. Share only masked file/path/line evidence.

## Secret rotation checklist (manual; not performed by this Patch)

- Revoke/regenerate the previously exposed Telegram Bot Token through the official Telegram bot-management flow. Store the replacement only in the private environment configuration, then remove the old value from every private configuration location where it remains.
- Independently rotate the previously exposed administrator credential outside this repository. Do not assume rotating the Bot Token also rotates the administrator credential.
- Rotate SMTP/API credentials only when evidence indicates that those credentials were exposed. Store replacements only in the private environment configuration.
- Review previous Git remotes, all available branches/tags/commit history, access logs, and previously shared ZIPs privately. Removing a value from the current file does not remove it from old Git history or already distributed archives. Rotate first; history rewriting/cleanup requires a separately reviewed owner-approved plan.
- These are Owner/Provider operations. Source code, a green CI job, and this document cannot prove that a Provider credential was actually revoked or replaced.

## Private storage, Project Root and Document Root

- **Project Root** is the extracted application directory containing `config.php`, `api.php`, `app/`, `core/`, and `data/`. **Document Root** is the directory the web server actually exposes over HTTP. They may be the same directory or different directories; do not assume their relationship without checking the Host's VirtualHost/server configuration.
- `app/support/PrivateStorage.php` validates that its configured storage path is absolute and outside `MRNEON_PROJECT_ROOT`. That source-level guard does **not** prove that the selected path is outside the Host's actual Document Root. The owner/operator must set `MRNEON_BACKUP_DIR` to a host-specific absolute path and verify that it is outside both the application Project Root and the actual Document Root, with restrictive filesystem ownership/permissions. The portable `.env.example` intentionally leaves `MRNEON_BACKUP_DIR` empty.
- The legacy `DATA_DIR` in `config.php` still points inside the project (`project-root/data`). Do not delete, clear, or move existing JSON, Session, Log, or other operational data as a setup step. Legacy call sites and any migration/backup implications must be assessed separately before changing that path.
- On Apache, `.htaccess` rules apply only when the server configuration permits the required overrides. The current rules use rewrite and authorization directives; allow the necessary `FileInfo` and `AuthConfig` overrides (or the appropriately scoped `AllowOverride All`) for the relevant directory, or place equivalent rules in the VirtualHost configuration. Verify the effective configuration on the actual Host.
- Nginx does not read `.htaccess`. When using Nginx, equivalent deny/location rules must be configured in the Nginx server configuration. The presence of `.htaccess` in the project is not evidence that Nginx blocks private paths.

## Wave 2 database foundation

Wave 2 proposes MariaDB/MySQL via PDO for the current XAMPP development target; Production engine compatibility remains open until the host is selected. Conservative SQL compatibility is MariaDB 10.6+ or MySQL 8.0.16+. Confirm the actual XAMPP MariaDB version and that PHP has `pdo_mysql` before using database commands.

Set private process/Apache values for `MRNEON_DB_HOST`, `MRNEON_DB_PORT`, `MRNEON_DB_NAME`, `MRNEON_DB_USER`, `MRNEON_DB_PASSWORD`; set `MRNEON_RATE_LIMIT_HMAC_KEY` to a privately generated random key of 32+ bytes; and `MRNEON_BACKUP_DIR` to a private absolute directory outside the application directory. `.env.example` documents names only and is not loaded by PHP. Empty DB passwords are permitted only by explicit opt-in on a loopback host.

After the configured database identity has been checked, `php bin/migrate.php status` is read-only and `php bin/migrate.php apply` creates the versioned schema. `php bin/migrate-json.php` is dry-run by default and supports only the two persistent user records that are structurally valid in the supplied snapshot; do not add `--apply` until the report, target test DB, and missing-source implications have been reviewed. Old OTPs, sessions, rate-limit records, pending drafts and logs are not imported. Original JSON files remain untouched.

See `DATA-MODEL.md`, `DATA-MIGRATION.md`, `DATABASE-OPERATIONS.md`, and `BACKUP-RESTORE.md` for actual table contracts and operations. No migration/backup/restore command has been proven against the current environment because the execution container lacks `pdo_mysql` and a MariaDB/MySQL server.

## Expired-session maintenance (owner-controlled)

`bin/cleanup-expired-sessions.php` is a CLI-only maintenance tool. It never chooses a retention period automatically: the owner must select a UTC creation-time cutoff after reviewing the Admin session-history requirement. A row is eligible only when `expires_at` is already in the past, `created_at` is at or before the chosen cutoff, and it is outside the 20 most recent session-history rows for its principal (the same history window returned by the Admin user-detail endpoint). Active sessions and the history window displayed by the UI are never selected. Each `--apply` invocation deletes at most one bounded batch (1–500 rows); rerunning is an explicit separate action. The cutoff is an explicit owner decision, not a built-in retention duration.

Start with the no-change preview:

```sh
php bin/cleanup-expired-sessions.php
php bin/cleanup-expired-sessions.php --before-utc=YYYY-MM-DDTHH:MM:SSZ --batch=100
```

The timestamp above is a placeholder, not a recommended retention value. Choose a real UTC cutoff only after the owner has approved how much session history the Admin UI must retain. Review the count before any deletion. To apply one batch, require all three owner-controlled gates: `--apply`, the exact `--confirm=PURGE-EXPIRED-SESSIONS:<same-cutoff>` phrase, and the private operator environment variable `MRNEON_ALLOW_SESSION_CLEANUP=1`. For Bash, set that flag only for the single approved invocation; for PowerShell, set `$env:MRNEON_ALLOW_SESSION_CLEANUP = '1'` only after approval. Do not run this tool against Production until the retention policy, backup and target database have been independently approved. This Patch does not run cleanup or choose a retention period.

## CSP Report-Only preparation (W7-P4)

`MRNEON_CSP_REPORT_ONLY` defaults to `0`. The centralized HTML security-header helper emits the CSP observation policy only when the private environment explicitly sets both `MRNEON_ENV=staging` and `MRNEON_CSP_REPORT_ONLY=1`. It never emits an enforcing CSP. `MRNEON_CSP_REPORT_URI` is optional and must be an approved absolute HTTPS collector URL with no query or fragment. Without a collector, violations are visible only through browser developer tools; reports are not centrally stored by this codebase. Read `CSP-ROLLOUT.md` before enabling staged observation. Do not set these values in production as a substitute for the separate CSP rollout and QA decision.
