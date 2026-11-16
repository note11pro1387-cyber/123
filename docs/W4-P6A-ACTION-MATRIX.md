# MR NEON — W4-P6A Action/API Contract Matrix

**Baseline:** original project archive overlaid in order with accepted Wave 1, Wave 2, Wave 3, W4-P2, W4-P3, W4-P4 and W4-P5 patches.  
**Scope:** Registration, registration verification, Telegram linking, Recovery, Reset-only authorization, and session entry points. This is a source-derived contract inventory, not a claim of live HTTP/DB/provider verification.

## Shared API contract

- Browser API calls are same-origin and routed through `js/api-client.js`; `apiFetch` remains a temporary compatibility adapter for legacy consumers.
- Successful API envelope: `{ data, meta: { requestId } }`, with the server's actual HTTP status.
- API problem envelope: `application/problem+json` with `status`, `code`, `title`, `detail`, and `requestId`.
- Mutating browser requests use POST and the Wave 3 session-bound `X-CSRF-Token` contract. The Telegram webhook is the deliberate exception: it authenticates using Telegram's configured secret header and does not use browser CSRF/session.
- No automatic mutation retry is performed. Network timeout/unknown delivery is not success.
- The `apiFetch` adapter translates the shared contract to the `{ success, data/error, meta }` shape expected by current domain consumers. W4-P6A fixes it to preserve the real successful HTTP status and request ID instead of synthesizing `200` and `null`.

## Action matrix

| Action | Method / route | Auth / CSRF | Request contract | Success contract observed in source | Consumer / state |
|---|---|---|---|---|---|
| `getSession` | `GET api.php?action=getSession` | Session check; no mutation CSRF | No body | Shared `{data, meta}`; session state and fresh CSRF token as provided by server | `AuthSystem.restoreSession`; central `getServerSession` |
| `login` | `POST api.php?action=login` | Anonymous/session CSRF via central client | `{username, password}` | Shared envelope; `AuthService` determines authenticated state | `AuthSystem.login` via `apiRequest` |
| `logout` | `POST api.php?action=logout` | Session-bound CSRF | `{}` | Shared envelope; server invalidates session | `AuthSystem.logout` via `apiRequest` |
| `register` | `POST api.php?action=register` | Anonymous-session CSRF | `RegistrationDTO`: `fullName`, `username`, `phone`, `password`, optional `email`, `selectedChannel` (`email` or `telegram`), `termsAccepted:true`; unknown fields rejected | `201` for new attempt or `200` for reused attempt; `attemptId`, `state=pending_verification`, `verificationStatus=not_started`, selected channel, and Telegram-link status/URL when configured | `RegistrationSystem` in `js/auth.js` via `apiFetch` |
| `telegramLinkStatus` | `POST api.php?action=telegramLinkStatus` | Anonymous-session CSRF; attempt-scoped lookup | `{attemptId}` | Shared envelope `{linked:boolean}`; does not expose provider identifiers | `RegistrationSystem` via `apiFetch` |
| `sendVerificationCode` | `POST api.php?action=sendVerificationCode` | Anonymous-session CSRF; attempt-scoped | `{attemptId}` | `202` when delivery attempt is new, `200` when reusing active challenge; active challenge ID, channel, `ttlSeconds=120`, status fields | `RegistrationSystem` via `apiFetch` |
| `resendVerificationCode` | `POST api.php?action=resendVerificationCode` | Anonymous-session CSRF; attempt-scoped | `{attemptId}` | Same active-challenge contract; server cooldown and delivery reconciliation apply | `RegistrationSystem` via `apiFetch` |
| `verifyRegisterCode` | `POST api.php?action=verifyRegisterCode` | Anonymous-session CSRF; attempt + challenge bound | `{attemptId, challengeId, code}` | `201` only after server verification/completion; `registered=true`, `authenticated=false`, `sessionEstablished=false` | `RegistrationSystem` via `apiFetch` |
| `forgotPassword` | `POST api.php?action=forgotPassword` | Anonymous-session CSRF | `{identifier, method}`; method exactly `email` or `telegram`; no client `userId` | `202`; generic message, pseudonymous `challengeRef`, `ttlSeconds=120`; does not disclose account/channel/destination | `ForgotPasswordSystem` via `apiFetch` |
| `verifyResetCode` | `POST api.php?action=verifyResetCode` | Anonymous-session CSRF | `{identifier, method, challengeRef, code}` | Shared envelope with one-time `resetAuthorizationToken`, `expiresInSeconds`, `passwordResetAllowed=true` only after valid Recovery proof | `ForgotPasswordSystem` via `apiFetch`; raw token held in page memory |
| `resetPassword` | `POST api.php?action=resetPassword` | Anonymous/session CSRF **and** `X-Reset-Authorization` | `{newPassword}` | Shared envelope with `passwordReset=true`, `authenticated=false`, `sessionEstablished=false` after commit | `ForgotPasswordSystem` via `apiFetch`; no automatic login |
| `verifyPasswordResetCode` | `POST api.php?action=verifyPasswordResetCode` | CSRF check then fail-closed legacy handler | Legacy combined OTP/password contract is not accepted | HTTP `410 RECOVERY_FLOW_UPDATED`; no current JS consumer found | Compatibility closure only; keep fail-closed until release regression confirms no external caller |
| `telegramWebhook` | `POST api.php?action=telegramWebhook` | Telegram secret header; no browser CSRF/session | Bounded Telegram Update JSON | Provider-specific `{"ok":true}` or non-2xx/false; intentionally not the browser API envelope | Telegram server-to-server webhook, not a browser consumer |

## Findings and decisions

1. The active registration and Recovery JS actions match the active router/controller names found in the merged baseline. The old `verifyPasswordResetCode` name remains registered for method policy and controller routing only so it can return a controlled `410`; no first-party JS caller was found.
2. `js/api-client.js` is the single transport path used by first-party JS. Auth and Recovery still use its documented compatibility adapter; direct adoption of `apiRequest` by every domain consumer is intentionally deferred to W4-P6B/C to avoid mixing UI work into this contract slice.
3. The compatibility adapter previously returned `status: 200` for every successful response and `meta.requestId: null`, losing server `201/202` semantics and request correlation. W4-P6A corrects this and adds a synthetic regression assertion.
4. The Telegram webhook intentionally has a provider-specific response and authentication contract. It must not be forced through the browser API envelope or browser CSRF mechanism.
5. API status/envelope checks do not prove DB transactions, real Telegram/SMTP delivery, or Browser E2E behavior. Those remain later W4-P6D release-gate work.
