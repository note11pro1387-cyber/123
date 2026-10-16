# MR NEON Continuation Ledger

Updated: 2026-10-16

## Baseline
- Repository: `note11pro1387-cyber/123`
- Working branch: `recovery/mr-neon-legacy-stabilization`
- Recovery base: legacy `main` commit `ef15058ef75893435bc64a6a1759272af7522f9d`.
- Accepted P1 baseline was not found/verified in the inspected repository state. This branch is legacy stabilization only, not accepted P1 or completed W5-P2.
- `main` was not modified.

## Commits
- `499e658fe5b4964a6826cee387b747b433aeb205`: environment-based server secrets, CORS/TLS changes, JSON persistence helpers and role-aware sessions.
- `eca534f971a84098f1a555b61743116dbcf2030b`: admin login handler, recovery no longer accepts a client-selected target user ID, OTP TTL 120 seconds, removed frontend API-key use, removed NEO local-storage false-success fallback, aligned action names.
- `19095e191e36d8bc47624f7ea1122238b5419459`: authenticated project save, server-generated IDs/tracking codes, customer DTO separation, password-hash filtering, atomic JSON writer and same-origin CORS handling.
- `68025541193f434466052d07d3bb7a24b9770348`: removed remaining frontend credential properties, restricted public verification endpoint, escaped customer project-card output, stopped showing admin notes in customer card, admin mutations now require successful HTTP responses.

## Known limits
- Telegram recovery requires a server-stored numeric `telegramChatId`; legacy accounts may not have one. The legacy registration delivery flow is not yet fully redesigned around verified numeric Telegram chat IDs.
- Email recovery requires `MR_NEON_MAIL_FROM` and a working host mail transport.
- Admin login, Telegram notifications and cross-origin hosting require the corresponding server environment variables to be configured.
- The public repository's earlier Git history may still contain exposed credentials. Rotating/revoking them through their providers remains required; source cleanup does not revoke them.
- JSON storage remains the legacy architecture. DB/Auth integration is not verified.

## Verification
- Static source assertions: limited checks only; not runtime tests.
- PHP syntax: NOT RUN.
- JavaScript syntax: NOT RUN.
- Unit tests: NOT RUN.
- DB/migration/integration tests: NOT RUN; no isolated non-production DB was verified.
- Browser E2E: NOT RUN.
- Live deployment/provider secret rotation: NOT RUN.
- Local clone for syntax validation failed because this environment could not resolve `github.com`.

## Gates — preserve exactly
- `W5-P2 = BLOCKED`
- `W4-P6D = BLOCKED`
- `OWNER-ACCEPTED DEFERMENT — DB/AUTH INTEGRATION NOT VERIFIED`

## Next continuation
1. Read this ledger and the latest branch commit first.
2. Continue static review of registration transport and remaining user-panel output paths.
3. Run PHP/JS syntax checks in a reachable local environment.
4. Do not run DB integration/migrations or deploy until an isolated non-production target and explicit authorization are established.
5. Rotate exposed credentials outside GitHub, then configure server environment variables without committing secret values.
