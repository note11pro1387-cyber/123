# MR NEON Continuation Ledger

Updated: 2026-10-16

## Baseline
- Repository: `note11pro1387-cyber/123`
- Working branch: `recovery/mr-neon-legacy-stabilization`
- Recovery base: legacy `main` commit `ef15058ef75893435bc64a6a1759272af7522f9d`.
- Accepted P1 baseline was not found/verified in the inspected repository state. This branch is legacy stabilization only, not accepted P1 or completed W5-P2.
- `main` was not modified.

## Commits on the working branch
- `499e658fe5b4964a6826cee387b747b433aeb205`: moved server secrets to environment configuration, tightened CORS/TLS, added role-aware sessions and JSON persistence helpers.
- `eca534f971a84098f1a555b61743116dbcf2030b`: added admin login handler, bound password recovery to its identifier instead of a client-selected user ID, set OTP lifetime to 120 seconds, removed frontend API-key use, removed NEO local-storage false-success fallback, aligned action names.
- `19095e191e36d8bc47624f7ea1122238b5419459`: authenticated project save, server-generated IDs/tracking codes, customer DTO separation, password-hash filtering, atomic JSON writer and same-origin CORS handling.
- `68025541193f434466052d07d3bb7a24b9770348`: removed remaining frontend credential properties, restricted the public verification endpoint, escaped customer project-card output, removed admin-note rendering from the customer card, and made admin mutations depend on successful HTTP responses.
- `14a74dc68e8e03d4bd95f4638571d2fea35b24b1`: created this ledger.
- `ec0a64f7b018cf5076ea7342e8cf5148d50a65d6`: added `data/.htaccess` to deny direct HTTP access to runtime data files on Apache.
- `8205519d1583e8b865ffb51949f35c00d10c420a`: updated this ledger.
- `c6359cfd99ebadac2b505a83242d5b99e42cb3cf`: serialized user project updates/deletes, admin deletes, and notification read mutations through the JSON mutation lock.
- `0e37e047fa6fedce44b69f839f293678f935da23`: corrected email recovery header sanitization.

## Known limits
- Telegram recovery requires a server-stored numeric `telegramChatId`; legacy accounts may not have one. The legacy registration delivery flow is not yet fully redesigned around verified numeric Telegram chat IDs.
- Email recovery requires `MR_NEON_MAIL_FROM` and a working host mail transport.
- Admin login requires `MR_NEON_ADMIN_USERNAME` and `MR_NEON_ADMIN_PASSWORD`; Telegram notifications require `MR_NEON_TELEGRAM_BOT_TOKEN` and `MR_NEON_TELEGRAM_ADMIN_CHAT_ID`; cross-origin hosting requires `MR_NEON_ALLOWED_ORIGINS`.
- The public repository's earlier Git history may still contain exposed credentials. Rotating/revoking them through their providers remains required; source cleanup does not revoke them.
- `data/.htaccess` protects the runtime data path on Apache only; configure equivalent deny rules for Nginx or other hosting stacks.
- JSON remains the legacy storage architecture. This work does not verify DB/Auth integration, and not all legacy direct read/write paths have received runtime concurrency testing.

## Verification
- Limited static source assertions: PASS for checked source-level conditions only. This is not a runtime or security test.
- PHP syntax checks: NOT RUN.
- JavaScript syntax checks: NOT RUN.
- Unit tests: NOT RUN.
- DB/migration/integration tests: NOT RUN; no isolated non-production DB was verified.
- Browser E2E: NOT RUN.
- Live deployment/provider secret rotation: NOT RUN.
- Local clone for syntax validation failed because this environment could not resolve `github.com`.
- Final static spot-check confirmed: latest source has no frontend API-key/admin-credential properties; API uses atomic temp-file replacement and same-origin-aware CORS; customer DTO strips admin notes; project creation requires a user session; TLS peer verification is enabled; admin delete checks the HTTP response.

## Gates — preserve exactly
- `W5-P2 = BLOCKED`
- `W4-P6D = BLOCKED`
- `OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED`

## Next continuation
1. Read this ledger and the latest branch commit first.
2. Continue reviewing registration transport and remaining user-panel/admin output paths.
3. Run PHP/JavaScript syntax checks in a reachable local environment.
4. Do not run DB integration/migrations or deploy until an isolated non-production target and explicit authorization are established.
5. Rotate exposed credentials outside GitHub, then configure server environment variables without committing secret values.
