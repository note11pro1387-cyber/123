# W4-P6A Acceptance Matrix

| Criterion | Status | Evidence / limit |
|---|---|---|
| Action names extracted from merged router and first-party consumers | PASS | `docs/W4-P6A-ACTION-MATRIX.md`; source checker |
| Registration/OTP/Recovery routes use expected POST method | PASS | `MethodPolicy`, controllers, 29 source checks |
| `getSession` uses GET; login/logout use expected methods | PASS | Wave 3 method policy and P6A source checks |
| Browser state-changing Auth/Recovery routes enforce CSRF | PASS (source-level) | Controllers and static checks; no live HTTP runtime test |
| Telegram webhook remains isolated from browser CSRF | PASS (source-level) | Dedicated provider secret header and webhook controller |
| Legacy `verifyPasswordResetCode` fails closed | PASS (source-level) | Controller returns `410 RECOVERY_FLOW_UPDATED`; no first-party JS caller found |
| API client rejects non-JSON/invalid envelopes and HTTP failures | PASS | Existing synthetic API client tests + Wave 3 static checks |
| Compatibility adapter preserves real successful HTTP status | PASS | New synthetic `202` regression assertion |
| Compatibility adapter preserves successful `requestId` | PASS | New synthetic request-correlation assertion |
| Central API client remains same-origin/no-store/no-redirect and no automatic retry | PASS (source-level) | Existing tests and static checks |
| Wave 1–W4-P5 unit/static regression suites | PASS | See `W4-P6A-TEST-REPORT.md` |
| Wave 2 database integration | BLOCKED | `pdo_mysql` unavailable; exit 77; no DB test attempted |
| Wave 3 Auth/database integration | BLOCKED | `pdo_mysql` unavailable; exit 77; no DB test attempted |
| W4-P2 registration DB integration | BLOCKED | `pdo_mysql` unavailable; exit 77 |
| W4-P3 OTP DB integration | BLOCKED | `pdo_mysql` unavailable; exit 77 |
| W4-P4 Telegram DB integration | BLOCKED | `pdo_mysql` unavailable; exit 77 |
| W4-P4 real Email delivery | NOT RUN | Explicit live-email opt-in and owner-controlled inbox absent; no email sent |
| W4-P5 Recovery DB integration | BLOCKED | `pdo_mysql` unavailable; exit 77 |
| Browser E2E across real site/runtime | NOT RUN | No real site/browser/DB/provider environment was available in this execution |
| Full W4-P6 / Wave 4 release | NOT RUN | Only internal part P6A was authorized and executed in this turn |
