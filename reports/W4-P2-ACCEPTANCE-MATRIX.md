# W4-P2 Acceptance Matrix

**Overall:** `PASS WITH NOTES` — all checks available in this runtime passed; DB-backed Acceptance remains unverified by owner-approved deferment.

| ID | Acceptance criterion | Evidence/check | Result |
|---|---|---|---|
| A01 | Strict registration DTO allowlist; privileged/unknown fields rejected | `tests/Unit/run-registration-tests.php` | PASS |
| A02 | Required values/types/null and consent validated | W4-P2 unit suite | PASS |
| A03 | Username format + lowercase normalization | W4-P2 unit suite | PASS |
| A04 | Phone canonicalization includes Persian/Arabic numerals, local/country formats and strict rejection | W4-P2 unit suite | PASS |
| A05 | Email optional only for Telegram; Email channel requires valid email | W4-P2 unit suite | PASS |
| A06 | Exactly one allowlisted Telegram/Email channel; SMS/unknown/both rejected | W4-P2 unit suite | PASS |
| A07 | Central 15-character password policy; hashing and no raw-password serialization | W4-P2 unit suite | PASS |
| A08 | Server-owned Terms version fails closed when missing; bounded separate Attempt TTL | W4-P2 unit suite | PASS |
| A09 | API controller performs POST-only, anonymous session CSRF and size-bounded JSON parsing | PHP lint + static source inspection; no HTTP DB-backed server test | PASS WITH NOTES |
| A10 | Registration action routed to the Attempt-only controller; verification/recovery stay closed | Wave 1/Wave 3 static checks | PASS |
| A11 | DB-backed registration IP/identity rate limits use existing Wave 2 keyed-HMAC buckets | Static checks + source inspection | PASS WITH NOTES; DB unverified |
| A12 | Transaction/rollback, duplicate resolution, named-lock behavior and actual Attempt writes | `tests/Integration/run-registration-attempt-integration.php` | BLOCKED, exit 77 (`pdo_mysql` unavailable) |
| A13 | No user/channel/challenge/OTP created; only pending Attempt if DB action succeeds | Unit contract + integration runner assertions prepared; DB action not run | BLOCKED for runtime claim |
| A14 | Password hash + Telegram nonce digest only stored; no raw credentials in logs/audit | Source/unit checks; DB assertions prepared | PASS WITH NOTES; DB unverified |
| A15 | Existing Wave 1 static contracts | `python tools/wave1_static_checks.py` | PASS, exit 0 |
| A16 | Wave 2 static contracts, no Migration alteration | `python tools/wave2_static_checks.py` | PASS, exit 0 (94 checks) |
| A17 | Wave 3 static contracts | `python tools/wave3_static_checks.py` | PASS, exit 0 (34 checks) |
| A18 | Existing Wave 1 unit contracts | `php tests/Unit/run-unit.php` | PASS, exit 0 (62 checks) |
| A19 | Existing Wave 3 unit/security contracts | `php tests/Unit/run-wave3-unit.php` | PASS, exit 0 (20 checks) |
| A20 | Central API Client compatibility tests | `node tests/Unit/run-api-client-tests.mjs` | PASS, exit 0 (14 assertions) |
| A21 | PHP syntax of all added/changed PHP files | `php -l` per file | PASS, exit 0 for all checked files |
| A22 | JS syntax for edited registration consumer | `node --input-type=module --check < js/auth.js` | PASS, exit 0 |
| A23 | No Wave 2 Migration, Schema, OTP sender, Telegram/Email transport or SMS path added | changed-file audit + static checks | PASS |
| A24 | Windows/XAMPP 7.4.25 behavior and supported-host runtime compatibility | not available in current environment; source uses PHP 8.1+ features | NOT RUN / RELEASE RISK |
| A25 | W4-P2-specific static safety/contract scan | `python tools/wave4_p2_static_checks.py` — 39 assertions | PASS, exit 0 |

`PASS WITH NOTES` is used instead of `PASS` because no actual MySQL integration, API-through-HTTP CSRF roundtrip, or Windows/XAMPP test was run in this environment. No DB-backed test is represented as passed.
